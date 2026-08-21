/* 通用地圖檢視器 —— 由 ?p=<project> 載入 projects/<project>/meta.json 與點位資料。
   後端：api/list.php、api/upload.php（純 PHP，append-only）。 */
window.MapApp = (() => {
  const APP = window.APP || { base: './', project: 'chairs' };
  const I18N = window.I18N || {};
  // 翻譯輔助：key 缺就直接顯示 key 本身（不會整段消失，方便發現漏翻）
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const params = new URLSearchParams(location.search);
  const PROJECT = (APP.project || params.get('p') || 'chairs').replace(/[^a-z0-9_-]/gi, '');
  const EMBED = !!(APP.embed) || params.get('embed') === '1';
  const apiUrl = (action) => APP.base + '?api=' + action;
  const catOrder = ['green', 'pink', 'blue'];

  // 版權標註（依 OSM 慣例含連結）——資料著作權（OSM）領頭，其次圖磚/框架（CARTO/Leaflet），自家連結依重要性排最後：GitHub → Souliong → prjToka
  const REPO_URL = 'https://github.com/zisunny104/koilisu-souliong';
  const SITE_URL = (APP.base || '/');          // Souliong 平台首頁
  const ORG_URL = 'https://toka.dev';          // prjToka
  const CREDIT_HTML =
    '<span class="cr-ext">' +
      '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> ' + t('osm_contributors') + ' &middot; ' +
      '<a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a> &middot; ' +
      '<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>' +
    '</span>' +
    '<span class="cr-sep" aria-hidden="true"></span>' +
    '<span class="cr-own">' +
      '<a href="' + REPO_URL + '" target="_blank" rel="noopener" aria-label="' + t('github_source_aria') + '"><i class="fa-brands fa-github"></i></a> &middot; ' +
      '<a href="' + SITE_URL + '">Souliong</a> &middot; <a href="' + ORG_URL + '" target="_blank" rel="noopener">prjToka</a>' +
    '</span>';
  // 主題：system / light / dark（手動可覆蓋系統偏好）
  const TILE_OPTS = { maxZoom: 20, subdomains: 'abcd', detectRetina: true, attribution: CREDIT_HTML };
  const systemDark = () => !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const isDark = () => { const t = document.documentElement.dataset.theme; return t === 'dark' ? true : t === 'light' ? false : systemDark(); };
  // 底圖：淺色用 Voyager（道路較寬、有淡彩），深色用 Dark Matter
  const tileUrl = () => 'https://{s}.basemaps.cartocdn.com/' + (isDark() ? 'dark_all' : 'rastertiles/voyager') + '/{z}/{x}/{y}{r}.png';
  function addTileLayer(map) { return L.tileLayer(tileUrl(), TILE_OPTS).addTo(map); }
  let themeMode = localStorage.getItem('theme') || 'system';
  if (themeMode !== 'system') document.documentElement.dataset.theme = themeMode;

  // 未填暱稱時給一個可愛的隨機匿名名（存在本機，重新整理不會換；長按身分可換新的）
  const ANON_NOUNS = t('anon_nouns').split(',');
  function newAnonName() { return t('anon_prefix') + ANON_NOUNS[Math.floor(Math.random() * ANON_NOUNS.length)]; }
  let SESSION_ANON = newAnonName();
  try { SESSION_ANON = localStorage.getItem('anonName') || SESSION_ANON; localStorage.setItem('anonName', SESSION_ANON); } catch (e) {}
  function rerollAnon() {
    SESSION_ANON = newAnonName();
    try { localStorage.setItem('anonName', SESSION_ANON); localStorage.removeItem('myName'); } catch (e) {}
    const myName = document.getElementById('myName'); if (myName) myName.value = '';
    emitHook('identityChanged');
    emitHook('identityReroll');
    toast(t('toast_new_anon_name', { name: SESSION_ANON }));
  }
  function displayName() {
    const n = (document.getElementById('myName').value || '').trim();
    return n || SESSION_ANON;
  }

  // 擁有者標記（此裝置）：用於「只刪自己的」。90 天後自動更換（過期即無法再刪舊內容）。
  function ownerToken() {
    const KEY = 'ownerToken', TTL = 90 * 24 * 3600 * 1000;
    try { const o = JSON.parse(localStorage.getItem(KEY) || 'null'); if (o && o.t && (Date.now() - o.c) < TTL) return o.t; } catch (e) {}
    const t = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2));
    try { localStorage.setItem(KEY, JSON.stringify({ t: t, c: Date.now() })); } catch (e) {}
    return t;
  }
  let myOwnerHash = '';
  async function computeMyHash() {
    try {
      const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(ownerToken()));
      myOwnerHash = [...new Uint8Array(d)].map(x => x.toString(16).padStart(2, '0')).join('');
    } catch (e) { myOwnerHash = ''; }
  }

  // 投稿者身分（可選，設 PIN 才有；匿名投稿者無此身分）：token 存於此裝置，跨裝置管理自己的投稿要靠它。
  function contribInfo() { try { return JSON.parse(localStorage.getItem('contrib_' + PROJECT) || 'null') || null; } catch (e) { return null; } }
  function contribToken() { const c = contribInfo(); return (c && c.token) ? c.token : ''; }
  function setContribInfo(info) { try { localStorage.setItem('contrib_' + PROJECT, JSON.stringify(info)); } catch (e) {} }
  let myContribId = '';
  async function computeContribId() {
    const ct = contribToken();
    if (!ct) { myContribId = ''; return; }
    try {
      const d = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('cid|' + ct));
      myContribId = [...new Uint8Array(d)].map(x => x.toString(16).padStart(2, '0')).join('').slice(0, 12);
    } catch (e) { myContribId = ''; }
  }
  const isMine = (e) => !!((myOwnerHash && e.owner_hash && e.owner_hash === myOwnerHash) || (myContribId && e.contrib_id && e.contrib_id === myContribId));

  // 新增一筆投稿（故事版本、照片…共用）：project/owner/code/ctoken 這些通用欄位統一在這裡補上，呼叫端只要給業務欄位（kind/name/comment/photo…）。
  // 欄位值傳 [blob, filename] 陣列可指定 Blob 的檔名（否則瀏覽器預設存成 "blob"）。
  // opts.maxRetry 搭配 opts.onRetry(waitSeconds, attempt, maxAttempt) 可在遇到伺服器限流（429）時自動倒數重試，不做的話（不傳 opts）就是原本的單次送出行為。
  async function submitContribution(fields, opts) {
    opts = opts || {};
    const maxRetry = opts.maxRetry || 0;
    for (let attempt = 0; attempt <= maxRetry; attempt++) {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('owner', ownerToken());
      fd.append('code', storedCode());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      for (const k in fields) {
        const v = fields[k];
        if (v === undefined || v === null) continue;
        if (Array.isArray(v)) fd.append(k, v[0], v[1]); else fd.append(k, v);
      }
      const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
      if (res.status === 429 && attempt < maxRetry) {
        const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
        if (opts.onRetry) await opts.onRetry(wait, attempt + 1, maxRetry);
        continue;
      }
      const j = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
      if (!res.ok || j.error) throw new Error((j.error || ('HTTP ' + res.status)) + (j.detail ? '：' + j.detail : ''));
      CONTRIB.push(j.item);
      return j.item;
    }
    const err = new Error(t('failed_rate_limited_retry_manually')); err.rateLimited = true; throw err;
  }

  async function deleteEntry(id) {
    if (!confirm(t('confirm_delete'))) return;
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('id', id); fd.append('owner', ownerToken());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      const res = await fetch(apiUrl('delete'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || j.error) { alert(t('delete_failed', { reason: j.error || ('HTTP ' + res.status) })); return; }
      CONTRIB = CONTRIB.filter(e => String(e.id) !== String(id));
      refreshAll();
    } catch (e) { alert(t('delete_failed', { reason: e.message })); }
  }

  // 匿名統計（只累加計數，不送個資）
  function statSend(type, id, extra) {
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('type', type);
      if (id != null) fd.append('id', id);
      if (extra) for (const k in extra) fd.append(k, extra[k]);
      if (navigator.sendBeacon) navigator.sendBeacon(apiUrl('stat'), fd);
      else fetch(apiUrl('stat'), { method: 'POST', body: fd, keepalive: true });
    } catch (e) {}
  }
  const feature = (name) => statSend('feature', name);
  function statVisit() {
    const now = new Date();
    statSend('view', null, { h: now.getHours(), d: now.getDay() });
    try {
      if (!sessionStorage.getItem('sVisited')) {
        sessionStorage.setItem('sVisited', '1');
        statSend('session');
        statSend('device', /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent) ? 'mobile' : 'desktop');
      }
    } catch (e) {}
  }

  // 上傳權限（預設 View；需投稿碼解鎖，限特定人。已用管理 PIN 登入者一律視為已解鎖。EMBED 一律不可上傳）
  function storedCode() { try { return localStorage.getItem('uploadCode_' + PROJECT) || ''; } catch (e) { return ''; } }
  function isUnlocked() { return !APP.gated || !!storedCode() || !!APP.isManager; }
  function canPost() { return !EMBED && MOD('upload') && isUnlocked(); }
  function applyPostState() {
    document.body.classList.toggle('noupload', !canPost());
    const fab = document.getElementById('unlockFab');
    if (fab) fab.style.display = (APP.gated && !isUnlocked() && !EMBED) ? '' : 'none';
    emitHook('identityChanged');
    if (current) renderEntries();   // 讓「編輯說明」鈕跟著出現/消失
  }
  // 右上身分指示鈕的點擊行為（觸發上傳捷徑或解鎖流程）：留在核心是因為要用到 MOD/canPost 等私有狀態；
  // 指示鈕本身的渲染／長按換名／解鎖對話框裡的建立身分欄位都在 assets/js/plugins/contributor-identity.js。
  function identityChipClick() { if (!MOD('upload')) return; if (canPost()) { emitHook('identityUploadShortcut'); } else if (APP.gated) { openUnlock(); } }
  async function doUnlock(code, cpin, cname) {
    code = (code || '').trim();
    if (!code) return { ok: false, msg: t('enter_code') };
    try {
      const fd = new FormData(); fd.append('project', PROJECT); fd.append('code', code);
      if (cpin) { fd.append('cpin', cpin); if (cname) fd.append('cname', cname); }
      const res = await fetch(apiUrl('unlock'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        try { localStorage.setItem('uploadCode_' + PROJECT, code); } catch (e) {}
        if (j.contrib) { setContribInfo(j.contrib); await computeContribId(); }
        applyPostState(); return { ok: true };
      }
      return { ok: false, msg: j.error || t('code_incorrect') };
    } catch (e) { return { ok: false, msg: t('connection_failed') }; }
  }
  function toast(html) { const t = document.createElement('div'); t.className = 'toast egg'; t.innerHTML = html; document.body.appendChild(t); setTimeout(() => { t.style.opacity = '0'; }, 2200); setTimeout(() => t.remove(), 2800); }

  // 管理 PIN 連結兌換：秘密只透過網址 fragment（#redeem=...&rmode=admin）傳遞，不落地在 query string／伺服器紀錄。
  // 讀出後立刻用 history.replaceState 清掉，避免重新整理或分享網址時重複兌換／外流。
  let pendingRedeem = null; // {project, token}，等待使用者在 #adminRedeemDialog 自己輸入 PIN／暱稱後才送出
  async function handleRedeemFragment() {
    if (EMBED || !MOD('delegation')) return;
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    if (!/(^|&)redeem=/.test(raw)) return;
    const hp = new URLSearchParams(raw);
    const token = hp.get('redeem');
    const rmode = hp.get('rmode');
    hp.delete('redeem'); hp.delete('rmode');
    const rest = hp.toString();
    try { history.replaceState(null, '', location.pathname + location.search + (rest ? '#' + rest : '')); } catch (e) {}
    if (!token || rmode !== 'admin') return;
    pendingRedeem = { project: PROJECT, token };
    openAdminRedeem();
  }

  // 管理 PIN 邀請兌換彈窗：PIN／暱稱由收到連結的人自己輸入，不做自動登入
  function openAdminRedeem() {
    const msg = document.getElementById('adminRedeemMsg'); if (msg) { msg.textContent = ''; msg.style.color = ''; }
    const pinEl = document.getElementById('adminRedeemPinInput'), nameEl = document.getElementById('adminRedeemNameInput');
    if (pinEl) pinEl.value = ''; if (nameEl) nameEl.value = '';
    const dlg = document.getElementById('adminRedeemDialog'); if (dlg) dlg.classList.add('open');
    setTimeout(() => { if (pinEl) pinEl.focus(); }, 60);
  }
  function closeAdminRedeem() { const dlg = document.getElementById('adminRedeemDialog'); if (dlg) dlg.classList.remove('open'); }
  async function trySubmitAdminRedeem() {
    if (!pendingRedeem) { closeAdminRedeem(); return; }
    const msg = document.getElementById('adminRedeemMsg');
    const pinEl = document.getElementById('adminRedeemPinInput'), nameEl = document.getElementById('adminRedeemNameInput');
    const pin = pinEl ? pinEl.value.trim() : '', label = nameEl ? nameEl.value.trim() : '';
    if (msg) { msg.style.color = ''; msg.textContent = t('unlocking_verifying'); }
    try {
      const fd = new FormData();
      fd.append('action', 'admin_redeem'); fd.append('project', pendingRedeem.project);
      fd.append('token', pendingRedeem.token); fd.append('pin', pin); fd.append('label', label);
      const res = await fetch(apiUrl('admin'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        pendingRedeem = null;
        closeAdminRedeem();
        toast('<i class="fa-solid fa-user-shield"></i> ' + esc(t('redeem_admin_ok')));
        setTimeout(() => location.reload(), 900);
      } else if (msg) {
        msg.style.color = '#c0392b';
        msg.textContent = j.error === 'pin_taken' ? t('redeem_admin_pin_taken') : (j.error || t('redeem_admin_link_invalid'));
      }
    } catch (e) { if (msg) { msg.style.color = '#c0392b'; msg.textContent = t('connection_failed'); } }
  }

  // 解鎖彈窗 + QR 掃描
  let scanStream = null, scanRAF = null;
  function openUnlock() {
    document.getElementById('unlockCodeInput').value = '';
    const msg = document.getElementById('unlockMsg'); msg.textContent = ''; msg.style.color = '';
    document.getElementById('scanBox').style.display = 'none';
    const pinEl = document.getElementById('unlockPinInput'), nameEl = document.getElementById('unlockCnameInput'), idFields = document.getElementById('idFields'), idBtn = document.getElementById('idToggleBtn');
    if (pinEl) pinEl.value = ''; if (nameEl) nameEl.value = '';
    if (idFields) idFields.style.display = 'none'; if (idBtn) idBtn.setAttribute('aria-expanded', 'false');
    document.getElementById('unlockDialog').classList.add('open');
    setTimeout(() => document.getElementById('unlockCodeInput').focus(), 60);
  }
  function closeUnlock() { stopScan(); const dlg = document.getElementById('unlockDialog'); if (dlg) dlg.classList.remove('open'); }
  async function trySubmitUnlock() {
    const msg = document.getElementById('unlockMsg');
    msg.style.color = ''; msg.textContent = t('unlocking_verifying');
    const pinEl = document.getElementById('unlockPinInput'), nameEl = document.getElementById('unlockCnameInput');
    const cpin = pinEl ? pinEl.value.trim() : '', cname = nameEl ? nameEl.value.trim() : '';
    const r = await doUnlock(document.getElementById('unlockCodeInput').value, cpin, cname);
    if (r.ok) { closeUnlock(); toast('<i class="fa-solid fa-check"></i> ' + esc(t('unlock_success'))); }
    else { msg.style.color = '#c0392b'; msg.textContent = r.msg; }
  }
  function extractCode(text) { if (!text) return ''; const m = String(text).match(/[?&]code=([^&\s]+)/i); return (m ? decodeURIComponent(m[1]) : String(text)).trim(); }
  async function startScan() {
    if (typeof jsQR === 'undefined') { document.getElementById('unlockMsg').textContent = t('scanner_not_loaded'); return; }
    const box = document.getElementById('scanBox'), video = document.getElementById('scanVideo');
    box.style.display = 'block';
    try {
      scanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = scanStream; await video.play();
      const cv = document.createElement('canvas'), ctx = cv.getContext('2d', { willReadFrequently: true });
      const tick = () => {
        if (!scanStream) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
          cv.width = video.videoWidth; cv.height = video.videoHeight;
          ctx.drawImage(video, 0, 0, cv.width, cv.height);
          const img = ctx.getImageData(0, 0, cv.width, cv.height);
          const q = jsQR(img.data, img.width, img.height);
          if (q && q.data) { const c = extractCode(q.data); stopScan(); document.getElementById('unlockCodeInput').value = c; trySubmitUnlock(); return; }
        }
        scanRAF = requestAnimationFrame(tick);
      };
      scanRAF = requestAnimationFrame(tick);
    } catch (e) { document.getElementById('unlockMsg').textContent = t('camera_open_failed', { reason: e.message || e }); box.style.display = 'none'; }
  }
  function stopScan() {
    if (scanRAF) { cancelAnimationFrame(scanRAF); scanRAF = null; }
    if (scanStream) { scanStream.getTracks().forEach(t => t.stop()); scanStream = null; }
    const box = document.getElementById('scanBox'); if (box) box.style.display = 'none';
  }

  let META = null, POINTS = [], CATS = [], active = {}, CONTRIB = [], counts = {};
  let map, photoLayer, baseTile = null, photoLayerOn = false;
  let filterPerson = '';

  function setBaseTile() {
    if (baseTile) map.removeLayer(baseTile);
    baseTile = L.tileLayer(tileUrl(), TILE_OPTS).addTo(map);
    baseTile.bringToBack();
  }
  function updateThemeIcon() {
    const icon = { system: 'fa-circle-half-stroke', light: 'fa-sun', dark: 'fa-moon' }[themeMode] || 'fa-circle-half-stroke';
    const btn = document.getElementById('themeBtn');
    if (btn) btn.innerHTML = '<i class="fa-solid ' + icon + '"></i>';
  }
  function applyTheme(mode) {
    themeMode = mode;
    if (mode === 'system') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = mode;
    localStorage.setItem('theme', mode);
    updateThemeIcon();
    if (map) setBaseTile();
  }
  const chairMarkers = {};

  /* ---------- 選用插件掛勾點（見 souliong/docs/EXTENDING.md）----------
     核心只負責「發生了什麼事」（onHook/emitHook）與「讓插件參與渲染結果」（registerPhotoFilter/registerEntriesHint），
     不認得任何特定插件；插件自己的邏輯、DOM、CSS 都放在 assets/js/plugins/ 底下獨立檔案。 */
  const hookListeners = {};
  function onHook(name, fn) { (hookListeners[name] = hookListeners[name] || []).push(fn); }
  function emitHook(name, ...args) { (hookListeners[name] || []).forEach(fn => { try { fn(...args); } catch (err) { console.error('[hook:' + name + ']', err); } }); }
  const photoFilters = [];     // fn(photoEntry, currentPoint) => bool；renderEntries() 的照片清單要 AND 全部通過
  const entriesHintFns = [];   // fn(currentPoint) => HTMLElement|null；renderEntries() 會把回傳的節點插進卡片內容
  const scopeParamFns = [];    // fn() => {key: value}|null；插件自己在分享連結／嵌入碼網址上帶的額外參數，讀取時插件自己讀 location.search，不需要核心知道
  function registerPhotoFilter(fn) { photoFilters.push(fn); }
  function registerEntriesHint(fn) { entriesHintFns.push(fn); }
  function registerScopeParam(fn) { scopeParamFns.push(fn); }

  // 插件基底類別：每個插件檔案 class XxxPlugin extends MapApp.Plugin，只需覆寫 mount()——
  // 核心建立實例並呼叫 init(MapApp)，插件自己的 state/DOM/CSS 一律掛在 this 底下，不碰核心內部變數。
  class SouliongPlugin {
    constructor(key) { this.key = key; this.mapApp = null; }
    init(MapApp) { this.mapApp = MapApp; this.mount(); }
    mount() {}
  }

  // 模組開關（見 souliong/api/features.php souliong_modules()）：直接讀 PHP 算好的 APP.moduleState，
  // 不在前端重算一次預設值/相依邏輯，確保跟 view.php 用 $mod() 決定要不要輸出 HTML 時的結果一致。
  function MOD(key) { return !!(APP.moduleState && APP.moduleState[key]); }

  /* ---------- helpers ---------- */
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const pad2 = (n) => String(n).padStart(2, '0');
  function fmtTime(t) {
    if (!t) return '';
    const d = new Date(t); if (isNaN(d)) return '';
    const p = n => String(n).padStart(2, '0');
    return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function pointTitle(p) {
    const base = p.theme || p.title || '';
    if (META.numbering === 'prefix') return (p.num != null ? pad2(p.num) + ' ' : '') + base;
    return base + (p.num != null ? ' ' + pad2(p.num) : '');
  }
  function pointSub(p) {
    const bits = [];
    if (p.area || p.chair) bits.push([p.area, p.chair].filter(Boolean).join(' ・ '));
    else if (p.sub) bits.push(p.sub);
    if (p.material) bits.push(p.material);
    return bits.join('<br>');
  }
  function chairOptionsHtml(selNum) {
    const none = '<option value=""' + (selNum == null ? ' selected' : '') + '>' + esc(t('point_none_option')) + '</option>';
    return none + POINTS.slice().sort((a, b) => a.num - b.num).map(p =>
      '<option value="' + p.num + '"' + (p.num === selNum ? ' selected' : '') +
      (p.color ? ' style="color:' + esc(p.color) + '"' : '') + '>' +
      '● ' + pad2(p.num) + '｜' + esc(p.theme || p.chair || '') + '（' + esc(p.area || '') + '）</option>').join('');
  }
  function haversine(aLat, aLon, bLat, bLon) {
    const R = 6371000, r = Math.PI / 180;
    const dLat = (bLat - aLat) * r, dLon = (bLon - aLon) * r;
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
  }
  function nearestPoint(lat, lon) {
    let best = null, bd = Infinity;
    for (const p of effectivePoints()) {
      const d = haversine(lat, lon, p.lat, p.lon);
      if (d < bd) { bd = d; best = p; }
    }
    return best;
  }
  function photoFullUrl(item) { return item.photo ? apiUrl('photo') + '&f=' + encodeURIComponent(item.photo) : null; }
  // 標記與照片牆用縮圖、燈箱用原圖；舊投稿沒 thumb 欄位就帶 th=1 讓伺服器自動產縮圖（失敗時回原圖）
  function photoThumbUrl(item) { return item.thumb ? apiUrl('photo') + '&f=' + encodeURIComponent(item.thumb) : (item.photo ? photoFullUrl(item) + '&th=1' : null); }

  // 合併「原始照片投稿」與其 edit_of 編輯紀錄，算出目前應顯示的內容：
  // 留言/關聯地點/定位取最新一筆編輯，但照片檔案與原始拍攝時間永遠沿用原始那筆（編輯不能換照片本身）。
  function effectivePhotos() {
    const originals = {}, edits = {};
    CONTRIB.forEach(e => {
      if (e.kind === 'desc') return;
      if (e.edit_of) (edits[e.edit_of] = edits[e.edit_of] || []).push(e);
      else if (e.photo) originals[e.id] = e;
    });
    return Object.keys(originals).map(id => {
      const orig = originals[id];
      const list = edits[id];
      if (!list || !list.length) return orig;
      list.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
      const latest = list[list.length - 1];
      return {
        ...orig,
        comment: latest.comment,
        item_num: latest.item_num,
        lat: latest.lat,
        lon: latest.lon,
        loc_source: latest.loc_source,
        edited: true,
        editHistory: [orig, ...list],
      };
    });
  }

  // 合併「定位點（椅子）原始座標」與管理者的位置編輯紀錄：同一 item_num 底下只留最新一筆 kind:'point' 覆蓋座標。
  // origLat/origLon 一律保留 chairs.json 的原始座標，供編輯面板「還原初始位置」使用。
  function effectivePoints() {
    const latest = {};
    CONTRIB.forEach(e => {
      if (e.kind !== 'point' || e.item_num == null) return;
      const cur = latest[e.item_num];
      if (!cur || new Date(e.created_at) > new Date(cur.created_at)) latest[e.item_num] = e;
    });
    return POINTS.map(p => {
      const ed = latest[p.num];
      return ed
        ? { ...p, lat: ed.lat, lon: ed.lon, posEdited: true, origLat: p.lat, origLon: p.lon }
        : { ...p, origLat: p.lat, origLon: p.lon };
    });
  }

  /* ---------- map ---------- */
  // badgeColor 有給值時（篩選單一投稿者時）覆蓋角標底色，跟該投稿者的路徑同色
  function chairIcon(c, count, badgeColor) {
    const badge = count ? '<div class="badge"' + (badgeColor ? ' style="background:' + badgeColor + '"' : '') + '>' + count + '</div>' : '';
    const cls = 'dot-pin' + (count ? ' has-contrib' : '');
    return L.divIcon({
      className: '', iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -12],
      html: '<div class="' + cls + '" style="background:' + c.color + '"><span>' + c.num + '</span>' + badge + '</div>'
    });
  }
  function renderChairs() {
    // 篩選單一投稿者時：角標改顯示「這個人在這個點的張數」，並跟路徑同色（同一個 personColor 快取）
    let personCounts = null, badgeColor = null;
    if (filterPerson) {
      personCounts = {};
      personPoints(filterPerson).forEach(e => { personCounts[e.item_num] = (personCounts[e.item_num] || 0) + 1; });
      badgeColor = personColor(filterPerson);
    }
    effectivePoints().forEach(c => {
      if (chairMarkers[c.num]) map.removeLayer(chairMarkers[c.num]);
      if (active[c.cat] === false) return;
      const count = personCounts ? (personCounts[c.num] || 0) : (counts[c.num] || 0);
      const m = L.marker([c.lat, c.lon], { icon: chairIcon(c, count, badgeColor) }).addTo(map);
      m.on('click', () => { emitHook('panelReset'); openPanel(c); });
      chairMarkers[c.num] = m;
    });
  }
  const THUMB_ZOOM = 15;   // ≥ 此縮放顯示縮圖，較遠只顯示小方塊
  function photoIcon(url, thumb) {
    if (thumb) return L.divIcon({
      className: '', iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -16],
      html: '<div class="photo-sq" style="background-image:url(' + url + ')"></div>'
    });
    // 遠距離的小方塊也用縮圖當底（載入前／載不到時由 background-color 墊色），不再是純色
    return L.divIcon({
      className: '', iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -8],
      html: '<div class="photo-sq plain" style="background-image:url(' + url + ')"></div>'
    });
  }
  let photoLayerThumbState = null;   // 上次 render 時是否在縮圖模式，zoomend 只在跨越門檻時才需要重建
  function renderPhotoLayer() {
    photoLayer.clearLayers();
    if (!photoLayerOn) return;
    const thumb = map.getZoom() >= THUMB_ZOOM;
    photoLayerThumbState = thumb;
    effectivePhotos().forEach(e => {
      if (filterPerson && e.name !== filterPerson) return;
      const url = photoFullUrl(e);
      if (typeof e.lat !== 'number' || typeof e.lon !== 'number' || !url) return;
      const m = L.marker([e.lat, e.lon], { icon: photoIcon(photoThumbUrl(e), thumb) });
      m.on('click', () => openLightbox(e, url));
      photoLayer.addLayer(m);
    });
    if (!map.hasLayer(photoLayer)) photoLayer.addTo(map);
  }

  // 某人的觀察路線（照片依時間串連）
  function personPoints(name) {
    return effectivePhotos().filter(e => e.name === name && typeof e.lat === 'number' && typeof e.lon === 'number')
      .sort((a, b) => tv(a) - tv(b));
  }

  /* ---------- 依序探索（選用功能，見 META.personExplore） ----------
     把某人的所有照片依「有沒有綁地標」分兩種：綁地標的（item_num 有值）同一地標合併成一站，
     用該站最早一張照片的時間排序；沒綁地標的零散照片各自一站，用自己的拍攝時間排序。
     兩種站合併後依時間排成一條時間軸，供左上卡片的兩個箭頭逐站切換。 */
  function personTimeline(name) {
    const groups = {}, loose = [];
    effectivePhotos().filter(e => e.name === name).forEach(e => {
      if (e.item_num != null) (groups[e.item_num] = groups[e.item_num] || []).push(e);
      else loose.push(e);
    });
    const stops = Object.keys(groups).map(numStr => {
      const num = +numStr;
      const photos = groups[numStr].sort((a, b) => tv(a) - tv(b));
      return { type: 'point', num: num, point: effectivePoints().find(p => p.num === num), photos: photos, time: tv(photos[0]) };
    });
    loose.forEach(e => stops.push({ type: 'loose', entry: e, time: tv(e) }));
    return stops.sort((a, b) => a.time - b.time);
  }
  // 隨機配色（不用姓名雜湊固定推算）：同一人第一次出現時抽色，之後這個分頁內都沿用同一色，
  // 讓路徑、篩選時的角標...等所有地方顯示的顏色能保持一致。
  const personColorCache = {};
  function personColor(name) {
    if (!personColorCache[name]) personColorCache[name] = 'hsl(' + Math.floor(Math.random() * 360) + ', 70%, 45%)';
    return personColorCache[name];
  }
  // 依目前「全部／投稿」模式切換下拉選單的用途：投稿模式列投稿者、全部模式列地點標籤（可跳到任一地點，
  // 不受地圖上標記可能重疊、點不到的影響）
  function rebuildPersonFilter() {
    const sel = document.getElementById('personFilter');
    if (!sel) return;
    if (photoLayerOn) {
      sel.title = t('filter_person');
      const names = [...new Set(CONTRIB.filter(e => e.name && (e.photo || e.comment) && !e.edit_of).map(e => e.name))].sort();
      sel.innerHTML = '';
      const all = document.createElement('option'); all.value = ''; all.textContent = t('all_contributors_count', { n: names.length });
      sel.appendChild(all);
      names.forEach(n => { const o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
      sel.value = names.includes(filterPerson) ? filterPerson : '';
    } else {
      sel.title = t('jump_to_point');
      sel.innerHTML = '<option value="">' + esc(t('jump_to_point_option', { n: POINTS.length })) + '</option>' +
        POINTS.slice().sort((a, b) => a.num - b.num).map(p =>
          '<option value="' + p.num + '">' + pad2(p.num) + '｜' + esc(p.theme || p.chair || '') + (p.area ? '（' + esc(p.area) + '）' : '') + '</option>'
        ).join('');
    }
  }

  /* ---------- legend ---------- */
  function buildLegend() {
    const legend = document.getElementById('legend'); legend.innerHTML = '';
    CATS.forEach(c => {
      const el = document.createElement('div'); el.className = 'chip' + (active[c.key] === false ? ' off' : '');
      el.innerHTML = '<span class="dot" style="background:' + c.color + '"></span>' + c.label;
      el.onclick = () => { active[c.key] = active[c.key] === false ? true : false; el.classList.toggle('off', active[c.key] === false); renderChairs(); };
      legend.appendChild(el);
    });
  }

  /* ---------- panel ---------- */
  let current = null;
  function openPanel(c) {
    current = c;
    const cat = CATS.find(x => x.key === c.cat) || { label: '', color: '' };
    document.getElementById('pCat').textContent = cat.label;
    document.getElementById('pCat').style.color = cat.color;
    document.getElementById('pTitle').textContent = pointTitle(c);
    document.getElementById('pSub').innerHTML = pointSub(c);
    document.getElementById('panel').classList.add('open');
    const peBtn = document.getElementById('pointEditBtn');
    if (peBtn) peBtn.style.display = (!EMBED && APP.isManager) ? '' : 'none';
    resetPointEditor();
    renderEntries();
    statSend('point', c.num);
  }
  function closePanel() { document.getElementById('panel').classList.remove('open'); resetPointEditor(); current = null; emitHook('panelReset'); }
  // 電腦版：地點卡片在「預設寬度」與「接近全螢幕的大卡片」之間切換（狀態保留到下次開啟）
  function togglePanelSize() {
    const p = document.getElementById('panel');
    const wide = p.classList.toggle('wide');
    const btn = p.querySelector('.p-expand');
    if (btn) {
      btn.title = t(wide ? 'collapse_panel' : 'expand_panel');
      btn.setAttribute('aria-label', btn.title);
      btn.innerHTML = '<i class="fa-solid ' + (wide ? 'fa-down-left-and-up-right-to-center' : 'fa-up-right-and-down-left-from-center') + '" aria-hidden="true"></i>';
    }
    // 寬度動畫結束後觸發 resize，讓卡片內的迷你地圖（Leaflet trackResize）重算尺寸
    setTimeout(() => window.dispatchEvent(new Event('resize')), 320);
  }
  function resetPointEditor() {
    const el = document.getElementById('pointEditor');
    if (!el) return;
    const m = el._mini; if (m) m.remove();
    el._mini = null; el.style.display = 'none'; el.innerHTML = '';
  }
  // 定位點（椅子）位置微調面板：僅管理者可見，比照 buildPhotoEditorPanel 的迷你地圖模式，
  // 但不需要留言/關聯地點欄位，多了「還原初始位置」讓管理者在儲存前能隨時退回 chairs.json 的原始座標。
  function togglePointEditor() {
    const el = document.getElementById('pointEditor');
    if (!el || !current) return;
    if (el.style.display !== 'none') { resetPointEditor(); return; }
    el.style.display = 'block';
    const c = current;
    const lat0 = c.lat, lon0 = c.lon;
    el.innerHTML =
      '<div class="mini pt-mini"></div>' +
      '<div class="row">' +
      '<button class="btn small pt-revert" type="button">' + esc(t('reset_location_btn')) + '</button>' +
      '<button class="btn small pt-cancel" type="button">' + esc(t('cancel')) + '</button>' +
      '<button class="btn primary small pt-save" type="button">' + esc(t('save_location_btn')) + '</button>' +
      '<span class="status pt-status"></span></div>';
    const miniDiv = el.querySelector('.pt-mini');
    const mini = L.map(miniDiv, { attributionControl: false, zoomControl: false, dragging: true }).setView([lat0, lon0], 17);
    el._mini = mini;
    L.tileLayer(tileUrl(), TILE_OPTS).addTo(mini);
    const mk = L.marker([lat0, lon0], { draggable: true }).addTo(mini);
    const state = { lat: lat0, lon: lon0 };
    mk.on('dragend', ev => { const ll = ev.target.getLatLng(); state.lat = ll.lat; state.lon = ll.lng; });
    mini.on('click', ev => { mk.setLatLng(ev.latlng); state.lat = ev.latlng.lat; state.lon = ev.latlng.lng; });
    const fix = () => { try { mini.invalidateSize(false); } catch (err) {} };
    requestAnimationFrame(fix);
    [150, 500, 1200].forEach(ms => setTimeout(fix, ms));

    el.querySelector('.pt-revert').onclick = () => {
      const ll = { lat: c.origLat, lng: c.origLon };
      mk.setLatLng(ll); mini.panTo(ll); state.lat = c.origLat; state.lon = c.origLon;
    };
    el.querySelector('.pt-cancel').onclick = () => resetPointEditor();
    el.querySelector('.pt-save').onclick = () => submitPointEdit(c, state, el, mini);
  }
  async function submitPointEdit(orig, state, panel, mini) {
    const btn = panel.querySelector('.pt-save'); const status = panel.querySelector('.pt-status');
    btn.disabled = true; status.textContent = t('saving');
    try {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('item_num', orig.num);
      fd.append('lat', state.lat);
      fd.append('lon', state.lon);
      fd.append('name', displayName());
      fd.append('csrf', APP.csrf || '');
      const res = await fetch(apiUrl('editpoint'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      resetPointEditor();
      renderChairs(); rebuildPersonFilter();
      const updated = effectivePoints().find(p => p.num === orig.num);
      if (updated) {
        current = updated;
        document.getElementById('pTitle').textContent = pointTitle(updated);
        document.getElementById('pSub').innerHTML = pointSub(updated);
        renderEntries();
      }
    } catch (err) {
      status.textContent = t('save_failed', { err: err.message });
      btn.disabled = false;
    }
  }
  function renderEntries() {
    if (!current) return;
    const box = document.getElementById('entries'); box.innerHTML = '';
    const descs = CONTRIB.filter(e => e.item_num === current.num && e.kind === 'desc' && e.comment).sort((a, b) => tv(a) - tv(b));
    const photos = effectivePhotos().filter(e => e.item_num === current.num && photoFilters.every(f => f(e, current))).sort((a, b) => tv(a) - tv(b));
    // 版本序列：原始（資料來源為專案自訂的 META.source，例如 StoryMaps）在最舊，之後接使用者編輯版本
    const versions = [];
    if (current.story) versions.push({ name: t('original_source_tag'), comment: current.story, created_at: null, baseline: true });
    descs.forEach(d => versions.push(d));
    const latest = versions[versions.length - 1];
    const byLine = latest
      ? (latest.baseline ? esc(t('source_label', { src: META.source || META.credit || '' })) : '— ' + esc(latest.name || t('anon_fallback')) + '・' + fmtTime(latest.photo_time || latest.created_at))
      : '';

    // 故事 / 說明（版本化，預設只顯示最新版）
    const story = document.createElement('div'); story.className = 'story';
    story.innerHTML =
      '<div class="story-head">' + esc(t('location_story_title')) + '</div>' +
      '<div class="story-body">' + (latest ? esc(latest.comment) : '<span class="empty">' + esc(t('story_empty')) + '</span>') + '</div>' +
      (byLine ? '<div class="story-by">' + byLine + '</div>' : '') +
      '<div class="story-actions" id="storyActions">' +
      (!EMBED && versions.length > 1 ? '<button class="btn small" id="histBtn">' + esc(t('history_versions', { n: versions.length })) + '</button>' : '') +
      '</div><div id="descHistory" style="display:none"></div>';
    box.appendChild(story);
    const hb = story.querySelector('#histBtn'); if (hb) hb.onclick = () => toggleHistory(versions);

    // 插件掛勾點：讓插件（例如上傳、依序探索）在照片牆前面插入自己的提示區塊或按鈕（如「上傳照片到這個點」「僅顯示 X 的照片・顯示全部」）
    entriesHintFns.forEach(fn => { const el = fn(current); if (el) box.appendChild(el); });

    // 照片牆
    const gwrap = document.createElement('div');
    gwrap.className = 'gallery';   // 大卡片模式時靠這個 class 排成多欄
    if (!photos.length) gwrap.innerHTML = '<div class="empty" style="margin-top:12px">' + esc(t('photos_empty')) + '</div>';
    photos.forEach(e => {
      const url = photoFullUrl(e);
      const d = document.createElement('div'); d.className = 'entry'; d.dataset.entryId = e.id;
      const alt = esc(e.comment || (current.chair || current.theme || t('contrib_photo_alt')));
      const canEdit = canPost() && (isMine(e) || APP.isManager);
      d.innerHTML = '<img src="' + photoThumbUrl(e) + '" loading="lazy" decoding="async" alt="' + alt + '" tabindex="0">' + '<div class="meta"><div class="who">' + esc(e.name || t('anon_fallback')) +
        (e.edited ? ' <span class="edited-tag">' + esc(t('edited_tag')) + '</span>' : '') + '</div>' +
        '<div class="time">' + fmtTime(e.photo_time || e.created_at) + '</div>' +
        (e.comment ? '<div class="txt">' + esc(e.comment) + '</div>' : '') +
        '<div class="entry-actions">' +
        (canEdit ? '<button class="btn small edit-btn" type="button"><i class="fa-solid fa-pen"></i> ' + esc(t('edit')) + '</button>' : '') +
        (!EMBED && e.editHistory && e.editHistory.length > 1 ? '<button class="btn small hist-btn" type="button">' + esc(t('history_versions', { n: e.editHistory.length })) + '</button>' : '') +
        (!EMBED && isMine(e) ? '<button class="del-btn" type="button"><i class="fa-solid fa-trash"></i> ' + esc(t('delete')) + '</button>' : '') + '</div>' +
        '</div><div class="photo-editor" style="display:none"></div><div class="photo-history" style="display:none"></div>';
      d.querySelector('img').onclick = () => openLightbox(e, url);
      const del = d.querySelector('.del-btn'); if (del) del.onclick = () => deleteEntry(e.id);
      const edbtn = d.querySelector('.edit-btn'); if (edbtn) edbtn.onclick = () => togglePhotoEditor(e, d);
      const hbtn = d.querySelector('.hist-btn'); if (hbtn) hbtn.onclick = () => togglePhotoEditHistory(e, d);
      gwrap.appendChild(d);
    });
    box.appendChild(gwrap);
  }
  const tv = (e) => new Date(e.photo_time || e.created_at).getTime() || 0;
  function chairColorOf(num) { const p = POINTS.find(x => x.num === num); return p ? p.color : '#888'; }

  // 編輯照片的留言/關聯地點/定位共用的面板內容建構（原始照片檔案本身不可更換）。
  // opts.onCancel / opts.onSaved 讓呼叫端（照片卡片 vs. 單張檢視）各自決定取消/儲存完成後要做什麼。
  function buildPhotoEditorPanel(e, panel, opts) {
    opts = opts || {};
    panel.innerHTML =
      '<textarea class="pe-cmt" placeholder="' + esc(t('write_something_placeholder')) + '">' + esc(e.comment || '') + '</textarea>' +
      '<label class="c-lab">' + esc(t('related_point_label')) + '</label>' +
      '<select class="pe-chair"></select>' +
      '<div class="mini pe-mini"></div>' +
      '<div class="loc pe-loc"></div>' +
      '<div class="row"><button class="btn small pe-cancel" type="button">' + esc(t('cancel')) + '</button><button class="btn primary small pe-save" type="button">' + esc(t('save')) + '</button><span class="status pe-status"></span></div>';
    const sel = panel.querySelector('.pe-chair');
    sel.innerHTML = chairOptionsHtml(e.item_num);
    const miniDiv = panel.querySelector('.pe-mini');
    const lat0 = typeof e.lat === 'number' ? e.lat : META.center[0];
    const lon0 = typeof e.lon === 'number' ? e.lon : META.center[1];
    const mini = L.map(miniDiv, { attributionControl: false, zoomControl: false, dragging: true }).setView([lat0, lon0], 16);
    panel._mini = mini;
    L.tileLayer(tileUrl(), TILE_OPTS).addTo(mini);
    const mk = L.marker([lat0, lon0], { draggable: true }).addTo(mini);
    const state = { lat: lat0, lon: lon0, source: e.loc_source || 'manual' };
    const locEl = panel.querySelector('.pe-loc');
    locEl.innerHTML = locNote(state.source) + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
    const setBorder = () => { miniDiv.style.borderColor = chairColorOf(sel.value ? +sel.value : null); };
    setBorder();
    sel.onchange = setBorder;
    const updLoc = (ll) => {
      state.lat = ll.lat; state.lon = ll.lng; state.source = 'manual';
      locEl.innerHTML = locNote('manual') + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
    };
    mk.on('dragend', ev => updLoc(ev.target.getLatLng()));
    mini.on('click', ev => { mk.setLatLng(ev.latlng); updLoc(ev.latlng); });
    const fix = () => { try { mini.invalidateSize(false); } catch (err) {} };
    requestAnimationFrame(fix);
    [150, 500, 1200].forEach(ms => setTimeout(fix, ms));

    panel.querySelector('.pe-cancel').onclick = () => {
      mini.remove(); panel._mini = null; panel.style.display = 'none'; panel.innerHTML = '';
      if (opts.onCancel) opts.onCancel();
    };
    panel.querySelector('.pe-save').onclick = () => submitPhotoEdit(e, {
      comment: panel.querySelector('.pe-cmt').value,
      item_num: sel.value,
      lat: state.lat, lon: state.lon, loc_source: state.source,
    }, panel, mini, opts.onSaved);
  }
  // 每張卡片一個可收合面板，同時只開一個。
  function togglePhotoEditor(e, container) {
    const panel = container.querySelector('.photo-editor');
    if (panel.style.display !== 'none') { const m = panel._mini; if (m) m.remove(); panel.style.display = 'none'; panel.innerHTML = ''; return; }
    document.querySelectorAll('.photo-editor').forEach(p => { if (p !== panel) { const m = p._mini; if (m) m.remove(); p.style.display = 'none'; p.innerHTML = ''; } });
    const histPanel = container.querySelector('.photo-history'); if (histPanel) { histPanel.style.display = 'none'; histPanel.innerHTML = ''; }
    panel.style.display = 'block';
    buildPhotoEditorPanel(e, panel);
  }
  // 單張檢視（lightbox）裡的獨立編輯面板：有些照片沒有對應的地點（item_num 為空），
  // 側欄的照片牆是依地點分組顯示，這種照片永遠不會出現在任何照片牆卡片上，
  // 所以這裡不能靠「捲到卡片」，要有一份完全獨立、平行的編輯入口。
  function toggleLightboxEditor(e) {
    const panel = document.getElementById('lbEditor');
    const cap = document.getElementById('lbCap');
    if (panel.style.display !== 'none') { const m = panel._mini; if (m) m.remove(); panel.style.display = 'none'; panel.innerHTML = ''; if (cap) cap.style.display = ''; return; }
    document.querySelectorAll('.photo-editor').forEach(p => { if (p !== panel) { const m = p._mini; if (m) m.remove(); p.style.display = 'none'; p.innerHTML = ''; } });
    if (cap) cap.style.display = 'none';  // 編輯面板跟 caption 都貼底置中，同時顯示會疊在一起，編輯時先收起 caption
    panel.style.display = 'block';
    buildPhotoEditorPanel(e, panel, {
      onCancel: () => { if (cap) cap.style.display = ''; },
      onSaved: () => {
        const updated = effectivePhotos().find(x => x.id === e.id) || e;
        openLightbox(updated, photoFullUrl(updated));
      }
    });
  }
  async function submitPhotoEdit(orig, vals, panel, mini, onSaved) {
    const btn = panel.querySelector('.pe-save'); const status = panel.querySelector('.pe-status');
    btn.disabled = true; status.textContent = t('saving');
    try {
      const fd = new FormData();
      fd.append('project', PROJECT);
      fd.append('edit_of', orig.id);
      if (vals.item_num !== '' && vals.item_num != null) fd.append('item_num', vals.item_num);
      fd.append('comment', (vals.comment || '').trim());
      if (vals.lat != null) fd.append('lat', vals.lat);
      if (vals.lon != null) fd.append('lon', vals.lon);
      fd.append('loc_source', vals.loc_source || 'manual');
      fd.append('name', displayName());
      fd.append('owner', ownerToken());
      const ct = contribToken(); if (ct) fd.append('ctoken', ct);
      const res = await fetch(apiUrl('editentry'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      mini.remove(); panel._mini = null;
      panel.style.display = 'none'; panel.innerHTML = '';
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); emitHook('stateChange');
      if (current) renderEntries();
      if (onSaved) onSaved(j.item);
    } catch (err) {
      status.textContent = t('save_failed', { err: err.message });
      btn.disabled = false;
    }
  }
  // 照片編輯歷史（比照故事的 toggleHistory）：原始投稿與之後每一次編輯都保留，新到舊列出
  function togglePhotoEditHistory(e, container) {
    const panel = container.querySelector('.photo-history');
    if (panel.style.display !== 'none') { panel.style.display = 'none'; panel.innerHTML = ''; return; }
    const editor = container.querySelector('.photo-editor'); if (editor) { const m = editor._mini; if (m) m.remove(); editor.style.display = 'none'; editor.innerHTML = ''; }
    panel.style.display = 'block';
    const list = (e.editHistory || []).slice().reverse();
    panel.innerHTML = '<div class="hist-title">' + esc(t('photo_history_title')) + '</div>' +
      list.map((v, i) => {
        const isOrig = !v.edit_of;
        return '<div class="hist-item"><div class="hist-meta">' + (i === 0 ? '<b>' + esc(t('latest_tag')) + '</b>・' : '') +
          esc(v.name || t('anon_fallback')) + '・' + fmtTime(v.photo_time || v.created_at) +
          (isOrig ? esc(t('original_submission_tag')) : '') +
          (!EMBED && isMine(v) && !isOrig ? ' <button class="del-btn" type="button" data-id="' + esc(v.id) + '">' + esc(t('delete')) + '</button>' : '') + '</div>' +
          '<div class="hist-txt">' + (v.comment ? esc(v.comment) : '<span class="empty">' + esc(t('no_comment')) + '</span>') + '</div></div>';
      }).join('');
    panel.querySelectorAll('.del-btn[data-id]').forEach(b => b.onclick = () => deleteEntry(b.dataset.id));
  }

  function toggleHistory(descs) {
    const el = document.getElementById('descHistory');
    if (el.style.display === 'none') {
      el.style.display = 'block';
      el.innerHTML = '<div class="hist-title">' + esc(t('desc_history_title')) + '</div>' +
        descs.slice().reverse().map((d, i) =>
          '<div class="hist-item"><div class="hist-meta">' + (i === 0 ? '<b>' + esc(t('latest_tag')) + '</b>・' : '') +
          (d.baseline ? esc(t('original_source_tag')) : esc(d.name || t('anon_fallback')) + '・' + fmtTime(d.photo_time || d.created_at)) +
          (!EMBED && isMine(d) ? ' <button class="del-btn" type="button" data-id="' + esc(d.id) + '">' + esc(t('delete')) + '</button>' : '') + '</div>' +
          '<div class="hist-txt">' + esc(d.comment) + '</div></div>').join('');
      el.querySelectorAll('.del-btn[data-id]').forEach(b => b.onclick = () => deleteEntry(b.dataset.id));
    } else { el.style.display = 'none'; el.innerHTML = ''; }
  }
  /* ---------- lightbox ---------- */
  // 單張的「i」資訊內容：相機 EXIF（機身/鏡頭/光圈/快門/焦段/ISO）、拍攝時間、座標與定位來源
  function photoInfoHtml(e) {
    const x = e.exif || {};
    const rows = [];
    const cam = [x.make, x.model].filter(Boolean).join(' ');
    if (cam) rows.push([t('info_camera'), esc(cam)]);
    if (x.lens) rows.push([t('info_lens'), esc(x.lens)]);
    const shot = [];
    if (x.f) shot.push('f/' + (+x.f));
    if (x.exp) shot.push((+x.exp) >= 1 ? (+x.exp) + 's' : '1/' + Math.round(1 / (+x.exp)) + 's');
    if (x.focal) shot.push((+x.focal) + 'mm');
    if (x.iso) shot.push('ISO ' + (+x.iso));
    if (shot.length) rows.push([t('info_params'), shot.join(' · ')]);
    if (x.sw) rows.push([t('info_software'), esc(x.sw)]);
    if (!rows.length) rows.push([t('info_camera'), '<span class="empty">' + esc(t('info_no_camera')) + '</span>']);
    const shotTime = e.photo_time || e.created_at;
    if (shotTime) rows.push([t('info_shot_time'), fmtTime(shotTime)]);
    if (e.lat != null && e.lon != null) rows.push([t('info_coords'), (+e.lat).toFixed(5) + ', ' + (+e.lon).toFixed(5)]);
    rows.push([t('info_loc_source'), locNote(e.loc_source)]);
    return rows.map(r => '<div class="lbi-row"><span class="lbi-k">' + r[0] + '</span><span class="lbi-v">' + r[1] + '</span></div>').join('');
  }
  // 傳整筆投稿資料進來，才能連留言／說明文字一起顯示成卡片（不只圖片+姓名時間）
  function openLightbox(e, url) {
    const lbImg = document.getElementById('lbImg');
    const nextUrl = url || photoFullUrl(e);
    // 換照片時先淡出，避免舊照片在新資訊（姓名/時間/說明）已更新後還被誤認成「已載入完成」
    if (lbImg.src !== new URL(nextUrl, location.href).href) {
      lbImg.classList.add('loading');
      lbImg.onload = lbImg.onerror = () => lbImg.classList.remove('loading');
    }
    lbImg.src = nextUrl;
    // 照片資訊改成「時間後面的 i 小圖示」，不佔一顆獨立按鈕（id 不變，沿用原本的展開邏輯）
    const who = esc(e.name || t('anon_fallback')) + ' ・ ' + fmtTime(e.photo_time || e.created_at) +
      ' <button class="lb-info-i" type="button" id="lbInfoBtn" title="' + esc(t('photo_info_title')) + '" aria-label="' + esc(t('photo_info_title')) + '"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button>';
    const txt = e.comment ? '<div class="lb-txt">' + esc(e.comment) + '</div>' : '';
    const canEdit = canPost() && (isMine(e) || APP.isManager);
    const actions =
      (canEdit ? '<button class="btn small" type="button" id="lbEditBtn"><i class="fa-solid fa-pen"></i> ' + esc(t('edit')) + '</button>' : '') +
      (!EMBED && isMine(e) ? '<button class="btn small danger" type="button" id="lbDelBtn"><i class="fa-solid fa-trash"></i> ' + esc(t('delete')) + '</button>' : '');
    const cap = document.getElementById('lbCap');
    cap.style.display = '';
    cap.innerHTML = '<div class="lb-who">' + who + '</div>' + txt + (actions ? '<div class="lb-actions">' + actions + '</div>' : '') +
      '<div class="lb-info" id="lbInfo" style="display:none"></div>';
    const ib = cap.querySelector('#lbInfoBtn');
    if (ib) ib.onclick = (ev) => {
      ev.stopPropagation();
      const box = cap.querySelector('#lbInfo');
      if (box.style.display === 'none') { box.innerHTML = photoInfoHtml(e); box.style.display = ''; feature('info'); }
      else { box.style.display = 'none'; box.innerHTML = ''; }
    };
    const eb = cap.querySelector('#lbEditBtn');
    if (eb) eb.onclick = (ev) => { ev.stopPropagation(); toggleLightboxEditor(e); };
    const db = cap.querySelector('#lbDelBtn');
    if (db) db.onclick = (ev) => { ev.stopPropagation(); closeLightbox(); deleteEntry(e.id); };
    // 換一張照片時，上一張留在 lbEditor 裡未存檔的編輯面板（含迷你地圖）要先清掉，避免殘留
    const oldPanel = document.getElementById('lbEditor');
    if (oldPanel) { const m = oldPanel._mini; if (m) m.remove(); oldPanel._mini = null; oldPanel.style.display = 'none'; oldPanel.innerHTML = ''; }
    document.getElementById('lb').style.display = 'flex';
  }
  function closeLightbox() {
    const panel = document.getElementById('lbEditor');
    if (panel) { const m = panel._mini; if (m) m.remove(); panel._mini = null; panel.style.display = 'none'; panel.innerHTML = ''; }
    document.getElementById('lb').style.display = 'none';
  }

  // 照片定位來源標示：lightbox 資訊面板（核心）與上傳插件的批次卡片共用，故留在核心並開放給插件呼叫
  const SRC_META = {
    exif:    { key: 'loc_src_exif',    tone: 'ok',    icon: 'fa-location-dot' },
    device:  { key: 'loc_src_device',  tone: 'warn',  icon: 'fa-location-crosshairs' },
    chair:   { key: 'loc_src_chair',   tone: 'muted', icon: 'fa-location-dot' },
    manual:  { key: 'loc_src_manual',  tone: 'info',  icon: 'fa-hand-pointer' },
    default: { key: 'loc_src_default', tone: 'muted', icon: 'fa-location-dot' },
  };
  function srcTone(src) { return (SRC_META[src] || SRC_META.default).tone; }
  function locNote(src) {
    const m = SRC_META[src] || SRC_META.default;
    return '<span class="loc-src ' + m.tone + '"><i class="fa-solid ' + m.icon + '"></i> ' + esc(t(m.key)) + '</span>';
  }

  let photoTotal = 0;
  function recount() {
    counts = {}; photoTotal = 0;
    effectivePhotos().forEach(e => {
      photoTotal++;
      if (e.item_num != null) counts[e.item_num] = (counts[e.item_num] || 0) + 1;
    });
    updatePhotoBtn();
  }
  // 供插件在自己完成一次會影響地圖/清單顯示的動作（例如上傳）後，一次重繪所有受影響的畫面
  function refreshAll() { recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); emitHook('stateChange'); renderEntries(); }
  // 「投稿」鈕顯示總照片張數；有投稿的地點數移到 title 提示裡
  function updatePhotoBtn() {
    const btn = document.getElementById('photoLayerBtn');
    if (!btn) return;
    const nPts = Object.keys(counts).length;
    btn.innerHTML = '<i class="fa-solid fa-image"></i> ' + esc(t('contrib')) + (photoTotal ? ' <span class="cnt">' + photoTotal + '</span>' : '');
    btn.title = photoTotal ? t('photo_layer_title_active', { n: photoTotal, a: nPts, b: POINTS.length }) : t('photo_layer_title_inactive');
  }

  /* ---------- data loading ---------- */
  async function loadContributions() {
    try {
      const res = await fetch(apiUrl('list') + '&project=' + encodeURIComponent(PROJECT));
      const j = await res.json();
      if (j.error) throw new Error(j.error + (j.detail ? '：' + j.detail : ''));
      CONTRIB = (j.items || []).map(x => ({ ...x, lat: x.lat != null ? +x.lat : null, lon: x.lon != null ? +x.lon : null }));
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); emitHook('stateChange');
      if (filterPerson) {   // 重新整理後恢復先前記住的投稿者篩選，順便把地圖對焦回他的觀察地圖
        const pts = personPoints(filterPerson).map(e => [e.lat, e.lon]);
        if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.25));
      }
      document.getElementById('cloudWarn').style.display = 'none';
    } catch (e) {
      document.getElementById('cloudWarn').style.display = 'block';
      document.getElementById('cloudWarn').textContent = t('backend_conn_failed', { detail: e.message ? ('（' + e.message + '）') : '' });
    }
  }

  async function boot() {
    // 資料由 view.php 伺服器端內嵌（框架不供應靜態檔）；獨立部署時退回 fetch。
    META = APP.meta || await fetch(APP.base + 'projects/' + PROJECT + '/meta.json').then(r => r.json());
    POINTS = APP.points || await fetch(APP.base + 'projects/' + PROJECT + '/' + (META.points || 'points.json')).then(r => r.json());
    document.title = (META.title || t('map_title_fallback')) + (META.subtitle ? '・' + META.subtitle : '');
    document.getElementById('titleTxt').textContent = META.title + (META.subtitle ? '・' + META.subtitle : '');
    // 資料來源連結（meta.sources = [{label,url}]，可展開看原始 StoryMaps）與原始單位署名（meta.credit）
    var srcLinks = '';
    if (Array.isArray(META.sources) && META.sources.length) {
      srcLinks = '<details class="src-links"><summary>' + esc(t('source_links_summary', { n: META.sources.length })) + '</summary>' +
        META.sources.map(function (s) {
          var safeUrl = /^https?:\/\//i.test(s.url || '') ? s.url : '#';
          return '<a href="' + esc(safeUrl) + '" target="_blank" rel="noopener">' + esc(s.label || s.url) + '</a>';
        }).join('') +
        '</details>';
    }
    document.getElementById('foot').innerHTML =
      (META.source ? '<div class="foot-src">' + esc(t('source_label', { src: META.source })) + '</div>' : '') +
      srcLinks +
      (META.credit ? '<div class="foot-src">' + esc(META.credit) + '</div>' : '') +
      '<div class="foot-note">' + esc(t('contrib_public_notice')) + '<a href="' + esc(APP.base) + 'privacy" target="_blank" rel="noopener">' + esc(t('privacy_link_text')) + '</a></div>';

    const seen = {};
    POINTS.forEach(c => { if (!seen[c.cat]) seen[c.cat] = { key: c.cat, label: c.catLabel, color: c.color }; });
    const order = META.categoryOrder || catOrder;
    CATS = order.filter(k => seen[k]).map(k => seen[k]);
    Object.keys(seen).forEach(k => { if (!CATS.find(c => c.key === k)) CATS.push(seen[k]); });
    // 分享／嵌入可帶 ?cat=key1,key2 只顯示指定主題／分類，其餘分類預設關閉
    const urlCats = (params.get('cat') || '').split(',').map(s => s.trim()).filter(Boolean);
    if (urlCats.length) CATS.forEach(c => { if (!urlCats.includes(c.key)) active[c.key] = false; });

    map = L.map('map', { zoomControl: false }).setView(META.center || [23.9, 120.7], META.zoom || 14);
    setBaseTile();
    L.control.zoom({ position: 'bottomleft' }).addTo(map);
    map.attributionControl.setPosition('bottomright');   // CSS 置中於下方
    map.attributionControl.setPrefix(false);
    photoLayer = L.layerGroup();
    map.on('zoomend', () => { if (photoLayerOn && (map.getZoom() >= THUMB_ZOOM) !== photoLayerThumbState) renderPhotoLayer(); });

    buildLegend();
    renderChairs();
    if (POINTS.length) map.fitBounds(L.latLngBounds(POINTS.map(c => [c.lat, c.lon])).pad(0.08));

    // 暱稱隱藏欄位（單一真實來源；與上傳視窗 #modalName 的同步交給 contribution-upload.js 自己處理）
    const myName = document.getElementById('myName');
    myName.value = localStorage.getItem('myName') || '';

    // 收折
    // 品牌互動：連點變形狀（點→線→三→四→五→六角）→ 六角小彩蛋；長按 → 管理入口
    // delegation 關閉時這張地圖不接受新的專案 PIN／邀請登入，連彩蛋入口都不掛，管理者一律走 /manager 直接登入
    if (MOD('delegation')) setupBrandEgg();

    // 主題切換（系統／淺／深）
    updateThemeIcon();
    document.getElementById('themeBtn').onclick = () => {
      const order = ['system', 'light', 'dark'];
      applyTheme(order[(order.indexOf(themeMode) + 1) % 3]);
      feature('theme');
    };
    if (window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (themeMode === 'system') setBaseTile(); });

    // 語言切換（手動覆寫）：客製化下拉（原生 select 選單樣式無法跟主題搭配），選了哪個就切哪個，
    // 帶著目前網址參數整頁重新載入，讓伺服器端重新解析並寫入 lang cookie
    const langMenu = document.getElementById('langMenu');
    const langBtn = document.getElementById('langBtn');
    const langList = document.getElementById('langList');
    if (langMenu && langBtn && langList) {
      langBtn.onclick = () => {
        const open = langMenu.classList.toggle('open');
        langBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      langList.querySelectorAll('li').forEach(li => {
        li.onclick = () => {
          const url = new URL(location.href);
          url.searchParams.set('lang', li.dataset.lang);
          location.href = url.toString();
        };
      });
      document.addEventListener('click', e => {
        if (langMenu.classList.contains('open') && !langMenu.contains(e.target)) {
          langMenu.classList.remove('open');
          langBtn.setAttribute('aria-expanded', 'false');
        }
      });
    }

    const controls = document.getElementById('controls');
    const chevron = (collapsed) => '<i class="fa-solid fa-chevron-' + (collapsed ? 'right' : 'down') + '"></i>';
    // 沒存過使用者偏好時，手機版（同 CSS 斷點 640px）預設收合，桌機維持展開
    const savedCollapsed = localStorage.getItem('ctlCollapsed');
    const startCollapsed = savedCollapsed !== null ? savedCollapsed === '1' : window.matchMedia('(max-width:640px)').matches;
    if (startCollapsed) { controls.classList.add('collapsed'); document.getElementById('collapseBtn').innerHTML = chevron(true); }
    document.getElementById('collapseBtn').onclick = function () {
      const c = controls.classList.toggle('collapsed');
      this.innerHTML = chevron(c);
      localStorage.setItem('ctlCollapsed', c ? '1' : '0');
    };

    // 定位點微調（僅管理者，顯示與否由 openPanel() 依 APP.isManager 控制）
    const peBtn = document.getElementById('pointEditBtn');
    if (peBtn) peBtn.onclick = togglePointEditor;

    // 全部點位／投稿：互斥的顯示模式，預設「全部」；?contrib=1（嵌入用）或曾記住的投稿者篩選可預設改成「投稿」
    const apb = document.getElementById('allPointsBtn'), plb = document.getElementById('photoLayerBtn');
    function setContribMode(on, opts) {
      photoLayerOn = on;
      plb.classList.toggle('on', on);
      apb.classList.toggle('on', !on);
      document.body.classList.toggle('focus-contrib', on);
      if (!on) filterPerson = '';   // 切回「全部」時，下拉選單改用途（跳到地點），投稿者篩選狀態一併清掉
      rebuildPersonFilter();
      if (on) { renderPhotoLayer(); if (!(opts && opts.silent)) feature('photos'); } else { map.removeLayer(photoLayer); }
      renderChairs(); emitHook('stateChange');
    }
    apb.onclick = function () { if (photoLayerOn) setContribMode(false); };
    plb.onclick = function () { if (!photoLayerOn) setContribMode(true); };
    const personPrefKey = 'filterPerson_' + PROJECT;
    let savedPerson = ''; try { savedPerson = localStorage.getItem(personPrefKey) || ''; } catch (e) {}
    // ?contributor=<name>（分享／嵌入帶入的指定投稿者）優先於本機記住的篩選，但不落地存到 localStorage，
    // 避免別人分享的連結覆蓋掉這台裝置本來記住的篩選對象
    const urlPerson = params.get('contributor') || '';
    if (urlPerson) filterPerson = urlPerson;
    else if (savedPerson) filterPerson = savedPerson;
    setContribMode(params.get('contrib') === '1' || !!filterPerson, { silent: true });

    // 下拉選單依模式切換用途：「投稿」模式＝篩選投稿者（看他的觀察地圖，選擇會記住，重新整理不會跑掉）；
    // 「全部」模式＝跳到指定地點標籤（不是持續篩選，選完會自動重置）
    const pf = document.getElementById('personFilter');
    if (pf) pf.onchange = () => {
      if (photoLayerOn) {
        filterPerson = pf.value;
        try { if (filterPerson) localStorage.setItem(personPrefKey, filterPerson); else localStorage.removeItem(personPrefKey); } catch (e) {}
        if (filterPerson) feature('filter');
        renderPhotoLayer(); renderChairs(); emitHook('stateChange');
        const pts = filterPerson ? personPoints(filterPerson).map(e => [e.lat, e.lon]) : [];
        if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.25));
      } else {
        const num = pf.value ? +pf.value : null;
        pf.value = '';
        if (num != null) {
          const pt = effectivePoints().find(p => p.num === num);
          if (pt) { emitHook('panelReset'); openPanel(pt); map.panTo([pt.lat, pt.lon], { animate: true }); }
        }
      }
    };

    // 右上：手機收成漢堡（避免遮住卡片），點外部或 Esc 收合
    const trGroup = document.getElementById('topright');
    const trToggle = document.getElementById('trToggle');
    if (trToggle && trGroup) {
      trToggle.onclick = () => {
        const open = trGroup.classList.toggle('open');
        trToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      };
      document.addEventListener('click', e => {
        if (trGroup.classList.contains('open') && !trGroup.contains(e.target)) {
          trGroup.classList.remove('open');
          trToggle.setAttribute('aria-expanded', 'false');
        }
      });
    }

    // 右上：重置；左下：重置地圖（身分指示鈕的渲染／互動見 assets/js/plugins/contributor-identity.js）
    const resetBtn = document.getElementById('resetBtn'); if (resetBtn) resetBtn.onclick = resetView;

    // 上傳權限：解鎖 FAB（右下）+ 彈窗（含 QR 掃描）+ 邀請連結 ?code=
    const ub = document.getElementById('unlockFab');
    if (ub) ub.onclick = openUnlock;
    if (document.getElementById('unlockDialog')) {
      document.getElementById('unlockSubmit').onclick = trySubmitUnlock;
      document.getElementById('scanBtn').onclick = startScan;
    }
    const arSubmit = document.getElementById('adminRedeemSubmit');
    if (arSubmit) arSubmit.onclick = trySubmitAdminRedeem;
    const arPin = document.getElementById('adminRedeemPinInput');
    if (arPin) arPin.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitAdminRedeem(); });
    const uci = document.getElementById('unlockCodeInput');
    if (uci) uci.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitUnlock(); });
    const pinBtn = document.getElementById('pinSubmitBtn');
    if (pinBtn) pinBtn.onclick = pinSubmit;
    const pinInputEl = document.getElementById('pinInput');
    if (pinInputEl) pinInputEl.addEventListener('keydown', e => { if (e.key === 'Enter') pinSubmit(); });
    if (params.get('code') && !EMBED && MOD('upload')) {
      const c = params.get('code');
      params.delete('code');
      const qs = params.toString();
      try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
      doUnlock(c).then(r => { if (r.ok) toast('<i class="fa-solid fa-check"></i> ' + esc(t('invite_unlock_success'))); });
    }
    await handleRedeemFragment();
    applyPostState();

    // 關閉浮動卡片：×、地圖背景點擊、Esc
    const pc = document.querySelector('.p-close'); if (pc) pc.onclick = closePanel;
    map.on('click', () => closePanel());
    setupTitleMarquee();

    // 鍵盤快速鍵（無障礙）：方向鍵/＋－由 Leaflet 平移縮放；此處補全域鍵
    document.addEventListener('keydown', e => {
      const tag = (e.target && e.target.tagName) || '';
      if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
      const k = e.key.toLowerCase();
      if (k === 'escape') {
        closePanel(); closeUnlock(); closePin(); closeAdminRedeem(); emitHook('closeAll');
        const trG = document.getElementById('topright'), trT = document.getElementById('trToggle');
        if (trG) { trG.classList.remove('open'); if (trT) trT.setAttribute('aria-expanded', 'false'); }
      }
      else if (k === 'r') { resetView(); }
      else if (k === 't') { const b = document.getElementById('themeBtn'); if (b) b.click(); }
    });

    await computeMyHash();       // 先算出本裝置擁有者雜湊（供「刪自己的」判斷）
    await computeContribId();    // 若已建立投稿者身分，算出對外可見的投稿者ID（供「刪自己的」判斷）
    await loadContributions();
    statVisit();                 // 匿名累加瀏覽 / 工作階段 / 裝置別
    hideSkeleton();
  }
  function hideSkeleton() {
    const sk = document.getElementById('skeleton');
    if (!sk) return;
    sk.classList.add('hide');
    setTimeout(() => { if (sk.parentNode) sk.remove(); }, 500);
  }
  setTimeout(hideSkeleton, 9000);   // 保險：即使載入卡住也移除骨架

  // 目前畫面的篩選狀態（投稿者／分類，再加上插件透過 registerScopeParam() 註冊的額外參數），
  // 供分享連結／嵌入碼帶入範圍限制；沒有特別篩選時回傳空字串
  function currentScopeParams() {
    const sp = new URLSearchParams();
    if (photoLayerOn && filterPerson) sp.set('contributor', filterPerson);
    const visibleCats = CATS.filter(c => active[c.key] !== false).map(c => c.key);
    if (CATS.length && visibleCats.length < CATS.length) sp.set('cat', visibleCats.join(','));
    scopeParamFns.forEach(fn => {
      try {
        const extra = fn();
        if (extra) Object.entries(extra).forEach(([k, v]) => { if (v != null && v !== '') sp.set(k, v); });
      } catch (err) { console.error('[scopeParam]', err); }
    });
    return sp.toString();
  }
  // 重置地圖回初始視角（左下地圖操作）
  function resetView() {
    if (!map) return;
    if (POINTS && POINTS.length) map.fitBounds(L.latLngBounds(effectivePoints().map(c => [c.lat, c.lon])).pad(0.08));
    else map.setView(META.center || [23.9, 120.7], META.zoom || 14);
    feature('reset');
  }
  // 標題單擊 → 若名稱溢出則跑馬燈一次（與形狀彩蛋並存，兩者都綁在同一次點擊）
  function setupTitleMarquee() {
    const el = document.getElementById('title');
    if (!el) return;
    const run = () => {
      if (el.scrollWidth > el.clientWidth + 2) {
        el.classList.remove('marquee'); void el.offsetWidth; el.classList.add('marquee');
        setTimeout(() => el.classList.remove('marquee'), 9000);
      }
    };
    el.addEventListener('click', run);
    el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); run(); } });
  }

  // 圓角形狀（內嵌 SVG，stroke-linejoin:round → 尖角變圓角）；顏色用 currentColor 跟主題
  function polyPoints(sides, R, rot) {
    rot = rot || 0;
    const p = [];
    for (let i = 0; i < sides; i++) { const a = (-90 + rot + i * 360 / sides) * Math.PI / 180; p.push((12 + R * Math.cos(a)).toFixed(1) + ',' + (12 + R * Math.sin(a)).toFixed(1)); }
    return p.join(' ');
  }
  function shapeSVG(kind) {
    // 角度各有變化：方塊微斜、五角略轉、六角平頂；線改厚矩形；stroke-linejoin:round → 圓角
    const open = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">';
    const inner = {
      dot: '<circle cx="12" cy="12" r="5.5" stroke="none"/>',
      line: '<rect x="3.5" y="8.5" width="17" height="7" rx="3.5" stroke="none"/>',
      triangle: '<polygon points="' + polyPoints(3, 8.2, 0) + '"/>',
      square: '<rect x="5.5" y="5.5" width="13" height="13" rx="4.5" transform="rotate(7 12 12)"/>',
      pentagon: '<polygon points="' + polyPoints(5, 8, -8) + '"/>',
      hexagon: '<polygon points="' + polyPoints(6, 8, 30) + '"/>',
    }[kind] || '';
    return open + inner + '</svg>';
  }

  // 連點標題 → 圓角形狀在標題旁依序出現（點→線→三→四→五角→六角）→ 六角跳彩蛋並開 PIN 面板
  function setupBrandEgg() {
    const el = document.getElementById('title');
    const slot = document.getElementById('brandShape');
    if (!el || !slot) return;
    const KINDS = ['dot', 'line', 'triangle', 'square', 'pentagon', 'hexagon'];
    let n = 0, timer = null;
    const reset = () => { n = 0; slot.innerHTML = ''; };
    el.addEventListener('click', () => {
      n = Math.min(n + 1, KINDS.length);
      clearTimeout(timer);
      timer = setTimeout(reset, 1800);
      slot.innerHTML = shapeSVG(KINDS[n - 1]);
      slot.style.animation = 'none'; void slot.offsetWidth; slot.style.animation = '';
      if (n === KINDS.length) { eggPop(); openPinPad(); }
    });
  }
  function eggPop() {
    const e = document.createElement('div');
    e.className = 'toast egg';
    e.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + esc(t('easter_egg_msg'));
    document.body.appendChild(e);
    setTimeout(() => { e.style.opacity = '0'; }, 3000);
    setTimeout(() => e.remove(), 3600);
  }

  // ---- 管理 PIN 面板（數字鍵盤／鍵盤切換輸入，見 pin-input.js） ----
  async function pinSubmit() {
    const input = document.getElementById('pinInput');
    const pin = input ? input.value : '';
    if (!pin) return;
    try {
      const fd = new FormData(); fd.append('action', 'login'); fd.append('json', '1'); fd.append('pin', pin); fd.append('project', PROJECT);
      const res = await fetch(APP.base + '?api=admin', { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        if (j.label) { try { localStorage.setItem('myName', j.label); } catch (e) {} }
        closePin(); location.href = APP.base + encodeURIComponent(PROJECT) + '/manager'; return;
      }
    } catch (e) {}
    if (input) { input.value = ''; input.dispatchEvent(new Event('input')); }
    const msg = document.getElementById('pinMsg'); if (msg) msg.textContent = t('admin_pin_incorrect');
    const box = document.querySelector('.pin-box'); if (box) { box.style.animation = 'none'; void box.offsetWidth; box.style.animation = 'pinshake .3s'; }
  }
  function openPinPad() {
    const input = document.getElementById('pinInput'), msg = document.getElementById('pinMsg');
    if (input) { input.value = ''; input.dispatchEvent(new Event('input')); }
    if (msg) msg.textContent = '';
    document.getElementById('pinDialog').classList.add('open');
    if (input) input.focus();
  }
  function closePin() { const dlg = document.getElementById('pinDialog'); if (dlg) dlg.classList.remove('open'); }

  boot();
  return {
    closePanel, togglePanelSize, openLightbox, closeLightbox, closePin, closeUnlock, closeAdminRedeem,
    // ---- 選用插件掛勾點 API（見 souliong/docs/EXTENDING.md）----
    onHook, registerPhotoFilter, registerEntriesHint, registerScopeParam,
    personTimeline, pointTitle, photoFullUrl, openPanel, openUnlock, refreshEntries: renderEntries,
    refreshPersonFilter: rebuildPersonFilter,
    getMap: () => map, getFilterPerson: () => filterPerson, isPhotoLayerOn: () => photoLayerOn,
    getCurrentPoint: () => current,
    isUnlocked, isEmbedMode: () => EMBED,
    trackFeature: feature, currentScopeParams, getProjectId: () => PROJECT,
    effectivePhotos, personColor, toast,
    displayName, anonName: () => SESSION_ANON, submitContribution,
    rerollAnon, identityChipClick,
    chairOptionsHtml, nearestPoint, locNote, srcTone, addTileLayer, fmtTime,
    getMeta: () => META,
    refreshCounts: recount, refreshAll,
    Plugin: SouliongPlugin,
  };
})();
