# Souliong 循跡

> 循著地方留下的痕跡，用地圖探索、記錄一座城市。
> Every place leaves traces. Every trace tells a story.

一個以地圖為媒介的開放**地方探索平台**，作為 [KoiLiSu 開利手](https://github.com/zisunny104/koilisu-framework) 框架下的一個 app。純 PHP + 檔案儲存，**零資料庫、零額外常駐服務**。底下可放多張地圖（第一張示例是中興新村「一百種停下來的理由・椅子地圖」）。

> 本 repo 只含**平台程式碼**，不含各專案內容與使用者資料（`projects/`、`state/` 皆不進版控）。

## 特色

- OSM 底圖 + 分類彩色圓點，深淺主題自動切換、骨架載入
- **可堆疊的地圖圖層**：底圖與自繪插畫疊圖由下往上疊，附對位切圖磚工具（`<base>/tilecut`）
- 投稿**照片／影片／音訊／文字**，可版本化的**地點故事**、投稿者觀察路線、參與者自建地點
- **限特定人投稿**：投稿碼（QR 掃描／邀請連結一點解鎖）
- **只能刪自己的**（裝置匿名標記，append-only）
- 批次上傳（EXIF/GPS、HEIC→WebP、可拖曳定位）
- 匿名聚合統計（後台附純 CSS 圖表，無圖表函式庫）、可嵌入（`?embed=1`）
- **雙層管理 PIN**：主 PIN（Master Key，開所有專案）＋各專案 PIN（多把、可加暱稱）

## 快速開始（本機）

```bash
cp api/config.example.php api/config.php   # 填 admin_pin、ip_salt
# projects/、state/ 已隨 repo 建好（各含 .gitkeep），確認 PHP 執行者可寫即可
php -S localhost:8000                      # 或掛你的 Nginx/PHP-FPM
# 開 http://localhost:8000/
```

## 初始化設定（`api/config.php`）

由 `api/config.example.php` 複製。至少改：

| 鍵 | 說明 |
|---|---|
| `admin_pin` | 主管理 PIN（Master Key，開所有專案） |
| `ip_salt` | 隨機鹽值（管理登入 cookie 與冒名鑑識用） |
| `trust_forwarded` | 位於 Nginx 反代後設 `true` |
| `debug` | 上線穩定後設 `false` |

## 建立一張地圖（專案）

在 `projects/<id>/` 放兩個檔，**免改程式**：

- `meta.json`：標題、中心點、分類、模組開關（投稿要不要碼由後台的投稿碼決定，不寫在這裡）
- 點位 JSON（`meta.json` 的 `points` 指定檔名）

```json
// projects/mymap/meta.json
{ "id":"mymap", "title":"我的地圖", "subtitle":"副標",
  "center":[23.95,120.69], "zoom":14, "points":"points.json",
  "numbering":"suffix", "categoryOrder":["green","pink","blue"] }
```
點位每筆：`num, theme, area, chair, material, lat, lon, cat, catLabel, color, story`。詳見 [EXTENDING.md](docs/EXTENDING.md)（投稿型別、模組開關、圖層系統）。

網址：`/koilisu/souliong/<id>`；`/koilisu/souliong/` 首頁自動列出所有地圖。

## 投稿碼（給參與者上傳）

- **碼即開關**：後台建立投稿碼＝開放投稿（要碼才能投）；一組有效碼都沒有＝目前未開放投稿，只有管理者能投。
- 碼存 `projects/<id>/codes.json`（清單），可同時開多組，各自可選填**到期時間**／**張數上限**（留空即不限）；後台一碼一張卡，含邀請連結、QR、用量、刪除。
- 在後台新增/刪除投稿碼、複製**邀請連結**或給參與者掃 **QR**；連結 `.../<id>?code=XXXX` 一點即解鎖上傳。

## 管理後台 · PIN 權限

- 進入：地圖頁**連點標題**（點→線→…→六角）叫出 PIN 面板，或首頁**連點 logo**；輸入 PIN。PIN 走 POST、以 httpOnly cookie 保持登入，**不進網址**。
- **雙層 PIN（很多房間鎖 + 一把 Master Key）**：
  - **主 PIN**：`config.admin_pin` ＋ 後台可再新增多把（各可加暱稱）。開**所有專案**、擁有全部權限。
  - **專案 PIN**：每個專案可設**多把**（各可加暱稱），只能管理**該專案**；預設不能動別人投稿的內容，主 PIN 可個別授權權限旗標：`delete_others`（刪別人投稿）、`edit_others`（改別人投稿）、`edit_points`（改定位點）、`delegate_admin`（可建立「管理PIN」型分享連結）。
  - 主後台可**新增/移除**各層 PIN（簡易權限管理，非帳號系統）。
- **投稿者身分是純自助的**：不是後台生產，而是參與者用投稿碼進地圖後，在解鎖視窗自行設一組 PIN（可選暱稱）即建立跨裝置身分；後台「身分管理」只顯示既有身分、可撤銷，不能代為建立。能不能投稿完全看當次用的**投稿碼**，身分本身不帶配額。
- **管理 PIN 也是純自助的**：後台只產生**邀請連結**（可選填到期時間／兌換次數上限），PIN／暱稱由收到連結的人自行輸入兌換；兌換出來的身分預設不帶任何權限，要下放權限一樣由主 PIN 事後逐項授權。秘密只透過網址 fragment（`#redeem=...`）傳遞，讀取後即清除，不落地在伺服器或瀏覽器紀錄。
- 後台功能：投稿碼／身分管理、統計摘要與圖表、審閱與刪除投稿、冒名鑑識線索、**個別/全部專案備份（ZIP）**、主題包與**圖層**管理（ZIP 匯出匯入）、資料修復工具（`exiffix`／`thumbfix`／`tilecut`）。

## 授權

程式碼採 **MIT**（見 [LICENSE](LICENSE)）。地圖圖資 © OpenStreetMap 貢獻者（ODbL）、圖磚 © CARTO；使用者投稿預設以 **CC0** 公開分享，已建立身分的投稿者可改選 **CC BY**（見投稿條款）。隱私說明見 [PRIVACY.md](docs/PRIVACY.md)、部署與安全見 [DEPLOY.md](docs/DEPLOY.md)。

© 2026 prjToka
