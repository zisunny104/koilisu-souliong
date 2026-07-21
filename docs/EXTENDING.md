# Souliong 擴充架構（趁早預留，之後只加不打掉）

## 一、資料模型為什麼「加不打掉」

投稿存的是 **JSON-Lines（每行一個 JSON 物件）**，是 **schemaless** 的——
新增欄位/新型別**不需要遷移舊資料**。搭配每筆都有的 `kind` 欄位，任何新內容型別都是「加一個分支」，不是重寫。

一筆記錄目前的欄位：
```
id, project, item_num, kind, name, comment,
photo, photo_time, lat, lon, loc_source,
exif{make,model,lens,f,exp,iso,focal,sw},
owner_hash, src_hash, contrib_id, contrib_hash, edit_of, created_at
```
`kind` 目前有：`photo`（照片投稿）、`desc`（地點說明版本）、`point`（定位點版本，見 `editpoint.php`）。
`edit_of` 指向被編輯的原始投稿 id（版本化：不覆寫，新增一筆版本紀錄，前端取最新版本蓋過原始值）。
`contrib_id`/`contrib_hash` 是可選的投稿者身分（自選 PIN 才有）：前者對外可見（分組顯示用），後者僅供伺服器驗證「本人編輯/刪除」，不外流。

## 二、新增一張地圖（完全不用改程式）

1. 建 `projects/<新id>/meta.json` 與點位 JSON（參考 `projects/100chairs/`）。
2. `meta.json` 可設 `"gated": true` 要投稿碼、或不設＝開放。
3. 進入方式：`/koilisu/souliong/<新id>`；`souliong/` 首頁會自動列出它。

點位 JSON 每筆：`num, theme, area, chair, material, lat, lon, cat, catLabel, color, story`
（非椅子主題可自訂欄位；`pointSub()` 會退回 `sub` 欄位。）

## 三、之後要加「聲音」當投稿（audio kind）——additive 清單

不需要打掉任何現有東西，只要「加分支」：

1. **前端**：上傳流程增加錄音/選音檔 → 送 `kind=audio` + 檔案欄位（例如 `media`）。
2. **upload.php**：加一段允許 `audio/*`（`allowed_mime` 增列 `audio/mpeg`、`audio/webm`…），存到 `media/<project>/`；記錄多存一個 `audio` 欄位（舊照片記錄不受影響）。
3. **輸出**：`?api=photo` 目前只服務照片；可加 `?api=media`（或把 photo.php 一般化成依副檔名判斷）。
4. **地圖呈現**：`renderPhotoLayer()` 目前只畫有 `photo` 的點；加一個「音訊圖層」畫有 `audio` 的點、用不同圖示，點開在卡片放 `<audio>`。
5. **卡片**：`renderEntries()` 目前分 `desc`/`photo`；再加 `audio` 分支即可。

> 因為儲存是 schemaless、且渲染是依 `kind` 分流，以上全是「新增」，舊資料與現有流程都不動。影片（video）同理。

## 四、多地圖「重疊」呈現（未來）

- 現在檢視器一次載入一張地圖（一個 project）。
- 要「疊圖」＝同時載入多個 project 的點位與投稿、用圖層開關切換。
- 資料本來就是「每個 project 各自的檔案」，所以重疊只是「多讀幾個 + 圖層控制」的**新增功能**，不需改資料結構。

## 五、統計要「顯示」怎麼做

統計已在記錄（`projects/<project>/stats.json`），只差顯示：
- 讀取：`GET ?api=stat&project=<id>&read=1`（需已用管理 PIN 登入）
- 用回傳 JSON 畫圖：`points` 排序＝熱門點、`by_hour`/`by_dow`＝時段、`device`/`features`/`cameras`＝圓餅或數字卡。
- 可在 `admin.php` 內嵌一段 `<canvas>` 直接畫（已有摘要文字版）。

## 七、選用前端插件（optional plugin）

有些功能不是每個專案都需要，做成「選用插件」比寫死在核心程式裡更乾淨：核心不認識任何特定插件，只提供一組掛勾點；插件是 `assets/plugins/` 底下**獨立的檔案**，自己管理自己的 state、DOM、CSS，只在對應 `meta.json` 旗標開啟時才由 `view.php` 用 `<?php if (!empty($meta['xxx'])): ?>` 條件載入（讀完核心 `viewer.leaflet.js` 之後）。

`window.MapApp`（核心）目前對插件公開：

- **事件**：`MapApp.onHook(name, fn)` 訂閱、核心內部用 `emitHook(name, ...)` 發送。目前有 `'stateChange'`（投稿者篩選／全部-投稿模式／資料重新整理時發送）、`'panelReset'`（有其他管道直接開/關地點面板時發送，插件若有自己的「聚焦」狀態應在這裡清掉）。
- **延伸點（有回傳值）**：`MapApp.registerPhotoFilter(fn)`（`fn(photoEntry, currentPoint) => bool`，篩掉不想顯示的照片，AND 疊加）、`MapApp.registerEntriesHint(fn)`（`fn(currentPoint) => HTMLElement|null`，插進地點卡片內容裡的提示區塊，插件自己建節點、自己綁事件）。
- **資料／動作**：`MapApp.personTimeline(name)`、`MapApp.pointTitle(p)`、`MapApp.photoFullUrl(item)`、`MapApp.openPanel(point)`、`MapApp.openLightbox(entry, url)`、`MapApp.refreshEntries()`（＝目前地點卡片重繪一次，通常在插件自己改了篩選狀態之後呼叫）。
- **唯讀狀態**：`MapApp.getMap()`、`MapApp.getFilterPerson()`、`MapApp.isPhotoLayerOn()`。

參考實作：`assets/plugins/person-explore.js`（`personExplore` 旗標——選了投稿者後可依序探索他的地標／零散照片時間軸），照抄這個檔案的結構（讀 `window.APP.meta` 自我檢查旗標、`<style>` 自己注入、DOM 自己插入 `#ctlBody`）就能再加一個新插件。

## 八、命名與品牌

- 平台：**Souliong｜循跡**（原創詞，靈感取自客語拼音音韻與 Soul＝地方精神；非客語單字）。
- Slogan：Every place leaves traces. Every trace tells a story.（每個地方都留下痕跡，每一道痕跡都有故事。）
- 署名：© 2026 prjToka。
