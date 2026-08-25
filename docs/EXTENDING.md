# Souliong 擴充架構（趁早預留，之後只加不打掉）

## 一、資料模型為什麼「加不打掉」

投稿存的是 **JSON-Lines（每行一個 JSON 物件）**，是 **schemaless** 的——
新增欄位/新型別**不需要遷移舊資料**。搭配每筆都有的 `kind` 欄位，任何新內容型別都是「加一個分支」，不是重寫。

一筆記錄目前的欄位：
```
id, project, item_num, kind, name, comment,
photo, thumb, photo_time, lat, lon, loc_source,
media, media_mime, duration,                      （影片／音訊；照片不會有這幾個）
exif{make,model,lens,f,exp,iso,focal,sw},
owner_hash, src_hash, contrib_id, contrib_hash, edit_of, created_at
```
`kind` 目前有：`photo`（照片投稿）、`video`／`audio`（影音投稿）、`text`（純文字的一則紀錄）、
`desc`（地點說明版本）、`point`（定位點版本，見 `editpoint.php`）、`newpoint`（新建立的地點，見 `newpoint.php`）。
完整定義在 `api/features.php` 的 `souliong_kinds()`，見第三節。
`edit_of` 指向被編輯的原始投稿 id（版本化：不覆寫，新增一筆版本紀錄，前端取最新版本蓋過原始值）。
`contrib_id`/`contrib_hash` 是可選的投稿者身分（自選 PIN 才有）：前者對外可見（分組顯示用），後者僅供伺服器驗證「本人編輯/刪除」，不外流。

## 二、新增一張地圖（完全不用改程式）

1. 建 `projects/<新id>/meta.json` 與點位 JSON（參考 `projects/100chairs/`）。
2. 要開放投稿就到後台建一組投稿碼（碼即開關，見 `api/security.php` 的 `contrib_open()`）。
3. 進入方式：`/koilisu/souliong/<新id>`；`souliong/` 首頁會自動列出它。

點位 JSON 每筆：`num, theme, area, chair, material, lat, lon, cat, catLabel, color, story`
（非椅子主題可自訂欄位；`pointSub()` 會退回 `sub` 欄位。）

## 三、投稿型別（kind）：一個殼 ＋ 一組型別檔

這一節原本是「之後要加聲音」的預測清單。實際做的時候一次做完照片／影片／音訊／文字四種投稿加上「建立地點」，
預測大致命中（儲存 schemaless、渲染依 `kind` 分流，所以全是新增），但有幾件事是動手才想清楚的，記在這裡。

### 3.1 中央註冊表：`api/features.php` 的 `souliong_kinds()`

這份表原本只有 `label` 跟一個全專案沒人讀的 `has_photo` 旗標——`kind` 只是「記錄下來的標籤」。
現在它是真正被消費的定義，每個 kind 帶：

| 欄位 | 意義 |
|---|---|
| `label` | 後台投稿列表與設定介面的種類名稱 |
| `tab` | 投稿對話框的分頁代號；`null` ＝不出現在對話框（由專屬流程產生） |
| `postable` | `upload.php` 是否接受前端直接 POST 這個 kind |
| `file` | 要收的 `$_FILES` 欄位名；`null` ＝純文字投稿 |
| `thumb` | 是否伴隨一張顯示用縮圖 |
| `mimes` | 允許的 MIME ⇒ 副檔名（取代原本只服務照片的 `allowed_mime`） |
| `max_bytes` | 該種類的大小上限（沒寫就用 config 的預設） |

**`postable` 是安全邊界，不是分類。** `point`／`newpoint` 都是 `false`：它們一旦可以直接 POST 到
`upload.php`，任何人都能偽造一筆座標覆蓋紀錄，繞過 `editpoint.php` 的 `admin_perm()` 與 `newpoint.php`
自己的權限判斷。`upload.php` 的白名單因此改成檢查這個旗標，而不是「有沒有出現在註冊表裡」。
之後新增 kind 時，這一格要先想清楚再填。

### 3.2 每張地圖自己決定開放哪幾種：`meta.json` 的 `contrib` 區塊

```json
"contrib": { "kinds": ["text", "photo", "video", "audio"], "default": "media", "newPoint": "contributor" }
```

