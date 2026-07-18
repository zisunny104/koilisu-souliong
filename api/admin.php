<?php
// 管理頁：?api=admin&token=你的PIN[&project=xxx]
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');   // 防 PIN 暴力嘗試

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if (!hash_equals($cfg['admin_token'], (string)$token)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><style>body{font-family:system-ui,"Microsoft JhengHei",sans-serif;display:grid;place-items:center;height:100vh;margin:0;background:#151517;color:#f1f1f3}</style><h3>403 · 需要正確的 PIN</h3>';
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
// 重新產生投稿碼
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
$basePath = preg_replace('#\?.*$#', '', $_SERVER['REQUEST_URI'] ?? '/');

header('Content-Type: text/html; charset=utf-8');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$short = fn($s) => $s ? substr((string)$s, 0, 8) : '—';
?><!DOCTYPE html>
<html lang="zh-Hant"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Souliong 管理</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{color-scheme:light dark;--bg:#f6f6f7;--fg:#1b1b1d;--muted:#6b6b70;--line:#e7e7ea;--card:#fff;--accent:#1b1b1d;--accent-fg:#fff;--r-lg:20px;--r-md:13px;--sh:0 6px 24px rgba(0,0,0,.08);--danger:#c0392b}
@media (prefers-color-scheme:dark){:root{--bg:#111113;--fg:#f1f1f3;--muted:#9c9ca3;--line:#2b2b2f;--card:#1c1c1f;--accent:#f1f1f3;--accent-fg:#151517;--sh:0 6px 24px rgba(0,0,0,.4);--danger:#ff6b6b}}
*{box-sizing:border-box}
body{margin:0;font-family:"Noto Sans TC","PingFang TC","Microsoft JhengHei",system-ui,sans-serif;background:var(--bg);color:var(--fg);-webkit-font-smoothing:antialiased}
.wrap{max-width:1000px;margin:0 auto;padding:28px 20px 60px}
h1{font-size:22px;font-weight:800;letter-spacing:-.01em;margin:0 0 4px;display:flex;align-items:center;gap:10px}
h1 .sub{font-size:12px;font-weight:500;color:var(--muted)}
h2{font-size:13px;font-weight:700;color:var(--muted);letter-spacing:.06em;text-transform:uppercase;margin:30px 0 12px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--sh)}
.code-card{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:16px 18px;margin-bottom:12px}
.code-card canvas{border-radius:12px;background:#fff;padding:6px;flex:none}
.code-info{flex:1;min-width:220px}
.code-info .pill{display:inline-block;font-size:12px;color:var(--muted);margin-bottom:6px}
.big{font-family:ui-monospace,Consolas,monospace;font-size:30px;font-weight:800;letter-spacing:4px}
.invite{font-family:ui-monospace,Consolas,monospace;font-size:12px;color:var(--muted);word-break:break-all;margin-top:8px;background:var(--bg);padding:8px 10px;border-radius:10px;border:1px solid var(--line)}
.btn{border:1px solid var(--line);border-radius:999px;background:var(--card);color:var(--fg);font-size:13px;font-weight:600;padding:8px 14px;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn:hover{background:var(--bg)}
.btn.danger{color:var(--danger)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:var(--r-md);padding:14px 16px}
.tile .n{font-size:26px;font-weight:800}
.tile .l{font-size:12px;color:var(--muted);margin-top:2px}
.break{font-size:12px;color:var(--muted);margin-top:10px;line-height:1.7}
.break b{color:var(--fg)}
.tablewrap{overflow-x:auto;border:1px solid var(--line);border-radius:var(--r-lg);background:var(--card)}
table{border-collapse:collapse;width:100%;font-size:13px;min-width:820px}
th{background:var(--bg);position:sticky;top:0;text-align:left;font-weight:700;color:var(--muted);font-size:11px;letter-spacing:.04em;text-transform:uppercase}
th,td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}
tr:hover td{background:var(--bg)}
td img{width:80px;height:80px;object-fit:cover;border-radius:10px;display:block}
.mono{font-family:ui-monospace,Consolas,monospace;font-size:11px;color:var(--muted)}
.tag{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:999px;background:var(--bg);border:1px solid var(--line)}
.hint{font-size:12px;color:var(--muted);margin-top:14px;line-height:1.7}
.iconbtn{background:none;border:none;color:var(--danger);cursor:pointer;font-size:15px}
</style></head>
<body><div class="wrap">
<h1><i class="fa-solid fa-gauge-high"></i> Souliong 管理後台 <span class="sub"><?= count($rows) ?> 筆投稿</span></h1>

<h2>投稿碼 · 邀請</h2>
<?php foreach ($allProjects as $p):
    $meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $p . '/meta.json'), true);
    $code = project_code($cfg, $p, is_array($meta) ? $meta : null);
    if ($code === '') continue;
    $invite = $origin . $basePath . $p . '?code=' . $code;
?>
<div class="card code-card">
  <canvas class="qr" data-url="<?= $esc($invite) ?>" width="120" height="120"></canvas>
  <div class="code-info">
    <div class="pill"><i class="fa-solid fa-map-location-dot"></i> <?= $esc($meta['title'] ?? $p) ?>（<?= $esc($p) ?>）· 目前投稿碼</div>
    <div class="big"><?= $esc($code) ?></div>
    <div class="invite"><?= $esc($invite) ?></div>
  </div>
  <form method="post">
    <input type="hidden" name="token" value="<?= $esc($token) ?>"><input type="hidden" name="action" value="rotate"><input type="hidden" name="project" value="<?= $esc($p) ?>">
    <button class="btn danger" onclick="return confirm('重新產生會讓舊碼與舊邀請連結失效，確定？')"><i class="fa-solid fa-rotate"></i> 重新產生</button>
  </form>
</div>
<?php endforeach; ?>

<h2>統計摘要</h2>
<?php foreach ($projects as $p): $s = stats_read($cfg, $p); if (!$s) continue;
    $s['points'] = $s['points'] ?? []; $s['cameras'] = $s['cameras'] ?? [];
    arsort($s['points']); arsort($s['cameras']);
    $fmtTop = function ($arr, $n = 5) { $out = []; foreach (array_slice($arr, 0, $n, true) as $k => $v) $out[] = $k . '·' . $v; return $out ? implode('、', $out) : '—'; };
?>
<div style="margin-bottom:14px">
  <div style="font-size:13px;font-weight:700;margin-bottom:8px"><?= $esc($p) ?></div>
  <div class="stats-grid">
    <div class="tile"><div class="n"><?= (int)($s['views'] ?? 0) ?></div><div class="l">瀏覽</div></div>
    <div class="tile"><div class="n"><?= (int)($s['sessions'] ?? 0) ?></div><div class="l">工作階段</div></div>
    <div class="tile"><div class="n"><?= (int)($s['uploads'] ?? 0) ?></div><div class="l">上傳</div></div>
    <div class="tile"><div class="n"><?= (int)(($s['device']['mobile'] ?? 0)) ?>/<?= (int)(($s['device']['desktop'] ?? 0)) ?></div><div class="l">手機/桌機</div></div>
  </div>
  <div class="break">熱門點位：<b><?= $esc($fmtTop($s['points'])) ?></b><br>相機：<b><?= $esc($fmtTop($s['cameras'])) ?></b>　功能：<b><?= $esc(json_encode($s['features'] ?? [], JSON_UNESCAPED_UNICODE)) ?></b></div>
</div>
<?php endforeach; ?>

<h2>投稿紀錄</h2>
<div class="tablewrap"><table>
<tr><th>項目</th><th>點</th><th>類型</th><th>照片</th><th>暱稱</th><th>內容</th><th>相機</th><th>時間</th><th>座標</th><th>o/s</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= $esc($r['project']) ?></td>
  <td><?= $esc($r['item_num'] ?? '') ?></td>
  <td><span class="tag"><?= $esc($r['kind'] ?? 'photo') ?></span></td>
  <td><?= !empty($r['photo']) ? '<a href="?api=photo&f=' . $esc($r['photo']) . '" target="_blank"><img src="?api=photo&f=' . $esc($r['photo']) . '" alt=""></a>' : '' ?></td>
  <td><?= $esc($r['name'] ?? '') ?></td>
  <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
  <td class="mono"><?= $esc(is_array($r['exif'] ?? null) ? implode(' ', array_map(fn($k, $v) => "$v", array_keys($r['exif']), $r['exif'])) : '') ?></td>
  <td class="mono"><?= $esc(substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16)) ?></td>
  <td class="mono"><?= $esc(round((float)($r['lat'] ?? 0), 4)) ?>,<?= $esc(round((float)($r['lon'] ?? 0), 4)) ?><br><?= $esc($r['loc_source'] ?? '') ?></td>
  <td class="mono"><?= $esc($short($r['owner_hash'] ?? '')) ?><br><?= $esc($short($r['src_hash'] ?? '')) ?></td>
  <td><form method="post" onsubmit="return confirm('確定刪除？')">
    <input type="hidden" name="token" value="<?= $esc($token) ?>"><input type="hidden" name="action" value="delete">
    <input type="hidden" name="project" value="<?= $esc($r['project']) ?>"><input type="hidden" name="id" value="<?= $esc($r['id'] ?? '') ?>">
    <button class="iconbtn" title="刪除"><i class="fa-solid fa-trash"></i></button></form></td>
</tr>
<?php endforeach; ?>
</table></div>
<div class="hint">o＝owner（同源可群組追蹤）、s＝加鹽 IP 雜湊；同一 owner 出現多個不同 s 可能是投稿碼外流／冒名。</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.4/build/qrcode.min.js"></script>
<script>document.querySelectorAll('canvas.qr').forEach(function(c){ if(window.QRCode) QRCode.toCanvas(c,c.dataset.url,{width:120,margin:1},function(){}); });</script>
</body></html>
