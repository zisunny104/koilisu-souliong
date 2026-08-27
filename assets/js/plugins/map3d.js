/* 選用插件：3D 地圖模式（見 souliong/docs/EXTENDING.md 第七節）
   只在該地圖 meta.json 的 features.map3d 為 true 時，view.php 才會載入這個檔案。
   MapLibre 是完全獨立的第二顆地圖引擎，不是把 Leaflet 換掉：切換時只是把 Leaflet 的
   #map 容器藏起來、疊上自己的 #map3d，兩顆地圖互不知情，2D 地圖的任何狀態（圖層、投稿、
   主題）都不會被這裡碰到、也不會反過來被 3D 影響。*/
(() => {
  const I18N = window.I18N || {};
  const t = (key, vars) => {
    let s = I18N[key] != null ? I18N[key] : key;
    if (vars) for (const k in vars) s = s.replace('{' + k + '}', vars[k]);
    return s;
  };

  // MapLibre CustomLayerInterface（renderingMode:'3d'）＋ three.js 畫單一自訂模型。
  // 座標換算沿用官方文件那套 MercatorCoordinate 作法：模型原點換成麥卡托座標＋公尺→麥卡托單位
  // 的縮放係數，render() 收到的 modelViewProjectionMatrix 只描述「地圖現在怎麼看整個世界」，
  // 疊上這個平移＋縮放矩陣才會變成「模型自己這個局部座標系怎麼被畫出來」。
  class Map3DModelLayer {
    constructor(id, region, THREE, GLTFLoaderCtor) {
      this.id = id;
      this.type = 'custom';
      this.renderingMode = '3d';
      this.region = region;
      this.THREE = THREE;
      this.GLTFLoaderCtor = GLTFLoaderCtor;
    }

    onAdd(map, gl) {
      const THREE = this.THREE;
      const m = this.region.model;
      this.map = map;
      this.camera = new THREE.Camera();
      this.scene = new THREE.Scene();
      this.scene.add(new THREE.AmbientLight(0xffffff, 1.2));
      const sun = new THREE.DirectionalLight(0xffffff, 0.8);
      sun.position.set(0, -70, 100);
      this.scene.add(sun);

      this.origin = maplibregl.MercatorCoordinate.fromLngLat(
        { lng: m.anchor[1], lat: m.anchor[0] },
        Number(m.altitudeOffset) || 0
      );
      this.mercatorScale = this.origin.meterInMercatorCoordinateUnits();

      new this.GLTFLoaderCtor().load(
        this.region.modelUrl,
        (gltf) => {
          const s = (Number(m.scale) || 1) * this.mercatorScale;
          gltf.scene.scale.set(s, s, s);
          // glTF 是 Y-up，麥卡托世界是「地面 XY、高度 Z」，先繞 X 轉正，水平朝向（管理員填的角度）
          // 才能單純疊在轉正後的 Z 軸上，不會跟這個座標系轉正操作互相纏在一起
          gltf.scene.rotation.x = Math.PI / 2;
          gltf.scene.rotation.z = -(Number(m.rotationDeg) || 0) * Math.PI / 180;
          this.scene.add(gltf.scene);
          map.triggerRepaint();
        },
        undefined,
        (err) => console.error('[map3d] glTF 載入失敗', this.region.id, err)
      );

      this.renderer = new THREE.WebGLRenderer({ canvas: map.getCanvas(), context: gl, antialias: true });
      this.renderer.autoClear = false;
    }

    render({ gl, modelViewProjectionMatrix }) {
      const THREE = this.THREE;
      const l = new THREE.Matrix4()
        .makeTranslation(this.origin.x, this.origin.y, this.origin.z)
        .scale(new THREE.Vector3(this.mercatorScale, -this.mercatorScale, this.mercatorScale));
      this.camera.projectionMatrix = new THREE.Matrix4().fromArray(modelViewProjectionMatrix).multiply(l);
      this.renderer.resetState();
      this.renderer.render(this.scene, this.camera);
      this.map.triggerRepaint();
    }
  }

  class Map3DPlugin extends MapApp.Plugin {
    constructor() {
      super('map3d');
      this.map = null;
      this.container = null;
      this.markers = [];
      this.modelLayerIds = [];
      this.active = false;
      this.threeLoading = false;
      this.THREE = null;
      this.GLTFLoader = null;
    }

    mount() {
      const cfg = window.APP && window.APP.map3d;
      // 沒有設定（模組沒開，或 provider 沒填 styleUrl）就整個不掛，2D 完全不受影響
      if (!cfg || !cfg.styleUrl) return;
      // WebGL 容錯：壞掉的 webview（Instagram/Line 內建瀏覽器常見）直接不出現切換鈕，
      // 不留一顆按下去只會看到黑畫面的按鈕
      if (typeof maplibregl === 'undefined' || !maplibregl.supported()) return;
      this.cfg = cfg;
      this.injectStyle();
      this.injectContainer();
      this.injectButton();
      this.mapApp.onHook('stateChange', () => { if (this.active) this.renderMarkers(); });
    }

    injectStyle() {
      const style = document.createElement('style');
      style.textContent = `
        #map3d { position: fixed; inset: 0; z-index: 0; display: none }
        .m3d-marker {
          width: 16px; height: 16px; border-radius: 50%;
          border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,.4);
          cursor: pointer; box-sizing: border-box;
        }
        #map3dBtn.on { background: var(--accent); color: var(--accent-fg); border-color: var(--accent) }
      `;
      document.head.appendChild(style);
    }

    injectContainer() {
      const div = document.createElement('div');
      div.id = 'map3d';
      const mapEl = document.getElementById('map');
      if (mapEl && mapEl.parentNode) mapEl.parentNode.insertBefore(div, mapEl.nextSibling);
      else document.body.appendChild(div);
      this.container = div;
    }

    injectButton() {
      const btn = document.createElement('button');
      btn.id = 'map3dBtn';
      btn.className = 'icon-btn';
      btn.title = t('toggle_3d');
      btn.setAttribute('aria-label', t('toggle_3d_aria'));
      btn.innerHTML = '<i class="fa-solid fa-cube" aria-hidden="true"></i>';
      btn.onclick = () => this.toggle();
      const themeBtn = document.getElementById('themeBtn');
      if (themeBtn) themeBtn.insertAdjacentElement('beforebegin', btn);
      this.btn = btn;
    }

    toggle() { this.active ? this.exit() : this.enter(); }

    enter() {
      this.mapApp.trackFeature('map3d');
      this.mapApp.getMap().getContainer().style.display = 'none';
      this.container.style.display = '';
      this.active = true;
      this.btn.classList.add('on');
      if (!this.map) {
        this.createMap();
      } else {
        this.map.resize();   // 容器隱藏期間視窗尺寸可能變了（例如手機轉向），MapLibre 不會自動偵測
        this.renderMarkers();
      }
      this.maybeLoadThree();
    }

    exit() {
      this.container.style.display = 'none';
      this.mapApp.getMap().getContainer().style.display = '';
      this.active = false;
      this.btn.classList.remove('on');
    }

    styleUrlWithKey() {
      const { styleUrl, key } = this.cfg;
      if (!key) return styleUrl;
      return styleUrl + (styleUrl.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(key);
    }

    createMap() {
      const lm = this.mapApp.getMap();
      const c = lm.getCenter();
      this.map = new maplibregl.Map({
        container: this.container,
        style: this.styleUrlWithKey(),
        center: [c.lng, c.lat],
        zoom: lm.getZoom(),
        pitch: 55,
      });
      this.map.addControl(new maplibregl.NavigationControl({ visualizePitch: true }), 'bottom-right');
      this.map.on('load', () => {
        this.applyBuildingExclusion();
        this.renderMarkers();
        this.renderCustomModels();
      });
    }

    // 排除機制見 api/regions3d.php 開頭的說明：清單是管理員存檔當下算好的靜態 id，這裡只是
    // 原樣套成 filter，不做任何即時查詢或重算——圖層建立當下就生效，訪客怎麼平移都一樣。
    applyBuildingExclusion() {
      const ids = this.cfg.excludedBuildingIds;
      if (!ids || !ids.length) return;
      const style = this.map.getStyle();
      const layer = style && style.layers && style.layers.find(
        (l) => l.type === 'fill-extrusion' && (l['source-layer'] === 'building' || /building/i.test(l.id))
      );
      if (!layer) { console.warn('[map3d] 找不到公用建物 fill-extrusion 圖層，排除清單未套用'); return; }
      const exclude = ['!', ['in', ['id'], ['literal', ids]]];
      this.map.setFilter(layer.id, layer.filter ? ['all', layer.filter, exclude] : exclude);
    }

    renderMarkers() {
      if (!this.map) return;
      this.markers.forEach((m) => m.remove());
      this.markers = [];
      this.mapApp.effectivePoints().forEach((c) => {
        const el = document.createElement('div');
        el.className = 'm3d-marker';
        el.style.background = c.color || '#7a7f87';
        el.addEventListener('click', (e) => { e.stopPropagation(); this.mapApp.openPanel(c); });
        this.markers.push(new maplibregl.Marker({ element: el }).setLngLat([c.lon, c.lat]).addTo(this.map));
      });
    }

    // three.js（~600KB）只有在這張地圖真的存了至少一個自訂模型時才載入，跟 kind-*.js
    // 「沒用到就零痕跡」同一個精神。import map 由 view.php 靜態輸出、不含任何下載成本，
    // 真正的檔案要等這裡的 import() 執行才會抓。
    async maybeLoadThree() {
      if (this.threeLoading || this.THREE || !this.cfg.regions || !this.cfg.regions.length) return;
      this.threeLoading = true;
      try {
        const [THREE, addon] = await Promise.all([
          import('three'),
          import('three/addons/loaders/GLTFLoader.js'),
        ]);
        this.THREE = THREE;
        this.GLTFLoader = addon.GLTFLoader;
        if (this.map && this.map.loaded()) this.renderCustomModels();
        else if (this.map) this.map.once('load', () => this.renderCustomModels());
      } catch (e) {
        console.error('[map3d] three.js 載入失敗', e);
      }
    }

    renderCustomModels() {
      if (!this.THREE || !this.map) return;
      this.cfg.regions.forEach((r) => {
        if (!r.model || !r.model.anchor || !r.modelUrl || this.modelLayerIds.includes(r.id)) return;
        this.map.addLayer(new Map3DModelLayer('m3d-model-' + r.id, r, this.THREE, this.GLTFLoader));
        this.modelLayerIds.push(r.id);
      });
    }
  }

  new Map3DPlugin().init(window.MapApp);
})();