`souliong_contrib_cfg($meta)` 解析它，精神比照 `souliong_module_on()`：**PHP 端算一次，前端直接讀
`$APP.contrib`**，不在兩邊各自重算預設值。回傳 `kinds`（依註冊表順序，不依 meta.json 的書寫順序，
分頁排列才會每張地圖一致）、`tabs`（由 kinds 推導）、`default`（保證在 tabs 內）、`newPoint`。

**沒有 `contrib` 區塊的舊地圖一律解析成 `kinds:["photo"]`、`newPoint:"off"`**，也就是跟加這個功能之前
完全一樣——既有地圖不改設定檔就零變化。後台「編輯專案描述」對話框可以勾選型別、選預設分頁與建立地點權限；
存檔前會再跑一次 `souliong_contrib_cfg()` 收斂（例如取消勾選所有媒體型別時，`default` 會自動從 `media`
換成第一個還存在的分頁），寫進 `meta.json` 的就是前端實際拿到的東西。

### 3.3 擷取在插件、呈現在核心

投稿的「**擷取**」（選檔、轉檔、錄音、送出）整套受 `upload` 模組旗標控制，關掉就完全不該存在 → 留在插件。
投稿的「**呈現**」（地圖圖層、卡片牆、燈箱）唯讀地圖也要看得到 → 必須在核心 `viewer.leaflet.js`。
所以型別的知識刻意拆兩邊，沒有試圖用同一組 class 兩邊共用。

擷取端：`assets/js/plugins/contribution.js` 是**與型別無關的殼**（批次佇列、進度、429 限流重試、
授權勾選、暱稱同步、`u` 快速鍵、小地圖定位校正），型別檔在 `assets/js/contrib/`：

| 檔案 | 內容 |
|---|---|
| `kind-base.js` | `ContribKind` 基底類別與註冊表 `window.SLContrib` |
| `kind-photo.js` | EXIF（exifr）、HEIC 轉檔（heic2any）、WebP 壓縮 |
| `kind-video.js` | 原檔直傳；`<video>` + canvas 抽第一幀當封面；讀長度 |
| `kind-audio.js` | 選檔 ＋ MediaRecorder 現場錄音；無縮圖 |
| `kind-text.js` | 無檔案、無座標的一則文字 |
| `kind-newpoint.js` | 標題／分類／故事／小地圖選點，送 `api/newpoint.php` |

三個當時想清楚的決定：

1. **型別是掛在每張卡片上的物件，不是一個插件。** 一個批次本來就可能混型別（同時丟一張照片、一段錄音），
   所以用組合：卡片持有一個 `ContribKind` 實體，殼只認得 `accepts()`／`prepare()`／`fields()`／`submit()`
   這組介面，完全不知道什麼是 EXIF、什麼是 MediaRecorder。
2. **「檔案有被載入」＝「這個型別可用」。** `view.php` 依 `souliong_contrib_cfg()` 只 `readfile` 該地圖啟用的
   型別檔（純文字地圖不會載到影片程式碼，`exifr`／`heic2any` 兩支 CDN script 也只在 `photo` 啟用時輸出），
   前端因此不需要再對 `APP.contrib.kinds` 過濾一次——註冊表裡有的就是能用的。
3. **`SLContrib.match(file, tab)` 讓目前分頁的型別優先認領檔案。** 只有音軌的 `.webm`／`.mp4`，瀏覽器一律
   照副檔名給 `video/*` 的 MIME，光看檔案分不出來是影片還是錄音；使用者當下在哪個分頁才是唯一可靠的線索。
   同一個檔案在音訊分頁是音訊、在媒體分頁是影片，這是刻意的。

分頁文案同理有一層 fallback：分頁裡只剩一種型別時（只開放照片的地圖就是），用型別自己的文案，
不然 100chairs 會看到「媒體／選擇照片或影片」——講的是這張地圖根本沒開放的東西。

### 3.4 儲存與端點

- **照片完全不動**：仍然收 `photo` 欄位、存進 `projects/<id>/photos/`、記錄欄位 `photo`／`thumb`，
  所以 `exiffix.php`／`thumbfix.php`／`editentry.php`／前端的 `photoFullUrl()` 全部照舊。
- **影音**走 `media` 欄位、存進 `projects/<id>/media/`、記錄欄位 `media`／`media_mime`（＋影片的 `thumb`）。
- **`api/media.php`** 服務影音檔，**必須支援 HTTP Range**——`<audio>`／`<video>` 靠它才能拖曳進度條，
  Safari 拿不到 `Accept-Ranges` 甚至直接不播。這是它跟 `photo.php` 分開存在的主要理由。
