<?php
/**
 * Souliong 主視圖。base 路徑自動推導、資料伺服器端內嵌（框架不供應靜態檔）。
 * ?embed=1 → 精簡檢視模式（僅供瀏覽）。?p=<project> → 切換項目。
 */
$cfg = include __DIR__ . '/../config.php';
$appName = $_APP['name'] ?? basename(dirname(__DIR__));
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
$metaF   = __DIR__ . '/../projects/' . $proj . '/meta.json';
$meta    = is_file($metaF) ? json_decode(file_get_contents($metaF), true) : null;
$ptsF    = $meta ? __DIR__ . '/../projects/' . $proj . '/' . ($meta['points'] ?? 'points.json') : null;
$points  = ($ptsF && is_file($ptsF)) ? json_decode(file_get_contents($ptsF), true) : [];

// 是否需要投稿碼（真正的碼在伺服器端 projects/<project>/code.txt，前端拿不到）
$gated = is_array($meta) && !empty($meta['gated']);

// 已用管理 PIN 登入者（主 PIN 或此專案的 PIN）直接視為已解鎖投稿身分，不受投稿碼限制
require __DIR__ . '/../api/security.php';
require __DIR__ . '/../api/i18n.php';
$apiCfg    = require __DIR__ . '/../api/config.php';
$isManager = admin_can($apiCfg, $proj);
[$LANG, $DICT] = i18n_init();
$t = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);

$APP = [
    'base'      => $base,
    'project'   => $proj,
    'embed'     => $embed,
    'gated'     => $gated,
    'meta'      => $meta,
    'points'    => $points,
    'isManager' => $isManager,
];
$jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS;
?><!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= $t('app_title') ?></title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style><?php readfile(__DIR__ . '/../assets/style.css'); ?></style>
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
    <span class="brand" id="title" title="<?= $t('brand_hint') ?>" role="button" tabindex="0"><span class="brand-txt" id="titleTxt"><?= $t('app_title') ?></span></span>
    <span id="brandShape" class="brand-shape" aria-hidden="true"></span>
    <span class="spacer"></span>
    <button id="collapseBtn" class="icon-btn" title="<?= $t('collapse') ?>" aria-label="<?= $t('collapse_aria') ?>"><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>
  </div>
  <div class="ctl-body" id="ctlBody">
    <div class="legend" id="legend"></div>
    <div class="ctl-row">
      <button class="btn" id="allPointsBtn" title="<?= $t('show_all_points') ?>"><i class="fa-solid fa-layer-group"></i> <?= $t('all') ?></button>
      <button class="btn" id="photoLayerBtn" title="<?= $t('filter_by_contrib') ?>"><i class="fa-solid fa-image"></i> <?= $t('contrib') ?></button>
      <button class="btn" id="routeBtn" title="<?= $t('route_by_number') ?>"><i class="fa-solid fa-route"></i> <?= $t('route') ?></button>
    </div>
    <div class="ctl-row">
      <select id="personFilter" title="<?= $t('filter_person') ?>"><option value=""><?= $t('all_contributors') ?></option></select>
    </div>
    <div class="ctl-foot" id="foot"></div>
  </div>
</div>

