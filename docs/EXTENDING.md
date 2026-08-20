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

## 六、功能模組開關（後台可關，每張地圖各自設定）

地圖核心只保留「顯示點位」這個基本功能；路線導覽、地點故事編輯、上傳投稿、嵌入碼、分享、投稿者身分這六樣都是可關的模組，管理者在後台「編輯專案描述」對話框裡逐一勾選，存在該地圖 `meta.json` 的 `features` 物件（`{"route":true,"upload":false,...}`）。單一事實來源在 `api/features.php` 的 `souliong_modules()`（key／中文說明／預設值）與 `souliong_module_on($meta, $key)`（沒設定就用預設值，舊地圖不受影響）：

- **後台**（`admin.php`）：`souliong_modules()` 逐一畫勾選框，送出後寫回 `meta.json`。
- **樣板**（`view.php`）：`$mod = fn($key) => souliong_module_on($meta, $key);`，模組關閉時直接不輸出對應的按鈕／彈窗 HTML（不是用 CSS 藏起來）。
- **前端邏輯**（`viewer.leaflet.js`）：`MOD(key)` 讀 `window.APP.meta.features[key]`（同樣「沒設定＝開」），`canPost()` 把 `MOD('upload')` 併進解鎖判斷；凡是對應 DOM 可能不存在的地方都要 `if (el)` 再綁事件，全域鍵盤快速鍵／Esc 關閉等會不分模組狀態一律觸發的路徑也要能安全跳過（見各 `close*()` 函式的 null 檢查）。

`personExplore`（依序探索）沿用原本的扁平旗標寫法（`meta.json` 直接存 `personExplore: true/false`），`souliong_module_on()` 對這個 key 特殊處理，行為與既有插件機制（見下一節）相容。

「隨機探索」是首頁層級功能（從所有地圖裡挑一個跳轉，不屬於單一地圖），所以不走 `meta.json`，而是存在跨地圖的 `state/settings.json`（`api/settings.php` 的 `souliong_settings_load()`／`souliong_random_explore_on()`），在後台「工具」分頁（僅主 PIN 可見）開關。

## 七、插件標準（plugin standard）

有些功能不是每個專案都需要，做成「插件」比寫死在核心程式裡更乾淨：核心不認識任何特定插件，只提供一組掛勾點與一個基底類別；插件是 `assets/js/plugins/` 底下**獨立的檔案**，自己管理自己的 state、DOM、CSS，只在對應模組旗標開啟時才由 `view.php` 用 `<?php if ($mod('xxx')): ?>` 條件載入（讀完核心 `viewer.leaflet.js` 之後）。關閉時整個檔案不會被讀進頁面，不留任何 HTML／`<script>` 痕跡——這是插件形式比原本散落在核心裡的 `if (MOD('xxx'))` 分支更乾淨的地方。

一個合格的插件必須符合：

1. **位置與命名**：`assets/js/plugins/<kebab-case-檔名>.js`，檔名中性、避免品牌名稱（例如 `share-link.js` 而非帶特定服務商名稱）；模組旗標的 key 維持 camelCase，跟 `api/features.php` 的 `souliong_modules()` 一致。
2. **繼承共用基底類別**：核心提供 `MapApp.Plugin`（即 `SouliongPlugin`），插件寫 `class XxxPlugin extends MapApp.Plugin`，只需覆寫 `mount()`——插件自己的 DOM／事件綁定都在這裡建立。載入時由核心（或插件檔案自己，見參考實作）`new XxxPlugin().init(window.MapApp)`；`init()` 是基底類別定義的生命週期，會存好 `this.mapApp` 再呼叫 `mount()`，插件不需要重複寫這段。
3. **自我管理**：state 放在 `this` 底下（不用模組層級的全域變數）、DOM 由 `mount()` 自己建立插入、CSS 用 `document.createElement('style')` 自己注入（不加進 `assets/css/*.css`——那些檔案是核心一定會載入的層疊樣式，插件的樣式必須跟著插件一起關掉）。
4. **只透過 `window.MapApp` 溝通**：插件不可讀寫核心內部的 closure 變數，只能呼叫下面公開的 API。需要掛進既有版位時，可以直接用文件裡列出的容器 id（例如 `#ctlBody`）當掛勾點，不需要核心另外開一個「註冊按鈕」的 API——這就是 `person-explore.js` 已經在用的作法。
5. **核心原生功能 vs. 模組專屬工具**：判斷「訪客能不能寫入」這類跟裝置權限有關的能力（`isUnlocked()`、`owner_hash`）是**核心原生功能**，任何模組都可以依賴；但某個模組自己專屬的工具函式（例如上傳模組的 `canPost()`、`resetQueue()`）**不算**核心原生功能，其他模組不可以呼叫它——即使當下剛好在同一個檔案裡摸得到。這條規則是為了避免「地點故事編輯」曾經誤呼叫上傳模組專屬的 `canPost()` 這種耦合。