- **MIME 一律由伺服器判**，瀏覽器宣稱的值只當參考：圖片用 `getimagesize()`、影音用 `finfo`。
  實測 `finfo` 會把只有音軌的 WebM 判成 `video/webm`、把 AAC-in-MP4 判成 `audio/x-m4a`（它只看容器格式），
  而那兩種正是 MediaRecorder 在 Chrome／Firefox 與 Safari 的產物，所以 `audio` 的白名單要一起收下——
  否則現場錄音跟 iPhone 的語音備忘錄都會被自己的白名單擋掉。副檔名照「送進來的 kind」給，顯示端要的是 `<audio>`。

### 3.5 `text` 不是 `desc`

兩者刻意分開：`desc` 是「改寫這個地點的故事」，取最新一筆覆蓋顯示在故事區，由 `story-editor.js` 送出，
不進投稿對話框；`text` 是「我留下的一則紀錄」，跟照片一樣平行排在投稿牆上、可以沒有座標。合併它們會讓
「留一句話」變成「改寫別人寫的故事」。

### 3.6 建立地點（`newpoint`）

`api/newpoint.php` 比照 `editpoint.php` 的版本化精神：不改寫匯入來源的 `points.json`，而是往 `data.jsonl`
附加一筆 `kind:'newpoint'`。`effectivePoints()` 先把這些新點併進點位清單，再套 `point` 的座標覆蓋——
建立出來的點之後一樣能被管理者搬位置，因為那條路徑是照 `num` 覆蓋的，不管這個 `num` 從哪來。
權限由該地圖的 `contrib.newPoint` 決定：`off`（預設，端點直接 403）／`admin`（比照 `editpoint.php`）／
`contributor`（比照 `upload.php` 的停權與投稿碼把關）。配號在 `store_append_locked()` 的 `LOCK_EX` 內完成，
避免兩人同時建點撞號。

> 主機端提醒：影片上傳會先撞到 PHP 自己的 `upload_max_filesize`／`post_max_size`（常見預設 2M／8M），
> 那是 php.ini 的事，程式改不掉。要開放影片投稿前得先確認部署主機放行到多大。

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

地圖核心只保留「顯示點位」這個基本功能；路線導覽、地點故事編輯、上傳投稿、嵌入碼、分享、回平台首頁、投稿者身分、管理者邀請登入這八樣都是可關的模組，管理者在後台「編輯專案描述」對話框裡逐一勾選，存在該地圖 `meta.json` 的 `features` 物件（`{"route":true,"upload":false,...}`）。單一事實來源在 `api/features.php` 的 `souliong_modules()`（key／中文說明／預設值）與 `souliong_module_on($meta, $key)`（沒設定就用預設值，舊地圖不受影響）：

- **後台**（`admin.php`）：`souliong_modules()` 逐一畫勾選框，送出後寫回 `meta.json`。
- **樣板**（`view.php`）：`$mod = fn($key) => souliong_module_on($meta, $key);`，模組關閉時直接不輸出對應的按鈕／彈窗 HTML（不是用 CSS 藏起來）。
- **前端邏輯**（`viewer.leaflet.js`）：`MOD(key)` 讀 `window.APP.meta.features[key]`（同樣「沒設定＝開」），`canPost()` 把 `MOD('upload')` 併進解鎖判斷；凡是對應 DOM 可能不存在的地方都要 `if (el)` 再綁事件，全域鍵盤快速鍵／Esc 關閉等會不分模組狀態一律觸發的路徑也要能安全跳過（見各 `close*()` 函式的 null 檢查）。

