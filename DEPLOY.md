# Souliong 部署與上線安全清單

Souliong 是 KoiLiSu 框架下的一個 app（`apps/souliong`）。純 PHP + 檔案儲存，零資料庫、零額外常駐服務。

## 一、上線前必做（安全關鍵）

### 1. ★ 擋掉 `data/` 與 `photos/` 的直接存取
`data/` 裡有：投稿內容、**投稿碼**、加鹽 IP 雜湊、統計、限流檔——**絕對不能被直接下載**（否則投稿碼外洩＝門檻失效）。
`photos/` 由 PHP（`?api=photo`）輸出，也不需要、也不應該被直接列目錄。

在 Nginx server 區塊加入（路徑對應你的實際 web root）：
```nginx
# 擋掉所有 app 的 data/ 與 photos/ 直接存取
location ~ ^/koilisu/apps/[^/]+/(data|photos)/ { deny all; return 404; }
# 保險：擋掉 .code.txt / .jsonl / .stats.json 這類資料檔
location ~ \.(jsonl|sqlite|code\.txt)$ { deny all; return 404; }
```
> 驗證：瀏覽器開 `https://你的網域/koilisu/apps/souliong/data/100chairs.code.txt` 應該是 403/404，不能看到碼。

### 2. 改掉預設密鑰（`api/config.php`）
- `admin_token` → 一組隨機長字串（管理頁用）。
- `ip_salt` → 一組隨機字串（鑑識 IP 雜湊用）。

### 3. 反代 IP 設定
- `trust_forwarded => true`（位於 Nginx 反代後**必設**，否則所有訪客共用一個 IP，限流會誤判、統計也會塞住）。

### 4. 可寫目錄
`data/`、`photos/` 需 php-fpm 執行者可寫：
```bash
cd /你的路徑/apps/souliong
chown -R www-data:www-data data photos      # www-data 換成你的 php-fpm 使用者
chmod -R 775 data photos
```

### 5. 上傳大小
Nginx：`client_max_body_size 15m;`　PHP：`upload_max_filesize=15M`、`post_max_size=16M`。

### 6. 穩定後關 debug
`api/config.php` 的 `debug => false`（錯誤不再回傳內部細節）。

### 7. HTTPS
定位、相機、`crypto.subtle`（刪除判斷）都需安全情境。全站 HTTPS。

## 二、投稿碼（限特定人上傳）

- 是否需要碼：由各地圖的 `projects/<id>/meta.json` 的 `"gated": true` 決定。
- 碼本身**自動產生**、存在 `data/<id>.code.txt`（不在 meta、前端拿不到）。
- **取得目前碼**：開 `?api=admin&token=...` 管理頁，會顯示碼與「邀請連結」。
- **換碼**：管理頁按「重新產生」，或直接刪掉 `data/<id>.code.txt`（下次自動產生新碼）。
- **給組員**：把邀請連結 `.../<id>?code=XXXX` 傳給他們，點一次即在該裝置解鎖。

## 三、管理 / 審閱 / 分析

- 管理頁：`?api=admin&token=你的admin密碼`
  - 看/換投稿碼、看統計摘要、瀏覽與刪除投稿。
  - 每筆顯示 `owner`(同源可群組) 與 `src`(加鹽 IP 雜湊)：**同一 owner 出現多個不同 src ＝ 可能投稿碼外流/冒名**，可據此界定污染範圍後刪除。
- 統計原始 JSON：`GET ?api=stat&project=<id>&read=1&token=你的admin密碼`

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
只要備份 `apps/souliong/data/` 與 `apps/souliong/photos/` 兩個資料夾即為全部使用者資料。

## 六、韌性說明
- 無狀態：每個請求獨立，php-fpm worker 崩潰會自動被取代，不需手動「重新上線」。
- 檔案儲存以 `flock` 加鎖、逐行解析且跳過壞行：單一壞行不會弄垮整個資料。
- 限流：檔案式滑動視窗（`rate_max`/`rate_window`），攻擊時擋下寫入；限流自身失敗時「放行」而非拒服務。
- 進階：可在 Nginx 加 `limit_req`、或用 fail2ban 針對 429/404 掃描來源封鎖。