`window.MapApp`（核心）目前對插件公開：

- **基底類別**：`MapApp.Plugin`（`class SouliongPlugin { constructor(key); init(MapApp); mount(); }`，`mount()` 留給子類別覆寫）。
- **事件**：`MapApp.onHook(name, fn)` 訂閱、核心內部用 `emitHook(name, ...)` 發送。目前有 `'stateChange'`（投稿新增／刪除／編輯、投稿者篩選、全部-投稿模式切換、資料重新整理等任何「顯示內容可能變了」的時機都會發送一次，插件不需要知道確切原因，收到就重新算自己要畫什麼）、`'panelReset'`（有其他管道直接開/關地點面板時發送，插件若有自己的「聚焦」狀態應在這裡清掉）、`'closeAll'`（全域 Esc 鍵或其他「全部關閉」時機發送；插件若有自己的浮層／對話框應在這裡關閉——核心不需要知道插件的對話框 id）、`'identityUploadShortcut'`（訪客點擊身分小標籤且已有投稿權限時發送；核心自己不認得「打開上傳批次視窗」這件事，改由上傳模組訂閱這個事件自己決定要做什麼——模組關閉時核心呼叫 `emitHook` 也只是發到空氣中，不會出錯）、`'identityChanged'`（顯示用的身分狀態可能變了——長按換匿名名、或解鎖狀態改變時發送；核心自己不畫身分小標籤，改由身分插件訂閱重繪）、`'identityReroll'`（專門給「換了一個新匿名名」這個更窄的時機，跟 `'identityChanged'` 分開是因為上傳模組批次視窗的暱稱欄位只需要在真的換名時重設 placeholder，不需要每次身分狀態變動就重設，否則會把使用者已經打的字清掉）。
- **延伸點（有回傳值）**：`MapApp.registerPhotoFilter(fn)`（`fn(photoEntry, currentPoint) => bool`，篩掉不想顯示的照片，AND 疊加）、`MapApp.registerEntriesHint(fn)`（`fn(currentPoint) => HTMLElement|null`，插進地點卡片內容裡的提示區塊，插件自己建節點、自己綁事件）、`MapApp.registerScopeParam(fn)`（`fn() => {key: value}|null`，插件自己想在分享連結／嵌入碼網址上多帶的參數，會併進 `currentScopeParams()` 的輸出；讀回來則不用核心幫忙——插件自己在 `mount()` 裡 `new URLSearchParams(location.search)` 讀自己定義的 key 即可，核心不需要知道有這個參數存在）。
- **資料／動作**：`MapApp.personTimeline(name)`、`MapApp.pointTitle(p)`、`MapApp.photoFullUrl(item)`、`MapApp.openPanel(point)`、`MapApp.openLightbox(entry, url)`、`MapApp.openUnlock()`（跳出投稿碼／解鎖視窗）、`MapApp.refreshEntries()`（＝目前地點卡片重繪一次，通常在插件自己改了篩選狀態之後呼叫）、`MapApp.trackFeature(name)`（記一筆功能使用統計，寫進該地圖的 `stats.json`）、`MapApp.currentScopeParams()`（目前的投稿者／分類篩選狀態，序列化成 querystring 片段，分享連結／嵌入碼都靠這個帶入範圍限制）、`MapApp.effectivePhotos()`（合併「原始照片投稿」與其編輯紀錄後的目前有效清單，是所有跟照片資料相關功能的單一事實來源）、`MapApp.personColor(name)`（某投稿者的固定配色，跟篩選角標同一份快取，同一頁內顏色不會兜不起來）、`MapApp.toast(html)`（畫面上方跳出的短暫提示訊息）、`MapApp.displayName()`（目前裝置設定的暱稱，沒設定就退回本次隨機匿名名）、`MapApp.anonName()`（純粹讀本次隨機匿名名，不管暱稱欄位有沒有填——輸入框 placeholder 要用這個而非 `displayName()`）、`MapApp.submitContribution(fields)`（送出一筆新投稿的共用管道；`project`/`owner`/`code`/`ctoken` 這些每筆投稿都要帶的欄位由核心統一補上，插件只要給業務欄位，例如 `{kind:'desc', item_num, name, comment, photo_time}`）、`MapApp.refreshPersonFilter()`（投稿者篩選下拉重新計算一次，新增投稿可能帶入新名字時要呼叫）、`MapApp.refreshCounts()`（只重算統計數字，不重繪地圖圖層／卡片——批次上傳中途每張都呼叫這個即可，比全套 `refreshAll()` 省事）、`MapApp.refreshAll()`（資料異動後的完整重繪：統計、地圖圖層、投稿者篩選、`'stateChange'` 事件、目前地點卡片，一次呼叫涵蓋所有連動，插件不需要自己記得哪些要重繪）、`MapApp.addTileLayer(map)`（用地圖目前的底圖設定，在插件自己建立的 `L.map` 實體上加一層底圖——例如批次上傳卡片裡的小地圖預覽）、`MapApp.fmtTime(date)`（統一的時間顯示格式化）、`MapApp.getMeta()`（目前地圖的 `meta.json` 內容；用函式而非直接暴露屬性，是因為部分欄位可能是非同步取得，插件不該假設它在 `mount()` 當下就是最終值）、`MapApp.getCurrentPoint()`（目前面板開著的地點物件，沒開面板則為 `null`——例如上傳快速鍵要「以目前地點為預設脈絡」開啟批次視窗時要用這個，而不是自己記一份）、`MapApp.nearestPoint(lat, lon)`（找離某座標最近的地點，EXIF GPS 定位配對用）、`MapApp.chairOptionsHtml(selectedNum)`（地點下拉選單的 `<option>` HTML，批次卡片讓使用者手動指定/修正地點用）、`MapApp.srcTone(src)` / `MapApp.locNote(src)`（照片定位來源的顯示文字與樣式，核心的照片編輯面板與上傳模組的批次卡片共用同一份判斷邏輯，避免兩處各自維護一份、日後兜不起來）、`MapApp.rerollAnon()`（換一個新的本次匿名名；會依序發送 `'identityChanged'` 與 `'identityReroll'`，實際換算 `SESSION_ANON` 這個私有狀態的邏輯留在核心，插件只管觸發時機，例如長按身分小標籤）、`MapApp.identityChipClick()`（身分小標籤被點擊時該做什麼——已有投稿權限就發 `'identityUploadShortcut'`、被鎖住就開解鎖視窗、上傳模組整個關閉則什麼都不做；這個判斷要用到 `MOD('upload')`/`canPost()` 等核心私有狀態，所以決策邏輯留在核心，身分插件只負責把點擊事件轉呼叫過來）。
- **唯讀狀態**：`MapApp.getMap()`、`MapApp.getFilterPerson()`、`MapApp.isPhotoLayerOn()`、`MapApp.isUnlocked()`（裝置是否已解鎖投稿權限——核心原生的權限判斷，見上面第 5 點）、`MapApp.isEmbedMode()`（這個頁面是不是以 `?embed=1` 嵌入模式載入）、`MapApp.getProjectId()`（目前地圖的 project id，已做過安全字元過濾）。