`delegation`（管理者邀請登入）跟其他六個不一樣，不是插件檔案，而是核心裡兩段既有 UI 的開關：地圖頁品牌區塊的彩蛋入口（連點六下開啟 `#pinDialog`，見 `setupBrandEgg()`）與邀請連結兌換彈窗 `#adminRedeemDialog`（見 `handleRedeemFragment()`）。關閉後這張地圖不會再讓人透過網址 fragment 兌換出新的專案 PIN，地圖頁上也不再有快速登入入口——但完全不影響 `config['admin_pin']`／`data/admin_pins.json` 的主 PIN：主 PIN 是全域權限，一律能從 `/manager` 直接登入任何專案，這條路由不經過 `view.php`，不受這個旗標影響（見 `api/security.php` 的「主 PIN／專案 PIN」兩層設計）。適合「僅檢視、只有超級管理者能更新內容，不需要專案 PIN 或邀請代理」的部署。**已知缺口**：目前只有 `view.php`／`viewer.leaflet.js`／`api/features.php` 接上這個旗標；`admin.php` 後台的邀請連結建立介面、與 `security.php` 的 `admin_can()`/`pins_redeem()` 對「這個專案要不要接受專案 PIN」的判斷，尚未跟著收斂，待補。

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
- **資料／動作**：`MapApp.personTimeline(name)`、`MapApp.pointTitle(p)`、`MapApp.photoFullUrl(item)`、`MapApp.openPanel(point)`、`MapApp.openLightbox(entry, url)`、`MapApp.openUnlock()`（跳出投稿碼／解鎖視窗）、`MapApp.refreshEntries()`（＝目前地點卡片重繪一次，通常在插件自己改了篩選狀態之後呼叫）、`MapApp.trackFeature(name)`（記一筆功能使用統計，寫進該地圖的 `stats.json`）、`MapApp.currentScopeParams()`（目前的投稿者／分類篩選狀態，序列化成 querystring 片段，分享連結／嵌入碼都靠這個帶入範圍限制）、`MapApp.effectiveEntries()`（合併「原始投稿」與其編輯紀錄後的目前有效清單，**所有型別**都在裡面，是投稿資料的單一事實來源）、`MapApp.effectivePhotos()`（同一份清單只留有照片的那些；`route-tour`／`person-explore` 這種畫面只處理得了 `<img>` 的插件用這個，不要為了「支援新型別」把它們改成吃 `effectiveEntries()`）、`MapApp.entryFullUrl(entry)` / `MapApp.entryThumbUrl(entry)`（依型別給出主檔／縮圖網址，照片走 `?api=photo`、影音走 `?api=media`，插件不需要自己判斷 kind）、`MapApp.kindOf(entry)`（一筆投稿的 kind，舊記錄沒有這個欄位時退回 `photo`）、`MapApp.contribCfg()`（這張地圖的投稿設定，見第三節的 `souliong_contrib_cfg()`）、`MapApp.fmtDur(sec)`（影音長度的顯示格式化）、`MapApp.effectivePoints()`（併入 `newpoint` 並套上 `point` 座標覆蓋後的目前點位清單）、`MapApp.getCats()`（目前地圖的分類清單）、`MapApp.submitNewPoint(fields)`（送出一筆新地點，走 `api/newpoint.php` 而非投稿端點）、`MapApp.personColor(name)`（某投稿者的固定配色，跟篩選角標同一份快取，同一頁內顏色不會兜不起來）、`MapApp.toast(html)`（畫面上方跳出的短暫提示訊息）、`MapApp.displayName()`（目前裝置設定的暱稱，沒設定就退回本次隨機匿名名）、`MapApp.anonName()`（純粹讀本次隨機匿名名，不管暱稱欄位有沒有填——輸入框 placeholder 要用這個而非 `displayName()`）、`MapApp.submitContribution(fields)`（送出一筆新投稿的共用管道；`project`/`owner`/`code`/`ctoken` 這些每筆投稿都要帶的欄位由核心統一補上，插件只要給業務欄位，例如 `{kind:'desc', item_num, name, comment, photo_time}`）、`MapApp.refreshPersonFilter()`（投稿者篩選下拉重新計算一次，新增投稿可能帶入新名字時要呼叫）、`MapApp.refreshCounts()`（只重算統計數字，不重繪地圖圖層／卡片——批次上傳中途每張都呼叫這個即可，比全套 `refreshAll()` 省事）、`MapApp.refreshAll()`（資料異動後的完整重繪：統計、地圖圖層、投稿者篩選、`'stateChange'` 事件、目前地點卡片，一次呼叫涵蓋所有連動，插件不需要自己記得哪些要重繪）、`MapApp.addTileLayer(map)`（用地圖目前的底圖設定，在插件自己建立的 `L.map` 實體上加一層底圖——例如批次上傳卡片裡的小地圖預覽）、`MapApp.fmtTime(date)`（統一的時間顯示格式化）、`MapApp.getMeta()`（目前地圖的 `meta.json` 內容；用函式而非直接暴露屬性，是因為部分欄位可能是非同步取得，插件不該假設它在 `mount()` 當下就是最終值）、`MapApp.getCurrentPoint()`（目前面板開著的地點物件，沒開面板則為 `null`——例如上傳快速鍵要「以目前地點為預設脈絡」開啟批次視窗時要用這個，而不是自己記一份）、`MapApp.nearestPoint(lat, lon)`（找離某座標最近的地點，EXIF GPS 定位配對用）、`MapApp.chairOptionsHtml(selectedNum)`（地點下拉選單的 `<option>` HTML，批次卡片讓使用者手動指定/修正地點用）、`MapApp.srcTone(src)` / `MapApp.locNote(src)`（照片定位來源的顯示文字與樣式，核心的照片編輯面板與上傳模組的批次卡片共用同一份判斷邏輯，避免兩處各自維護一份、日後兜不起來）、`MapApp.rerollAnon()`（換一個新的本次匿名名；會依序發送 `'identityChanged'` 與 `'identityReroll'`，實際換算 `SESSION_ANON` 這個私有狀態的邏輯留在核心，插件只管觸發時機，例如長按身分小標籤）、`MapApp.identityChipClick()`（身分小標籤被點擊時該做什麼——已有投稿權限就發 `'identityUploadShortcut'`、被鎖住就開解鎖視窗、上傳模組整個關閉則什麼都不做；這個判斷要用到 `MOD('upload')`/`canPost()` 等核心私有狀態，所以決策邏輯留在核心，身分插件只負責把點擊事件轉呼叫過來）。
- **唯讀狀態**：`MapApp.getMap()`、`MapApp.getFilterPerson()`、`MapApp.isPhotoLayerOn()`、`MapApp.isUnlocked()`（裝置是否已解鎖投稿權限——核心原生的權限判斷，見上面第 5 點）、`MapApp.isEmbedMode()`（這個頁面是不是以 `?embed=1` 嵌入模式載入）、`MapApp.getProjectId()`（目前地圖的 project id，已做過安全字元過濾）。

