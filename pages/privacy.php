<?php

/**
 * 隱私與資料說明頁（站內、主題感、中英對照）。由路由 <base>/privacy 顯示。
 * 內容為平台通則；不含任何個別使用者資料。
 */
$cfg = @include __DIR__ . '/../config.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$appName = $_APP['name'] ?? basename(dirname(__DIR__));
$i = strpos($path, '/' . $appName);
$base = $i !== false ? substr($path, 0, $i + strlen($appName) + 1) . '/' : '/';
$base = '/' . trim(str_replace('\\', '/', $base), '/');
$base = $base === '/' ? '/' : $base . '/';
$b = htmlspecialchars($base, ENT_QUOTES);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>隱私與資料說明 · Souliong 循跡</title>
  <script>
    try {
      var t = localStorage.getItem('theme');
      if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
    } catch (e) {}
  </script>
  <style>
    :root {
      color-scheme: light dark;
      --bg: #fafafa;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d;
      --r: 1.25rem
    }

    @media(prefers-color-scheme:dark) {
      :root {
        --bg: #141416;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2e2e31;
        --card: #1d1d20;
        --accent: #f1f1f3
      }
    }

    :root[data-theme=light] {
      --bg: #fafafa;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d
    }

    :root[data-theme=dark] {
      --bg: #141416;
      --fg: #f1f1f3;
      --muted: #9c9ca3;
      --line: #2e2e31;
      --card: #1d1d20;
      --accent: #f1f1f3
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: var(--bg);
      color: var(--fg);
      line-height: 1.75;
      -webkit-font-smoothing: antialiased
    }

    .wrap {
      max-width: 44rem;
      margin: 0 auto;
      padding: 2.5rem 1.25rem 4rem
    }

    a {
      color: inherit
    }

    h1 {
      font-size: 1.6rem;
      font-weight: 800;
      margin: 0 0 .25rem
    }

    .lead {
      color: var(--muted);
      font-size: .95rem;
      margin: 0 0 1.75rem
    }

    h2 {
      font-size: 1.15rem;
      font-weight: 800;
      margin: 2rem 0 .5rem
    }

    .en {
      color: var(--muted);
      font-size: .9rem
    }

    ul {
      margin: .4rem 0 0;
      padding-left: 1.2rem
    }

    li {
      margin: .3rem 0
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r);
      padding: 1.1rem 1.3rem;
      margin-top: .75rem
    }

    .back {
      display: inline-block;
      margin-bottom: 1.5rem;
      font-size: .9rem;
      color: var(--muted);
      text-decoration: none
    }

    code {
      font-family: ui-monospace, Consolas, monospace;
      font-size: .85em;
      background: var(--line);
      padding: .1em .35em;
      border-radius: .3em
    }

    footer {
      margin-top: 2.5rem;
      padding-top: 1rem;
      border-top: 1px solid var(--line);
      color: var(--muted);
      font-size: .8rem
    }
  </style>
</head>

<body>
  <div class="wrap">
    <a class="back" href="<?= $b ?>">← 返回地圖列表 · Back</a>
    <h1>隱私與資料說明</h1>
    <p class="lead">Privacy &amp; Data Notice — 我們盡量少收資料、以去識別方式處理，且不使用第三方追蹤或廣告。<br>We collect as little as possible, keep it de-identified, and use no third-party tracking or ads.</p>

    <h2>存在你裝置上的資料 <span class="en">/ Stored on your device</span></h2>
    <div class="card">
      以瀏覽器 <code>localStorage</code> 儲存（僅在你的裝置、可自行清除）：
      <ul>
        <li>主題偏好（淺／深／系統）<span class="en">— theme preference</span></li>
        <li>你輸入的暱稱 <span class="en">— the nickname you type</span></li>
        <li>投稿權限（解鎖後記住投稿碼）<span class="en">— contribution code once unlocked</span></li>
        <li>投稿「擁有者標記」，用來讓你在原裝置刪除自己的投稿；此標記有期限，逾期後即無法用它刪除。<span class="en">— an owner marker (time-limited) so you can delete your own posts from this device</span></li>
      </ul>
      公開地圖頁不設 Cookie；只有進入後台登入時，會使用一個功能性的 <code>httpOnly</code> Cookie 維持登入，並非追蹤用途。<br>
      <span class="en">The public map sets no cookies; only admin login uses a functional httpOnly cookie (not for tracking).</span>
    </div>

    <h2>伺服器記錄的資料 <span class="en">/ Recorded on the server</span></h2>
    <div class="card">
      <ul>
        <li><b>投稿內容</b>：照片、文字、你填的暱稱、時間、以及你選擇的地點座標——這些會公開顯示於地圖。<span class="en">— your posts (photo, text, nickname, time, chosen location), shown publicly.</span></li>
        <li><b>匿名使用統計</b>：瀏覽次數、工作階段、裝置類別（手機／桌機）、功能使用次數、照片的相機型號等彙總數字，<b>不含個人身分</b>。<span class="en">— aggregate, non-identifying usage stats.</span></li>
        <li><b>防冒名的來源標記</b>：為了在發生冒名或濫用時界定影響範圍，系統會儲存一段<b>加鹽雜湊</b>後的來源標記。它經過去識別、無法還原成 IP，僅供管理者鑑識，<b>不對外顯示</b>。<span class="en">— a salted, de-identified source hash for abuse forensics; never shown publicly and not reversible to an IP.</span></li>
      </ul>
    </div>

    <h2>刪除與內容處理 <span class="en">/ Deletion &amp; takedown</span></h2>
    <div class="card">
      投稿者可在<b>當初上傳的裝置</b>上、於擁有者標記的<b>有效期限內</b>自行移除自己的投稿。逾期、或發現不當、疑似侵權的內容，可向網站管理者反映協助移除。<br>
      <span class="en">Contributors can remove their own posts from the original device while the owner marker is valid. For expired posts or inappropriate/infringing content, ask the site administrator to remove it.</span>
    </div>

    <h2>第三方 <span class="en">/ Third parties</span></h2>
    <div class="card">
      地圖以 Leaflet 顯示、圖磚來自 CARTO、圖資 © OpenStreetMap 貢獻者；QR 產生在你的瀏覽器本機完成。這些用於顯示功能，非廣告或追蹤。授權詳見 <a href="https://github.com/zisunny104/koilisu-souliong/blob/main/LICENSE" target="_blank" rel="noopener">LICENSE</a>。<br>
      <span class="en">Map via Leaflet, tiles by CARTO, data © OpenStreetMap contributors; QR generated locally in your browser. For display only — no ads or tracking.</span>
    </div>

    <footer>© 2026 prjToka · Souliong 循跡 · 本頁為平台通則，各地圖另可能有自訂的投稿說明。</footer>
  </div>
</body>

</html>