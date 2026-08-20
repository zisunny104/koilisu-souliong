/* 選用插件：投稿者身分（見 souliong/docs/EXTENDING.md 第七節）
   由 api/features.php 的 identity 旗標決定要不要載入（關閉時連 #identity／#idToggleBtn 等 DOM
   都不會輸出，personExplore 透過 dependsOn 一併隨之關閉，見 souliong_module_on()）。
   管右上角的身分指示鈕（#identity：顯示暱稱/管理者/匿名預覽名、點按觸發上傳捷徑或解鎖、長按換一個匿名名），
   以及解鎖對話框裡「建立身分」的展開/收合（#idToggleBtn/#idFields，PIN／暱稱欄位的讀取與重置仍留在核心，
   因為它們跟純代碼解鎖共用同一個對話框與送出按鈕，拆不乾淨）。
   contribToken/contribInfo 等「有無設定過 PIN」的實際存取與運算留在核心：submitContribution／deleteEntry／
   photo 編輯等核心自身的送出流程都要用到，且沒設 PIN 時它們本來就是無害的空字串，行為與這個模組開／關無關。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  class ContributorIdentityPlugin extends MapApp.Plugin {
    constructor() { super('identity'); }

    mount() {
      const idEl = document.getElementById('identity');
      if (idEl) {
        let idLpTimer = null, idLpFired = false;
        idEl.addEventListener('pointerdown', () => { idLpFired = false; idLpTimer = setTimeout(() => { idLpFired = true; this.mapApp.rerollAnon(); }, 600); });
        const idLpCancel = () => { if (idLpTimer) { clearTimeout(idLpTimer); idLpTimer = null; } };
        idEl.addEventListener('pointerup', idLpCancel);
        idEl.addEventListener('pointerleave', idLpCancel);
        idEl.addEventListener('pointercancel', idLpCancel);
        idEl.onclick = () => { if (idLpFired) { idLpFired = false; return; } this.mapApp.identityChipClick(); };
        idEl.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.mapApp.identityChipClick(); } };
      }

      const idBtn = document.getElementById('idToggleBtn'), idFields = document.getElementById('idFields');
      if (idBtn && idFields) idBtn.onclick = () => {
        const open = idFields.style.display === 'none';
        idFields.style.display = open ? '' : 'none';
        idBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      };

      this.mapApp.onHook('identityChanged', () => this.render());
      this.render();
    }

    // 顯示目前暱稱（未輸入則管理者帶入「管理者」、否則顯示本次匿名預覽名）；解鎖狀態附鎖圖示
    render() {
      const el = document.getElementById('identity');
      if (!el) return;
      if (this.mapApp.isEmbedMode()) { el.style.display = 'none'; return; }
      let name = '';
      try { name = (localStorage.getItem('myName') || '').trim(); } catch (e) {}
      const shown = name || (window.APP.isManager ? t('identity_manager') : this.mapApp.anonName());
      const unlocked = this.mapApp.isUnlocked();
      el.innerHTML = '<i class="fa-solid ' + (unlocked ? 'fa-user-check' : 'fa-user') + '"></i> ' + esc(shown);
      el.title = t('identity_title_named', { name: shown }) + (name ? '' : (window.APP.isManager ? t('identity_title_manager_suffix') : t('identity_title_anon_suffix')));
    }
  }

  new ContributorIdentityPlugin().init(window.MapApp);
})();