參考實作：
- `assets/js/plugins/embed-code.js`（`embed` 旗標——產生 `<iframe>` 嵌入碼；`class EmbedCodePlugin extends MapApp.Plugin` 寫法，是目前符合完整標準的範例）。
- `assets/js/plugins/share-link.js`（`share` 旗標——全螢幕分享卡片＋QR code；`class ShareLinkPlugin extends MapApp.Plugin` 寫法。連帶的 vendor 函式庫 `qrcode-generator.js` 也一併只在 `share` 開啟時由 `view.php` 條件載入，避免關閉時仍多載一支用不到的腳本）。
- `assets/js/plugins/route-tour.js`（`route` 旗標——多投稿者路徑／單人路徑／連點彩蛋動畫；`class RouteTourPlugin extends MapApp.Plugin` 寫法。這個模組原本跟核心主渲染流程（新增／刪除／編輯／篩選）交纏最深，改法是把核心那些散落各處的 `drawRoute()`/`drawPersonRoute()` 呼叫全部收斂成統一的 `'stateChange'` 事件——資料或篩選狀態一變就發送一次，插件訂閱這個事件自己決定要不要重繪，核心不用再認得「路徑」這個概念。`#routeBtn` 也已改為插件自己在 `mount()` 建立並插入 `#ctlBody` 第一個 `.ctl-row`，`view.php` 不再輸出這顆按鈕）。
- `assets/js/plugins/story-editor.js`（`story` 旗標——地點故事「新增一則版本」；`class StoryEditorPlugin extends MapApp.Plugin` 寫法。故事的顯示與「歷史版本」查看／刪除仍留在核心（唯讀、一律開放，不受此旗標影響），只有「編輯」按鈕與送出表單移進插件。順便修掉一個既有 bug：舊版編輯鈕誤判成要 `MOD('upload')` 也開著才顯示（複製貼上上傳模組的 `canPost()` 判斷式），現在單純看 `isUnlocked()`，`story` 與 `upload` 各自獨立開關就名副其實了。插件透過 `registerEntriesHint` 掛勾，純粹借用它「每次 `renderEntries()` 重繪都會呼叫」的時機，把按鈕插進核心模板裡固定的 `#storyActions` 容器，而不是用它「回傳元素插進去」的字面用法）。
- `assets/js/plugins/contribution.js`（`upload` 旗標——目前最大的一個插件：投稿按鈕、分頁式批次投稿視窗、每張卡片的小地圖／定位來源／地點下拉、送出（含 429 限流自動重試）。型別專屬的知識（EXIF、WebP 轉檔、抽影格、錄音、建立地點表單）全都不在這個檔案裡，而在 `assets/js/contrib/kind-*.js`，見第三節。`#uploadBtn`/`#unlockFab`/`#pickImages`（插在 `#resetBtn` 之後）與批次投稿彈窗 `#contribModal`（插在 `#panel` 之後）都已改為插件自己在 `mount()` 的 `injectDom()` 建立並插入固定位置，`view.php` 不再輸出這幾段 HTML。解鎖對話框（掃碼／投稿碼／PIN）與 `?code=` 網址參數自動解鎖仍留在核心，完全不受此旗標影響——「能不能寫入」是核心原生功能，「怎麼上傳」才是這個模組的範圍，見上面第 5 點規則。身分小標籤點擊快速開啟批次視窗改用 `'identityUploadShortcut'` 事件（核心不再直接呼叫插件內部函式），`u` 鍵快速鍵與地點卡片裡的「投稿到這個點」按鈕都用 `MapApp.getCurrentPoint()`／`MapApp.isUnlocked()` 等核心原生功能拿到需要的狀態，不假設自己知道核心內部變數）。
- `assets/js/plugins/contributor-identity.js`（`identity` 旗標——右上角身分小標籤的渲染（暱稱／管理者／匿名預覽名、解鎖狀態圖示）、點擊與長按換名的事件綁定、解鎖對話框裡「建立身分」PIN／暱稱欄位的展開收合按鈕。`#identity` 小標籤已改為插件自己在 `mount()` 建立並插入 `#trItems` 的最前面，`view.php` 不再輸出這段 HTML；旗標關閉時整個檔案不會被載入，`#identity` 自然不存在。`#idToggleBtn`/`#idFields` 仍是 `view.php` 在 `#unlockDialog` 裡輸出的既有 HTML（原因見下段），解鎖對話框其餘部分（投稿碼輸入、QR 掃描）不受影響，純代碼解鎖照常可用。PIN／暱稱欄位本身的讀取（送出解鎖時）與重置（對話框重開時）仍留在核心——它們跟純代碼解鎖共用同一個對話框與送出按鈕，沒有乾淨的切點，且已對 DOM 是否存在做防呆（`if (el)`），旗標關閉時安全略過；`contribToken`/`contribInfo`/`myContribId` 這些「有無設定過 PIN」的實際存取也留在核心，因為 `submitContribution`／刪除／照片編輯等核心自身送出流程都要用到，且沒設 PIN 時它們本來就是無害的空字串，不受這個模組開關影響。`personExplore` 透過 `dependsOn` 相依這個模組，見下一節）。
- `assets/js/plugins/person-explore.js`（`personExplore` 旗標——選了投稿者後可依序探索他的地標／零散照片時間軸；這個檔案早於 `MapApp.Plugin` 基底類別存在，尚未改寫成 `extends` 寫法，但其餘規則——旗標自我檢查、`<style>` 自己注入、DOM 自己插入 `#ctlBody`——仍是有效範例）。

