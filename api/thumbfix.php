<?php
// 維護工具（備援）：舊投稿縮圖平常由 photo.php（&th=1）自動產生，這支只在自動產生失敗
//（主機 GD 沒編 WebP）或想一次補齊全部時使用——由管理者瀏覽器抓原圖、canvas 縮小後回存並補 thumb 欄位。
// 只補「沒有 thumb」的原始投稿，不動已有縮圖的紀錄與照片原檔。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 還原 app 掛載根目錄，組「回後台」連結用（理由同 exiffix.php：不能用相對的 ?api=admin）
$appName = $_APP['name'] ?? basename(dirname(__DIR__));
$reqPathOnly = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$appMarkerPos = strpos($reqPathOnly, '/' . $appName);
$basePath = $appMarkerPos !== false ? rtrim(substr($reqPathOnly, 0, $appMarkerPos + strlen($appName) + 1), '/') . '/' : '/';
$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$adminUrl = $esc($origin . $basePath . '?api=admin');

if (!admin_authed($cfg)) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>需要主要管理者登入。請先到 <a href="' . $adminUrl . '">後台</a> 登入後再回到這頁。</p>';
    exit;
}
$csrf = admin_derived($cfg);

/** 這個專案裡「有照片、還沒有縮圖」的原始投稿（編輯版本 photo 為 null，天然不在名單裡） */
function thumbfix_missing(array $cfg, string $project): array {
    $out = [];
    foreach (store_all($cfg, $project) as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['photo']) || !empty($r['thumb'])) continue;
        $abs = photo_abs_path($cfg, (string)$r['photo']);
        if ($abs === null || !is_file($abs)) continue;   // 原檔已不存在的沒得縮，略過
        $out[] = [
            'id'    => (string)$r['id'],
            'photo' => (string)$r['photo'],
            'label' => '第 ' . ($r['item_num'] ?? '?') . ' 號・' . ($r['name'] ?? '匿名') . '・' . substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16),
        ];
    }
    return $out;
}

// ── 待補名單（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'list') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => '憑證失效，請重新整理頁面'], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    json_out(['ok' => true, 'items' => thumbfix_missing($cfg, $project)]);
}

// ── 存回單張縮圖（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => '憑證失效，請重新整理頁面'], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    if ($project === '' || $id === '' || strlen($id) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad request'], 400);
    }
    $rec = null;
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; }
    }
    if (!$rec || empty($rec['photo'])) { json_out(['error' => 'not found'], 404); }
    if (!empty($rec['thumb'])) { json_out(['error' => '這筆已經有縮圖，不重複產生'], 409); }
    if (!isset($_FILES['thumb']) || $_FILES['thumb']['error'] !== UPLOAD_ERR_OK) {
        json_out(['error' => 'need thumb file'], 400);
    }
    $tf = $_FILES['thumb'];
    $tinfo = @getimagesize($tf['tmp_name']);
    $tmime = is_array($tinfo) ? ($tinfo['mime'] ?? '') : '';
    if ($tf['size'] > 1024 * 1024 || !isset($cfg['allowed_mime'][$tmime])) {
        json_out(['error' => 'bad thumb'], 415);
    }
    // 縮圖檔名沿用照片檔名加 _t（跟 upload.php 一致），放在同一個 photos 目錄
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', (string)$rec['photo'], $m)) {
        json_out(['error' => 'bad photo path'], 500);
    }
    $tname = preg_replace('/\.[A-Za-z0-9]+$/', '', $m[2]) . '_t.' . $cfg['allowed_mime'][$tmime];
    $destAbs = project_dir($cfg, $m[1]) . '/photos/' . $tname;
    if (!move_uploaded_file($tf['tmp_name'], $destAbs)) {
        json_out(['error' => 'save failed'], 500);
    }
    $patched = store_patch($cfg, $project, $id, ['thumb' => $m[1] . '/' . $tname]);
    if (!$patched) {
        @unlink($destAbs);   // 欄位沒寫成就別留孤兒檔
        json_out(['error' => 'patch failed'], 500);
    }
    json_out(['ok' => true, 'thumb' => $m[1] . '/' . $tname]);
}

// ── 頁面 ──
$allProjects = store_projects($cfg);
$reqProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? ($allProjects[0] ?? ''));
?>
<!doctype html>
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title>補產照片縮圖 · Souliong</title>
  <style>
    :root {
      --bg: #f6f5f2;
      --fg: #1c1a17;
      --muted: #7a756c;
      --line: #e2ddd3;
      --card: #fff;
      --accent: #b5482e;
      --accent-fg: #fff
    }

    @media (prefers-color-scheme:dark) {
      :root {
        --bg: #17140f;
        --fg: #f1ede6;
        --muted: #a69d8e;
        --line: #322c22;
        --card: #211c15;
        --accent: #e0663f;
        --accent-fg: #1c1a17
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      background: var(--bg);
      color: var(--fg);
      font: 15px/1.6 system-ui, -apple-system, "Noto Sans TC", sans-serif;
      padding: 24px 16px 60px
    }

    .wrap {
      max-width: 720px;
      margin: 0 auto
    }

    h1 {
      font-size: 1.125rem
    }

    .warn {
      border: 1px solid var(--accent);
      color: var(--accent);
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 0.8125rem;
      margin-bottom: 16px
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 16px 18px;
      margin-bottom: 16px
    }

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: 6px
    }

    select {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--bg);
      color: var(--fg);
      padding: 8px 10px;
      font-size: 0.875rem;
      margin-bottom: 12px
    }

    button {
      border: none;
      background: var(--accent);
      color: var(--accent-fg);
      border-radius: 10px;
      padding: 9px 16px;
      font-size: 0.875rem;
      font-weight: 700;
      cursor: pointer
    }

    button:disabled {
      opacity: .5;
      cursor: default
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    ul#results {
      list-style: none;
      margin: 14px 0 0;
      padding: 0;
      font-size: 0.8125rem
    }

    ul#results li {
      padding: 6px 0;
      border-top: 1px solid var(--line);
      display: flex;
      justify-content: space-between;
      gap: 10px
    }

    .ok {
      color: #2a8a4a
    }

    .no {
      color: var(--muted)
    }

    a {
      color: var(--accent)
    }
  </style>