參考實作：
- `assets/js/plugins/embed-code.js`（`embed` 旗標——產生 `<iframe>` 嵌入碼；`class EmbedCodePlugin extends MapApp.Plugin` 寫法，是目前符合完整標準的範例）。
- `assets/js/plugins/share-link.js`（`share` 旗標——全螢幕分享卡片＋QR code；`class ShareLinkPlugin extends MapApp.Plugin` 寫法。連帶的 vendor 函式庫 `qrcode-generator.js` 也一併只在 `share` 開啟時由 `view.php` 條件載入，避免關閉時仍多載一支用不到的腳本）。
- `assets/js/plugins/route-tour.js`（`route` 旗標——多投稿者路徑／單人路徑／連點彩蛋動畫；`class RouteTourPlugin extends MapApp.Plugin` 寫法。這個模組原本跟核心主渲染流程（新增／刪除／編輯／篩選）交纏最深，改法是把核心那些散落各處的 `drawRoute()`/`drawPersonRoute()` 呼叫全部收斂成統一的 `'stateChange'` 事件——資料或篩選狀態一變就發送一次，插件訂閱這個事件自己決定要不要重繪，核心不用再認得「路徑」這個概念）。
- `assets/js/plugins/story-editor.js`（`story` 旗標——地點故事「新增一則版本」；`class StoryEditorPlugin extends MapApp.Plugin` 寫法。故事的顯示與「歷史版本」查看／刪除仍留在核心（唯讀、一律開放，不受此旗標影響），只有「編輯」按鈕與送出表單移進插件。順便修掉一個既有 bug：舊版編輯鈕誤判成要 `MOD('upload')` 也開著才顯示（複製貼上上傳模組的 `canPost()` 判斷式），現在單純看 `isUnlocked()`，`story` 與 `upload` 各自獨立開關就名副其實了。插件透過 `registerEntriesHint` 掛勾，純粹借用它「每次 `renderEntries()` 重繪都會呼叫」的時機，把按鈕插進核心模板裡固定的 `#storyActions` 容器，而不是用它「回傳元素插進去」的字面用法）。
- `assets/js/plugins/contribution-upload.js`（`upload` 旗標——目前最大的一個插件：上傳按鈕、批次選圖／拍照視窗、EXIF 讀取與 WebP 轉檔、每張照片的小地圖／定位來源／地點下拉、送出（含 429 限流自動重試）。解鎖對話框（掃碼／投稿碼／PIN）與 `?code=` 網址參數自動解鎖仍留在核心，完全不受此旗標影響——「能不能寫入」是核心原生功能，「怎麼上傳」才是這個模組的範圍，見上面第 5 點規則。身分小標籤點擊快速開啟批次視窗改用 `'identityUploadShortcut'` 事件（核心不再直接呼叫插件內部函式），`u` 鍵快速鍵與地點卡片裡的「上傳照片到這個點」按鈕都用 `MapApp.getCurrentPoint()`／`MapApp.isUnlocked()` 等核心原生功能拿到需要的狀態，不假設自己知道核心內部變數）。
- `assets/js/plugins/contributor-identity.js`（`identity` 旗標——右上角身分小標籤的渲染（暱稱／管理者／匿名預覽名、解鎖狀態圖示）、點擊與長按換名的事件綁定、解鎖對話框裡「建立身分」PIN／暱稱欄位的展開收合按鈕。旗標關閉時 `view.php` 連 `#identity` 小標籤與 `#idToggleBtn`/`#idFields` 這段 HTML 都不會輸出，不是只藏插件腳本；解鎖對話框其餘部分（投稿碼輸入、QR 掃描）不受影響，純代碼解鎖照常可用。PIN／暱稱欄位本身的讀取（送出解鎖時）與重置（對話框重開時）仍留在核心——它們跟純代碼解鎖共用同一個對話框與送出按鈕，沒有乾淨的切點，且已對 DOM 是否存在做防呆（`if (el)`），旗標關閉時安全略過；`contribToken`/`contribInfo`/`myContribId` 這些「有無設定過 PIN」的實際存取也留在核心，因為 `submitContribution`／刪除／照片編輯等核心自身送出流程都要用到，且沒設 PIN 時它們本來就是無害的空字串，不受這個模組開關影響。`personExplore` 透過 `dependsOn` 相依這個模組，見下一節）。
- `assets/js/plugins/person-explore.js`（`personExplore` 旗標——選了投稿者後可依序探索他的地標／零散照片時間軸；這個檔案早於 `MapApp.Plugin` 基底類別存在，尚未改寫成 `extends` 寫法，但其餘規則——旗標自我檢查、`<style>` 自己注入、DOM 自己插入 `#ctlBody`——仍是有效範例）。

