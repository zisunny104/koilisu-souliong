<?php
/**
 * Souliong 主視圖。base 路徑自動推導、資料伺服器端內嵌（框架不供應靜態檔）。
 * ?embed=1 → 精簡檢視模式（僅供瀏覽）。?p=<project> → 切換項目。
 */
$cfg = include __DIR__ . '/config.php';
$appName = $_APP['name'] ?? basename(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$needle = '/' . $appName;
$i = strpos($path, $needle);
if ($i !== false) {
    $base = substr($path, 0, $i + strlen($needle)) . '/';
} else {
    $base = dirname($path);
}
$base = str_replace('\\', '/', $base);
$base = '/' . trim($base, '/');
$base = ($base === '/') ? '/' : $base . '/';

$b       = htmlspecialchars($base, ENT_QUOTES);
$embed   = (($_GET['embed'] ?? '') === '1');
$proj    = preg_replace('/[^a-z0-9_-]/', '', $_GET['p'] ?? ($cfg['default_project'] ?? 'chairs'));
$metaF   = __DIR__ . '/projects/' . $proj . '/meta.json';
$meta    = is_file($metaF) ? json_decode(file_get_contents($metaF), true) : null;
$ptsF    = $meta ? __DIR__ . '/projects/' . $proj . '/' . ($meta['points'] ?? 'points.json') : null;
$points  = ($ptsF && is_file($ptsF)) ? json_decode(file_get_contents($ptsF), true) : [];

// 是否需要投稿碼（真正的碼在伺服器端 data/<project>.code.txt，前端拿不到）
$gated = is_array($meta) && !empty($meta['gated']);

$APP = [
    'base'    => $base,
    'project' => $proj,
    'embed'   => $embed,
    'gated'   => $gated,
    'meta'    => $meta,
    'points'  => $points,
];
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS;
?><!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Souliong 循跡</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style><?php readfile(__DIR__ . '/assets/style.css'); ?></style>
<script>try{var t=localStorage.getItem('theme');if(t==='dark'||t==='light')document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="<?= $embed ? 'embed' : '' ?>">

<div id="skeleton" aria-hidden="true">
  <div class="sk-card sk">
    <div class="sk-line" style="width:55%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-line" style="width:80%"></div>
    <div class="sk-row"><span class="sk-btn"></span><span class="sk-btn"></span></div>
  </div>
  <div class="sk-fab sk"></div>
</div>

<div id="map"></div>

<div id="controls" class="floatcard">
  <div class="ctl-head">
    <span class="brand" id="title" title="點一下看完整名稱" role="button" tabindex="0"><span class="brand-txt" id="titleTxt">Souliong 循跡</span></span>
    <span id="brandShape" class="brand-shape" aria-hidden="true"></span>
    <span class="spacer"></span>
    <button id="collapseBtn" class="icon-btn" title="收折" aria-label="收合控制面板"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
  </div>
  <div class="ctl-body" id="ctlBody">
    <div class="legend" id="legend"></div>
    <div class="ctl-row">
      <button class="btn" id="photoLayerBtn" title="顯示/隱藏投稿照片"><i class="fa-solid fa-image"></i> 投稿</button>
      <button class="btn" id="routeBtn" title="依編號串連路徑"><i class="fa-solid fa-route"></i> 路徑</button>
    </div>
    <div class="ctl-row">
      <select id="personFilter" title="篩選投稿者，看他的觀察地圖"><option value="">所有投稿者</option></select>
    </div>
    <div class="ctl-foot" id="foot"></div>
  </div>
</div>

<div id="topright" class="tr-group">
  <span id="identity" class="idchip" title="投稿者身分" role="button" tabindex="0"></span>
  <a class="icon-btn hide-in-embed" id="homeBtn" href="<?= $b ?>" title="回地圖列表" aria-label="回地圖列表"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
  <button class="icon-btn" id="shareBtn" title="分享這張地圖" aria-label="分享這張地圖"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
  <button id="embedBtn" class="icon-btn hide-in-embed" title="取得嵌入碼" aria-label="嵌入本地圖"><i class="fa-solid fa-code" aria-hidden="true"></i></button>
  <button id="themeBtn" class="icon-btn" title="切換主題（系統／淺／深）" aria-label="切換深淺主題"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button>
</div>

<button class="icon-btn mapop-btn" id="resetBtn" title="回到地圖初始位置（R）" aria-label="重置地圖視角"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i></button>

<button class="fab upload-only" id="uploadBtn"><i class="fa-solid fa-plus"></i> 上傳</button>
<button class="fab fab-unlock" id="unlockFab" style="display:none"><i class="fa-solid fa-lock"></i> 解鎖投稿</button>
<input type="file" id="pickImages" multiple hidden>
<input type="text" id="myName" hidden>

<div id="shareScreen" class="sharescreen" aria-hidden="true">
  <div class="share-card">
    <button class="icon-btn share-close" onclick="MapApp.closeShare()" aria-label="關閉"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    <div class="share-qr" id="shareQr" aria-hidden="true"></div>
    <div class="share-title" id="shareTitle"></div>
    <div class="share-sub" id="shareSub"></div>
    <div class="share-url" id="shareUrl"></div>
    <div class="dialog-actions" style="justify-content:center">
      <button class="btn primary" id="shareCopyBtn"><i class="fa-solid fa-link"></i> 複製連結</button>
      <span id="shareCopyMsg" class="hint"></span>
    </div>
  </div>
</div>

<div id="cloudWarn" class="toast" style="display:none"></div>

<div id="panel">
  <button class="p-close" onclick="MapApp.closePanel()" aria-label="關閉"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  <div class="p-head">
    <div class="cat" id="pCat"></div>
    <h2 id="pTitle"></h2>
    <div class="sub" id="pSub"></div>
  </div>
  <div class="p-body">
    <button class="btn primary upload-only" id="panelUploadBtn" style="width:100%"><i class="fa-solid fa-plus"></i> 上傳照片到這個點</button>
    <div class="sec-t">大家的紀錄（依時間）</div>
    <div id="entries"></div>
  </div>
</div>

<div id="modal">
  <div class="modal-box">
    <div class="modal-head">
      <h3>上傳照片</h3>
      <input class="name-in" id="modalName" placeholder="你的暱稱" autocomplete="off" style="width:130px">
      <button class="btn" onclick="MapApp.closeModal()">關閉</button>
    </div>
    <div class="modal-body" id="queue"></div>
    <div class="modal-foot">
      <button class="btn" id="addMoreBtn"><i class="fa-solid fa-plus"></i> 再加照片</button>
      <span class="spacer"></span>
      <button class="btn primary" id="submitAllBtn">全部送出</button>
    </div>
  </div>
</div>

<div id="embedDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b>嵌入這張地圖</b><button class="icon-btn" onclick="MapApp.closeEmbed()" aria-label="關閉"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint">貼進你的頁面即可。嵌入為「精簡檢視模式」僅供瀏覽（無上傳/編輯）；可自行調整 iframe 的高度。</div>
    <textarea id="embedCode" readonly rows="4"></textarea>
    <div class="dialog-actions"><button class="btn primary" id="copyEmbedBtn">複製</button><span id="copyMsg" class="hint"></span></div>
  </div>
</div>

<div id="unlockDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b>解鎖投稿</b><button class="icon-btn" onclick="MapApp.closeUnlock()" aria-label="關閉"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint">輸入主辦者提供的投稿碼，或掃描邀請 QR。</div>
    <input id="unlockCodeInput" class="name-in" style="width:100%;letter-spacing:8px;text-align:center;font-size:22px" placeholder="6 位數字" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" inputmode="numeric" pattern="[0-9]*" maxlength="8" aria-label="投稿碼">
    <div id="unlockMsg" class="hint" role="status"></div>
    <div class="dialog-actions">
      <button class="btn" id="scanBtn"><i class="fa-solid fa-qrcode"></i> 掃描 QR</button>
      <span class="spacer"></span>
      <button class="btn primary" id="unlockSubmit">解鎖</button>
    </div>
    <div id="scanBox" style="display:none"><video id="scanVideo" playsinline muted></video></div>
  </div>
</div>

<div id="pinDialog" class="dialog">
  <div class="dialog-box pin-box">
    <div class="dialog-head"><b>管理登入</b><button class="icon-btn" onclick="MapApp.closePin()" aria-label="關閉"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div id="pinDisplay" class="pin-display" aria-label="PIN 輸入區"></div>
    <div class="pin-pad" id="pinPad"></div>
  </div>
</div>

<div id="lb" onclick="this.style.display='none'"><img id="lbImg" alt=""><div class="cap" id="lbCap"></div></div>

<script>window.APP = <?= json_encode($APP, $jsonFlags) ?>;</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script><?php readfile(__DIR__ . '/assets/vendor/qrcode-generator.js'); ?></script>
<script><?php readfile(__DIR__ . '/assets/viewer.js'); ?></script>
</body>
</html>
