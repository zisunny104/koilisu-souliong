# Souliong 擴充架構（趁早預留，之後只加不打掉）

## 一、資料模型為什麼「加不打掉」

投稿存的是 **JSON-Lines（每行一個 JSON 物件）**，是 **schemaless** 的——
新增欄位/新型別**不需要遷移舊資料**。搭配每筆都有的 `kind` 欄位，任何新內容型別都是「加一個分支」，不是重寫。

一筆記錄目前的欄位：
```
id, project, item_num, kind, name, comment,
photo, photo_time, lat, lon, loc_source,
exif{make,model,lens,f,exp,iso,focal,sw},
owner_hash, src_hash, created_at
```
`kind` 目前有：`photo`（照片投稿）、`desc`（地點說明版本）。

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

統計已在記錄（`data/<project>.stats.json`），只差顯示：
- 讀取：`GET ?api=stat&project=<id>&read=1&token=admin密碼`
- 用回傳 JSON 畫圖：`points` 排序＝熱門點、`by_hour`/`by_dow`＝時段、`device`/`features`/`cameras`＝圓餅或數字卡。
- 可在 `admin.php` 內嵌一段 `<canvas>` 直接畫（已有摘要文字版）。

## 六、命名與品牌

- 平台：**Souliong｜循跡**（原創詞，靈感取自客語拼音音韻與 Soul＝地方精神；非客語單字）。
- Slogan：Every place leaves traces. Every trace tells a story.（每個地方都留下痕跡，每一道痕跡都有故事。）
- 署名：© 2026 prjToka。