### 模組相依

當一個模組的存在前提是另一個模組開著，`souliong_modules()` 的模組定義可以加一個 `'dependsOn' => 'otherKey'`；`souliong_module_on()` 判斷時，父模組關閉就一併視為關閉，不管自己的旗標是什麼。因為 `view.php`（PHP 端 `$mod()`）與 `viewer.leaflet.js`（JS 端 `MOD()`）都要算出一致的結果，`$APP` 會多帶一份 `moduleState`（每個模組 key 對應解析後的布林值，PHP 端用 `$mod()` 算好），JS 的 `MOD(key)` 直接讀這份資料，不在前端重算一次預設值／相依邏輯——避免兩邊各自判斷、日後兜不起來。

目前唯一接上這個機制的是 `personExplore`（依序探索）相依 `identity`（投稿者身分）：只有訪客能設定具名身分時，「選了某人、依序探索他的地標」才有意義；`identity` 關閉時 `personExplore` 一併視為關閉，即使該地圖的 `personExplore` 旗標本身是開著的（後台勾選框仍會顯示、但不生效，直到 `identity` 重新打開）。

## 八、命名與品牌

- 平台：**Souliong｜循跡**（原創詞，靈感取自客語拼音音韻與 Soul＝地方精神；非客語單字）。
- Slogan：Every place leaves traces. Every trace tells a story.（每個地方都留下痕跡，每一道痕跡都有故事。）
- 署名：© 2026 prjToka。