<div id="topright" class="tr-group">
  <button class="icon-btn tr-toggle" id="trToggle" title="<?= $t('more_options') ?>" aria-label="<?= $t('expand_options_aria') ?>" aria-expanded="false" aria-controls="trItems"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
  <div class="tr-items" id="trItems">
    <span id="identity" class="idchip" title="<?= $t('contributor_identity') ?>" role="button" tabindex="0"></span>
    <a class="icon-btn hide-in-embed" id="homeBtn" href="<?= $b ?>" title="<?= $t('back_to_list') ?>" aria-label="<?= $t('back_to_list') ?>"><i class="fa-solid fa-house" aria-hidden="true"></i></a>
    <button class="icon-btn" id="shareBtn" title="<?= $t('share_map') ?>" aria-label="<?= $t('share_map') ?>"><i class="fa-solid fa-share-nodes" aria-hidden="true"></i></button>
    <button id="embedBtn" class="icon-btn hide-in-embed" title="<?= $t('get_embed_code') ?>" aria-label="<?= $t('embed_this_map') ?>"><i class="fa-solid fa-code" aria-hidden="true"></i></button>
    <button id="themeBtn" class="icon-btn" title="<?= $t('toggle_theme') ?>" aria-label="<?= $t('toggle_theme_aria') ?>"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></button>
    <div class="lang-menu hide-in-embed" id="langMenu">
      <button type="button" class="lang-btn" id="langBtn" title="<?= $t('lang_switch') ?>" aria-haspopup="listbox" aria-expanded="false">
        <span id="langBtnLabel"><?= $LANG === 'en' ? 'English' : '中文' ?></span>
        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
      </button>
      <ul class="lang-list" id="langList" role="listbox" aria-label="<?= $t('lang_switch') ?>">
        <li role="option" data-lang="zh_TW" aria-selected="<?= $LANG === 'zh_TW' ? 'true' : 'false' ?>">中文</li>
        <li role="option" data-lang="en" aria-selected="<?= $LANG === 'en' ? 'true' : 'false' ?>">English</li>
      </ul>
    </div>
  </div>
</div>

<button class="icon-btn mapop-btn" id="resetBtn" title="<?= $t('reset_view') ?>" aria-label="<?= $t('reset_view_aria') ?>"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i></button>

<button class="fabtn upload-only" id="uploadBtn"><i class="fa-solid fa-plus"></i> <?= $t('upload') ?></button>
<button class="fabtn fab-unlock" id="unlockFab" style="display:none"><i class="fa-solid fa-lock"></i> <?= $t('unlock_contrib') ?></button>
<input type="file" id="pickImages" multiple hidden>
<input type="text" id="myName" hidden>

<div id="shareScreen" class="sharescreen" aria-hidden="true">
  <div class="share-card">
    <button class="icon-btn share-close" onclick="MapApp.closeShare()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    <div class="share-qr" id="shareQr" aria-hidden="true"></div>
    <div class="share-title" id="shareTitle"></div>
    <div class="share-sub" id="shareSub"></div>
    <div class="share-url" id="shareUrl"></div>
    <div class="dialog-actions" style="justify-content:center">
      <button class="btn primary" id="shareCopyBtn"><i class="fa-solid fa-link"></i> <?= $t('copy_link') ?></button>
      <span id="shareCopyMsg" class="hint"></span>
    </div>
  </div>
</div>

<div id="cloudWarn" class="toast" style="display:none"></div>

<div id="panel">
  <button class="p-close" onclick="MapApp.closePanel()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
  <div class="p-head">
    <div class="cat" id="pCat"></div>
    <h2 id="pTitle"></h2>
    <div class="sub" id="pSub"></div>
    <button class="btn small" id="pointEditBtn" type="button" style="display:none"><i class="fa-solid fa-location-dot"></i> <?= $t('adjust_location') ?></button>
    <div class="photo-editor point-editor" id="pointEditor" style="display:none"></div>
  </div>
  <div class="p-body">
    <div id="entries"></div>
  </div>
</div>

<div id="modal">
  <div class="modal-box">
    <div class="modal-head">
      <h3><?= $t('upload_photo') ?></h3>
      <input class="name-in" id="modalName" placeholder="<?= $t('your_nickname') ?>" autocomplete="off" style="width:130px">
      <button class="btn" onclick="MapApp.closeModal()"><?= $t('close') ?></button>
    </div>
    <div class="modal-body" id="queue"></div>
    <div class="modal-foot">
      <button class="btn" id="addMoreBtn"><i class="fa-solid fa-plus"></i> <?= $t('add_more_photos') ?></button>
      <span class="spacer"></span>
      <span class="hint" id="batchProgress"></span>
      <button class="btn primary" id="submitAllBtn"><?= $t('submit_all') ?></button>
    </div>
  </div>
</div>

