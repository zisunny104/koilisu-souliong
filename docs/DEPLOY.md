# Souliong 部署與上線安全清單

Souliong 是 KoiLiSu 框架下的一個 app（`apps/souliong`）。純 PHP + 檔案儲存，零資料庫、零額外常駐服務。

## 一、上線前必做（安全關鍵）

### 1. ★ 擋掉 `projects/` 與 `state/` 的直接存取
`projects/<id>/` 裡有：投稿內容、**投稿碼**（`codes.json`）、投稿者名冊、統計——**絕對不能被直接下載**（否則投稿碼外洩＝門檻失效）；照片也在裡面，一律由 PHP（`?api=photo`）輸出，不應該被直接列目錄。
`state/` 裡有 admin PIN 清單與限流檔，同樣不能外流。

在 Nginx server 區塊加入（路徑對應你的實際 web root）：
```nginx
# 擋掉所有 app 的 projects/ 與 state/ 直接存取
location ~ ^/koilisu/apps/[^/]+/(state|projects)/ { deny all; return 404; }
# 保險：擋掉 .jsonl / .json / .stats.json 這類資料檔
location ~ \.(jsonl|sqlite|json)$ { deny all; return 404; }
```
> 驗證：瀏覽器開 `https://你的網域/koilisu/apps/souliong/projects/100chairs/codes.json` 應該是 403/404，不能看到碼。

### 2. 改掉預設密鑰（`api/config.php`）
- `admin_pin` → 一組不易猜的 PIN（管理頁登入用；驗證後種 httpOnly cookie，PIN 不進網址）。
- `ip_salt` → 一組隨機字串（鑑識 IP 雜湊用）。

### 3. 反代 IP 設定
- `trust_forwarded => true`（位於 Nginx 反代後**必設**，否則所有訪客共用一個 IP，限流會誤判、統計也會塞住）。

### 4. 可寫目錄
`projects/`、`state/` 需 php-fpm 執行者可寫：
```bash
cd /你的路徑/apps/souliong
chown -R www-data:www-data projects state   # www-data 換成你的 php-fpm 使用者
chmod -R 775 projects state
```

### 5. 上傳大小
Nginx：`client_max_body_size 15m;`　PHP：`upload_max_filesize=15M`、`post_max_size=16M`。

### 6. 穩定後關 debug
`api/config.php` 的 `debug => false`（錯誤不再回傳內部細節）。

### 7. HTTPS
定位、相機、`crypto.subtle`（刪除判斷）都需安全情境。全站 HTTPS。

## 二、投稿碼（限特定人上傳）

- 是否需要碼：由各地圖的 `projects/<id>/meta.json` 的 `"gated": true` 決定。
- 碼存在 `projects/<id>/codes.json`（清單，不在 meta、前端拿不到）；可同時開多組碼，各自可選填到期時間／張數上限（留空即不限），用滿即失效。
- **管理**：管理頁「投稿碼」區塊一碼一張卡，新增/刪除都在後台操作，刪除立即失效但不影響已投稿內容。
- **給組員**：把邀請連結 `.../<id>?code=XXXX` 傳給他們，點一次即在該裝置解鎖。

## 三、管理 / 審閱 / 分析

- 管理頁：`?api=admin`（或 `/<mapid>/manager`），輸入 PIN 登入（POST，httpOnly cookie 保持登入，PIN 不進網址）。
  - **主 PIN**（`config.admin_pin`）：開所有專案。**專案 PIN**：只開該專案，由主 PIN 在後台新增/移除，可個別授權下列權限旗標（`admin_perm`，預設關閉）：`edit_others`（改別人的投稿）、`edit_points`（改定位點）、`delegate_admin`（可建立「管理PIN」型分享連結）。
  - 投稿者身分是**純自助**的：參與者用投稿碼進地圖後，在解鎖視窗自行設 PIN 建立身分；後台「身分管理」只顯示/撤銷，不能代為建立，身分本身也不帶到期／次數（那是投稿碼的事）。
  - 「管理 PIN」同樣是**純自助**的：後台只建立**邀請連結**（可設到期時間／兌換次數上限），收到連結的人自行輸入 PIN／暱稱兌換；兌換出來的身分預設無任何權限，需由主 PIN 事後逐項授權（`admin_perm`）。連結的秘密只透過網址 fragment（`#redeem=...`）傳遞，不進伺服器紀錄。
  - 投稿碼／身分管理、看統計摘要、瀏覽與刪除投稿。
  - 每筆顯示 `owner`(同源可群組) 與 `src`(加鹽 IP 雜湊)：**同一 owner 出現多個不同 src ＝ 可能投稿碼外流/冒名**，可據此界定污染範圍後刪除。
- 統計原始 JSON：`GET ?api=stat&project=<id>&read=1`（需已用管理 PIN 登入）

## 四、CSP（若你日後強制執行）
本站目前依賴的外部來源（report-only 下可用；強制執行需放行）：
```
script-src  'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;
style-src   'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com;
img-src     'self' data: blob: https://*.basemaps.cartocdn.com;
connect-src 'self';
font-src    https://cdnjs.cloudflare.com;
worker-src  blob:;
```

## 五、備份
只要備份 `apps/souliong/projects/`（每個專案的全部資料與照片）與 `apps/souliong/state/`（admin PIN 清單）兩個資料夾即為全部使用者資料。

> 若你是從舊版（`data/` + `photos/` + `projects/` 三資料夾並存）升級：這是有資料位置破壞性的變動，新舊程式碼與新舊資料夾結構不能混用。升級時需把舊的 `data/<id>.jsonl`／`<id>.code.txt`／`<id>.contrib.json`／`<id>.stats.json` 與 `photos/<id>/` 併入 `projects/<id>/`（分別改名為 `data.jsonl`／`code.txt`／`contrib.json`／`stats.json`／`photos/`），再把只剩 `admin_pins.json`、`.rate/` 的舊 `data/` 改名為 `state/`。放進去的 `code.txt` 不用手動轉檔——後端第一次讀取投稿碼時會自動把它併入 `codes.json`（不限期不限次數那筆）並刪除 `code.txt`，舊碼不會失效。

## 六、韌性說明
- 無狀態：每個請求獨立，php-fpm worker 崩潰會自動被取代，不需手動「重新上線」。
- 檔案儲存以 `flock` 加鎖、逐行解析且跳過壞行：單一壞行不會弄垮整個資料。
- 限流：檔案式滑動視窗（`rate_max`/`rate_window`），攻擊時擋下寫入；限流自身失敗時「放行」而非拒服務。
- 進階：可在 Nginx 加 `limit_req`、或用 fail2ban 針對 429/404 掃描來源封鎖。
