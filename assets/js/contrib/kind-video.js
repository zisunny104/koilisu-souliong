/* 投稿型別：影片
   原檔直傳（瀏覽器端轉碼影片不切實際：耗時、耗電、品質還更差），只在前端做兩件輕的事——
   讀長度、抽一幀當封面圖。封面圖跟主檔一起送，伺服器存成 <主檔名>_t.webp，
   顯示端（viewer.leaflet.js 的 entryThumbUrl）才有東西可鋪。
   抽幀會因編碼器不支援而失敗（HEVC 的 .mov 在多數瀏覽器就抽不出來），失敗不擋上傳：
   沒有封面圖的影片，卡片與地圖標記會退回顯示影片圖示。 */
(() => {
  const { Kind, t, register } = window.SLContrib;

  // 只在 metadata 到齊後才動作；壞檔或不支援的編碼會走 onerror，別讓 await 永遠掛著
  function loadVideo(url) {
    return new Promise((resolve, reject) => {
      const v = document.createElement('video');
      v.preload = 'metadata';
      v.muted = true;
      v.playsInline = true;
      v.onloadedmetadata = () => resolve(v);
      v.onerror = () => reject(new Error('video decode failed'));
      v.src = url;
    });
  }

  function seekTo(v, sec) {
    return new Promise((resolve, reject) => {
      const done = () => { v.onseeked = v.onerror = null; resolve(); };
      v.onseeked = done;
      v.onerror = () => reject(new Error('seek failed'));
      v.currentTime = sec;
      // 有些瀏覽器對 currentTime=0 不觸發 seeked（本來就在 0），逾時就當作已就位
      setTimeout(done, 1500);
    });
  }

  class VideoKind extends Kind {
    get key() { return 'video'; }
    get tab() { return 'media'; }
    get icon() { return 'fa-film'; }

    acceptAttr() { return 'video/*'; }
    accepts(file) { return /^video\//i.test(file.type) || /\.(mp4|m4v|webm|mov|qt)$/i.test(file.name); }
    // 同 kind-photo：媒體分頁只開放影片時，用影片自己的文案
    tabLabel() { return 'tab_video'; }
    pickLabel() { return 'pick_video_btn'; }
    pickHint() { return 'pick_video_hint'; }

    async prepare(file, state) {
      state.blob = file;
      const url = this.objUrl(state, file);
      let v = null;
      try { v = await loadVideo(url); } catch (e) { state.thumbError = true; return; }
      if (isFinite(v.duration) && v.duration > 0) state.duration = v.duration;
      state.poster = url;   // 抽不出幀時，預覽區還是可以用 <video> 自己顯示
      try {
        // 第 0 幀常常是黑的（淡入），往後挪一點；短片就取正中間
        await seekTo(v, Math.min(0.25, (state.duration || 1) / 2));
        const w = v.videoWidth, h = v.videoHeight;
        if (!w || !h) throw new Error('no frame');
        const max = 640, s = Math.min(1, max / Math.max(w, h));
        const cv = document.createElement('canvas');
        cv.width = Math.round(w * s); cv.height = Math.round(h * s);
        cv.getContext('2d').drawImage(v, 0, 0, cv.width, cv.height);
        state.thumb = await new Promise(r => cv.toBlob(r, 'image/webp', 0.8));
      } catch (e) {
        state.thumb = null;
        state.thumbError = true;
      }
    }

    renderPreview(state, el) {
      el.textContent = '';
      if (state.thumb) { el.style.backgroundImage = 'url(' + this.objUrl(state, state.thumb) + ')'; return; }
      if (state.poster) {
        // 抽不出封面圖時直接放一個小播放器，至少讓投稿者確認自己選對檔了
        el.innerHTML = '<video class="sl-c-video" src="' + state.poster + '" controls preload="metadata" playsinline></video>';
        return;
      }
      el.textContent = t('media_read_failed');
    }

    fields(state, card, common) {
      const f = { ...common, media: [state.blob, state.blob.name || 'video'] };
      if (state.thumb) f.thumb = [state.thumb, 'thumb.webp'];
      if (state.duration) f.duration = state.duration;
      return f;
    }
  }

  register(new VideoKind());
})();
