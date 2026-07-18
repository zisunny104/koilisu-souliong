<?php
// 管理頁：?api=admin&token=你的密碼[&project=xxx]
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');   // 防 PIN 暴力嘗試

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if (!hash_equals($cfg['admin_token'], (string)$token)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h3>403 — 需要正確的 token</h3>';
    exit;
}

// 站方刪除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    if ($project !== '' && $id !== '') {
        $removed = store_delete($cfg, $project, $id);
        if ($removed && !empty($removed['photo'])) { @unlink($cfg['photos_dir'] . '/' . $removed['photo']); }
    }
    $q = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
    header('Location: ?api=admin&token=' . urlencode($token) . ($q !== '' ? '&project=' . urlencode($q) : ''));
    exit;
}
// 重新產生投稿碼（刪除碼檔，下次自動產生新碼）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rotate') {
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if ($project !== '') { @unlink(rtrim($cfg['store_dir'], '/\\') . '/' . $project . '.code.txt'); }
    header('Location: ?api=admin&token=' . urlencode($token));
    exit;
}

$onlyProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$allProjects = store_projects($cfg);
foreach (glob($cfg['projects_dir'] . '/*', GLOB_ONLYDIR) as $d) { $pid = basename($d); if (!in_array($pid, $allProjects, true)) $allProjects[] = $pid; }
$projects = $onlyProject !== '' ? [$onlyProject] : $allProjects;

$rows = [];
foreach ($projects as $p) {
    foreach (store_all($cfg, $p) as $r) { $r['project'] = $p; $rows[] = $r; }
}
usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));

$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$basePath = preg_replace('#\?.*$#', '', $_SERVER['REQUEST_URI'] ?? '/');  // 去掉 query，得到 app 目錄路徑

header('Content-Type: text/html; charset=utf-8');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$short = fn($s) => $s ? substr((string)$s, 0, 8) : '';
?><!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Souliong 管理</title>
<style>
body{font-family:system-ui,"Microsoft JhengHei",sans-serif;margin:20px;background:#fafafa;color:#222;font-size:13px}
h2,h3{margin:18px 0 8px}
table{border-collapse:collapse;width:100%;background:#fff;margin-bottom:24px}
th,td{border:1px solid #ddd;padding:6px 8px;vertical-align:top}
th{background:#f0f0f0}
img{max-width:110px;border-radius:4px;display:block}
.del{color:#c0392b}
code{background:#eee;padding:1px 5px;border-radius:4px}
.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:12px 14px;margin-bottom:10px}
.big{font-size:22px;font-weight:800;letter-spacing:2px}
.mono{font-family:ui-monospace,Consolas,monospace}
small{color:#666}
</style></head><body>
<h2>Souliong 管理後台</h2>

<h3>投稿碼（限特定人上傳）</h3>
<?php foreach ($allProjects as $p):
    $meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $p . '/meta.json'), true);
    $code = project_code($cfg, $p, is_array($meta) ? $meta : null);
    if ($code === '') continue;
    $invite = $origin . $basePath . $p . '?code=' . $code;
?>
<div class="card" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
  <canvas class="qr" data-url="<?= $esc($invite) ?>" width="132" height="132" style="border-radius:8px"></canvas>
  <div style="flex:1;min-width:220px">
    <b><?= $esc($p) ?></b>　目前投稿碼：<span class="big mono"><?= $esc($code) ?></span>
    <form method="post" style="display:inline;margin-left:12px">
      <input type="hidden" name="token" value="<?= $esc($token) ?>"><input type="hidden" name="action" value="rotate"><input type="hidden" name="project" value="<?= $esc($p) ?>">
      <button onclick="return confirm('重新產生會讓舊碼與舊邀請連結失效，確定？')">重新產生</button>
    </form>
    <div style="margin-top:6px"><small>邀請連結（傳給組員、或給他們掃左邊 QR，一點即解鎖可上傳）：</small><br><span class="mono"><?= $esc($invite) ?></span></div>
  </div>
</div>
<?php endforeach; ?>

<h3>統計摘要</h3>
<?php foreach ($projects as $p): $s = stats_read($cfg, $p); if (!$s) continue;
    $s['points'] = $s['points'] ?? []; $s['cameras'] = $s['cameras'] ?? [];
    arsort($s['points']); arsort($s['cameras']); ?>
<div class="card">
  <b><?= $esc($p) ?></b>：瀏覽 <?= (int)($s['views'] ?? 0) ?>、工作階段 <?= (int)($s['sessions'] ?? 0) ?>、上傳 <?= (int)($s['uploads'] ?? 0) ?>
  ・裝置 <?= $esc(json_encode($s['device'] ?? [], JSON_UNESCAPED_UNICODE)) ?>
  ・功能 <?= $esc(json_encode($s['features'] ?? [], JSON_UNESCAPED_UNICODE)) ?>
  <br><small>熱門點位(前5)：<?= $esc(json_encode(array_slice($s['points'], 0, 5, true), JSON_UNESCAPED_UNICODE)) ?>
  ・相機：<?= $esc(json_encode(array_slice($s['cameras'], 0, 5, true), JSON_UNESCAPED_UNICODE)) ?></small>
</div>
<?php endforeach; ?>

<h3>投稿紀錄（共 <?= count($rows) ?> 筆）</h3>
<table><tr><th>項目</th><th>編號</th><th>類型</th><th>照片</th><th>暱稱</th><th>內容</th><th>相機</th><th>時間</th><th>座標</th><th>owner/來源</th><th>上傳</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= $esc($r['project']) ?></td>
  <td><?= $esc($r['item_num'] ?? '') ?></td>
  <td><?= $esc($r['kind'] ?? 'photo') ?></td>
  <td><?= !empty($r['photo']) ? '<a href="?api=photo&f=' . $esc($r['photo']) . '" target="_blank"><img src="?api=photo&f=' . $esc($r['photo']) . '"></a>' : '' ?></td>
  <td><?= $esc($r['name'] ?? '') ?></td>
  <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
  <td><small><?= $esc(is_array($r['exif'] ?? null) ? json_encode($r['exif'], JSON_UNESCAPED_UNICODE) : '') ?></small></td>
  <td><small><?= $esc($r['photo_time'] ?? '') ?></small></td>
  <td><small><?= $esc($r['lat'] ?? '') ?>,<?= $esc($r['lon'] ?? '') ?> (<?= $esc($r['loc_source'] ?? '') ?>)</small></td>
  <td><small class="mono">o:<?= $esc($short($r['owner_hash'] ?? '')) ?><br>s:<?= $esc($short($r['src_hash'] ?? '')) ?></small></td>
  <td><small><?= $esc($r['created_at'] ?? '') ?></small></td>
  <td><form method="post" onsubmit="return confirm('確定刪除？')">
    <input type="hidden" name="token" value="<?= $esc($token) ?>"><input type="hidden" name="action" value="delete">
    <input type="hidden" name="project" value="<?= $esc($r['project']) ?>"><input type="hidden" name="id" value="<?= $esc($r['id'] ?? '') ?>">
    <button class="del">刪除</button></form></td>
</tr>
<?php endforeach; ?>
</table>
<p><small>owner(o) 同源可群組追蹤；src(s) 為加鹽 IP 雜湊，同一 owner 出現多個不同 src 可能是投稿碼外流/冒名。</small></p>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>
document.querySelectorAll('canvas.qr').forEach(function(c){
  if (window.QRCode) QRCode.toCanvas(c, c.dataset.url, { width:132, margin:1 }, function(){});
});
</script>
</body></html>