</head>

<body>
  <div class="wrap">
    <h1>補產照片縮圖</h1>
    <div class="warn">正常情況不需要用這頁：舊投稿的縮圖會在第一次被瀏覽時自動產生。這裡是自動產生失敗（例如主機不支援 WebP）時的備援，或想一次先補齊全部縮圖時使用。只會替「還沒有縮圖」的投稿產生縮圖（原始照片檔與所有欄位都不會被改動）；已有縮圖的一律略過。縮圖在這台裝置的瀏覽器產生後回存，過程視照片數量可能需要一點時間，請保持此分頁開啟。</div>

    <div class="card">
      <label>選擇專案</label>
      <select id="project">
        <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
      </select>
      <button id="go">掃描並補產縮圖</button>
      <div class="hint" id="status" style="margin-top:8px"></div>
      <ul id="results"></ul>
    </div>

    <p><a href="<?= $adminUrl ?>">&larr; 回後台</a></p>
  </div>

  <script>
    const csrf = <?= json_encode($csrf) ?>;
    // 跨端點請求一律用絕對 base：這頁可能是從 <base>/thumbfix 路徑進來的，相對 ?api= 會被路徑路由搶走
    const BASE = <?= json_encode($origin . $basePath) ?>;
    const THUMB_MAX = 640, THUMB_Q = 0.78;   // 跟前端上傳的縮圖規格一致
    const go = document.getElementById('go');
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');

    function addResult(label, ok, reason) {
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.textContent = label;
      const state = document.createElement('span');
      state.className = ok ? 'ok' : 'no';
      state.textContent = (ok ? '✓ ' : '— ') + reason;
      li.appendChild(name);
      li.appendChild(state);
      resultsEl.appendChild(li);
    }

    // canvas.toBlob 對不支援的格式會靜默退回 PNG（又大又慢），所以驗 type 不符就改用 JPEG
    async function makeThumb(blob) {
      const bmp = await createImageBitmap(blob, { imageOrientation: 'from-image' });
      let w = bmp.width, h = bmp.height;
      if (Math.max(w, h) > THUMB_MAX) { const s = THUMB_MAX / Math.max(w, h); w = Math.round(w * s); h = Math.round(h * s); }
      const cv = document.createElement('canvas'); cv.width = w; cv.height = h;
      cv.getContext('2d').drawImage(bmp, 0, 0, w, h);
      let out = await new Promise(r => cv.toBlob(r, 'image/webp', THUMB_Q));
      if (!out || out.type !== 'image/webp') out = await new Promise(r => cv.toBlob(r, 'image/jpeg', 0.8));
      if (!out) throw new Error('encode failed');
      return out;
    }

    go.addEventListener('click', async () => {
      go.disabled = true;
      resultsEl.innerHTML = '';
      statusEl.textContent = '掃描中…';
      const project = document.getElementById('project').value;
      try {
        const fd = new FormData();
        fd.append('action', 'list');
        fd.append('csrf', csrf);
        fd.append('project', project);
        const res = await fetch(BASE + '?api=thumbfix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusEl.textContent = '錯誤：' + (j.error || res.status); go.disabled = false; return; }
        const items = j.items || [];
        if (!items.length) { statusEl.textContent = '這個專案的投稿都已經有縮圖，不需要補'; go.disabled = false; return; }
        let done = 0, fail = 0;
        for (const it of items) {
          statusEl.textContent = '補產中… ' + (done + fail + 1) + ' / ' + items.length;
          try {
            const pr = await fetch(BASE + '?api=photo&f=' + encodeURIComponent(it.photo));
            if (!pr.ok) throw new Error('原圖載入失敗（HTTP ' + pr.status + '）');
            const thumb = await makeThumb(await pr.blob());
            const sfd = new FormData();
            sfd.append('action', 'save');
            sfd.append('csrf', csrf);
            sfd.append('project', project);
            sfd.append('id', it.id);
            sfd.append('thumb', thumb, 'thumb.webp');
            // 照片多時可能碰到限流（429）：照 Retry-After 等一下再試，不直接放棄這張
            let sr, sj;
            for (let attempt = 0; attempt < 5; attempt++) {
              sr = await fetch(BASE + '?api=thumbfix', { method: 'POST', body: sfd });
              if (sr.status !== 429) break;
              const wait = parseInt(sr.headers.get('Retry-After') || '10', 10) || 10;
              statusEl.textContent = '流量限制，等 ' + wait + ' 秒後續傳… ' + (done + fail + 1) + ' / ' + items.length;
              await new Promise(r => setTimeout(r, wait * 1000));
            }
            sj = await sr.json().catch(() => ({}));
            if (!sr.ok || !sj.ok) throw new Error(sj.error || ('HTTP ' + sr.status));
            done++;
            addResult(it.label, true, '已補上縮圖');
          } catch (e) {
            fail++;
            addResult(it.label, false, '失敗：' + (e.message || e));
          }
        }
        statusEl.textContent = '完成：成功 ' + done + ' 張' + (fail ? '、失敗 ' + fail + ' 張（可重新掃描再試，已成功的不會重做）' : '');
      } catch (e) {
        statusEl.textContent = '連線失敗，請稍後再試';
      }
      go.disabled = false;
    });
  </script>
</body>

</html>
