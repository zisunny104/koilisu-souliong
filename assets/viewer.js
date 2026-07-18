/* 通用地圖檢視器 —— 由 ?p=<project> 載入 projects/<project>/meta.json 與點位資料。
   後端：api/list.php、api/upload.php（純 PHP，append-only）。 */
const MapApp = (() => {
  const APP = window.APP || { base: './', project: 'chairs' };
  const params = new URLSearchParams(location.search);
  const PROJECT = (APP.project || params.get('p') || 'chairs').replace(/[^a-z0-9_-]/gi, '');
  const EMBED = !!(APP.embed) || params.get('embed') === '1';
  const apiUrl = (action) => APP.base + '?api=' + action;
  const catOrder = ['green', 'pink', 'blue'];

  // 版權標註（依 OSM 慣例含連結；prjToka 一併放在此）——連結可自行修改
  const CREDIT_HTML =
    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> 貢獻者 &middot; ' +
    '<a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a> &middot; ' +
    '<a href="https://toka.dev" target="_blank" rel="noopener">循跡 by prjToka</a>';
  // 主題：system / light / dark（手動可覆蓋系統偏好）
  const TILE_OPTS = { maxZoom: 20, subdomains: 'abcd', detectRetina: true, attribution: CREDIT_HTML };
  const systemDark = () => !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  const isDark = () => { const t = document.documentElement.dataset.theme; return t === 'dark' ? true : t === 'light' ? false : systemDark(); };
  const tileUrl = () => 'https://{s}.basemaps.cartocdn.com/' + (isDark() ? 'dark_all' : 'light_all') + '/{z}/{x}/{y}{r}.png';
  let themeMode = localStorage.getItem('theme') || 'system';
  if (themeMode !== 'system') document.documentElement.dataset.theme = themeMode;

  // 未填暱稱時給一個可愛的隨機匿名名（同一裝置維持一致）
  const ANON_NOUNS = ['松鼠', '月亮', '石虎', '藍鵲', '貓頭鷹', '山羌', '螢火蟲', '白鷺', '穿山甲', '樹蛙', '蒲公英', '晚風', '溪流', '苔蘚', '雲豹', '燕子', '麻雀', '銀杏'];
  // 本次造訪的匿名名（重新整理會換一個）；顯示為暱稱欄 placeholder，未填即用它
  const SESSION_ANON = '匿名' + ANON_NOUNS[Math.floor(Math.random() * ANON_NOUNS.length)];
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
  const isMine = (e) => !!(myOwnerHash && e.owner_hash && e.owner_hash === myOwnerHash);

  async function deleteEntry(id) {
    if (!confirm('確定刪除？此動作無法復原')) return;
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('id', id); fd.append('owner', ownerToken());
      const res = await fetch(apiUrl('delete'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (!res.ok || j.error) { alert('刪除失敗：' + (j.error || ('HTTP ' + res.status))); return; }
      CONTRIB = CONTRIB.filter(e => String(e.id) !== String(id));
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute();
      if (current) renderEntries();
    } catch (e) { alert('刪除失敗：' + e.message); }
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

  // 上傳權限（預設 View；需投稿碼解鎖，限特定人。EMBED 一律不可上傳）
  function storedCode() { try { return localStorage.getItem('uploadCode_' + PROJECT) || ''; } catch (e) { return ''; } }
  function canPost() { return !EMBED && (!APP.gated || !!storedCode()); }
  function applyPostState() {
    document.body.classList.toggle('noupload', !canPost());
    const row = document.getElementById('unlockRow');
    if (row) row.style.display = (APP.gated && !storedCode() && !EMBED) ? '' : 'none';
    if (current) renderEntries();   // 讓「編輯說明」鈕跟著出現/消失
  }
  async function doUnlock(code) {
    code = (code || '').trim();
    if (!code) return { ok: false, msg: '請輸入投稿碼' };
    try {
      const fd = new FormData(); fd.append('project', PROJECT); fd.append('code', code);
      const res = await fetch(apiUrl('unlock'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({}));
      if (res.ok && j.ok) { try { localStorage.setItem('uploadCode_' + PROJECT, code); } catch (e) {} applyPostState(); return { ok: true }; }
      return { ok: false, msg: j.error || '投稿碼不正確' };
    } catch (e) { return { ok: false, msg: '連線失敗，請稍後再試' }; }
  }
  function toast(text) { const t = document.createElement('div'); t.className = 'toast egg'; t.textContent = text; document.body.appendChild(t); setTimeout(() => { t.style.opacity = '0'; }, 2200); setTimeout(() => t.remove(), 2800); }

  // 解鎖彈窗 + QR 掃描
  let scanStream = null, scanRAF = null;
  function openUnlock() {
    document.getElementById('unlockCodeInput').value = '';
    const msg = document.getElementById('unlockMsg'); msg.textContent = ''; msg.style.color = '';
    document.getElementById('scanBox').style.display = 'none';
    document.getElementById('unlockDialog').classList.add('open');
    setTimeout(() => document.getElementById('unlockCodeInput').focus(), 60);
  }
  function closeUnlock() { stopScan(); document.getElementById('unlockDialog').classList.remove('open'); }
  async function trySubmitUnlock() {
    const msg = document.getElementById('unlockMsg');
    msg.style.color = ''; msg.textContent = '驗證中…';
    const r = await doUnlock(document.getElementById('unlockCodeInput').value);
    if (r.ok) { closeUnlock(); toast('✓ 已解鎖，可以上傳了'); }
    else { msg.style.color = '#c0392b'; msg.textContent = r.msg; }
  }
  function extractCode(text) { if (!text) return ''; const m = String(text).match(/[?&]code=([^&\s]+)/i); return (m ? decodeURIComponent(m[1]) : String(text)).trim(); }
  async function startScan() {
    if (typeof jsQR === 'undefined') { document.getElementById('unlockMsg').textContent = '掃描元件尚未載入'; return; }
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
    } catch (e) { document.getElementById('unlockMsg').textContent = '無法開啟相機：' + (e.message || e); box.style.display = 'none'; }
  }
  function stopScan() {
    if (scanRAF) { cancelAnimationFrame(scanRAF); scanRAF = null; }
    if (scanStream) { scanStream.getTracks().forEach(t => t.stop()); scanStream = null; }
    const box = document.getElementById('scanBox'); if (box) box.style.display = 'none';
  }

  let META = null, POINTS = [], CATS = [], active = {}, CONTRIB = [], counts = {};
  let map, photoLayer, baseTile = null, routeLine = null, routeOn = false, photoLayerOn = true;
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
    return POINTS.slice().sort((a, b) => a.num - b.num).map(p =>
      '<option value="' + p.num + '"' + (p.num === selNum ? ' selected' : '') + '>' +
      pad2(p.num) + '｜' + esc(p.theme || p.chair || '') + '（' + esc(p.area || '') + '）</option>').join('');
  }
  function haversine(aLat, aLon, bLat, bLon) {
    const R = 6371000, r = Math.PI / 180;
    const dLat = (bLat - aLat) * r, dLon = (bLon - aLon) * r;
    const s = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * r) * Math.cos(bLat * r) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(s));
  }
  function nearestPoint(lat, lon) {
    let best = null, bd = Infinity;
    for (const p of POINTS) {
      const d = haversine(lat, lon, p.lat, p.lon);
      if (d < bd) { bd = d; best = p; }
    }
    return best;
  }
  function photoFullUrl(item) { return item.photo ? apiUrl('photo') + '&f=' + encodeURIComponent(item.photo) : null; }

  /* ---------- map ---------- */
  function chairIcon(c, count) {
    const badge = count ? '<div class="badge">' + count + '</div>' : '';
    return L.divIcon({
      className: '', iconSize: [24, 24], iconAnchor: [12, 12], popupAnchor: [0, -12],
      html: '<div class="dot-pin" style="background:' + c.color + '"><span>' + c.num + '</span>' + badge + '</div>'
    });
  }
  function renderChairs() {
    POINTS.forEach(c => {
      if (chairMarkers[c.num]) map.removeLayer(chairMarkers[c.num]);
      if (active[c.cat] === false) return;
      const m = L.marker([c.lat, c.lon], { icon: chairIcon(c, counts[c.num] || 0) }).addTo(map);
      m.on('click', () => openPanel(c));
      chairMarkers[c.num] = m;
    });
    drawRoute();
  }
  function drawRoute() {
    if (routeLine) { map.removeLayer(routeLine); routeLine = null; }
    if (!routeOn) return;
    const pts = POINTS.filter(c => active[c.cat] !== false).sort((a, b) => a.num - b.num).map(c => [c.lat, c.lon]);
    routeLine = L.polyline(pts, { color: '#555', weight: 2, opacity: .7, dashArray: '6 6' }).addTo(map);
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
    CONTRIB.forEach(e => {
      if (filterPerson && e.name !== filterPerson) return;
      const url = photoFullUrl(e);
      if (typeof e.lat !== 'number' || typeof e.lon !== 'number' || !url) return;
      const m = L.marker([e.lat, e.lon], { icon: photoIcon(url, thumb) });
      m.on('click', () => openLightbox(url, (e.name || '匿名') + ' ・ ' + fmtTime(e.photo_time || e.created_at)));
      photoLayer.addLayer(m);
    });
    if (!map.hasLayer(photoLayer)) photoLayer.addTo(map);
  }

  // 某人的觀察路線（照片依時間串連）
  function personPoints(name) {
    return CONTRIB.filter(e => e.name === name && e.photo && typeof e.lat === 'number' && typeof e.lon === 'number')
      .sort((a, b) => tv(a) - tv(b));
  }
  function drawPersonRoute() {
    if (personLine) { map.removeLayer(personLine); personLine = null; }
    if (!filterPerson) return;
    const pts = personPoints(filterPerson).map(e => [e.lat, e.lon]);
    if (pts.length >= 2) personLine = L.polyline(pts, { color: '#e34a6f', weight: 3, opacity: .85 }).addTo(map);
  }
  function rebuildPersonFilter() {
    const sel = document.getElementById('personFilter');
    if (!sel) return;
    const cur = sel.value;
    const names = [...new Set(CONTRIB.filter(e => e.name && (e.photo || e.comment)).map(e => e.name))].sort();
    sel.innerHTML = '';
    const all = document.createElement('option'); all.value = ''; all.textContent = '所有投稿者（' + names.length + ' 人）';
    sel.appendChild(all);
    names.forEach(n => { const o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
    sel.value = names.includes(cur) ? cur : '';
  }

  /* ---------- legend ---------- */
  function buildLegend() {
    const legend = document.getElementById('legend'); legend.innerHTML = '';
    CATS.forEach(c => {
      const el = document.createElement('div'); el.className = 'chip';
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
    renderEntries();
    statSend('point', c.num);
  }
  function closePanel() { document.getElementById('panel').classList.remove('open'); current = null; }
  function renderEntries() {
    const box = document.getElementById('entries'); box.innerHTML = '';
    const all = CONTRIB.filter(e => e.item_num === current.num);
    const descs = all.filter(e => e.kind === 'desc' && e.comment).sort((a, b) => tv(a) - tv(b));
    const photos = all.filter(e => e.kind !== 'desc' && e.photo).sort((a, b) => tv(a) - tv(b));
    // 版本序列：原始（資料來源 StoryMaps）在最舊，之後接使用者編輯版本
    const versions = [];
    if (current.story) versions.push({ name: '資料來源', comment: current.story, created_at: null, baseline: true });
    descs.forEach(d => versions.push(d));
    const latest = versions[versions.length - 1];
    const byLine = latest
      ? (latest.baseline ? '資料來源：StoryMaps 原始介紹' : '— ' + esc(latest.name || '匿名') + '・' + fmtTime(latest.photo_time || latest.created_at))
      : '';

    // 故事 / 說明（版本化，預設只顯示最新版）
    const story = document.createElement('div'); story.className = 'story';
    story.innerHTML =
      '<div class="story-head">這個地點的故事</div>' +
      '<div class="story-body">' + (latest ? esc(latest.comment) : '<span class="empty">還沒有故事，來寫下第一段。</span>') + '</div>' +
      (byLine ? '<div class="story-by">' + byLine + '</div>' : '') +
      '<div class="story-actions">' +
      (!canPost() ? '' : '<button class="btn small" id="editDescBtn"><i class="fa-solid fa-pen"></i> 編輯說明</button>') +
      (versions.length > 1 ? '<button class="btn small" id="histBtn">歷史版本 (' + versions.length + ')</button>' : '') +
      '</div><div id="descEditor" style="display:none"></div><div id="descHistory" style="display:none"></div>';
    box.appendChild(story);
    const edb = story.querySelector('#editDescBtn'); if (edb) edb.onclick = () => toggleDescEditor(current.num);
    const hb = story.querySelector('#histBtn'); if (hb) hb.onclick = () => toggleHistory(versions);

    // 照片牆
    const gwrap = document.createElement('div');
    if (!photos.length) gwrap.innerHTML = '<div class="empty" style="margin-top:12px">還沒有照片，上傳你在這裡的一張。</div>';
    photos.forEach(e => {
      const url = photoFullUrl(e);
      const d = document.createElement('div'); d.className = 'entry';
      const cap = (e.name || '匿名') + ' ・ ' + fmtTime(e.photo_time || e.created_at);
      const alt = esc(e.comment || (current.chair || current.theme || '投稿照片'));
      d.innerHTML = '<img src="' + url + '" alt="' + alt + '" tabindex="0">' + '<div class="meta"><div class="who">' + esc(e.name || '匿名') + '</div>' +
        '<div class="time">' + fmtTime(e.photo_time || e.created_at) + '</div>' +
        (e.comment ? '<div class="txt">' + esc(e.comment) + '</div>' : '') +
        (isMine(e) ? '<button class="del-btn" type="button">刪除這則</button>' : '') + '</div>';
      d.querySelector('img').onclick = () => openLightbox(url, cap);
      const del = d.querySelector('.del-btn'); if (del) del.onclick = () => deleteEntry(e.id);
      gwrap.appendChild(d);
    });
    box.appendChild(gwrap);
  }
  const tv = (e) => new Date(e.photo_time || e.created_at).getTime() || 0;

  function toggleDescEditor(num) {
    const el = document.getElementById('descEditor');
    if (el.style.display === 'none') {
      el.style.display = 'block';
      el.innerHTML = '<input type="text" id="descName" class="name-in" placeholder="' + SESSION_ANON + '" style="width:100%;margin-top:8px">' +
        '<textarea id="descText" placeholder="寫下這個地點的故事…（送出後會成為新版本，舊版本仍永久保留）"></textarea>' +
        '<button class="btn primary small" id="descSave">送出新版本</button>';
      document.getElementById('descName').value = document.getElementById('myName').value || '';
      document.getElementById('descSave').onclick = () => submitDesc(num);
      document.getElementById('descText').focus();
    } else { el.style.display = 'none'; el.innerHTML = ''; }
  }
  function toggleHistory(descs) {
    const el = document.getElementById('descHistory');
    if (el.style.display === 'none') {
      el.style.display = 'block';
      el.innerHTML = '<div class="hist-title">說明版本紀錄（新到舊）</div>' +
        descs.slice().reverse().map((d, i) =>
          '<div class="hist-item"><div class="hist-meta">' + (i === 0 ? '<b>最新</b>・' : '') +
          (d.baseline ? '原始（資料來源）' : esc(d.name || '匿名') + '・' + fmtTime(d.photo_time || d.created_at)) +
          (isMine(d) ? ' <button class="del-btn" type="button" data-id="' + esc(d.id) + '">刪除</button>' : '') + '</div>' +
          '<div class="hist-txt">' + esc(d.comment) + '</div></div>').join('');
      el.querySelectorAll('.del-btn[data-id]').forEach(b => b.onclick = () => deleteEntry(b.dataset.id));
    } else { el.style.display = 'none'; el.innerHTML = ''; }
  }
  async function submitDesc(num) {
    const text = (document.getElementById('descText').value || '').trim();
    if (!text) { alert('請輸入說明內容'); return; }
    const nick = (document.getElementById('descName').value || '').trim();
    if (nick) { document.getElementById('myName').value = nick; localStorage.setItem('myName', nick); document.getElementById('modalName').value = nick; }
    const btn = document.getElementById('descSave'); btn.disabled = true; btn.textContent = '送出中…';
    try {
      const fd = new FormData();
      fd.append('project', PROJECT); fd.append('kind', 'desc'); fd.append('item_num', num);
      fd.append('name', nick || displayName());
      fd.append('comment', text);
      fd.append('photo_time', new Date().toISOString());
      fd.append('owner', ownerToken());
      fd.append('code', storedCode());
      const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
      const j = await res.json();
      if (!res.ok || j.error) throw new Error(j.error || ('HTTP ' + res.status));
      CONTRIB.push(j.item);
      feature('story');
      rebuildPersonFilter();
      renderEntries();
    } catch (err) { alert('失敗：' + (err.message || err)); btn.disabled = false; btn.textContent = '送出新版本'; }
  }

  /* ---------- lightbox ---------- */
  function openLightbox(url, cap) {
    document.getElementById('lbImg').src = url;
    document.getElementById('lbCap').textContent = cap || '';
    document.getElementById('lb').style.display = 'flex';
  }

  /* ---------- image: EXIF + HEIC→WebP ---------- */
  async function readExif(file) {
    const out = { time: null, lat: null, lon: null, source: null, cam: null };
    try { const g = await exifr.gps(file); if (g && typeof g.latitude === 'number') { out.lat = g.latitude; out.lon = g.longitude; out.source = 'exif'; } } catch (e) {}
    try {
      const m = await exifr.parse(file, ['DateTimeOriginal', 'CreateDate', 'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime', 'ISO', 'FocalLength', 'Software']);
      if (m) {
        const dt = m.DateTimeOriginal || m.CreateDate; if (dt) out.time = new Date(dt).getTime();
        const s = (v, n) => String(v).replace(/ /g, '').trim().slice(0, n);
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
  function closeModal() { document.getElementById('modal').classList.remove('open'); resetQueue(); }
  let modalContext = null;

  async function addFiles(files) {
    if (!files || !files.length) return;
    // 只收圖片檔（改用「檔案」選擇器後可能混入其他類型）
    const imgs = (files || []).filter(f => /^image\//i.test(f.type) || /\.(jpe?g|png|webp|heic|heif|gif|bmp|tiff?)$/i.test(f.name));
    if (!imgs.length) { alert('請選擇圖片檔（jpg / png / webp / heic…）'); return; }
    const qe = document.querySelector('.queue-empty'); if (qe) qe.remove();
    for (const file of imgs) {
     try {
      const id = 'q' + (++queueSeq);
      const card = document.createElement('div'); card.className = 'card'; card.id = id;
      card.innerHTML =
        '<div class="thumb">處理中…</div>' +
        '<div class="fields">' +
        '<div class="time">讀取中…</div>' +
        '<input type="text" class="c-name" placeholder="' + SESSION_ANON + '">' +
        '<textarea class="c-cmt" placeholder="寫一段話…"></textarea>' +
        '<label class="c-lab">關聯椅子（群組，每張可不同）</label>' +
        '<div class="row"><select class="c-chair"></select><button class="btn small c-nearest" type="button">最近</button></div>' +
        '<div class="mini"></div>' +
        '<div class="loc"></div>' +
        '<div class="row"><button class="btn primary c-send">送出這張</button><span class="status"></span></div>' +
        '</div>';
      document.getElementById('queue').appendChild(card);
      card.querySelector('.c-name').value = document.getElementById('modalName').value || '';
      const state = { id, file, exif: null, webp: null, loc: null, source: null, done: false, mini: null, marker: null };
      cards[id] = state;

      // 座標優先抓「照片 EXIF GPS」；沒有才用裝置定位（僅輔助，且到這時才詢問權限）
      state.exif = await readExif(file);
      if (state.exif.source === 'exif') { state.loc = { lat: state.exif.lat, lon: state.exif.lon }; state.source = 'exif'; }
      else {
        const dev = await getDeviceLoc();
        if (dev) { state.loc = { lat: dev.lat, lon: dev.lon }; state.source = 'device'; }
        else if (modalContext) { state.loc = { lat: modalContext.lat, lon: modalContext.lon }; state.source = 'chair'; }
        else { state.loc = { lat: META.center[0], lon: META.center[1] }; state.source = 'default'; }
      }
      card.querySelector('.time').innerHTML = '<i class="fa-solid fa-clock"></i> ' + fmtTime(state.exif.time);

      // 關聯椅子選單（預設：開啟來源椅子，否則最近的一張；每張卡片獨立）
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
        card.querySelector('.thumb').textContent = '無法讀取圖片';
      }

      // 迷你地圖（可拖曳；只調整照片自己的座標，不會改動椅子座標）
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
        card.querySelector('.loc').innerHTML = locNote(state.source) + ' <span class="loc-hint">可拖曳小地圖修正</span>';
      };
      mk.on('dragend', e => { state.source = 'manual'; updLoc(e.target.getLatLng()); });
      mini.on('click', e => { mk.setLatLng(e.latlng); state.source = 'manual'; updLoc(e.latlng); });
      updLoc({ lat: state.loc.lat, lng: state.loc.lon });
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
    exif:    { label: '照片定位', tone: 'ok',    icon: 'fa-location-dot' },
    device:  { label: '裝置定位', tone: 'warn',  icon: 'fa-location-crosshairs' },
    chair:   { label: '此點位置', tone: 'muted', icon: 'fa-location-dot' },
    manual:  { label: '手動指定', tone: 'info',  icon: 'fa-hand-pointer' },
    default: { label: '預設中心', tone: 'muted', icon: 'fa-location-dot' },
  };
  function srcTone(src) { return (SRC_META[src] || SRC_META.default).tone; }
  function locNote(src) {
    const m = SRC_META[src] || SRC_META.default;
    return '<span class="loc-src ' + m.tone + '"><i class="fa-solid ' + m.icon + '"></i> ' + m.label + '</span>';
  }
  const cards = {};
  function resetQueue() {
    Object.values(cards).forEach(st => { try { if (st.ro) st.ro.disconnect(); } catch (e) {} try { if (st.mini) st.mini.remove(); } catch (e) {} });
    Object.keys(cards).forEach(k => delete cards[k]);
    document.getElementById('queue').innerHTML =
      '<div class="queue-empty"><button class="btn primary" id="pickBtn"><i class="fa-solid fa-image"></i> 選擇照片（可多選）</button>' +
      '<div class="hint">可從相簿、檔案選取，或用相機拍照</div></div>';
    const pb = document.getElementById('pickBtn');
    if (pb) pb.onclick = () => { const p = document.getElementById('pickImages'); p.value = ''; p.click(); };
  }

  async function submitCard(state, card) {
    if (state.done) return;
    const statusEl = card.querySelector('.status');
    const btn = card.querySelector('.c-send');
    if (!state.webp && !card.querySelector('.c-cmt').value.trim()) { statusEl.textContent = '需照片或留言'; statusEl.className = 'status err'; return; }
    btn.disabled = true; statusEl.textContent = '上傳中…'; statusEl.className = 'status';
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
      if (state.exif && state.exif.cam) fd.append('exif', JSON.stringify(state.exif.cam));
      if (state.webp) fd.append('photo', state.webp, 'photo.webp');
      const res = await fetch(apiUrl('upload'), { method: 'POST', body: fd });
      const j = await res.json().catch(() => ({ error: 'HTTP ' + res.status }));
      if (!res.ok || j.error) throw new Error((j.error || ('HTTP ' + res.status)) + (j.detail ? '：' + j.detail : ''));
      // 成功：鎖定卡片
      state.done = true;
      CONTRIB.push(j.item);
      feature('upload');
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute();
      if (current) renderEntries();
      card.classList.add('done');
      card.querySelectorAll('input,textarea,button').forEach(el => el.disabled = true);
      if (state.marker) state.marker.dragging.disable();
      statusEl.textContent = '✓ 已送出，不可更改'; statusEl.className = 'status ok';
    } catch (err) {
      statusEl.textContent = '失敗：' + (err.message || err); statusEl.className = 'status err';
      btn.disabled = false;
    }
  }
  async function submitAll() {
    for (const id of Object.keys(cards)) {
      const st = cards[id]; if (st.done) continue;
      await submitCard(st, document.getElementById(id));
    }
  }

  function recount() {
    counts = {};
    CONTRIB.forEach(e => { if (e.kind !== 'desc' && e.photo && e.item_num != null) counts[e.item_num] = (counts[e.item_num] || 0) + 1; });
  }

  /* ---------- data loading ---------- */
  async function loadContributions() {
    try {
      const res = await fetch(apiUrl('list') + '&project=' + encodeURIComponent(PROJECT));
      const j = await res.json();
      if (j.error) throw new Error(j.error + (j.detail ? '：' + j.detail : ''));
      CONTRIB = (j.items || []).map(x => ({ ...x, lat: x.lat != null ? +x.lat : null, lon: x.lon != null ? +x.lon : null }));
      recount(); renderChairs(); renderPhotoLayer(); rebuildPersonFilter(); drawPersonRoute();
      document.getElementById('cloudWarn').style.display = 'none';
    } catch (e) {
      document.getElementById('cloudWarn').style.display = 'block';
      document.getElementById('cloudWarn').textContent = '後端連線失敗：地圖可瀏覽，上傳/共享暫停用。' + (e.message ? '（' + e.message + '）' : '');
    }
  }

  async function boot() {
    // 資料由 view.php 伺服器端內嵌（框架不供應靜態檔）；獨立部署時退回 fetch。
    META = APP.meta || await fetch(APP.base + 'projects/' + PROJECT + '/meta.json').then(r => r.json());
    POINTS = APP.points || await fetch(APP.base + 'projects/' + PROJECT + '/' + (META.points || 'points.json')).then(r => r.json());
    document.title = (META.title || '地圖') + (META.subtitle ? '・' + META.subtitle : '');
    document.getElementById('title').textContent = META.title + (META.subtitle ? '・' + META.subtitle : '');
    document.getElementById('foot').textContent = (META.source ? '資料來源：' + META.source + '。' : '') +
      '投稿公開共享，可刪自己的；偏好存於本機，無追蹤 Cookie。';

    const seen = {};
    POINTS.forEach(c => { if (!seen[c.cat]) seen[c.cat] = { key: c.cat, label: c.catLabel, color: c.color }; });
    const order = META.categoryOrder || catOrder;
    CATS = order.filter(k => seen[k]).map(k => seen[k]);
    Object.keys(seen).forEach(k => { if (!CATS.find(c => c.key === k)) CATS.push(seen[k]); });

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

    const controls = document.getElementById('controls');
    const chevron = (collapsed) => '<i class="fa-solid fa-chevron-' + (collapsed ? 'right' : 'down') + '"></i>';
    if (localStorage.getItem('ctlCollapsed') === '1') { controls.classList.add('collapsed'); document.getElementById('collapseBtn').innerHTML = chevron(true); }
    document.getElementById('collapseBtn').onclick = function () {
      const c = controls.classList.toggle('collapsed');
      this.innerHTML = chevron(c);
      localStorage.setItem('ctlCollapsed', c ? '1' : '0');
    };

    // 圖層/路徑切換
    document.getElementById('routeBtn').onclick = function () { routeOn = !routeOn; this.classList.toggle('on', routeOn); drawRoute(); if (routeOn) feature('route'); };
    const plb = document.getElementById('photoLayerBtn'); plb.classList.add('on');
    plb.onclick = function () { photoLayerOn = !photoLayerOn; this.classList.toggle('on', photoLayerOn); if (photoLayerOn) { renderPhotoLayer(); feature('photos'); } else map.removeLayer(photoLayer); };

    // 投稿者篩選 → 顯示某人的觀察地圖
    const pf = document.getElementById('personFilter');
    if (pf) pf.onchange = () => {
      filterPerson = pf.value;
      if (filterPerson) feature('filter');
      if (!photoLayerOn && filterPerson) { photoLayerOn = true; plb.classList.add('on'); }
      renderPhotoLayer(); drawPersonRoute();
      const pts = filterPerson ? personPoints(filterPerson).map(e => [e.lat, e.lon]) : [];
      if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.25));
    };

    // 嵌入碼
    const embedBtn = document.getElementById('embedBtn');
    if (embedBtn) embedBtn.onclick = openEmbed;
    document.getElementById('copyEmbedBtn').onclick = async () => {
      const ta = document.getElementById('embedCode');
      try { await navigator.clipboard.writeText(ta.value); } catch (e) { ta.select(); document.execCommand('copy'); }
      document.getElementById('copyMsg').textContent = '已複製 ✓';
      setTimeout(() => document.getElementById('copyMsg').textContent = '', 2000);
    };

    // 上傳（精簡模式停用）
    if (!EMBED) {
      const pick = document.getElementById('pickImages');
      document.getElementById('uploadBtn').onclick = () => { modalContext = null; resetQueue(); openModal(null); };
      document.getElementById('panelUploadBtn').onclick = () => { resetQueue(); openModal(current); };
      document.getElementById('addMoreBtn').onclick = () => { pick.value = ''; pick.click(); };
      pick.onchange = e => { addFiles(Array.from(e.target.files)); };
      document.getElementById('submitAllBtn').onclick = submitAll;
    }

    // 上傳權限：解鎖彈窗（含 QR 掃描）+ 邀請連結 ?code=
    const ub = document.getElementById('unlockBtn');
    if (ub) ub.onclick = openUnlock;
    document.getElementById('unlockSubmit').onclick = trySubmitUnlock;
    document.getElementById('scanBtn').onclick = startScan;
    const uci = document.getElementById('unlockCodeInput');
    if (uci) uci.addEventListener('keydown', e => { if (e.key === 'Enter') trySubmitUnlock(); });
    if (params.get('code') && !EMBED) {
      const c = params.get('code');
      params.delete('code');
      const qs = params.toString();
      try { history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash); } catch (e) {}
      doUnlock(c).then(r => { if (r.ok) toast('✓ 已用邀請連結解鎖'); });
    }
    applyPostState();

    // 關閉浮動卡片：×、地圖背景點擊、Esc
    const pc = document.querySelector('.p-close'); if (pc) pc.onclick = closePanel;
    map.on('click', () => closePanel());
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closePanel(); closeModal(); closeEmbed(); } });

    await computeMyHash();       // 先算出本裝置擁有者雜湊（供「刪自己的」判斷）
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

  function openEmbed() {
    feature('embed');
    const url = location.origin + APP.base + PROJECT + '?embed=1';
    document.getElementById('embedCode').value =
      '<iframe src="' + url + '" title="' + esc(META.title || 'Souliong') + '" ' +
      'style="width:100%;height:600px;border:0;border-radius:12px" loading="lazy"></iframe>';
    document.getElementById('embedDialog').classList.add('open');
  }
  function closeEmbed() { document.getElementById('embedDialog').classList.remove('open'); }

  // 圓角形狀（內嵌 SVG，stroke-linejoin:round → 尖角變圓角）；顏色用 currentColor 跟主題
  function polyPoints(sides, R) {
    const p = [];
    for (let i = 0; i < sides; i++) { const a = (-90 + i * 360 / sides) * Math.PI / 180; p.push((12 + R * Math.cos(a)).toFixed(1) + ',' + (12 + R * Math.sin(a)).toFixed(1)); }
    return p.join(' ');
  }
  function shapeSVG(kind) {
    const open = '<svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="3.5" stroke-linejoin="round" stroke-linecap="round">';
    const inner = {
      dot: '<circle cx="12" cy="12" r="5.5" stroke="none"/>',
      line: '<rect x="4" y="10" width="16" height="4" rx="2" stroke="none"/>',
      triangle: '<polygon points="' + polyPoints(3, 8) + '"/>',
      square: '<rect x="5.5" y="5.5" width="13" height="13" rx="4"/>',
      pentagon: '<polygon points="' + polyPoints(5, 8) + '"/>',
      hexagon: '<polygon points="' + polyPoints(6, 8) + '"/>',
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
    e.textContent = '🎉 循跡小彩蛋：每個地方都留下痕跡，每一道痕跡都有故事。';
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
  function pinSubmit() { if (!pinVal) return; const t = pinVal; closePin(); location.href = APP.base + '?api=admin&token=' + encodeURIComponent(t); }
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
      b.setAttribute('aria-label', k === 'del' ? '刪除' : k === 'ok' ? '送出' : k);
      b.innerHTML = k === 'del' ? '<i class="fa-solid fa-delete-left"></i>' : k === 'ok' ? '<i class="fa-solid fa-check"></i>' : k;
      b.onclick = () => { k === 'del' ? pinDel() : k === 'ok' ? pinSubmit() : pinAdd(k); };
      pad.appendChild(b);
    });
  }
  function openPinPad() { pinVal = ''; buildPinPad(); renderPin(); document.getElementById('pinDialog').classList.add('open'); document.addEventListener('keydown', pinKey); }
  function closePin() { document.getElementById('pinDialog').classList.remove('open'); document.removeEventListener('keydown', pinKey); }

  boot();
  return { closePanel, closeModal, openLightbox, openEmbed, closeEmbed, closePin, closeUnlock };
})();
