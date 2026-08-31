/* 選用插件：路徑（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.route 為 true 時，view.php 才會載入這個檔案。
   #routeBtn 由這裡自己建立、插入 #ctlBody 的第一個 .ctl-row（旗標關閉時整個檔案不會載入，按鈕自然也不存在）。
   未選投稿者時：畫出每位投稿者各自的時間路徑（多色）；選了投稿者則改畫他單人的路徑（較粗）。
   彩蛋：快速連點「路徑」鈕數下，依所有投稿的時間順序，重新走一次整條路徑動畫。 */
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };
  const esc = (s) => String(s).replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const tv = (e) => new Date(e.photo_time || e.created_at).getTime() || 0;

  const EGG_COUNT = 6, EGG_WINDOW = 1200;

  class RouteTourPlugin extends MapApp.Plugin {
    constructor() { super('route'); }

    mount() {
      this.routeOn = false;
      this.routeLines = [];
      this.personLine = null;
      this.eggN = 0;
      this.eggTimer = null;
      this.eggRun = 0;

      const row = document.querySelector('#ctlBody .ctl-row');
      if (!row) return;
      const btn = document.createElement('button');
      btn.className = 'btn';
      btn.id = 'routeBtn';
      btn.title = t('route_by_number');
      btn.innerHTML = '<i class="fa-solid fa-route"></i> ' + esc(t('route'));
      row.appendChild(btn);
      btn.onclick = () => {
        this.routeOn = !this.routeOn;
        btn.classList.toggle('on', this.routeOn);
        this.drawAll();
        if (this.routeOn) this.mapApp.trackFeature('route');
        this.eggClick();
      };
      this.mapApp.onHook('stateChange', () => this.drawAll());
    }

    // 依姓名分組取得每人的照片點（依拍攝／建立時間排序，只取有座標的）
    personPoints(name) {
      return this.mapApp.effectivePhotos()
        .filter(e => e.name === name && typeof e.lat === 'number' && typeof e.lon === 'number')
        .sort((a, b) => tv(a) - tv(b));
    }

    drawAll() { this.drawRoute(); this.drawPersonRoute(); }

    // 未選特定投稿者時：同時畫出每位投稿者各自的時間路徑（多色）
    drawRoute() {
      const engine = this.mapApp.getEngine();
      this.routeLines.forEach(l => engine.removePolyline(l));
      this.routeLines = [];
      const filterPerson = this.mapApp.getFilterPerson();
      if (!this.routeOn || filterPerson) return;
      const byName = {};
      this.mapApp.effectivePhotos().forEach(e => {
        if (!e.name || typeof e.lat !== 'number' || typeof e.lon !== 'number') return;
        (byName[e.name] = byName[e.name] || []).push(e);
      });
      Object.keys(byName).forEach(name => {
        const pts = byName[name].sort((a, b) => tv(a) - tv(b)).map(e => [e.lat, e.lon]);
        if (pts.length >= 2) this.routeLines.push(engine.drawPolyline(pts, { color: this.mapApp.personColor(name), weight: 2, opacity: .65 }));
      });
    }

    // 選了投稿者時：改畫他單人的路徑（較粗），要「選投稿者」且「路徑」開關也開著才畫
    drawPersonRoute() {
      const engine = this.mapApp.getEngine();
      if (this.personLine) { engine.removePolyline(this.personLine); this.personLine = null; }
      const filterPerson = this.mapApp.getFilterPerson();
      if (!filterPerson || !this.routeOn) return;
      const pts = this.personPoints(filterPerson).map(e => [e.lat, e.lon]);
      if (pts.length >= 2) this.personLine = engine.drawPolyline(pts, { color: this.mapApp.personColor(filterPerson), weight: 3, opacity: .85 });
    }

    // 彩蛋：快速連點「路徑」鈕數下 → 依所有投稿的時間順序，重新走一次整條路徑
    eggClick() {
      this.eggN++;
      clearTimeout(this.eggTimer);
      this.eggTimer = setTimeout(() => { this.eggN = 0; }, EGG_WINDOW);
      if (this.eggN >= EGG_COUNT) {
        this.eggN = 0; clearTimeout(this.eggTimer);
        this.playTimeline();
      }
    }

    async playTimeline() {
      const engine = this.mapApp.getEngine();
      const pts = this.mapApp.effectivePhotos().filter(e => typeof e.lat === 'number' && typeof e.lon === 'number').sort((a, b) => tv(a) - tv(b));
      if (pts.length < 2) { this.mapApp.toast(esc(t('route_egg_not_enough'))); return; }
      const myRun = ++this.eggRun;   // 若又被連點觸發一次，讓前一場動畫提早結束，不互相干擾
      this.mapApp.toast(esc(t('route_egg_msg')));
      const trail = engine.drawPolyline([], { color: '#ff6b35', weight: 3, opacity: .85, dashArray: '4 6' });
      const marker = engine.drawPoint(pts[0].lat, pts[0].lon, { radius: 8, color: '#fff', weight: 2, fillColor: '#ff6b35', fillOpacity: 1 });
      const path = [];
      for (let i = 0; i < pts.length && myRun === this.eggRun; i++) {
        const p = pts[i];
        path.push([p.lat, p.lon]);
        engine.updatePolylinePoints(trail, path);
        engine.updatePointPosition(marker, p.lat, p.lon);
        engine.panTo(p.lat, p.lon, { animate: true, duration: .5 });
        await new Promise(r => setTimeout(r, 550));
      }
      if (myRun === this.eggRun) setTimeout(() => { engine.removePolyline(trail); engine.removePoint(marker); }, 1200);
    }
  }

  new RouteTourPlugin().init(window.MapApp);
})();
