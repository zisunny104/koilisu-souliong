<?php
// 維護工具：修復投稿缺少相機 EXIF 資訊的情況（含舊版編輯流程曾誤把 exif 欄位清空的既有投稿）。
// 根本原因已在 editentry.php 修正（編輯不再清空 exif，改沿用原始值），這支檔案是常駐的事後補救工具。
// 兩種修復方式（都採欄位級補齊：現有值優先、只補缺少的欄位，整組遺失或只缺光圈/焦段等部分欄位皆可修）：
//  1) 直接修復（優先用這個）：編輯流程的 bug 只會清空「編輯版本」自己的 exif 欄位，被編輯的原始投稿本身從未被動過、
//     exif 仍完好保留在同一份資料裡；只要順著編輯版本的 edit_of 找回原始投稿，把 exif 複製過去即可，不需要使用者做任何事。
//  2) 上傳原始檔比對（備援）：在原始投稿本身 exif 缺漏（例如上傳當下就沒存到、或只存到部分欄位）時使用，
//     由管理者上傳當初的原始照片檔，瀏覽器端用 exifr 讀出 EXIF 後只送出時間／座標／相機欄位（不上傳整張照片、不新增投稿），
//     伺服器用「時間 + 座標」比對現有缺 exif 的投稿，找不到相符的就略過、絕不亂猜配對。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 還原 app 掛載根目錄，組「回後台」連結用──不能用相對的 ?api=admin：
// 這支工具本身就是走 /exiffix 這個路徑進來的，若後台是用 /<project>/manager 這種路徑式網址開的，
// 相對連結會被路由誤判成同一個專案頁面，回不去後台首頁。
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

function exiffix_dist_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
function exiffix_clean_str(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s));
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}

// ── 方式一：直接從原始投稿複製 exif（POST，JSON 回應）──
// 編輯版本（edit_of 指向原始投稿 id）自己的 exif 若是空的，而它指向的原始投稿有 exif，直接複製過去。
// 這是最主要的修復路徑：多數「編輯後相機資訊不見」的案例，資料本來就沒真的丟，只是編輯版本自己那筆沒有而已。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autofix') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => '憑證失效，請重新整理頁面'], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    $all = store_all($cfg, $project);
    $byId = [];
    foreach ($all as $r) { if (isset($r['id'])) $byId[(string)$r['id']] = $r; }

    $results = [];
    foreach ($all as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['edit_of'])) continue;      // 只處理編輯版本，原始投稿本身不需要（也不該）被這條路徑動到
        $orig = $byId[(string)$r['edit_of']] ?? null;
        if (!$orig || empty($orig['exif']) || !is_array($orig['exif'])) continue;   // 原始投稿自己也沒有 exif，這條路徑幫不上忙，留給上傳比對
        $cur = is_array($r['exif'] ?? null) ? $r['exif'] : [];
        $merged = $cur + $orig['exif'];          // 現有欄位優先、只補缺少的欄位（例如缺焦段/光圈），絕不覆蓋既有值
        if ($merged == $cur) continue;           // 沒有可補的欄位就不動
        $patched = store_patch($cfg, $project, (string)$r['id'], ['exif' => $merged]);
        $results[] = [
            'name' => '第 ' . ($r['item_num'] ?? '?') . ' 號・' . substr((string)($r['created_at'] ?? ''), 0, 16),
            'ok' => (bool)$patched,
            'item_num' => $r['item_num'] ?? null,
            'reason' => $patched ? ($cur ? '已補齊缺少的相機欄位' : '已從原始投稿複製相機資訊') : '修補失敗（寫入錯誤）',
        ];
    }
    json_out(['ok' => true, 'results' => $results]);
}

