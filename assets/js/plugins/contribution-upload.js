/* 選用插件：投稿上傳（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.upload 為 true 時，view.php 才會載入這個檔案。
   #uploadBtn／#unlockFab／#pickImages（插在 #resetBtn 之後）與批次上傳彈窗 #modal（插在 #panel 之後）
   都由這裡的 injectDom() 自己建立、插入固定位置，view.php 不再輸出這幾段 HTML。
   管理：批次上傳彈窗（EXIF/HEIC 轉檔、GPS 定位、迷你地圖校正）、u 快速鍵、身分晶片的「快速上傳」捷徑。
   解鎖對話框（QR 掃描、PIN 建立身分）仍留在核心，因為 isUnlocked()/openUnlock() 是任何模組都可能用到的核心原語。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  const MAX_RATE_RETRY = 6;

  function queueEmptyHtml() {
    return '<div class="queue-empty"><button class="btn primary" id="pickBtn"><i class="fa-solid fa-image"></i> ' + esc(t('pick_photos_btn')) + '</button>' +
      '<div class="hint">' + esc(t('pick_photos_hint')) + '</div></div>';
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

  class ContributionUploadPlugin extends MapApp.Plugin {
    constructor() {
      super('upload');
      this.cards = {};
      this.queueSeq = 0;
      this.modalContext = null;
      this.batchRunning = false;
      this.deviceLocCache = null;
    }

    // #uploadBtn／#unlockFab／#pickImages 原本緊接在 #resetBtn 之後、#myName 之前；
    // #modal 批次上傳彈窗原本緊接在 #panel 之後、#unlockDialog 之前——插入點沿用原本 view.php 的順序。
    injectDom() {
      const resetBtn = document.getElementById('resetBtn');
      if (resetBtn) {
        resetBtn.insertAdjacentHTML('afterend',
          '<button class="fabtn upload-only" id="uploadBtn"><i class="fa-solid fa-plus"></i> ' + esc(t('upload')) + '</button>' +
          '<button class="fabtn fab-unlock" id="unlockFab" style="display:none"><i class="fa-solid fa-lock"></i> ' + esc(t('unlock_contrib')) + '</button>' +
          '<input type="file" id="pickImages" multiple hidden>');
      }

      const panel = document.getElementById('panel');
      if (panel) {
        panel.insertAdjacentHTML('afterend',
          '<div id="modal">' +
            '<div class="modal-box">' +
              '<div class="modal-head">' +
                '<h3>' + esc(t('upload_photo')) + '</h3>' +
                '<input class="name-in" id="modalName" placeholder="' + esc(t('your_nickname')) + '" autocomplete="off" style="width:130px">' +
                '<button class="btn" onclick="MapApp.closeModal()">' + esc(t('close')) + '</button>' +
              '</div>' +
              '<div class="modal-body" id="queue"></div>' +
              '<div class="modal-foot">' +
                '<button class="btn" id="addMoreBtn"><i class="fa-solid fa-plus"></i> ' + esc(t('add_more_photos')) + '</button>' +
                '<span class="spacer"></span>' +
                '<span class="hint" id="batchProgress"></span>' +
                '<button class="btn primary" id="submitAllBtn">' + esc(t('submit_all')) + '</button>' +
              '</div>' +
            '</div>' +
          '</div>');
      }
    }

    mount() {
      this.injectDom();
      this.mapApp.registerEntriesHint(point => this.entriesUploadButton(point));
      this.mapApp.onHook('closeAll', () => this.closeModal());
      this.mapApp.onHook('identityUploadShortcut', () => {
        this.resetQueue();
        this.openModal(null);
        setTimeout(() => { const n = document.getElementById('modalName'); if (n) n.focus(); }, 60);
      });
      // 暱稱：#modalName 是這個模組自己的欄位，開機時從核心的 #myName（單一真實來源）帶入初始值，
      // 之後雙向同步；長按身分鈕換一個匿名名時（identityReroll）把預覽 placeholder 換過來。
      const modalName = document.getElementById('modalName');
      if (modalName) {
        modalName.value = document.getElementById('myName').value;
        modalName.setAttribute('placeholder', this.mapApp.anonName());
        modalName.oninput = e => { document.getElementById('myName').value = e.target.value; localStorage.setItem('myName', e.target.value); };
        this.mapApp.onHook('identityReroll', () => {
          modalName.value = '';
          modalName.setAttribute('placeholder', this.mapApp.anonName());
        });
      }
      // 供核心 view.php 內嵌的 onclick="MapApp.closeModal()" 呼叫（HTML 屬性只能呼叫掛在 MapApp 上的方法，無法用 hook）
      this.mapApp.closeModal = () => this.closeModal();

      if (!this.mapApp.isEmbedMode()) {
        const pick = document.getElementById('pickImages');
        const uploadBtn = document.getElementById('uploadBtn');
        if (uploadBtn) uploadBtn.onclick = () => { this.modalContext = null; this.resetQueue(); this.openModal(null); };
        const addMoreBtn = document.getElementById('addMoreBtn');
        if (addMoreBtn) addMoreBtn.onclick = () => { pick.value = ''; pick.click(); };
        if (pick) pick.onchange = e => { this.addFiles(Array.from(e.target.files)); };
        const submitAllBtn = document.getElementById('submitAllBtn');
        if (submitAllBtn) submitAllBtn.onclick = () => this.submitAll();
      }

      document.addEventListener('keydown', e => {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key.toLowerCase() !== 'u' || this.mapApp.isEmbedMode()) return;
        if (this.mapApp.isUnlocked()) { this.resetQueue(); this.openModal(this.mapApp.getCurrentPoint()); }
        else if (window.APP && window.APP.gated) this.mapApp.openUnlock();
      });
    }

    // 「上傳照片到這個點」鈕：放在故事底下、第一張照片之前（核心 renderEntries() 在故事區塊後、照片牆前呼叫這裡）
    entriesUploadButton(point) {
      const upBtn = document.createElement('button');
      upBtn.className = 'btn primary upload-only'; upBtn.style.width = '100%';
      upBtn.innerHTML = '<i class="fa-solid fa-plus"></i> ' + esc(t('upload_to_point'));
      upBtn.onclick = () => { this.resetQueue(); this.openModal(point); };
      return upBtn;
    }

    openModal(contextPoint) {
      document.getElementById('modalName').value = document.getElementById('myName').value || localStorage.getItem('myName') || '';
      document.getElementById('modal').classList.add('open');
      this.modalContext = contextPoint || null;
    }
    closeModal() {
      const m = document.getElementById('modal');
      if (!m) return;
      m.classList.remove('open');
      // 批次上傳仍在背景進行時不可清空佇列，否則尚未送出的照片會直接遺失；重開視窗會看到原本的佇列與進度
      if (!this.batchRunning) this.resetQueue();
    }

    wirePickBtn() {
      const pb = document.getElementById('pickBtn');
      if (pb) pb.onclick = () => { const p = document.getElementById('pickImages'); p.value = ''; p.click(); };
    }
    resetQueue() {
      Object.values(this.cards).forEach(st => { try { if (st.ro) st.ro.disconnect(); } catch (e) {} try { if (st.mini) st.mini.remove(); } catch (e) {} });
      Object.keys(this.cards).forEach(k => delete this.cards[k]);
      document.getElementById('queue').innerHTML = queueEmptyHtml();
      this.wirePickBtn();
    }
    cancelCard(id) {
      const st = this.cards[id];
      if (!st || st.done) return;   // 已送出的不可取消（不可更改），只能取消尚未送出的
      try { if (st.ro) st.ro.disconnect(); } catch (e) {}
      try { if (st.mini) st.mini.remove(); } catch (e) {}
      delete this.cards[id];
      const card = document.getElementById(id);
      if (card) card.remove();
      if (!Object.keys(this.cards).length) { document.getElementById('queue').innerHTML = queueEmptyHtml(); this.wirePickBtn(); }
    }

    getDeviceLoc() {
      if (this.deviceLocCache !== null) return Promise.resolve(this.deviceLocCache);
      return new Promise(res => {
        if (!navigator.geolocation) { this.deviceLocCache = false; return res(false); }
        navigator.geolocation.getCurrentPosition(
          p => { this.deviceLocCache = { lat: p.coords.latitude, lon: p.coords.longitude }; res(this.deviceLocCache); },
          () => { this.deviceLocCache = false; res(false); }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 });
      });
    }

    async addFiles(files) {
      if (!files || !files.length) return;
      // 只收圖片檔（改用「檔案」選擇器後可能混入其他類型）
      const imgs = (files || []).filter(f => /^image\//i.test(f.type) || /\.(jpe?g|png|webp|heic|heif|gif|bmp|tiff?)$/i.test(f.name));
      if (!imgs.length) { alert(t('select_images_alert')); return; }
      const qe = document.querySelector('.queue-empty'); if (qe) qe.remove();
      for (const file of imgs) {
       try {
        const id = 'q' + (++this.queueSeq);
        const card = document.createElement('div'); card.className = 'card'; card.id = id;
        card.innerHTML =
          '<button class="btn small c-cancel" type="button" title="' + esc(t('cancel_remove_from_queue')) + '"><i class="fa-solid fa-xmark"></i></button>' +
          '<div class="thumb">' + esc(t('processing')) + '</div>' +
          '<div class="fields">' +
          '<div class="time">' + esc(t('loading')) + '</div>' +
          '<input type="text" class="c-name" placeholder="' + esc(this.mapApp.anonName()) + '">' +
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
        const state = { id, file, exif: null, webp: null, thumb: null, loc: null, origLoc: null, source: null, done: false, mini: null, marker: null };
        this.cards[id] = state;
        card.querySelector('.c-cancel').onclick = () => this.cancelCard(id);

        // 座標優先抓「照片 EXIF GPS」；沒有才用裝置定位（僅輔助，且到這時才詢問權限）
        state.exif = await readExif(file);
        if (state.exif.source === 'exif') { state.loc = { lat: state.exif.lat, lon: state.exif.lon }; state.source = 'exif'; }
        else {
          const dev = await this.getDeviceLoc();
          if (dev) { state.loc = { lat: dev.lat, lon: dev.lon }; state.source = 'device'; }
          else if (this.modalContext) { state.loc = { lat: this.modalContext.lat, lon: this.modalContext.lon }; state.source = 'chair'; }
          else { const meta = this.mapApp.getMeta(); state.loc = { lat: meta.center[0], lon: meta.center[1] }; state.source = 'default'; }
        }
        state.origLoc = { lat: state.loc.lat, lon: state.loc.lon, source: state.source };
        card.querySelector('.time').innerHTML = '<i class="fa-solid fa-clock"></i> ' + this.mapApp.fmtTime(state.exif.time);

        // 關聯地點選單（預設：開啟來源地點，否則最近的一個；可選不指定；每張卡片獨立）
        const defChair = this.modalContext ? this.modalContext.num : (this.mapApp.nearestPoint(state.loc.lat, state.loc.lon) || {}).num;
        const sel = card.querySelector('.c-chair');
        sel.innerHTML = this.mapApp.chairOptionsHtml(defChair);
        card.querySelector('.c-nearest').onclick = () => { const np = this.mapApp.nearestPoint(state.loc.lat, state.loc.lon); if (np) sel.value = String(np.num); };

        // 縮圖/轉檔（WebP）
        try {
          state.webp = await toWebp(file);
          card.querySelector('.thumb').style.backgroundImage = 'url(' + URL.createObjectURL(state.webp) + ')';
          card.querySelector('.thumb').textContent = '';
          // 顯示用小縮圖（從已轉好的主圖再縮一次，避免重讀 HEIC）；失敗不擋上傳，顯示端會 fallback 用原圖
          try { state.thumb = await toWebp(state.webp, 640, 0.78); } catch (e) { state.thumb = null; }
        } catch (e) {
          card.querySelector('.thumb').textContent = t('image_read_failed');
        }

        // 迷你地圖（可拖曳；只調整照片自己的座標，不會改動地點座標）
        const miniDiv = card.querySelector('.mini');
        const mini = L.map(miniDiv, { attributionControl: false, zoomControl: false, dragging: true })
          .setView([state.loc.lat, state.loc.lon], 16);
        this.mapApp.addTileLayer(mini);
        const mk = L.marker([state.loc.lat, state.loc.lon], { draggable: true }).addTo(mini);
        state.mini = mini; state.marker = mk;
        const updLoc = (ll) => {
          state.loc = { lat: ll.lat, lon: ll.lng };
          // 用外框顏色標示定位來源（不覆蓋 Leaflet 自身 class）
          miniDiv.classList.remove('src-ok', 'src-warn', 'src-info', 'src-muted');
          miniDiv.classList.add('src-' + this.mapApp.srcTone(state.source));
          card.querySelector('.loc').innerHTML = this.mapApp.locNote(state.source) + ' <span class="loc-hint">' + esc(t('drag_to_fix_hint')) + '</span>';
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

        card.querySelector('.c-send').onclick = () => this.submitCard(state, card);
       } catch (err) { console.warn('這張照片處理失敗，略過：', err); }
      }
    }

    // opts.bulk：批次送出時，成功後只更新輕量的投稿計數，地圖/清單重繪留給 submitAll 結束後一次做，避免逐張重繪卡頓
    async submitCard(state, card, opts) {
      opts = opts || {};
      if (state.done) return true;
      const statusEl = card.querySelector('.status');
      const btn = card.querySelector('.c-send');
      const cancelBtn = card.querySelector('.c-cancel');
      if (!state.webp && !card.querySelector('.c-cmt').value.trim()) { statusEl.textContent = t('need_photo_or_comment'); statusEl.className = 'status err'; return false; }
      btn.disabled = true; if (cancelBtn) cancelBtn.disabled = true;
      statusEl.textContent = t('uploading'); statusEl.className = 'status';
      try {
        const chairNum = parseInt(card.querySelector('.c-chair').value, 10);
        const fields = {
          item_num: isNaN(chairNum) ? undefined : chairNum,
          name: card.querySelector('.c-name').value.trim() || this.mapApp.displayName(),
          comment: card.querySelector('.c-cmt').value.trim(),
          photo_time: new Date(state.exif.time).toISOString(),
          lat: state.loc.lat,
          lon: state.loc.lon,
          loc_source: state.source,
          exif: (state.exif && state.exif.cam) ? JSON.stringify(state.exif.cam) : undefined,
        };
        if (state.webp) fields.photo = [state.webp, 'photo.webp'];
        if (state.webp && state.thumb) fields.thumb = [state.thumb, 'thumb.webp'];
        await this.mapApp.submitContribution(fields, {
          maxRetry: MAX_RATE_RETRY,
          onRetry: (wait, attempt, maxAttempt) => waitCountdown(statusEl, wait, attempt, maxAttempt),
        });
        this.mapApp.trackFeature('upload');
        // 成功：鎖定卡片
        state.done = true;
        if (opts.bulk) { this.mapApp.refreshCounts(); } else { this.mapApp.refreshAll(); }
        card.classList.add('done');
        card.querySelectorAll('input,textarea,button').forEach(el => el.disabled = true);
        if (state.marker) state.marker.dragging.disable();
        statusEl.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('submitted_locked')); statusEl.className = 'status ok';
        return true;
      } catch (err) {
        // 重試次數用盡仍被限流：留在佇列裡，讓使用者可按「送出這張」手動再試，不會憑空消失
        statusEl.textContent = err.rateLimited ? t('failed_rate_limited_retry_manually') : t('save_failed', { err: err.message || err });
        statusEl.className = 'status err';
        btn.disabled = false; if (cancelBtn) cancelBtn.disabled = false;
        return false;
      }
    }

    async submitAll() {
      const ids = Object.keys(this.cards).filter(id => !this.cards[id].done);
      if (!ids.length) return;
      this.batchRunning = true;
      const submitBtn = document.getElementById('submitAllBtn');
      const prog = document.getElementById('batchProgress');
      const total = ids.length;
      let ok = 0, fail = 0;
      if (submitBtn) submitBtn.disabled = true;
      const showProg = () => { if (prog) { prog.removeAttribute('data-done'); prog.textContent = t('upload_progress', { done: ok + fail, total: total, failSuffix: fail ? t('upload_fail_suffix', { fail: fail }) : '' }); } };
      showProg();
      for (const id of ids) {
        const st = this.cards[id]; const card = document.getElementById(id);
        if (!st || st.done || !card) continue;
        const success = await this.submitCard(st, card, { bulk: true });
        if (success) ok++; else fail++;
        showProg();
      }
      // 批次跑完後才一次重繪地圖／清單，避免每送出一張就整層重繪造成卡頓
      this.mapApp.refreshAll();
      this.batchRunning = false;
      if (submitBtn) submitBtn.disabled = false;
      if (prog) {
        if (!fail) { prog.dataset.done = '1'; prog.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('upload_all_done', { ok: ok })); setTimeout(() => { if (prog.dataset.done === '1') { prog.textContent = ''; delete prog.dataset.done; } }, 5000); }
        else prog.textContent = t('upload_partial_done', { ok: ok, total: total, fail: fail });
      }
    }
  }

  new ContributionUploadPlugin().init(window.MapApp);
})();
