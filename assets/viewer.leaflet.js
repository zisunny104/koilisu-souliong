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
    const modalName = document.getElementById('modalName');
    if (modalName) { modalName.value = ''; modalName.setAttribute('placeholder', SESSION_ANON); }
    updateIdentity();
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
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawRoute(); drawPersonRoute();
      if (current) renderEntries();
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
  function canPost() { return !EMBED && isUnlocked(); }
  function applyPostState() {
    document.body.classList.toggle('noupload', !canPost());
    const fab = document.getElementById('unlockFab');
    if (fab) fab.style.display = (APP.gated && !isUnlocked() && !EMBED) ? '' : 'none';
    updateIdentity();
    if (current) renderEntries();   // 讓「編輯說明」鈕跟著出現/消失
  }
  // 右上身分指示：顯示目前暱稱（未輸入則管理者帶入「管理者」、否則顯示本次匿名預覽名）；解鎖狀態附鎖圖示
  function updateIdentity() {
    const el = document.getElementById('identity');
    if (!el) return;
    if (EMBED) { el.style.display = 'none'; return; }
    let name = '';
    try { name = (localStorage.getItem('myName') || '').trim(); } catch (e) {}
    const shown = name || (APP.isManager ? t('identity_manager') : SESSION_ANON);
    const unlocked = isUnlocked();
    el.innerHTML = '<i class="fa-solid ' + (unlocked ? 'fa-user-check' : 'fa-user') + '"></i> ' + esc(shown);
    el.title = t('identity_title_named', { name: shown }) + (name ? '' : (APP.isManager ? t('identity_title_manager_suffix') : t('identity_title_anon_suffix')));
  }
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

  // 分享編輯連結兌換：秘密只透過網址 fragment（#redeem=...）傳遞，不落地在 query string／伺服器紀錄。
  // 讀出後立刻用 history.replaceState 清掉，避免重新整理或分享網址時重複兌換／外流。
  async function handleRedeemFragment() {
    if (EMBED) return;
    const raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
    if (!/(^|&)redeem=/.test(raw)) return;
    const hp = new URLSearchParams(raw);
    const token = hp.get('redeem');
    const rmode = hp.get('rmode');
    hp.delete('redeem'); hp.delete('rmode');
    const rest = hp.toString();
    try { history.replaceState(null, '', location.pathname + location.search + (rest ? '#' + rest : '')); } catch (e) {}
    if (!token) return;
    if (rmode === 'admin') {
      try {
        const fd = new FormData(); fd.append('action', 'login'); fd.append('pin', token); fd.append('project', PROJECT); fd.append('json', '1');
        const res = await fetch(apiUrl('admin'), { method: 'POST', body: fd });
        const j = await res.json().catch(() => ({}));
        if (res.ok && j.ok) { toast('<i class="fa-solid fa-user-shield"></i> ' + esc(t('redeem_admin_ok'))); setTimeout(() => location.reload(), 900); }
        else toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(j.error || t('redeem_admin_link_invalid')));
      } catch (e) { toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(t('connection_failed'))); }
      return;
    }
    const existing = contribInfo();
    if (existing && existing.token && existing.token !== token) {
      const ok = confirm(t('confirm_switch_identity', { label: existing.label ? ('(' + existing.label + ')') : '' }));
      if (!ok) return;
    }
    try {
      const fd = new FormData(); fd.append('project', PROJECT); fd.append('ctoken', token);
      const res = await fetch(apiUrl('redeem'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok && j.contrib) {
        setContribInfo(j.contrib); await computeContribId(); applyPostState();
        toast('<i class="fa-solid fa-check"></i> ' + esc(t('got_identity', { label: j.contrib.label ? (': ' + j.contrib.label) : '' })));
      } else toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(j.error || t('link_expired')));
    } catch (e) { toast('<i class="fa-solid fa-triangle-exclamation"></i> ' + esc(t('connection_failed'))); }
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
  function closeUnlock() { stopScan(); document.getElementById('unlockDialog').classList.remove('open'); }
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
  let map, photoLayer, baseTile = null, routeLines = [], routeOn = false, photoLayerOn = false;
  let filterPerson = '', personLine = null;

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
  let deviceLocCache = null;

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
      (p.color ? ' style="color:' + p.color + '"' : '') + '>' +
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
      m.on('click', () => openPanel(c));
      chairMarkers[c.num] = m;
    });
    drawRoute();
  }
  // 未選特定投稿者時：同時畫出「每位投稿者」各自的時間路徑（多色）；選了人則交給 drawPersonRoute() 畫單人路徑。
  // 效能：只單次掃描 effectivePhotos() 依姓名分組，不對每個人各自重新計算一次。
  function drawRoute() {
    routeLines.forEach(l => map.removeLayer(l)); routeLines = [];
    if (!routeOn || filterPerson) return;
    const byName = {};
    effectivePhotos().forEach(e => {
      if (!e.name || typeof e.lat !== 'number' || typeof e.lon !== 'number') return;
      (byName[e.name] = byName[e.name] || []).push(e);
    });
    Object.keys(byName).forEach(name => {
      const pts = byName[name].sort((a, b) => tv(a) - tv(b)).map(e => [e.lat, e.lon]);
      if (pts.length >= 2) routeLines.push(L.polyline(pts, { color: personColor(name), weight: 2, opacity: .65 }).addTo(map));
    });
  }
  // 彩蛋：快速連點「路徑」鈕數下 → 依所有投稿的時間順序，重新走一次整條路徑
  const ROUTE_EGG_COUNT = 6, ROUTE_EGG_WINDOW = 1200;
  let routeEggN = 0, routeEggTimer = null, routeEggRun = 0;
  function routeEggClick() {
    routeEggN++;
    clearTimeout(routeEggTimer);
    routeEggTimer = setTimeout(() => { routeEggN = 0; }, ROUTE_EGG_WINDOW);
    if (routeEggN >= ROUTE_EGG_COUNT) {
      routeEggN = 0; clearTimeout(routeEggTimer);
      playRouteTimeline();
    }
  }
  async function playRouteTimeline() {
    const pts = effectivePhotos().filter(e => typeof e.lat === 'number' && typeof e.lon === 'number').sort((a, b) => tv(a) - tv(b));
    if (pts.length < 2) { toast(esc(t('route_egg_not_enough'))); return; }
    const myRun = ++routeEggRun;   // 若又被連點觸發一次，讓前一場動畫提早結束，不互相干擾
    toast(esc(t('route_egg_msg')));
    const trail = L.polyline([], { color: '#ff6b35', weight: 3, opacity: .85, dashArray: '4 6' }).addTo(map);
    const marker = L.circleMarker([pts[0].lat, pts[0].lon], { radius: 8, color: '#fff', weight: 2, fillColor: '#ff6b35', fillOpacity: 1 }).addTo(map);
    const path = [];
    for (let i = 0; i < pts.length && myRun === routeEggRun; i++) {
      const p = pts[i];
      path.push([p.lat, p.lon]);
      trail.setLatLngs(path);
      marker.setLatLng([p.lat, p.lon]);
      map.panTo([p.lat, p.lon], { animate: true, duration: .5 });
      await new Promise(r => setTimeout(r, 550));
    }
    if (myRun === routeEggRun) setTimeout(() => { map.removeLayer(trail); map.removeLayer(marker); }, 1200);
  }
  const THUMB_ZOOM = 15;   // ≥ 此縮放顯示縮圖，較遠只顯示小方塊
  function photoIcon(url, thumb) {
    if (thumb) return L.divIcon({
      className: '', iconSize: [30, 30], iconAnchor: [15, 15], popupAnchor: [0, -16],
      html: '<div class="photo-sq" style="background-image:url(' + url + ')"></div>'
    });
    return L.divIcon({
      className: '', iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -8],
      html: '<div class="photo-sq plain"></div>'
    });
  }
  function renderPhotoLayer() {
    photoLayer.clearLayers();
    if (!photoLayerOn) return;
    const thumb = map.getZoom() >= THUMB_ZOOM;
    effectivePhotos().forEach(e => {
      if (filterPerson && e.name !== filterPerson) return;
      const url = photoFullUrl(e);
      if (typeof e.lat !== 'number' || typeof e.lon !== 'number' || !url) return;
      const m = L.marker([e.lat, e.lon], { icon: photoIcon(url, thumb) });
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
  // 隨機配色（不用姓名雜湊固定推算）：同一人第一次出現時抽色，之後這個分頁內都沿用同一色，
  // 讓路徑、篩選時的角標...等所有地方顯示的顏色能保持一致。
  const personColorCache = {};
  function personColor(name) {
    if (!personColorCache[name]) personColorCache[name] = 'hsl(' + Math.floor(Math.random() * 360) + ', 70%, 45%)';
    return personColorCache[name];
  }
  function drawPersonRoute() {
    if (personLine) { map.removeLayer(personLine); personLine = null; }
    if (!filterPerson || !routeOn) return;   // 要「選投稿者」且「路徑」開關也開著，才畫路線
    const pts = personPoints(filterPerson).map(e => [e.lat, e.lon]);
    if (pts.length >= 2) personLine = L.polyline(pts, { color: personColor(filterPerson), weight: 3, opacity: .85 }).addTo(map);
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
  function closePanel() { document.getElementById('panel').classList.remove('open'); resetPointEditor(); current = null; }
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
    const box = document.getElementById('entries'); box.innerHTML = '';
    const descs = CONTRIB.filter(e => e.item_num === current.num && e.kind === 'desc' && e.comment).sort((a, b) => tv(a) - tv(b));
    const photos = effectivePhotos().filter(e => e.item_num === current.num).sort((a, b) => tv(a) - tv(b));
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
      '<div class="story-actions">' +
      (!canPost() ? '' : '<button class="btn small" id="editDescBtn"><i class="fa-solid fa-pen"></i> ' + esc(t('edit_story_btn')) + '</button>') +
      (!EMBED && versions.length > 1 ? '<button class="btn small" id="histBtn">' + esc(t('history_versions', { n: versions.length })) + '</button>' : '') +
      '</div><div id="descEditor" style="display:none"></div><div id="descHistory" style="display:none"></div>';
    box.appendChild(story);
    const edb = story.querySelector('#editDescBtn'); if (edb) edb.onclick = () => toggleDescEditor(current.num);
    const hb = story.querySelector('#histBtn'); if (hb) hb.onclick = () => toggleHistory(versions);

    // 上傳照片到這個點：放在故事底下、第一張照片之前
    const upBtn = document.createElement('button');
    upBtn.className = 'btn primary upload-only'; upBtn.style.width = '100%';
    upBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + esc(t('upload_to_point'));
    upBtn.onclick = () => { resetQueue(); openModal(current); };
    box.appendChild(upBtn);

    // 照片牆
    const gwrap = document.createElement('div');
    if (!photos.length) gwrap.innerHTML = '<div class="empty" style="margin-top:12px">' + esc(t('photos_empty')) + '</div>';
    photos.forEach(e => {
      const url = photoFullUrl(e);
      const d = document.createElement('div'); d.className = 'entry'; d.dataset.entryId = e.id;
      const alt = esc(e.comment || (current.chair || current.theme || t('contrib_photo_alt')));
      const canEdit = canPost() && (isMine(e) || APP.isManager);
      d.innerHTML = '<img src="' + url + '" alt="' + alt + '" tabindex="0">' + '<div class="meta"><div class="who">' + esc(e.name || t('anon_fallback')) +
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
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawRoute(); drawPersonRoute();
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

  function toggleDescEditor(num) {
    const el = document.getElementById('descEditor');
    if (el.style.display === 'none') {
      el.style.display = 'block';
      el.innerHTML = '<input type="text" id="descName" class="name-in" placeholder="' + SESSION_ANON + '" style="width:100%;margin-top:8px">' +
        '<textarea id="descText" placeholder="' + esc(t('story_textarea_placeholder')) + '"></textarea>' +
        '<button class="btn primary small" id="descSave">' + esc(t('submit_new_version')) + '</button>';
      document.getElementById('descName').value = document.getElementById('myName').value || '';
      document.getElementById('descSave').onclick = () => submitDesc(num);
      document.getElementById('descText').focus();
    } else { el.style.display = 'none'; el.innerHTML = ''; }
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
  async function submitDesc(num) {
    const text = (document.getElementById('descText').value || '').trim();
    if (!text) { alert(t('enter_story_content')); return; }
    const nick = (document.getElementById('descName').value || '').trim();
    if (nick) { document.getElementById('myName').value = nick; localStorage.setItem('myName', nick); document.getElementById('modalName').value = nick; }
    const btn = document.getElementById('descSave'); btn.disabled = true; btn.textContent = t('submitting');
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('kind', 'desc'); fd.append('item_num', num);
      fd.append('name', nick || displayName());
      fd.append('comment', text);
      fd.append('photo_time', new Date().toISOString());
      fd.append('owner', ownerToken());
      fd.append('code', storedCode());
      const ct1 = contribToken(); if (ct1) fd.append('ctoken', ct1);
      const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      feature('story');
      rebuildPersonFilter();
      renderEntries();
    } catch (err) { alert(t('save_failed', { err: err.message || err })); btn.disabled = false; btn.textContent = t('submit_new_version'); }
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
    document.getElementById('lbImg').src = url || photoFullUrl(e);
    const who = esc(e.name || t('anon_fallback')) + ' ・ ' + fmtTime(e.photo_time || e.created_at);
    const txt = e.comment ? '<div class="lb-txt">' + esc(e.comment) + '</div>' : '';
    const canEdit = canPost() && (isMine(e) || APP.isManager);
    const actions = '<button class="btn small" type="button" id="lbInfoBtn" title="' + esc(t('photo_info_title')) + '"><i class="fa-solid fa-circle-info"></i> ' + esc(t('info_btn_label')) + '</button>' +
      (canEdit ? '<button class="btn small" type="button" id="lbEditBtn"><i class="fa-solid fa-pen"></i> ' + esc(t('edit')) + '</button>' : '') +
      (!EMBED && isMine(e) ? '<button class="btn small danger" type="button" id="lbDelBtn"><i class="fa-solid fa-trash"></i> ' + esc(t('delete')) + '</button>' : '');
    const cap = document.getElementById('lbCap');
    cap.style.display = '';
    cap.innerHTML = '<div class="lb-who">' + who + '</div>' + txt + '<div class="lb-actions">' + actions + '</div>' +
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

  /* ---------- image: EXIF + HEIC→WebP ---------- */
  async function readExif(file) {
    const out = { time: null, lat: null, lon: null, source: null, cam: null };
    try { const g = await exifr.gps(file); if (g && typeof g.latitude === 'number') { out.lat = g.latitude; out.lon = g.longitude; out.source = 'exif'; } } catch (e) {}
    try {
      const m = await exifr.parse(file, ['DateTimeOriginal', 'CreateDate', 'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime', 'ISO', 'FocalLength', 'Software']);
      if (m) {
        const dt = m.DateTimeOriginal || m.CreateDate; if (dt) out.time = new Date(dt).getTime();
        const s = (v, n) => String(v).replace(/\x00/g, '').trim().slice(0, n);
        const cam = {};
        if (m.Make) cam.make = s(m.Make, 40);
        if (m.Model) cam.model = s(m.Model, 60);
        if (m.LensModel) cam.lens = s(m.LensModel, 60);
        if (m.FNumber) cam.f = +(+m.FNumber).toFixed(1);
        if (m.ExposureTime) cam.exp = +(+m.ExposureTime).toFixed(5);
        if (m.ISO) cam.iso = parseInt(m.ISO, 10) || undefined;
        if (m.FocalLength) cam.focal = +(+m.FocalLength).toFixed(1);
        if (m.Software) cam.sw = s(m.Software, 40);
        if (Object.keys(cam).length) out.cam = cam;
      }
    } catch (e) {}
    if (!out.time) out.time = file.lastModified || Date.now();
    return out;
  }
  async function toWebp(file, max = 1600, q = 0.85) {
    let src = file;
    if (/heic|heif/i.test(file.type) || /\.hei[cf]$/i.test(file.name)) {
      const j = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
      src = Array.isArray(j) ? j[0] : j;
    }
    let bmp;
    try { bmp = await createImageBitmap(src, { imageOrientation: 'from-image' }); }
    catch (e) { bmp = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(src); }); }
    let w = bmp.width, h = bmp.height;
    if (Math.max(w, h) > max) { const s = max / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
    const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
    cv.getContext('2d').drawImage(bmp, 0, 0, w, h);
    return await new Promise(r => cv.toBlob(r, 'image/webp', q));
  }
  function getDeviceLoc() {
    if (deviceLocCache !== null) return Promise.resolve(deviceLocCache);
    return new Promise(res => {
      if (!navigator.geolocation) { deviceLocCache = false; return res(false); }
      navigator.geolocation.getCurrentPosition(
        p => { deviceLocCache = { lat: p.coords.latitude, lon: p.coords.longitude }; res(deviceLocCache); },
        () => { deviceLocCache = false; res(false); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
    });
  }

  /* ---------- batch upload ---------- */
  let queueSeq = 0;
  function openModal(contextPoint) {
    document.getElementById('modalName').value = document.getElementById('myName').value || localStorage.getItem('myName') || '';
    document.getElementById('modal').classList.add('open');
    modalContext = contextPoint || null;
  }
  let batchRunning = false;
  function closeModal() {
    document.getElementById('modal').classList.remove('open');
    // 批次上傳仍在背景進行時不可清空佇列，否則尚未送出的照片會直接遺失；重開視窗會看到原本的佇列與進度
    if (!batchRunning) resetQueue();
  }
  let modalContext = null;

  async function addFiles(files) {
    if (!files || !files.length) return;
    // 只收圖片檔（改用「檔案」選擇器後可能混入其他類型）
    const imgs = (files || []).filter(f => /^image\//i.test(f.type) || /\.(jpe?g|png|webp|heic|heif|gif|bmp|tiff?)$/i.test(f.name));
    if (!imgs.length) { alert(t('select_images_alert')); return; }
    const qe = document.querySelector('.queue-empty'); if (qe) qe.remove();
    for (const file of imgs) {
     try {
      const id = 'q' + (++queueSeq);
      const card = document.createElement('div'); card.className = 'card'; card.id = id;
      card.innerHTML =
        '<button class="btn small c-cancel" type="button" title="' + esc(t('cancel_remove_from_queue')) + '"><i class="fa-solid fa-xmark"></i></button>' +
        '<div class="thumb">' + esc(t('processing')) + '</div>' +
        '<div class="fields">' +
        '<div class="time">' + esc(t('loading')) + '</div>' +
        '<input type="text" class="c-name" placeholder="' + SESSION_ANON + '">' +
        '<textarea class="c-cmt" placeholder="' + esc(t('write_something_placeholder')) + '"></textarea>' +
        '<label class="c-lab">' + esc(t('related_point_label_multi')) + '</label>' +
        '<div class="row"><select class="c-chair"></select><button class="btn small c-nearest" type="button">' + esc(t('nearest_btn')) + '</button></div>' +
        '<div class="mini"></div>' +
        '<div class="loc"></div>' +
        '<div class="row"><button class="btn small c-reset-loc" type="button">' + esc(t('reset_location_btn')) + '</button></div>' +
        '<div class="row"><button class="btn primary c-send">' + esc(t('submit_this_one')) + '</button><span class="status"></span></div>' +
        '</div>';
      document.getElementById('queue').appendChild(card);
      card.querySelector('.c-name').value = document.getElementById('modalName').value || '';
      const state = { id, file, exif: null, webp: null, loc: null, origLoc: null, source: null, done: false, mini: null, marker: null };
      cards[id] = state;
      card.querySelector('.c-cancel').onclick = () => cancelCard(id);

      // 座標優先抓「照片 EXIF GPS」；沒有才用裝置定位（僅輔助，且到這時才詢問權限）
      state.exif = await readExif(file);
      if (state.exif.source === 'exif') { state.loc = { lat: state.exif.lat, lon: state.exif.lon }; state.source = 'exif'; }
      else {
        const dev = await getDeviceLoc();
        if (dev) { state.loc = { lat: dev.lat, lon: dev.lon }; state.source = 'device'; }
        else if (modalContext) { state.loc = { lat: modalContext.lat, lon: modalContext.lon }; state.source = 'chair'; }
        else { state.loc = { lat: META.center[0], lon: META.center[1] }; state.source = 'default'; }
      }
      state.origLoc = { lat: state.loc.lat, lon: state.loc.lon, source: state.source };
      card.querySelector('.time').innerHTML = '<i class="fa-solid fa-clock"></i> ' + fmtTime(state.exif.time);

      // 關聯地點選單（預設：開啟來源地點，否則最近的一個；可選不指定；每張卡片獨立）
      const defChair = modalContext ? modalContext.num : (nearestPoint(state.loc.lat, state.loc.lon) || {}).num;
      const sel = card.querySelector('.c-chair');
      sel.innerHTML = chairOptionsHtml(defChair);
      card.querySelector('.c-nearest').onclick = () => { const np = nearestPoint(state.loc.lat, state.loc.lon); if (np) sel.value = String(np.num); };

      // 縮圖/轉檔（WebP）
      try {
        state.webp = await toWebp(file);
        card.querySelector('.thumb').style.backgroundImage = 'url(' + URL.createObjectURL(state.webp) + ')';
        card.querySelector('.thumb').textContent = '';
      } catch (e) {
        card.querySelector('.thumb').textContent = t('image_read_failed');
      }

      // 迷你地圖（可拖曳；只調整照片自己的座標，不會改動地點座標）
      const miniDiv = card.querySelector('.mini');
      const mini = L.map(miniDiv, { attributionControl: false, zoomControl: false, dragging: true })
        .setView([state.loc.lat, state.loc.lon], 16);
      L.tileLayer(tileUrl(), TILE_OPTS).addTo(mini);
      const mk = L.marker([state.loc.lat, state.loc.lon], { draggable: true }).addTo(mini);
      state.mini = mini; state.marker = mk;
      const updLoc = (ll) => {
        state.loc = { lat: ll.lat, lon: ll.lng };
        // 用外框顏色標示定位來源（不覆蓋 Leaflet 自身 class）
        miniDiv.classList.remove('src-ok', 'src-warn', 'src-info', 'src-muted');
        miniDiv.classList.add('src-' + srcTone(state.source));
        card.querySelector('.loc').innerHTML = locNote(state.source) + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
      };
      mk.on('dragend', e => { state.source = 'manual'; updLoc(e.target.getLatLng()); });
      mini.on('click', e => { mk.setLatLng(e.latlng); state.source = 'manual'; updLoc(e.latlng); });
      updLoc({ lat: state.loc.lat, lng: state.loc.lon });
      card.querySelector('.c-reset-loc').onclick = () => {
        const o = state.origLoc; if (!o) return;
        mk.setLatLng([o.lat, o.lon]); mini.panTo([o.lat, o.lon]);
        state.source = o.source; updLoc({ lat: o.lat, lng: o.lon });
      };
      // 校正尺寸（手機容器尺寸較晚定案）：rAF + ResizeObserver + 逾時保險
      const fix = () => { try { mini.invalidateSize(false); } catch (e) {} };
      requestAnimationFrame(fix);
      if (window.ResizeObserver) { const ro = new ResizeObserver(fix); ro.observe(miniDiv); state.ro = ro; }
      [150, 500, 1200].forEach(ms => setTimeout(fix, ms));

      card.querySelector('.c-send').onclick = () => submitCard(state, card);
     } catch (err) { console.warn('這張照片處理失敗，略過：', err); }
    }
  }
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
  const cards = {};
  function queueEmptyHtml() {
    return '<div class="queue-empty"><button class="btn primary" id="pickBtn"><i class="fa-solid fa-image"></i> ' + esc(t('pick_photos_btn')) + '</button>' +
      '<div class="hint">' + esc(t('pick_photos_hint')) + '</div></div>';
  }
  function wirePickBtn() {
    const pb = document.getElementById('pickBtn');
    if (pb) pb.onclick = () => { const p = document.getElementById('pickImages'); p.value = ''; p.click(); };
  }
  function resetQueue() {
    Object.values(cards).forEach(st => { try { if (st.ro) st.ro.disconnect(); } catch (e) {} try { if (st.mini) st.mini.remove(); } catch (e) {} });
    Object.keys(cards).forEach(k => delete cards[k]);
    document.getElementById('queue').innerHTML = queueEmptyHtml();
    wirePickBtn();
  }
  function cancelCard(id) {
    const st = cards[id];
    if (!st || st.done) return;   // 已送出的不可取消（不可更改），只能取消尚未送出的
    try { if (st.ro) st.ro.disconnect(); } catch (e) {}
    try { if (st.mini) st.mini.remove(); } catch (e) {}
    delete cards[id];
    const card = document.getElementById(id);
    if (card) card.remove();
    if (!Object.keys(cards).length) { document.getElementById('queue').innerHTML = queueEmptyHtml(); wirePickBtn(); }
  }

  // 遇到伺服器限流（429）時倒數等待再自動重試，而不是直接判定失敗、丟掉這張
  function waitCountdown(statusEl, seconds, attempt, maxAttempt) {
    return new Promise(resolve => {
      let left = seconds;
      const tick = () => { statusEl.textContent = t('rate_limited_retry', { left: left, attempt: attempt, maxAttempt: maxAttempt }); statusEl.className = 'status warn'; };
      tick();
      const iv = setInterval(() => { left--; if (left <= 0) { clearInterval(iv); resolve(); } else tick(); }, 1000);
    });
  }
  const MAX_RATE_RETRY = 6;
  // opts.bulk：批次送出時，成功後只更新輕量的投稿計數，地圖/清單重繪留給 submitAll 結束後一次做，避免逐張重繪卡頓
  async function submitCard(state, card, opts) {
    opts = opts || {};
    if (state.done) return true;
    const statusEl = card.querySelector('.status');
    const btn = card.querySelector('.c-send');
    const cancelBtn = card.querySelector('.c-cancel');
    if (!state.webp && !card.querySelector('.c-cmt').value.trim()) { statusEl.textContent = t('need_photo_or_comment'); statusEl.className = 'status err'; return false; }
    btn.disabled = true; if (cancelBtn) cancelBtn.disabled = true;
    for (let attempt = 0; attempt <= MAX_RATE_RETRY; attempt++) {
      if (attempt === 0) { statusEl.textContent = t('uploading'); statusEl.className = 'status'; }
      try {
        const chairNum = parseInt(card.querySelector('.c-chair').value, 10);
        const fd = new FormData();
        fd.append('project', PROJECT);
        if (!isNaN(chairNum)) fd.append('item_num', chairNum);
        fd.append('name', card.querySelector('.c-name').value.trim() || displayName());
        fd.append('comment', card.querySelector('.c-cmt').value.trim());
        fd.append('photo_time', new Date(state.exif.time).toISOString());
        fd.append('lat', state.loc.lat);
        fd.append('lon', state.loc.lon);
        fd.append('loc_source', state.source);
        fd.append('owner', ownerToken());
        fd.append('code', storedCode());
        const ct2 = contribToken(); if (ct2) fd.append('ctoken', ct2);
        if (state.exif && state.exif.cam) fd.append('exif', JSON.stringify(state.exif.cam));
        if (state.webp) fd.append('photo', state.webp, 'photo.webp');
        const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
        if (res.status === 429 && attempt < MAX_RATE_RETRY) {
          const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
          await waitCountdown(statusEl, wait, attempt + 1, MAX_RATE_RETRY);
          continue;   // 排進去繼續等，不視為失敗、不丟棄這張
        }
        const j = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
        if (!res.ok || j.error) throw new Error((j.error || ('HTTP ' + res.status)) + (j.detail ? '：' + j.detail : ''));
        // 成功：鎖定卡片
        state.done = true;
        CONTRIB.push(j.item);
        feature('upload');
        if (opts.bulk) { recount(); } else { recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute(); if (current) renderEntries(); }
        card.classList.add('done');
        card.querySelectorAll('input,textarea,button').forEach(el => el.disabled = true);
        if (state.marker) state.marker.dragging.disable();
        statusEl.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('submitted_locked')); statusEl.className = 'status ok';
        return true;
      } catch (err) {
        statusEl.textContent = t('save_failed', { err: err.message || err }); statusEl.className = 'status err';
        btn.disabled = false; if (cancelBtn) cancelBtn.disabled = false;
        return false;
      }
    }
    // 重試次數用盡仍被限流：留在佇列裡，讓使用者可按「送出這張」手動再試，不會憑空消失
    statusEl.textContent = t('failed_rate_limited_retry_manually'); statusEl.className = 'status err';
    btn.disabled = false; if (cancelBtn) cancelBtn.disabled = false;
    return false;
  }
  async function submitAll() {
    const ids = Object.keys(cards).filter(id => !cards[id].done);
    if (!ids.length) return;
    batchRunning = true;
    const submitBtn = document.getElementById('submitAllBtn');
    const prog = document.getElementById('batchProgress');
    const total = ids.length;
    let ok = 0, fail = 0;
    if (submitBtn) submitBtn.disabled = true;
    const showProg = () => { if (prog) { prog.removeAttribute('data-done'); prog.textContent = t('upload_progress', { done: ok + fail, total: total, failSuffix: fail ? t('upload_fail_suffix', { fail: fail }) : '' }); } };
    showProg();
    for (const id of ids) {
      const st = cards[id]; const card = document.getElementById(id);
      if (!st || st.done || !card) continue;
      const success = await submitCard(st, card, { bulk: true });
      if (success) ok++; else fail++;
      showProg();
    }
    // 批次跑完後才一次重繪地圖／清單，避免每送出一張就整層重繪造成卡頓
    renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute();
    if (current) renderEntries();
    batchRunning = false;
    if (submitBtn) submitBtn.disabled = false;
    if (prog) {
      if (!fail) { prog.dataset.done = '1'; prog.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('upload_all_done', { ok: ok })); setTimeout(() => { if (prog.dataset.done === '1') { prog.textContent = ''; delete prog.dataset.done; } }, 5000); }
      else prog.textContent = t('upload_partial_done', { ok: ok, total: total, fail: fail });
    }
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
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute();
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
        META.sources.map(function (s) { return '<a href="' + esc(s.url) + '" target="_blank" rel="noopener">' + esc(s.label || s.url) + '</a>'; }).join('') +
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
    map.on('zoomend', () => { if (photoLayerOn) renderPhotoLayer(); });

    buildLegend();
    renderChairs();
    if (POINTS.length) map.fitBounds(L.latLngBounds(POINTS.map(c => [c.lat, c.lon])).pad(0.08));

    // 暱稱（隱藏欄位為單一真實來源，與上傳視窗同步）
    const myName = document.getElementById('myName');
    myName.value = localStorage.getItem('myName') || '';
    const modalName = document.getElementById('modalName');
    modalName.value = myName.value;
    modalName.setAttribute('placeholder', SESSION_ANON);   // 預覽本次匿名名
    modalName.oninput = e => { myName.value = e.target.value; localStorage.setItem('myName', e.target.value); };

    // 收折
    // 品牌互動：連點變形狀（點→線→三→四→五→六角）→ 六角小彩蛋；長按 → 管理入口
    setupBrandEgg();

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
    if (localStorage.getItem('ctlCollapsed') === '1') { controls.classList.add('collapsed'); document.getElementById('collapseBtn').innerHTML = chevron(true); }
    document.getElementById('collapseBtn').onclick = function () {
      const c = controls.classList.toggle('collapsed');
      this.innerHTML = chevron(c);
      localStorage.setItem('ctlCollapsed', c ? '1' : '0');
    };

    // 定位點微調（僅管理者，顯示與否由 openPanel() 依 APP.isManager 控制）
    const peBtn = document.getElementById('pointEditBtn');
    if (peBtn) peBtn.onclick = togglePointEditor;

    // 圖層/路徑切換
    document.getElementById('routeBtn').onclick = function () {
      routeOn = !routeOn; this.classList.toggle('on', routeOn); drawRoute(); drawPersonRoute(); if (routeOn) feature('route');
      routeEggClick();
    };

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
      renderChairs(); drawPersonRoute();
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
        renderPhotoLayer(); renderChairs(); drawPersonRoute();
        const pts = filterPerson ? personPoints(filterPerson).map(e => [e.lat, e.lon]) : [];
        if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.25));
      } else {
        const num = pf.value ? +pf.value : null;
        pf.value = '';
        if (num != null) {
          const pt = effectivePoints().find(p => p.num === num);
          if (pt) { openPanel(pt); map.panTo([pt.lat, pt.lon], { animate: true }); }
        }
      }
    };

    // 嵌入碼
    const embedBtn = document.getElementById('embedBtn');
    if (embedBtn) embedBtn.onclick = openEmbed;
    document.getElementById('copyEmbedBtn').onclick = async () => {
      const ta = document.getElementById('embedCode');
      try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
      document.getElementById('copyMsg').innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('copied'));
      setTimeout(() => document.getElementById('copyMsg').textContent = '', 2000);
    };
    document.getElementById('embedWidth').oninput = buildEmbedCode;
    document.getElementById('embedHeight').oninput = buildEmbedCode;
    document.getElementById('embedSizePreset').onchange = buildEmbedCode;

    // 上傳（精簡模式停用）
    if (!EMBED) {
      const pick = document.getElementById('pickImages');
      document.getElementById('uploadBtn').onclick = () => { modalContext = null; resetQueue(); openModal(null); };
      document.getElementById('addMoreBtn').onclick = () => { pick.value = ''; pick.click(); };
      pick.onchange = e => { addFiles(Array.from(e.target.files)); };
      document.getElementById('submitAllBtn').onclick = submitAll;
    }

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

    // 右上：分享、重置、身分；左下：重置地圖
    const shareBtn = document.getElementById('shareBtn'); if (shareBtn) shareBtn.onclick = openShare;
    document.getElementById('shareCopyBtn').onclick = copyShareLink;
    const resetBtn = document.getElementById('resetBtn'); if (resetBtn) resetBtn.onclick = resetView;
    const idEl = document.getElementById('identity');
    const idAction = () => { if (canPost()) { resetQueue(); openModal(null); setTimeout(() => { const n = document.getElementById('modalName'); if (n) n.focus(); }, 60); } else if (APP.gated) { openUnlock(); } };
    if (idEl) {
      let idLpTimer = null, idLpFired = false;
      idEl.addEventListener('pointerdown', () => { idLpFired = false; idLpTimer = setTimeout(() => { idLpFired = true; rerollAnon(); }, 600); });
      const idLpCancel = () => { if (idLpTimer) { clearTimeout(idLpTimer); idLpTimer = null; } };
      idEl.addEventListener('pointerup', idLpCancel);
      idEl.addEventListener('pointerleave', idLpCancel);
      idEl.addEventListener('pointercancel', idLpCancel);
      idEl.onclick = () => { if (idLpFired) { idLpFired = false; return; } idAction(); };
      idEl.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); idAction(); } };
    }

    // 上傳權限：解鎖 FAB（右下）+ 彈窗（含 QR 掃描）+ 邀請連結 ?code=
    const ub = document.getElementById('unlockFab');
    if (ub) ub.onclick = openUnlock;
    document.getElementById('unlockSubmit').onclick = trySubmitUnlock;
    document.getElementById('scanBtn').onclick = startScan;
    const idBtn = document.getElementById('idToggleBtn'), idFields = document.getElementById('idFields');
    if (idBtn && idFields) idBtn.onclick = () => {
      const open = idFields.style.display === 'none';
      idFields.style.display = open ? '' : 'none';
      idBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    const uci = document.getElementById('unlockCodeInput');
    if (uci) uci.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitUnlock(); });
    if (params.get('code') && !EMBED) {
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
        closePanel(); closeModal(); closeEmbed(); closeUnlock(); closeShare(); closePin();
        const trG = document.getElementById('topright'), trT = document.getElementById('trToggle');
        if (trG) { trG.classList.remove('open'); if (trT) trT.setAttribute('aria-expanded', 'false'); }
      }
      else if (k === 'r') { resetView(); }
      else if (k === 's' && !EMBED) { openShare(); }
      else if (k === 't') { const b = document.getElementById('themeBtn'); if (b) b.click(); }
      else if (k === 'u' && !EMBED) { if (canPost()) { resetQueue(); openModal(current); } else if (APP.gated) openUnlock(); }
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

  function buildEmbedCode() {
    // 直接沿用網站目前的顯示狀態（投稿者／分類／是否只顯示投稿），跟分享連結同一套邏輯，不用另外挑選
    const sp = new URLSearchParams(currentScopeParams());
    sp.set('embed', '1');
    if (photoLayerOn) sp.set('contrib', '1');
    const url = location.origin + APP.base + PROJECT + '?' + sp.toString();
    const preset = document.getElementById('embedSizePreset').value;
    const w = document.getElementById('embedWidth').value || 800;
    const h = document.getElementById('embedHeight').value || 600;
    const wFill = preset === 'fill_w' || preset === 'fill_wh';
    const hFill = preset === 'fill_h' || preset === 'fill_wh';
    const sizeStyle = 'width:' + (wFill ? '100%' : w + 'px') + ';height:' + (hFill ? '100%' : h + 'px');
    document.getElementById('embedCode').value =
      '<iframe src="' + url + '" title="' + esc(META.title || 'Souliong') + '" ' +
      'style="' + sizeStyle + ';border:0;border-radius:12px" loading="lazy"></iframe>';
  }
  function openEmbed() {
    feature('embed');
    buildEmbedCode();
    document.getElementById('embedDialog').classList.add('open');
  }
  function closeEmbed() { document.getElementById('embedDialog').classList.remove('open'); }

  // 目前畫面的篩選狀態（投稿者／分類），供分享連結／嵌入碼帶入範圍限制；沒有特別篩選時回傳空字串
  function currentScopeParams() {
    const sp = new URLSearchParams();
    if (photoLayerOn && filterPerson) sp.set('contributor', filterPerson);
    const visibleCats = CATS.filter(c => active[c.key] !== false).map(c => c.key);
    if (CATS.length && visibleCats.length < CATS.length) sp.set('cat', visibleCats.join(','));
    return sp.toString();
  }
  // 公開地圖網址（分享用，不含 embed/code；帶目前篩選狀態，讓分享連結只給對方看你指定的投稿者或主題）
  function mapPublicUrl() {
    const qs = currentScopeParams();
    return location.origin + APP.base + PROJECT + (qs ? '?' + qs : '');
  }
  // 重置地圖回初始視角（左下地圖操作）
  function resetView() {
    if (!map) return;
    if (POINTS && POINTS.length) map.fitBounds(L.latLngBounds(effectivePoints().map(c => [c.lat, c.lon])).pad(0.08));
    else map.setView(META.center || [23.9, 120.7], META.zoom || 14);
    feature('reset');
  }
  // 全螢幕分享：地圖背景 + 中央浮空卡片（含 QR）
  let shareReturnFocus = null;
  function openShare() {
    feature('share');
    const url = mapPublicUrl();
    document.getElementById('shareTitle').textContent = META.title || t('app_title');
    document.getElementById('shareSub').textContent = META.subtitle || t('app_tagline');
    document.getElementById('shareUrl').textContent = url;
    const box = document.getElementById('shareQr'); box.innerHTML = '';
    try { const qr = qrcode(0, 'M'); qr.addData(url); qr.make(); box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); }
    catch (e) { box.textContent = t('qr_generate_failed'); }
    shareReturnFocus = document.activeElement;
    const scr = document.getElementById('shareScreen');
    scr.removeAttribute('aria-hidden');   // 先解除 aria-hidden，才能把焦點移進去（避免 aria-hidden 蓋住有焦點的子元素）
    scr.classList.add('open');
    const closeBtn = scr.querySelector('.share-close'); if (closeBtn) closeBtn.focus();
  }
  function closeShare() {
    const scr = document.getElementById('shareScreen');
    // 先把焦點移出這個容器，再設回 aria-hidden，避免「aria-hidden 蓋住仍保有焦點的子元素」的衝突
    const restore = (shareReturnFocus && document.body.contains(shareReturnFocus)) ? shareReturnFocus : document.getElementById('shareBtn');
    if (restore && typeof restore.focus === 'function') restore.focus();
    scr.setAttribute('aria-hidden', 'true');
    scr.classList.remove('open');
    shareReturnFocus = null;
  }
  async function copyShareLink() {
    try { await navigator.clipboard.writeText(mapPublicUrl()); } catch (e) { }
    const m = document.getElementById('shareCopyMsg'); if (m) { m.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('copied')); setTimeout(() => m.textContent = '', 2000); }
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

  // ---- 管理 PIN 面板 ----
  const PIN_KINDS = ['dot', 'triangle', 'square', 'pentagon', 'hexagon', 'line'];
  const PIN_EMPTY = '<svg width="13" height="13" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5" fill="none" stroke="currentColor" stroke-width="2.5"/></svg>';
  const PIN_MAX = 12;          // 容器可顯示上限
  let pinVal = '';
  function renderPin() {
    const disp = document.getElementById('pinDisplay');
    if (!disp) return;
    const slots = Math.min(Math.max(4, pinVal.length), PIN_MAX);
    let html = '';
    for (let i = 0; i < slots; i++) {
      html += (i < pinVal.length)
        ? '<span class="pin-dot filled' + (i === pinVal.length - 1 ? ' pop' : '') + '">' + shapeSVG(PIN_KINDS[i % PIN_KINDS.length]) + '</span>'
        : '<span class="pin-dot">' + PIN_EMPTY + '</span>';
    }
    disp.innerHTML = html;
  }
  function pinAdd(d) { if (pinVal.length < PIN_MAX) { pinVal += d; renderPin(); } }
  function pinDel() { pinVal = pinVal.slice(0, -1); renderPin(); }
  async function pinSubmit() {
    if (!pinVal) return;
    try {
      const fd = new FormData(); fd.append('action', 'login'); fd.append('json', '1'); fd.append('pin', pinVal); fd.append('project', PROJECT);
      const res = await fetch(APP.base + '?api=admin', { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) {
        if (j.label) { try { localStorage.setItem('myName', j.label); } catch (e) {} }
        closePin(); location.href = APP.base + encodeURIComponent(PROJECT) + '/manager'; return;
      }
    } catch (e) {}
    pinVal = ''; renderPin();
    const box = document.querySelector('.pin-box'); if (box) { box.style.animation = 'none'; void box.offsetWidth; box.style.animation = 'pinshake .3s'; }
  }
  function pinKey(e) {
    if (e.key >= '0' && e.key <= '9') { pinAdd(e.key); e.preventDefault(); }
    else if (e.key === 'Backspace') { pinDel(); e.preventDefault(); }
    else if (e.key === 'Enter') { pinSubmit(); }
    else if (e.key === 'Escape') { closePin(); }
  }
  function buildPinPad() {
    const pad = document.getElementById('pinPad');
    if (!pad || pad.dataset.built) return;
    pad.dataset.built = '1';
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'del', '0', 'ok'].forEach(k => {
      const b = document.createElement('button');
      b.className = 'pin-key' + (k === 'ok' ? ' ok' : '') + (k === 'del' ? ' del' : '');
      b.type = 'button';
      b.setAttribute('aria-label', k === 'del' ? t('delete') : k === 'ok' ? t('confirm_ok') : k);
      b.innerHTML = k === 'del' ? '<i class="fa-solid fa-delete-left"></i>' : k === 'ok' ? '<i class="fa-solid fa-check"></i>' : k;
      b.onclick = () => { k === 'del' ? pinDel() : k === 'ok' ? pinSubmit() : pinAdd(k); };
      pad.appendChild(b);
    });
  }
  function openPinPad() { pinVal = ''; buildPinPad(); renderPin(); document.getElementById('pinDialog').classList.add('open'); document.addEventListener('keydown', pinKey); }
  function closePin() { document.getElementById('pinDialog').classList.remove('open'); document.removeEventListener('keydown', pinKey); }

  boot();
  return { closePanel, closeModal, openLightbox, closeLightbox, openEmbed, closeEmbed, closePin, closeUnlock, closeShare };
})();
