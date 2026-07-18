<?php
/**
 * Souliong 地圖清單首頁。背景為隨機一張地圖（不可互動），內容浮在其上。
 */
$cfg = include __DIR__ . '/config.php';
$appName = $_APP['name'] ?? basename(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$i = strpos($path, '/' . $appName);
$base = $i !== false ? substr($path, 0, $i + strlen($appName) + 1) . '/' : dirname($path);
$base = str_replace('\\', '/', $base);
$base = '/' . trim($base, '/');
$base = ($base === '/') ? '/' : $base . '/';

$maps = [];
foreach (glob(__DIR__ . '/projects/*/meta.json') as $mf) {
    $m = json_decode(file_get_contents($mf), true);
    if (!is_array($m)) continue;
    $maps[] = [
        'id' => $m['id'] ?? basename(dirname($mf)),
        'title' => $m['title'] ?? '',
        'subtitle' => $m['subtitle'] ?? '',
        'desc' => $m['desc'] ?? '',
        'center' => $m['center'] ?? [23.9, 120.7],
        'zoom' => $m['zoom'] ?? 14,
    ];
}
$b = htmlspecialchars($base, ENT_QUOTES);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$bg = $maps ? $maps[array_rand($maps)] : ['center' => [23.9, 120.7], 'zoom' => 14];
?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Souliong 循跡</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root{ --bg:#fafafa; --fg:#1b1b1d; --muted:#6b6b70; --line:#e7e7ea; --card:rgba(255,255,255,.86); --accent:#1b1b1d; --accent-fg:#fff; --r-lg:22px; --scrim:rgba(250,250,250,.72); }
@media (prefers-color-scheme: dark){ :root{ --bg:#141416; --fg:#f1f1f3; --muted:#9c9ca3; --line:#2e2e31; --card:rgba(29,29,32,.86); --accent:#f1f1f3; --accent-fg:#141416; --scrim:rgba(20,20,22,.72); } }
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--fg);-webkit-font-smoothing:antialiased;min-height:100vh}
#bgmap{position:fixed;inset:0;z-index:0;pointer-events:none}
.scrim{position:fixed;inset:0;z-index:1;background:var(--scrim);-webkit-backdrop-filter:blur(1.5px);backdrop-filter:blur(1.5px)}
.page{position:relative;z-index:2}
.wrap{max-width:940px;margin:0 auto;padding:0 20px 24px}
header{text-align:center;padding:60px 20px 26px}
header .logo{font-size:2rem;font-weight:800;letter-spacing:-.02em}
header .tag{color:var(--muted);font-size:0.875rem;margin-top:10px;line-height:1.7}
.bar{display:flex;justify-content:center;gap:10px;margin:24px 0 6px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:999px;padding:10px 18px;font-size:0.875rem;font-weight:600;background:var(--card);color:var(--fg);cursor:pointer;text-decoration:none;transition:transform .15s,box-shadow .15s;-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
.btn.primary{background:var(--accent);color:var(--accent-fg);border-color:var(--accent)}
.btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.15)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;margin-top:26px}
.card{display:flex;flex-direction:column;text-decoration:none;color:inherit;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;transition:transform .16s,box-shadow .16s;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.card:hover{transform:translateY(-3px);box-shadow:0 16px 44px rgba(0,0,0,.18)}
.card .cover{height:120px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;color:#fff;background:linear-gradient(135deg,#2e9e5b,#2f7ec6 55%,#e34a6f)}
.card .body{padding:16px 18px}
.card h2{margin:0 0 3px;font-size:1.125rem;font-weight:800}
.card .st{color:var(--muted);font-size:0.7813rem;margin-bottom:9px}
.card .desc{font-size:0.8125rem;line-height:1.6}
.empty{text-align:center;color:var(--muted);padding:50px;font-size:0.875rem}
footer{text-align:center;color:var(--muted);font-size:0.75rem;padding:22px;line-height:1.8}
footer a{color:inherit}
</style>
</head>
<body>
<div id="bgmap"></div>
<div class="scrim"></div>
<div class="page">
<header>
  <div class="logo" id="logo">Souliong 循跡<span id="logoShape" class="logo-shape"></span></div>
  <div class="tag">循著地方留下的痕跡，用地圖探索、記錄一座城市。<br>每個地方都留下痕跡，每一道痕跡都有故事。</div>
  <div class="bar">
    <?php if ($maps): ?><a class="btn primary" id="randomBtn"><i class="fa-solid fa-shuffle"></i> 隨機探索</a><?php endif; ?>
    <a class="btn" href="https://github.com/zisunny104/koilisu-souliong" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> 原始碼</a>
  </div>
</header>
<div class="wrap">
  <?php if (!$maps): ?>
    <div class="empty">尚未有地圖。在 <code>projects/</code> 底下新增資料夾與 meta.json 即可。</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($maps as $m): ?>
        <a class="card" href="<?= $b . $esc($m['id']) ?>">
          <div class="cover"><i class="fa-solid fa-map-location-dot"></i></div>
          <div class="body"><h2><?= $esc($m['title']) ?></h2><div class="st"><?= $esc($m['subtitle']) ?></div><div class="desc"><?= $esc($m['desc']) ?></div></div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<footer>
  <a href="https://github.com/zisunny104/koilisu-souliong" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i> GitHub</a>
  ・ <a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>
  ・ &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> 貢獻者
  ・ <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>
  ・ <a href="<?= $b ?>">Souliong</a>
  ・ <a href="https://toka.dev" target="_blank" rel="noopener">prjToka</a><br>
  開放的地方探索地圖平台 ・ <a href="<?= $b ?>privacy">隱私與資料說明</a>
</footer>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
var BASE = <?= json_encode($base) ?>, IDS = <?= json_encode(array_map(fn($m) => $m['id'], $maps)) ?>;
var BG = <?= json_encode($bg) ?>;
(function(){
  var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  var url = 'https://{s}.basemaps.cartocdn.com/' + (dark ? 'dark_all' : 'rastertiles/voyager') + '/{z}/{x}/{y}{r}.png';
  var map = L.map('bgmap', { zoomControl:false, attributionControl:false, dragging:false, scrollWheelZoom:false, doubleClickZoom:false, boxZoom:false, keyboard:false, touchZoom:false, tap:false, inertia:false, fadeAnimation:true })
    .setView(BG.center || [23.9,120.7], BG.zoom || 14);
  L.tileLayer(url, { maxZoom:20, subdomains:'abcd', detectRetina:true }).addTo(map);
  var rb = document.getElementById('randomBtn');
  if (rb) rb.onclick = function(){ location.href = BASE + IDS[Math.floor(Math.random()*IDS.length)]; };
})();
</script>
</body>
</html>