<div id="embedDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('embed_this_map_title') ?></b><button class="icon-btn" onclick="MapApp.closeEmbed()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('embed_hint') ?></div>
    <div class="embed-size-row">
      <span class="embed-size-label"><?= $t('embed_size_label') ?></span>
      <input type="number" id="embedWidth" class="name-in" min="200" step="10" value="800">
      <span class="hint">×</span>
      <input type="number" id="embedHeight" class="name-in" min="200" step="10" value="600">
      <span class="hint">px</span>
      <select id="embedSizePreset" class="name-in embed-size-preset">
        <option value="custom"><?= $t('embed_size_custom') ?></option>
        <option value="fill_w"><?= $t('embed_fill_container_w') ?></option>
        <option value="fill_h"><?= $t('embed_fill_container_h') ?></option>
        <option value="fill_wh"><?= $t('embed_fill_container_wh') ?></option>
      </select>
    </div>
    <textarea id="embedCode" readonly rows="4"></textarea>
    <div class="dialog-actions"><button class="btn primary" id="copyEmbedBtn"><?= $t('copy') ?></button><span id="copyMsg" class="hint"></span></div>
  </div>
</div>

<div id="unlockDialog" class="dialog">
  <div class="dialog-box">
    <div class="dialog-head"><b><?= $t('unlock_contrib') ?></b><button class="icon-btn" onclick="MapApp.closeUnlock()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div class="hint"><?= $t('unlock_hint') ?></div>
    <input id="unlockCodeInput" class="name-in" style="width:100%;letter-spacing:8px;text-align:center;font-size:1.375rem" placeholder="<?= $t('six_digits') ?>" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" inputmode="numeric" pattern="[0-9]*" maxlength="8" aria-label="<?= $t('contrib_code') ?>">
    <div id="unlockMsg" class="hint" role="status"></div>
    <div style="margin-top:10px">
      <button type="button" class="btn" id="idToggleBtn" aria-expanded="false" aria-controls="idFields"><?= $t('create_identity_btn') ?></button>
      <div id="idFields" style="display:none;margin-top:8px">
        <div class="hint"><?= $t('identity_hint') ?></div>
        <input id="unlockPinInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('set_pin') ?>" autocomplete="off" inputmode="numeric" aria-label="<?= $t('contrib_pin') ?>">
        <input id="unlockCnameInput" class="name-in" style="width:100%;margin-top:6px" placeholder="<?= $t('display_nickname') ?>" autocomplete="off" maxlength="40" aria-label="<?= $t('contributor_nickname') ?>">
      </div>
    </div>
    <div class="dialog-actions">
      <button class="btn" id="scanBtn"><i class="fa-solid fa-qrcode"></i> <?= $t('scan_qr') ?></button>
      <span class="spacer"></span>
      <button class="btn primary" id="unlockSubmit"><?= $t('unlock') ?></button>
    </div>
    <div id="scanBox" style="display:none"><video id="scanVideo" playsinline muted></video></div>
  </div>
</div>

<div id="pinDialog" class="dialog">
  <div class="dialog-box pin-box">
    <div class="dialog-head"><b><?= $t('admin_login') ?></b><button class="icon-btn" onclick="MapApp.closePin()" aria-label="<?= $t('close') ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    <div id="pinDisplay" class="pin-display" aria-label="<?= $t('pin_input_area') ?>"></div>
    <div class="pin-pad" id="pinPad"></div>
  </div>
</div>

<div id="lb" onclick="MapApp.closeLightbox()"><img id="lbImg" alt=""><div class="cap" id="lbCap"></div><div class="photo-editor" id="lbEditor" style="display:none" onclick="event.stopPropagation()"></div></div>

<script>window.APP = <?= json_encode($APP, $jsonFlags) ?>; window.I18N = <?= json_encode($DICT, $jsonFlags) ?>; window.LANG = <?= json_encode($LANG, $jsonFlags) ?>;</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script><?php readfile(__DIR__ . '/../assets/vendor/qrcode-generator.js'); ?></script>
<script><?php readfile(__DIR__ . '/../assets/viewer.leaflet.js'); ?></script>
</body>
</html>
