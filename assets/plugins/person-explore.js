/* 選用插件：依序探索（見 souliong/docs/EXTENDING.md 第七節）
   只在 projects/<project>/meta.json 設 "personExplore": true 時，view.php 才會載入這個檔案。
   完全透過 window.MapApp 公開的掛勾點 API 運作，不碰核心 viewer.leaflet.js 的內部變數。
   選了投稿者之後：把他所有照片依「有沒有綁地標」合併／排序成一條時間軸，
   卡片新增一個子區塊，兩個箭頭可逐站切換，切到某站就展開對應的單張照片或地點面板。 */
(() => {
  const App = window.MapApp;
  const meta = (window.APP && window.APP.meta) || {};
  if (!App || !meta.personExplore) return;   // 沒開這個旗標的專案，這支插件什麼都不做

  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const tv = (e) => new Date(e.photo_time || e.created_at).getTime() || 0;

  /* ---------- 注入自己的樣式：核心 style.css 完全不需要知道這個插件存在 ---------- */
  const style = document.createElement('style');
  style.textContent = `
    .pexp-row { flex-direction: column; flex-wrap: nowrap; align-items: stretch; gap: 6px; padding-top: 10px; border-top: 1px solid var(--line) }
    .pexp-summary { font-size: 0.6875rem; color: var(--muted) }
    .pexp-nav { display: flex; align-items: center; gap: 4px; min-width: 0 }
    .pexp-pos { flex: 1; min-width: 0; font-size: 0.8125rem; font-weight: 500; text-align: center; overflow: hidden; white-space: nowrap }
    .pexp-pos.has-overflow { text-align: left; cursor: pointer }
    .pexp-pos-inner { display: inline-block; white-space: nowrap }
    .pexp-hint { font-size: 0.75rem; color: var(--muted); margin: 2px 0 10px }
    .pexp-linklike { border: none; background: none; padding: 0; font: inherit; color: var(--accent); text-decoration: underline; cursor: pointer }
    .pexp-start { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; border: 1px dashed var(--line); border-radius: var(--r-sm); background: none; color: var(--fg); font: inherit; font-weight: 500; cursor: pointer }
    .pexp-start:hover { background: var(--hover) }
  `;
  document.head.appendChild(style);

  /* ---------- 注入自己的 DOM：接在下拉選單後面、卡片註腳前面 ---------- */
  const ctlBody = document.getElementById('ctlBody');
  const foot = document.getElementById('foot');
  if (!ctlBody) return;
  const row = document.createElement('div');
  row.className = 'ctl-row pexp-row';
  row.id = 'pexpRow';
  row.style.display = 'none';
  row.innerHTML = `
    <div class="pexp-summary" id="pexpSummary"></div>
    <button class="pexp-start" id="pexpStartBtn" type="button"><i class="fa-solid fa-play" aria-hidden="true"></i> ${esc(t('explore_start'))}</button>
    <div class="pexp-nav" id="pexpNav" style="display:none">
      <button class="icon-btn" id="pexpPrevBtn" type="button" title="${esc(t('explore_prev'))}" aria-label="${esc(t('explore_prev'))}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
      <span class="pexp-pos" id="pexpPos"><span class="pexp-pos-inner" id="pexpPosInner"></span></span>
      <button class="icon-btn" id="pexpNextBtn" type="button" title="${esc(t('explore_next'))}" aria-label="${esc(t('explore_next'))}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
    </div>
  `;
  if (foot) ctlBody.insertBefore(row, foot); else ctlBody.appendChild(row);

  /* ---------- state ---------- */
  let stops = [], idx = -1, focusName = null, started = false, lastPerson = null;

  function stopKey(s) { return s.type === 'point' ? ('p' + s.num) : ('l' + s.entry.id); }
  function stopLabel(s) {
    if (s.type === 'point') {
      return (s.point ? App.pointTitle(s.point) : t('explore_point_removed')) + '｜' + t('explore_photo_count', { n: s.photos.length });
    }
    const cmt = (s.entry.comment || '').trim();
    return esc(cmt ? (cmt.length > 24 ? cmt.slice(0, 24) + '…' : cmt) : t('explore_loose_photo'));
  }

  // 文字跑一次馬燈：滑鼠移入（桌面）或點一下（觸控）才觸發，不會自己跑起來；
  // 跑到底、停一下、再跑回開頭就停住（不循環），尊重 prefers-reduced-motion。
  function playMarquee(inner, dist) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (inner.getAnimations().length) return;   // 正在跑就不重疊觸發
    const dur = Math.min(7000, Math.max(2200, dist * 22));
    inner.animate([
      { transform: 'translateX(0)', offset: 0 },
      { transform: 'translateX(0)', offset: 0.12 },
      { transform: 'translateX(-' + dist + 'px)', offset: 0.5 },
      { transform: 'translateX(-' + dist + 'px)', offset: 0.72 },
      { transform: 'translateX(0)', offset: 1 }
    ], { duration: dur, easing: 'ease-in-out' });
  }

  const posOuter = row.querySelector('#pexpPos');
  const posInner = row.querySelector('#pexpPosInner');
  function tryPlayMarquee() {
    if (!posOuter.classList.contains('has-overflow')) return;
    const overflow = posInner.scrollWidth - posOuter.clientWidth;
    if (overflow > 4) playMarquee(posInner, overflow + 6);
  }
  posOuter.addEventListener('mouseenter', tryPlayMarquee);
  posOuter.addEventListener('click', tryPlayMarquee);

  const startBtn = row.querySelector('#pexpStartBtn');
  const navEl = row.querySelector('#pexpNav');

  function renderUI() {
    const on = App.isPhotoLayerOn() && !!App.getFilterPerson() && stops.length > 0;
    row.style.display = on ? '' : 'none';
    if (!on) return;
    const nPoint = stops.filter(s => s.type === 'point').length;
    const nLoose = stops.length - nPoint;
    document.getElementById('pexpSummary').textContent = t('explore_summary', { points: nPoint, loose: nLoose });
    startBtn.style.display = started ? 'none' : '';
    navEl.style.display = started ? '' : 'none';
    if (!started) return;   // 還沒按開始，不用算目前這站的位置文字
    posInner.getAnimations().forEach(a => a.cancel());
    posInner.style.transform = 'translateX(0)';
    posInner.textContent = t('explore_pos', { i: idx + 1, n: stops.length }) + '｜' + stopLabel(stops[idx]);
    // 量測要等這一輪 layout 真的套用（尤其是 row 剛從 display:none 打開時）才準
    requestAnimationFrame(() => {
      const overflow = posInner.scrollWidth - posOuter.clientWidth;
      posOuter.classList.toggle('has-overflow', overflow > 4);
    });
  }

  // 重新整理資料或切換投稿者時呼叫：盡量保留原本正在看的那一站（用 key 對回新清單的位置）
  function rebuild() {
    const name = App.getFilterPerson();
    if (name !== lastPerson) { started = false; lastPerson = name; }   // 換人就要重新按開始
    if (!(App.isPhotoLayerOn() && name)) { stops = []; idx = -1; renderUI(); return; }
    const prevKey = (idx >= 0 && stops[idx]) ? stopKey(stops[idx]) : null;
    stops = App.personTimeline(name);
    idx = stops.length ? Math.max(0, prevKey ? stops.findIndex(s => stopKey(s) === prevKey) : 0) : -1;
    renderUI();
  }

  function jumpToStop(s) {
    const map = App.getMap();
    if (s.type === 'point' && s.point) {
      focusName = App.getFilterPerson();
      map.panTo([s.point.lat, s.point.lon], { animate: true });
      App.openPanel(s.point);
    } else if (s.type === 'loose') {
      const e = s.entry;
      if (typeof e.lat === 'number' && typeof e.lon === 'number') map.panTo([e.lat, e.lon], { animate: true });
      App.openLightbox(e, App.photoFullUrl(e));
    }
  }

  function start() {
    if (!stops.length) return;
    started = true;
    idx = 0;
    jumpToStop(stops[0]);
    renderUI();
  }

  function go(delta) {
    if (!started || !stops.length) return;
    idx = (idx + delta + stops.length) % stops.length;
    jumpToStop(stops[idx]);
    renderUI();
  }

  startBtn.onclick = start;
  document.getElementById('pexpPrevBtn').onclick = () => go(-1);
  document.getElementById('pexpNextBtn').onclick = () => go(1);

  /* ---------- 掛到核心的掛勾點 ---------- */
  App.onHook('stateChange', rebuild);
  App.onHook('panelReset', () => { focusName = null; started = false; renderUI(); });

  // 只顯示這個人在這個點的照片：跟核心「這個點所有投稿的照片」清單做交集
  App.registerPhotoFilter((e) => !focusName || e.name === focusName);

  // 附一個「顯示全部」的提示，讓人隨時能看回這個點的全部投稿
  App.registerEntriesHint((current) => {
    if (!focusName) return null;
    const hint = document.createElement('div');
    hint.className = 'hint pexp-hint';
    hint.innerHTML = esc(t('explore_filtered_hint', { name: focusName })) + ' <button type="button" class="pexp-linklike" id="pexpShowAllBtn">' + esc(t('explore_show_all')) + '</button>';
    const sab = hint.querySelector('#pexpShowAllBtn');
    if (sab) sab.onclick = () => { focusName = null; App.refreshEntries(); };
    return hint;
  });
})();