### 模組相依

當一個模組的存在前提是另一個模組開著，`souliong_modules()` 的模組定義可以加一個 `'dependsOn' => 'otherKey'`；`souliong_module_on()` 判斷時，父模組關閉就一併視為關閉，不管自己的旗標是什麼。因為 `view.php`（PHP 端 `$mod()`）與 `viewer.leaflet.js`（JS 端 `MOD()`）都要算出一致的結果，`$APP` 會多帶一份 `moduleState`（每個模組 key 對應解析後的布林值，PHP 端用 `$mod()` 算好），JS 的 `MOD(key)` 直接讀這份資料，不在前端重算一次預設值／相依邏輯——避免兩邊各自判斷、日後兜不起來。

目前接上這個機制的有兩組：`personExplore`（依序探索）相依 `identity`（投稿者身分）——只有訪客能設定具名身分時，「選了某人、依序探索他的地標」才有意義；`identity` 相依 `upload`（上傳投稿）——身分小標籤點擊後的「快速上傳」捷徑、與解鎖對話框裡「建立身分」的 PIN／暱稱欄位（`#idToggleBtn`/`#idFields`）都只在 `#unlockDialog` 存在（即 `upload` 開啟）時才有意義，核心 `identityChipClick()` 本來就已經用 `MOD('upload')` 判斷過一次（見上一節），這裡只是把既有的耦合明文化。兩段相依會串連：`upload` 關閉 → `identity` 一併關閉 → `personExplore` 也跟著關閉，即使該地圖的 `personExplore`／`identity` 旗標本身仍是開著的（後台勾選框仍會顯示、但不生效）。

