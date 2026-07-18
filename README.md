# Souliong 循跡

> 循著地方留下的痕跡，用地圖探索、記錄一座城市。
> Every place leaves traces. Every trace tells a story.

一個以地圖為媒介的開放**地方探索平台**，作為 [KoiLiSu 開利手](https://github.com/zisunny104/koilisu-framework) 框架下的一個 app。純 PHP + 檔案儲存，**零資料庫、零額外常駐服務**。底下可放多張地圖，第一張是「一百種停下來的理由・椅子地圖」（中興新村 76 張椅子）。

## 特色

- **OSM 底圖 + 分類彩色標記**（CARTO Positron / Dark Matter，依系統深淺主題自動切換）
- **投稿照片與地點故事**：每個點一張可展開卡片，故事可版本化編輯、預設顯示最新
- **投稿者觀察地圖**：篩選某人，看他的照片與依時間串連的觀察路線
- **限特定人投稿**：投稿碼（可 QR 掃描 / 邀請連結一點解鎖），碼由後端自動產生、可重產
- **只能刪自己的**：以裝置匿名標記辨識（append-only + 版本歷史）
- **批次上傳**：多張逐一確認，讀 EXIF 時間/GPS、無 GPS 用裝置定位、可拖曳修正、HEIC→WebP
- **匿名聚合統計**：瀏覽/點擊/時段/裝置/相機型號…（僅計數、無個資、有上限）
- **可嵌入**（`?embed=1` 精簡檢視）、深淺主題、骨架載入、基礎無障礙
- 隱藏彩蛋與管理 PIN 面板（連點品牌 → 圓角形狀 → PIN）

## 快速開始（本機）

```bash
cp api/config.example.php api/config.php   # 填入你的 admin PIN 與鹽值
php -S localhost:8000                        # 或放進你的 Nginx/PHP-FPM
# 開 http://localhost:8000/
```

## 部署（重點）

見 **[部署與安全.md](部署與安全.md)**。上線**必做**：

1. Nginx 擋掉 `data/`、`photos/` 直接存取（投稿碼存在 `data/`）
2. `api/config.php`：改 `admin_token`、`ip_salt`、反代後設 `trust_forwarded=true`、穩定後 `debug=false`
3. `data/`、`photos/` 給 php-fpm 可寫；全站 HTTPS

## 管理

- 網址 `?api=admin&token=你的PIN`，或**長按品牌**叫出 PIN 面板。
- 可看/重產投稿碼與**邀請 QR**、統計摘要、審閱與刪除投稿、冒名鑑識線索。

## 新增一張地圖

在 `projects/<id>/` 放 `meta.json` 與點位 JSON 即可，免改程式。詳見 **[擴充架構.md](擴充架構.md)**（含日後加入「聲音」等媒體型別的 additive 作法）。

## 技術

純前端（Leaflet + 內嵌 CSS/JS）+ 純 PHP 檔案後端（JSON-Lines + 檔案鎖）。外部函式庫：Leaflet、exifr、heic2any、jsQR、Font Awesome、CARTO 圖磚。

## 授權

程式碼採 **MIT**（見 [LICENSE](LICENSE)）。地圖圖資 © OpenStreetMap 貢獻者（ODbL）、圖磚 © CARTO；使用者照片依投稿條款公開分享。隱私說明見 **[隱私權與條款.md](隱私權與條款.md)**。

© 2026 prjToka