// ── 方式二：上傳原始檔比對並修補（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'match') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => '憑證失效，請重新整理頁面'], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) {
        json_out(['error' => 'bad project'], 400);
    }
    $candidates = json_decode((string)($_POST['candidates'] ?? '[]'), true);
    if (!is_array($candidates)) $candidates = [];

    // 候選比對池：所有有拍攝時間的照片投稿。比對到之後只「補缺少的欄位」、絕不覆蓋已有值，
    // 所以 exif 完整的投稿就算被比對到也不會被動到（會回報「已完整」）；
    // 這讓 exif 整組遺失、或只缺部分欄位（如焦段/光圈）的投稿都能靠上傳原始檔補齊。
    $pool = [];
    foreach (store_all($cfg, $project) as $r) {
        if (($r['kind'] ?? 'photo') !== 'photo') continue;
        if (empty($r['photo_time'])) continue;
        $pool[] = $r;
    }

    $results = [];
    foreach ($candidates as $c) {
        $name = (string)($c['name'] ?? '');
        $t = isset($c['time']) ? strtotime((string)$c['time']) : false;
        $lat = isset($c['lat']) && is_numeric($c['lat']) ? (float)$c['lat'] : null;
        $lon = isset($c['lon']) && is_numeric($c['lon']) ? (float)$c['lon'] : null;
        if ($t === false) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => '這張檔案沒有可用的拍攝時間，已略過'];
            continue;
        }

        $best = null;
        $bestScore = null;
        foreach ($pool as $r) {
            $rt = strtotime((string)$r['photo_time']);
            if ($rt === false) continue;
            $dt = abs($rt - $t);
            if ($dt > 120) continue;   // 時間容許 ±2 分鐘（同一次拍攝，時鐘略有誤差）
            $d = null;
            if ($lat !== null && $lon !== null && isset($r['lat'], $r['lon'])) {
                $d = exiffix_dist_m($lat, $lon, (float)$r['lat'], (float)$r['lon']);
                if ($d > 300) continue;   // 座標容許 300 公尺（投稿後可能被拖曳校正過位置）
            }
            $score = $dt + ($d !== null ? $d / 50 : 0);   // 時間為主、座標為輔的綜合分數，取最接近者
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        if (!$best) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => '找不到時間／座標相符的投稿，已略過'];
            continue;
        }

        $exif = [];
        foreach (['make', 'model', 'lens', 'sw'] as $k) {
            if (!empty($c[$k])) {
                $v = exiffix_clean_str((string)$c[$k], 60);
                if ($v !== null) $exif[$k] = $v;
            }
        }
        foreach (['f', 'exp', 'focal'] as $k) {
            if (isset($c[$k]) && is_numeric($c[$k])) $exif[$k] = (float)$c[$k];
        }
        if (isset($c['iso']) && is_numeric($c['iso'])) $exif['iso'] = (int)$c['iso'];
        if (!$exif) {
            $results[] = ['name' => $name, 'ok' => false, 'reason' => '這張原始檔沒有可用的相機資訊，已略過'];
            continue;
        }

        $cur = is_array($best['exif'] ?? null) ? $best['exif'] : [];
        $merged = $cur + $exif;   // 現有欄位優先、只補缺少的欄位，絕不覆蓋既有值
        if ($merged == $cur) {
            // 沒有可補的欄位：不動資料、也不從池中移除（讓它仍可被其他更相符的檔案比對）
            $results[] = ['name' => $name, 'ok' => false, 'item_num' => $best['item_num'] ?? null, 'reason' => '比對到第 ' . ($best['item_num'] ?? '?') . ' 號，但相機資訊已完整，無需修補'];
            continue;
        }

        // 用過的紀錄從池中移除，避免同一批裡不同照片搶配到同一筆投稿
        $pool = array_values(array_filter($pool, fn($r) => ($r['id'] ?? null) !== ($best['id'] ?? null)));

        $patched = store_patch($cfg, $project, (string)$best['id'], ['exif' => $merged]);
        $results[] = [
            'name' => $name,
            'ok' => (bool)$patched,
            'item_num' => $best['item_num'] ?? null,
            'reason' => $patched ? ($cur ? '已補齊缺少的相機欄位' : '已修補相機資訊') : '修補失敗（寫入錯誤）',
        ];
    }
    json_out(['ok' => true, 'results' => $results]);
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
  <title>修復相機資訊 · Souliong</title>
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

    select,
    input[type=file] {
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
    <h1><i></i> 修復相機資訊</h1>
    <div class="warn">只會補上「缺少的相機資訊欄位」（整組遺失或只缺光圈／焦段等部分欄位都能補），絕不覆蓋已有的值；找不到相符對象就會略過，不會亂猜配對。</div>

    <div class="card">
      <label>選擇專案</label>
      <select id="project">
        <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
      </select>
      <div class="hint" style="margin-bottom:10px">方式一：多數「編輯後相機資訊不見」的情況，資料其實還在——原始投稿本身沒被動過，只是它的編輯版本自己那筆是空的。按下面按鈕會直接從原始投稿複製過去，不需要上傳任何檔案，立即生效。</div>
      <button id="goAuto">直接由原始版本修復（不需上傳檔案）</button>
      <div class="hint" id="statusAuto" style="margin-top:8px"></div>
      <ul id="resultsAuto"></ul>
    </div>

    <div class="card">
      <div class="hint" style="margin-bottom:10px">方式二（備援）：只有在原始投稿本身就沒有相機資訊時才需要——上傳當初的原始照片檔（僅在瀏覽器本機讀取 EXIF 時間／座標／相機資訊做比對，不會上傳整張照片、也不會新增投稿），伺服器用「時間 + 座標」比對現有缺 exif 的投稿。</div>
      <label>選擇當初的原始照片檔（可一次選多張）</label>
      <input type="file" id="files" accept="image/*" multiple>
      <button id="go" disabled>開始比對並修補</button>
      <div class="hint" id="status" style="margin-top:8px"></div>
      <ul id="results"></ul>
    </div>

    <p><a href="<?= $adminUrl ?>">&larr; 回後台</a></p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
  <script>
    const csrf = <?= json_encode($csrf) ?>;
    function renderResults(el, list) {
      el.innerHTML = '';
      list.forEach(r => {
        const li = document.createElement('li');
        const label = document.createElement('span');
        label.textContent = r.name;
        const state = document.createElement('span');
        state.className = r.ok ? 'ok' : 'no';
        state.textContent = r.ok ? ('✓ ' + r.reason + (r.item_num != null ? '（第 ' + r.item_num + ' 號）' : '')) : ('— ' + r.reason);
        li.appendChild(label);
        li.appendChild(state);
        el.appendChild(li);
      });
    }

    const goAuto = document.getElementById('goAuto');
    const statusAutoEl = document.getElementById('statusAuto');
    const resultsAutoEl = document.getElementById('resultsAuto');
    goAuto.addEventListener('click', async () => {
      goAuto.disabled = true;
      statusAutoEl.textContent = '修復中…';
      resultsAutoEl.innerHTML = '';
      try {
        const fd = new FormData();
        fd.append('action', 'autofix');
        fd.append('csrf', csrf);
        fd.append('project', document.getElementById('project').value);
        const res = await fetch('?api=exiffix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusAutoEl.textContent = '錯誤：' + (j.error || res.status); goAuto.disabled = false; return; }
        statusAutoEl.textContent = j.results.length ? ('完成，共處理 ' + j.results.length + ' 筆') : '沒有需要修復的編輯版本（原始投稿有 exif 但編輯版本沒有的情況）';
        renderResults(resultsAutoEl, j.results);
      } catch (e) {
        statusAutoEl.textContent = '連線失敗，請稍後再試';
      }
      goAuto.disabled = false;
    });

    const filesInput = document.getElementById('files');
    const go = document.getElementById('go');
    const statusEl = document.getElementById('status');
    const resultsEl = document.getElementById('results');
    let picked = [];

    filesInput.addEventListener('change', () => {
      picked = Array.from(filesInput.files || []);
      go.disabled = picked.length === 0;
      statusEl.textContent = picked.length ? ('已選 ' + picked.length + ' 張，按下方按鈕開始讀取比對') : '';
    });

    go.addEventListener('click', async () => {
      go.disabled = true;
      resultsEl.innerHTML = '';
      statusEl.textContent = '讀取 EXIF 中…';
      const candidates = [];
      for (const file of picked) {
        try {
          const m = await exifr.parse(file, ['DateTimeOriginal', 'CreateDate', 'Make', 'Model', 'LensModel', 'FNumber', 'ExposureTime', 'ISO', 'FocalLength', 'Software']);
          const g = await exifr.gps(file).catch(() => null);
          const time = m && (m.DateTimeOriginal || m.CreateDate);
          candidates.push({
            name: file.name,
            time: time ? new Date(time).toISOString() : null,
            lat: g && typeof g.latitude === 'number' ? g.latitude : null,
            lon: g && typeof g.longitude === 'number' ? g.longitude : null,
            make: m && m.Make || null,
            model: m && m.Model || null,
            lens: m && m.LensModel || null,
            f: m && m.FNumber || null,
            exp: m && m.ExposureTime || null,
            iso: m && m.ISO || null,
            focal: m && m.FocalLength || null,
            sw: m && m.Software || null,
          });
        } catch (e) {
          candidates.push({ name: file.name, time: null });
        }
      }
      statusEl.textContent = '比對並修補中…';
      try {
        const fd = new FormData();
        fd.append('action', 'match');
        fd.append('csrf', csrf);
        fd.append('project', document.getElementById('project').value);
        fd.append('candidates', JSON.stringify(candidates));
        const res = await fetch('?api=exiffix', { method: 'POST', body: fd });
        const j = await res.json();
        if (!res.ok || !j.ok) { statusEl.textContent = '錯誤：' + (j.error || res.status); go.disabled = false; return; }
        statusEl.textContent = '完成，共 ' + j.results.length + ' 張';
        renderResults(resultsEl, j.results);
      } catch (e) {
        statusEl.textContent = '連線失敗，請稍後再試';
      }
      go.disabled = false;
    });
  </script>
</body>

</html>