## 八、地圖圖層（底圖／疊圖）

底圖與插畫疊圖歸「資源」那一類，**不是插件**。判準就是上一節那條：插件是「可以整包關掉、關掉後核心不認得它」的功能，而底圖關掉地圖就沒了。它的形狀跟主題包一樣是「用哪一包」，不是布林開關——差別只在**數量與順序**：一張地圖只套一個 pack，卻可以由下往上疊好幾層圖層。

### 8.1 兩層作用域

| 作用域 | 位置 | 進版控？ | 用途 |
|---|---|---|---|
| `site` | `layers/<id>/` | 是 | 平台內建、所有地圖共用的底圖 |
| `project` | `projects/<proj>/layers/<id>/` | 否 | 這張地圖專屬（自繪插畫多半屬於這類） |

專案層放在 `projects/` 底下不是隨便選的：那整棵目錄本來就在 `.gitignore`，所以自繪插畫、切好的圖磚金字塔這種「內容而非程式」的檔案天然不進版控，不必為了體積另立規則。同名 id 時**專案層覆蓋全站層**，讓單一地圖能在不影響其他地圖的前提下改掉內建圖層。

反過來說，`url` 直接指向外部圖磚服務的圖層（CARTO、國土測繪中心…）一個檔案都不落地，連數量問題都不存在。

### 8.2 註冊表與選用

比照 `api/packs.php`：註冊表就是目錄底下的資料夾本身，沒有中央 index 檔，新增一層只要新增一個資料夾（內含 `layer.json`）。解析在 `api/layers.php`：

- `souliong_layer_list($cfg, $proj)` — 掃兩層作用域，回傳 `[id => manifest]`，manifest 會被補上 `id`（資料夾名稱才算數，`layer.json` 內容不可覆寫）與 `scope`。
- `souliong_layers_for($cfg, $meta, $proj)` — 這張地圖生效的**有序**陣列。`meta.json` 的 `"layers": ["carto-voyager", "chungshing-art"]` 由下往上；沒有這個欄位就退回 `config` 的 `default_layers`（預設 `['carto-voyager']`，也就是圖層化之前寫死在檢視器裡的那張底圖，所以舊地圖零影響）。選到不存在的 id 會被靜靜略過，不讓整張地圖開天窗。
- `souliong_layers_public($cfg, $meta, $proj, $base)` — 前端版本，額外把相對 `url` 改寫成絕對網址。

「特定專案才有插畫疊圖」不需要額外的開關：`meta.json` 沒寫就是沒有。

### 8.3 `layer.json`

```json
{
  "label": "中興新村手繪",
  "type": "image",
  "pane": "art",
  "url": "overlay.svg",
  "bounds": [[23.94, 120.66], [23.97, 120.70]],
  "opacity": 0.9,
  "attribution": "繪圖：某某"
}
```

`type` 目前兩種：`raster`（`L.tileLayer`，吃 `subdomains`／`detectRetina`／`maxZoom`／`maxNativeZoom`／`minZoom`／`tms`／`bounds`）與 `image`（`L.imageOverlay`，`bounds` 是必要的，可用透明 PNG 或 SVG）。`urlDark` 有值的圖層會在深淺色切換時重建，沒有的原地不動。

**這些欄位必須整組跟著 manifest，不能只抽 URL**：`subdomains`／`detectRetina`／`maxNativeZoom` 都是跟著來源走的屬性，少一個就破圖。

`attribution` 也一樣跟著來源走——CARTO 的圖磚是 OSM 資料，國土測繪中心的不是，寫死在檢視器裡一定會錯。裡面可以用 `{key}` 引用翻譯字串（例 `{osm_contributors}`），這樣來源標註留在 manifest，又不必為每種語言各寫一份。檢視器的 `buildCredit()` 把各層的 attribution 去重串接，再接上固定的 Leaflet ＋ 自家連結。

`pane` 決定疊放層級。Leaflet 預設只有 `tilePane`(200)／`overlayPane`(400)／`markerPane`(600)，圖層之間沒有可指定的層級，所以檢視器替四種角色各開一個 pane：

