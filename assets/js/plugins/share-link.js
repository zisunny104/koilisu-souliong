/* 選用插件：分享（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.share 為 true 時，view.php 才會載入這個檔案。
   全螢幕分享卡片（含 QR code）＋複製公開網址，網址會帶入目前的篩選範圍
   （投稿者／分類，以及其他插件透過 MapApp.registerScopeParam() 註冊的參數）。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class ShareLinkPlugin extends MapApp.Plugin {
    constructor() { super('share'); }

    mount() {
      this.returnFocus = null;
      this.injectDialog();
      this.injectButton();
      this.mapApp.onHook('closeAll', () => this.close());
      // 快速鍵 s：與核心鍵盤處理分開自己監聽，嵌入模式或表單輸入中不觸發
      document.addEventListener('keydown', e => {
        const tag = (e.target && e.target.tagName) || '';
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(tag) || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.key.toLowerCase() === 's' && !this.mapApp.isEmbedMode()) this.open();
      });
    }

    injectDialog() {
      const scr = document.createElement('div');
      scr.id = 'shareScreen';
      scr.className = 'sharescreen';
      scr.setAttribute('aria-hidden', 'true');
      scr.innerHTML = `
        <div class="share-card">
          <button class="icon-btn share-close" aria-label="${esc(t('close'))}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          <div class="share-qr" id="shareQr" aria-hidden="true"></div>
          <div class="share-title" id="shareTitle"></div>
          <div class="share-sub" id="shareSub"></div>
          <div class="share-url" id="shareUrl"></div>
          <div class="dialog-actions" style="justify-content:center">
            <button class="btn primary" id="shareCopyBtn"><i class="fa-solid fa-link"></i> ${esc(t('copy_link'))}</button>
            <span id="shareCopyMsg" class="hint"></span>
          </div>
        </div>
      `;
      document.body.appendChild(scr);

      scr.querySelector('.share-close').onclick = () => this.close();
      scr.querySelector('#shareCopyBtn').onclick = () => this.copyLink();
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.id = 'shareBtn';
      btn.className = 'icon-btn hide-in-embed';
      btn.title = t('share_map');
      btn.setAttribute('aria-label', t('share_map'));
      btn.innerHTML = '<i class="fa-solid fa-share-nodes" aria-hidden="true"></i>';
      btn.onclick = () => this.open();
      const homeBtn = document.getElementById('homeBtn');
      if (homeBtn) homeBtn.insertAdjacentElement('afterend', btn);
    }

    // 公開地圖網址（不含 embed/code；帶目前篩選狀態，讓分享連結只給對方看你指定的投稿者或主題）
    publicUrl() {
      const App = this.mapApp;
      const qs = App.currentScopeParams();
      return location.origin + window.APP.base + App.getProjectId() + (qs ? '?' + qs : '');
    }

    open() {
      this.mapApp.trackFeature('share');
      const url = this.publicUrl();
      const meta = window.APP.meta || {};
      document.getElementById('shareTitle').textContent = meta.title || t('app_title');
      document.getElementById('shareSub').textContent = meta.subtitle || t('app_tagline');
      document.getElementById('shareUrl').textContent = url;
      const box = document.getElementById('shareQr'); box.innerHTML = '';
      try { const qr = qrcode(0, 'M'); qr.addData(url); qr.make(); box.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true }); }
      catch (e) { box.textContent = t('qr_generate_failed'); }
      this.returnFocus = document.activeElement;
      const scr = document.getElementById('shareScreen');
      scr.removeAttribute('aria-hidden');   // 先解除 aria-hidden，才能把焦點移進去（避免 aria-hidden 蓋住有焦點的子元素）
      scr.classList.add('open');
      const closeBtn = scr.querySelector('.share-close'); if (closeBtn) closeBtn.focus();
    }

    close() {
      const scr = document.getElementById('shareScreen');
      if (!scr) return;
      // 先把焦點移出這個容器，再設回 aria-hidden，避免「aria-hidden 蓋住仍保有焦點的子元素」的衝突
      const restore = (this.returnFocus && document.body.contains(this.returnFocus)) ? this.returnFocus : document.getElementById('shareBtn');
      if (restore && typeof restore.focus === 'function') restore.focus();
      scr.setAttribute('aria-hidden', 'true');
      scr.classList.remove('open');
      this.returnFocus = null;
    }

    async copyLink() {
      try { await navigator.clipboard.writeText(this.publicUrl()); } catch (e) { }
      const m = document.getElementById('shareCopyMsg');
      if (m) { m.innerHTML = '<i class="fa-solid fa-check"></i> ' + esc(t('copied')); setTimeout(() => m.textContent = '', 2000); }
    }
  }

  new ShareLinkPlugin().init(window.MapApp);
})();
