# 隱私與資料說明 · Privacy &amp; Data Notice

_最後更新 / Last updated: 2026-07_

> 站內同款頁面：`<base>/privacy`（由 `privacy.php` 提供，中英對照）。
> Same notice on-site at `<base>/privacy`.

我們盡量少收資料、以去識別方式處理，且不使用第三方追蹤或廣告。
We collect as little as possible, keep it de-identified, and use no third-party tracking or ads.

---

## 存在你裝置上的資料 / Stored on your device

以瀏覽器 `localStorage` 儲存（僅在你的裝置，可自行清除）：主題偏好、你輸入的暱稱、投稿權限（解鎖後記住的投稿碼）、一個**有期限**的「擁有者標記」（用來讓你在原裝置刪除自己的投稿），以及——若你自選了 PIN 建立可跨裝置延續的投稿者身分——一組由該 PIN 衍生的識別權杖（可用來在其他裝置編輯/刪除同一身分投稿過的內容；若此身分是由分享連結建立，管理者可為其設到期時間或使用次數上限，僅影響「新增投稿」，不影響既有內容的編輯/刪除）。

公開地圖頁**不設 Cookie**；只有後台登入使用一個功能性的 `httpOnly` Cookie 維持登入，並非追蹤用途。

> Kept in `localStorage` (your device only): theme, nickname, the contribution code once unlocked, a time-limited “owner marker” to let you delete your own posts from this device, and — if you chose a PIN to create a portable, cross-device contributor identity — a derived token for that identity (lets you edit/delete that identity's posts from another device; if the identity was created via a share link, an admin may set an expiry or use-count limit on it, which only affects new posts, never editing/deleting existing ones). The public map sets **no cookies**; only admin login uses a functional httpOnly cookie — not for tracking.

## 伺服器記錄的資料 / Recorded on the server

- **投稿內容 / Posts**：照片、文字、暱稱、時間、你選擇的座標——公開顯示於地圖。Photo, text, nickname, time, chosen location — shown publicly.
- **匿名統計 / Aggregate stats**：瀏覽、工作階段、裝置類別、功能使用、相機型號等彙總數字，**不含個人身分**。Non-identifying counts only.
- **防冒名來源標記 / Abuse-forensics marker**：一段**加鹽雜湊**後的來源標記，經去識別、**無法還原成 IP**，僅供管理者鑑識，不對外顯示。A salted, de-identified source hash; never shown, not reversible to an IP.

上傳時照片會轉存為 WebP，過程**移除原始 EXIF**（僅另存我們讀到的拍攝時間、座標，以及相機廠牌/型號/鏡頭/軟體、光圈、快門、ISO、焦距等有限的拍攝參數欄位；不含機身序號等可唯一識別裝置的資訊）。
On upload, photos are re-encoded to WebP and **original EXIF is stripped** (only shot time, coordinates, and a limited set of shooting parameters are kept — camera make/model/lens/software, aperture, shutter speed, ISO, focal length; no device serial number or other uniquely-identifying fields).

## 刪除與內容處理 / Deletion &amp; takedown

投稿者可在**當初上傳的裝置**上、於擁有者標記的**有效期限內**自行移除自己的投稿。逾期、或發現不當、疑似侵權的內容，可向網站管理者反映協助移除。
Contributors can remove their own posts from the original device while the owner marker is valid. For expired posts or inappropriate/infringing content, ask the site administrator to remove it.

本服務不針對兒童設計；請勿上傳可識別未成年人或敏感個資的內容。
This service is not directed at children; do not upload content identifying minors or sensitive personal data.

## 第三方 / Third parties

地圖以 Leaflet 顯示、圖磚 © CARTO、圖資 © OpenStreetMap 貢獻者（ODbL）；QR 在你的瀏覽器本機產生。皆用於顯示功能，非廣告或追蹤。授權詳見 [LICENSE](../LICENSE)。
Map via Leaflet, tiles © CARTO, data © OpenStreetMap contributors (ODbL); QR generated locally in your browser. For display only. See [LICENSE](../LICENSE).

---

## 投稿條款 / Contribution terms

1. 你保證對上傳內容擁有合法權利，且不侵害他人著作權、肖像權或隱私。<br><span>You warrant you have the rights to what you upload and infringe no one's copyright, likeness, or privacy.</span>
2. 你同意上傳內容以**公開、非專屬**方式在本平台展示；著作權仍屬你本人。<br><span>You agree your content is shown publicly and non-exclusively; copyright remains yours.</span>
3. 禁止上傳違法、仇恨、猥褻、廣告或含他人敏感個資之內容。<br><span>No illegal, hateful, obscene, advertising, or sensitive-personal-data content.</span>
4. 站方得於必要時移除不當內容或更換投稿碼。<br><span>The site may remove inappropriate content or rotate the contribution code when necessary.</span>
5. 本服務按「現狀」提供，不保證不中斷或無錯誤。<br><span>The service is provided “as is”, without warranty of uninterrupted or error-free operation.</span>

---
© 2026 prjToka ・ 程式碼採 MIT 授權 / Code under MIT.