| `pane` | z-index | 角色 |
|---|---|---|
| `base` | 200 | 底圖 |
| `paper` | 220 | 紙張底色 |
| `road` | 240 | 道路 |
| `art` | 260 | 插畫疊圖（認不得的值一律歸這裡） |

全部落在 400 以下，所以路徑線與點位標記照舊蓋在所有圖層上面。

### 8.4 前端：`MapLayer` 類別族

在 `viewer.leaflet.js`。`MapLayer` 是抽象基底，子類只需要回答「怎麼變成一個 `L.Layer`」（`build(dark, opts)`），其餘（pane、換主題重建、掛上／移除）都在基底；`MapLayer.from(manifest)` 依 `type` 挑子類。`LayerStack` 持有一整疊圖層與它們共同的版權標註，地圖只跟它打交道（`addTo(map, dark)` / `applyTheme(dark)`）。版權整組掛在最底層那一個 `L.Layer` 上，上層換主題重建時版權列才不會閃一下。

新增一種圖層＝多一個 `MapLayer` 子類（例如向量圖磚：protomaps-leaflet 是 `L.GridLayer`，不必離開 Leaflet），核心其餘部分不用動。

分享卡片與定位用的小地圖走 `addTileLayer(map)`（插件公開 API），它只掛 `base` 那一層——那些畫面不需要插畫疊圖，也不該被它擋住地標。

### 8.5 圖檔端點 `<base>/layer/<project>/<id>/<路徑>`

`layer.json` 的 `url` 若是相對路徑，代表圖檔就放在該層自己的資料夾裡。框架不供應靜態檔（理由同 `api/photo.php`），所以由 `api/layerfile.php` 輸出：

```
圖磚   <base>/layer/100chairs/chungshing-art/tiles/16/54738/28275.png
單張   <base>/layer/100chairs/demo-overlay/overlay.svg
```

一支端點同時吃圖磚與單張：自繪插畫可能切成金字塔、也可能就是一張大透明 PNG／SVG，兩者只差在資料夾裡的路徑長相，`layer.json` 想換形式時網址結構不用跟著改。`<project>` 只決定解析範圍（專案層優先於全站層），全站層也走同一條網址——前端因此永遠拿到同一種網址形狀，不必知道圖層是誰的。

把關方式：

- **副檔名白名單**（`png`／`webp`／`jpg`／`jpeg`／`avif`／`svg`）。這道關卡同時保證 `layer.json` 本身拿不到——註冊表內容不該從公開端點外流。
- **路徑**每一段只允許保守字元、整串不得出現 `..`，最後再用 `realpath()` 確認實體位置落在該圖層資料夾之內（符號連結一併攤平）。
- **SVG 回應加 `Content-Security-Policy: default-src 'none'; sandbox`**：SVG 可以內嵌 `<script>`，放在 `<img>` 裡不會執行，但有人直接開這個網址就會——同源之下那等於「能放圖層檔的人＝能在本站執行腳本」。在回應層面關掉，不倚賴呼叫端怎麼用。
- **找不到檔案時分兩種**：稀疏疊圖的常態是「這一格根本沒畫」，所以圖磚形狀（`…/<z>/<x>/<y>.<ext>`）的請求回一張 68 bytes 的全透明 PNG（帶 `X-Souliong-Tile: miss`，分得出「空白」與「真的有一張全透明的磚」），Leaflet 就不會為每個空格印一行紅字；其餘（單張疊圖路徑打錯）照實回 404。

`layers/demo-overlay/` 是可以照抄的參考範例：一張透明 SVG 蓋在底圖上，沒畫到的地方完全透出底圖。要用在自己的地圖上，複製整個資料夾、換掉 `overlay.svg`、把 `bounds` 改成插畫實際對應的西南／東北兩角，再把 id 加進 `meta.json` 的 `layers`。

### 8.6 尚未完成

- 後台沒有圖層管理介面（排序、匯出匯入），目前只能手改 `meta.json`。
- 沒有「匯入一張圖、自動切成圖磚」的工具。要做的話形狀比照 `api/thumbfix.php`：瀏覽器逐批呼叫、PHP 用 GD 一次切一個 zoom level，避開執行時間與記憶體上限。

## 九、命名與品牌

- 平台：**Souliong｜循跡**（原創詞，靈感取自客語拼音音韻與 Soul＝地方精神；非客語單字）。
- Slogan：Every place leaves traces. Every trace tells a story.（每個地方都留下痕跡，每一道痕跡都有故事。）
- 署名：© 2026 prjToka。
