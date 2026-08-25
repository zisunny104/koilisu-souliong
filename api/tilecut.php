<?php
// 常駐工具：把一張自繪插畫切成圖磚金字塔，落地成一個「專案層」（projects/<proj>/layers/<id>/）。
//
// 為什麼切磚而不是直接用一張 ImageOverlay：單張圖在放大時整張都要下載且會糊掉，A1 尺寸的手繪
// 稿動輒數十 MB；切成金字塔後瀏覽器只抓看得到的那幾格，還能逐級換解析度。缺點是檔案數量多，
// 而這正是「專案層不進版控」（projects/* 本來就 gitignore）要解決的問題。
//
// ── 為什麼切割放在瀏覽器 ──
// 跟 thumbfix.php 同一個理由：PHP 端要吃下一張上億像素的來源圖，memory_limit 與 max_execution_time
// 都會先倒下，而且主機不一定編了 WebP。瀏覽器的 canvas 沒有這些限制，還天然支援 SVG 來源。
// PHP 這邊只負責「收下已經切好的 256×256 小圖並放到正確路徑」，每一張都重新驗過型別與座標。
//
// ── 稀疏 ──
// 全透明的磚根本不上傳。缺磚由 layerfile.php 回 68 bytes 的透明 PNG（見該檔），所以「沒畫到的
// 地方」既不佔硬碟也不會在主控台印錯誤。layer.json 再寫入 bounds，Leaflet 連範圍外的請求都不發。
//
// ── 幾何 ──
// 影像四角在 Web Mercator 投影空間線性對應，與 Leaflet 的 L.imageOverlay 一致——這是刻意的：
// 同一張圖「切磚前用 ImageOverlay 預覽」與「切磚後用 tileLayer 顯示」必須長得一模一樣，
// 否則對位工具就白做了。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/i18n.php';
require_once __DIR__ . '/layers.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// 還原 app 掛載根目錄，組「回後台」連結用（理由同 thumbfix.php：不能用相對的 ?api=admin）
$appName = $_APP['name'] ?? basename(dirname(__DIR__));
$reqPathOnly = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$appMarkerPos = strpos($reqPathOnly, '/' . $appName);
$basePath = $appMarkerPos !== false ? rtrim(substr($reqPathOnly, 0, $appMarkerPos + strlen($appName) + 1), '/') . '/' : '/';
$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$backProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$adminUrl = $esc($origin . $basePath . '?api=admin' . ($backProject !== '' ? '&project=' . urlencode($backProject) : '') . '#tools');

// 這支寫的是專案層，所以權限跟著專案走（比照 admin.php 的 layerimport）：主要管理者通吃，
// 專案管理者只能動自己那張地圖。thumbfix 之類的全站維護工具是 master only，這支不是。
$master = admin_authed($cfg);
$canProj = function (string $p) use ($cfg, $master): bool {
    return $p !== '' && preg_match('/^[a-z0-9_-]+$/', $p) === 1
        && is_dir(project_dir($cfg, $p))
        && ($master || admin_can($cfg, $p));
};
$auditWho = fn(string $p) => $master ? 'master' : (($acc = account_current($cfg)) !== null ? 'acct:' . $acc['id'] : 'pin:' . (string)padm_pin_id($cfg, $p));

/** 這個圖層在磁碟上的位置；專案不合法或 projects_dir 沒設回 null。 */
function tilecut_dir(array $cfg, string $project, string $id): ?string
{
    $root = souliong_layer_roots($cfg, $project)['project'] ?? '';
    if ($root === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $id)) {
        return null;
    }
    return rtrim($root, '/\\') . '/' . $id;
}

// ── 開工：建立（或清空）圖層資料夾（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'begin') {
    if (!hash_equals(admin_derived($cfg), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null) {
        json_out(['error' => $tr('tilecut_bad_id_msg')], 400);
    }
    $existed = is_dir($dir);
    if ($existed && empty($_POST['overwrite'])) {
        json_out(['error' => $tr('tilecut_exists_msg', ['id' => $id])], 409);
    }
    // 清不乾淨就不往下走：舊磚留著會變成擦不掉的殘影，寧可當場說失敗，也不要交出一張半新半舊的圖層。
    if ($existed && is_dir($dir . '/tiles') && !souliong_layer_rmtree($dir . '/tiles', $dir)) {
        json_out(['error' => $tr('tilecut_clear_failed_msg')], 500);
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    json_out(['ok' => true, 'replaced' => $existed]);
}

// ── 收磚：一次一批（POST，JSON 回應） ──
// 欄位名就是座標：tiles[<z>_<x>_<y>]。不用另外傳一份對照表，省掉「檔案與座標對不上」這種錯。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tile') {
    if (!hash_equals(admin_derived($cfg), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('layer_not_found_msg')], 404);
    }
    $accept = ['image/png' => 'png', 'image/webp' => 'webp'];
    $saved = 0;
    $rejected = 0;
    foreach ((array)($_FILES['tiles']['tmp_name'] ?? []) as $key => $tmp) {
        // 每一張都獨立驗過：座標形狀、該 zoom 的合法範圍、大小、真實型別、像素尺寸。
        // 「客戶端剛剛才產生這些檔」不構成信任的理由——這是一支公開端點。
        if (($_FILES['tiles']['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !preg_match('/^(\d{1,2})_(\d{1,9})_(\d{1,9})$/', (string)$key, $mm)) {
            $rejected++;
            continue;
        }
        [, $z, $x, $y] = array_map('intval', $mm);
        $span = 1 << $z;
        $info = ($z <= 24 && $x < $span && $y < $span
            && ($_FILES['tiles']['size'][$key] ?? 0) <= 512 * 1024
            && is_uploaded_file($tmp)) ? @getimagesize($tmp) : false;
        $ext = is_array($info) ? ($accept[$info['mime'] ?? ''] ?? null) : null;
        if ($ext === null || $info[0] > 512 || $info[1] > 512) {
            $rejected++;
            continue;
        }
        $sub = $dir . '/tiles/' . $z . '/' . $x;
        if (!is_dir($sub)) {
            @mkdir($sub, 0775, true);
        }
        if (move_uploaded_file($tmp, $sub . '/' . $y . '.' . $ext)) {
            $saved++;
        } else {
            $rejected++;
        }
    }
    json_out(['ok' => true, 'saved' => $saved, 'rejected' => $rejected]);
}

// ── 收尾：寫 layer.json（POST，JSON 回應） ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish') {
    if (!hash_equals(admin_derived($cfg), (string)($_POST['csrf'] ?? ''))) {
        json_out(['error' => $tr('csrf_invalid_ajax_msg')], 403);
    }
    $project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
    if (!$canProj($project)) {
        json_out(['error' => $tr('no_permission_title')], 403);
    }
    $id  = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['id'] ?? ''));
    $dir = tilecut_dir($cfg, $project, $id);
    if ($dir === null || !is_dir($dir)) {
        json_out(['error' => $tr('layer_not_found_msg')], 404);
    }
    $num = fn($k, $d = 0.0) => is_numeric($_POST[$k] ?? null) ? (float)$_POST[$k] : $d;
    $s = $num('south');
    $w = $num('west');
    $n = $num('north');
    $e = $num('east');
    $z0 = max(0, min(24, (int)$num('minZoom')));
    $z1 = max(0, min(24, (int)$num('maxZoom')));
    // 邊界的合理範圍由 souliong_layer_bounds_valid() 定義（Web Mercator 在南北極附近發散），
    // 後台的就地編輯用同一支，兩邊對「畫得出來」的定義才會一致。
    if (!souliong_layer_bounds_valid($s, $w, $n, $e) || $z0 > $z1) {
        json_out(['error' => $tr('tilecut_bad_bounds_msg')], 400);
    }
    $ext = in_array($_POST['ext'] ?? '', ['png', 'webp'], true) ? $_POST['ext'] : 'png';
    $pane = in_array($_POST['pane'] ?? '', souliong_layer_panes(), true) ? $_POST['pane'] : 'art';
    $label = trim((string)($_POST['label'] ?? ''));
    $count = max(0, (int)$num('count'));
    $manifest = [
        'label' => mb_substr($label !== '' ? $label : $id, 0, 60),
        'desc'  => $tr('tilecut_manifest_desc', ['at' => gmdate('Y-m-d H:i') . ' UTC', 'tiles' => $count]),
        'type'  => 'raster',
        'pane'  => $pane,
        'url'   => 'tiles/{z}/{x}/{y}.' . $ext,
        'bounds' => [[$s, $w], [$n, $e]],
        'minZoom' => $z0,
        // 切到哪一級就 maxNativeZoom 到哪一級，maxZoom 再放寬：再放大時 Leaflet 會把最後一級
        // 拉伸上去，總比整層消失好（手繪稿放大本來就是糊的，使用者預期得到）。
        'maxNativeZoom' => $z1,
        'maxZoom' => min(24, $z1 + 4),
        'opacity' => max(0.0, min(1.0, $num('opacity', 1.0))),
    ];
    $attr = trim((string)($_POST['attribution'] ?? ''));
    if ($attr !== '') {
        $manifest['attribution'] = mb_substr($attr, 0, 200);
    }
    // 這一段純粹是留給人看的來歷：哪支工具、什麼時候、幾張磚。程式不讀它。
    $manifest['generated'] = ['tool' => 'tilecut', 'at' => gmdate('c'), 'tiles' => $count];
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($dir . '/layer.json', $json, LOCK_EX) === false) {
        json_out(['error' => $tr('tilecut_mkdir_failed_msg')], 500);
    }
    audit_log($cfg, $auditWho($project), 'layer_tilecut', $project, $id . ' x' . $count);
    json_out(['ok' => true, 'id' => $id]);
}

// ── 頁面 ──
$allProjects = array_values(array_filter(store_projects($cfg), fn($p) => $master || admin_can($cfg, $p)));
if (!$master && !$allProjects) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . $tr('tilecut_login_required_msg', ['url' => $adminUrl]) . '</p>';
    exit;
}
$csrf = admin_derived($cfg);
$reqProject = in_array($backProject, $allProjects, true) ? $backProject : ($allProjects[0] ?? '');
?>
<!doctype html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= $t('tilecut_title') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <style>
    :root {
      --bg: #f6f5f2;
      --fg: #1c1a17;
      --muted: #7a756c;
      --line: #e2ddd3;
      --card: #fff;
      --accent: #b5482e;
      --accent-fg: #fff;
      --sp-1: 0.25rem;
      --sp-2: 0.5rem;
      --sp-3: 0.75rem;
      --sp-4: 1rem;
      --sp-5: 1.5rem;
      --tap: 1.75rem
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
      font: 0.9375rem/1.6 system-ui, -apple-system, "Noto Sans TC", sans-serif;
      padding: var(--sp-4) var(--sp-4) 3.75rem
    }

    .wrap {
      max-width: 52rem;
      margin: 0 auto
    }

    .langsw {
      max-width: 52rem;
      margin: 0 auto var(--sp-2);
      display: flex;
      justify-content: flex-end;
      gap: var(--sp-1);
      font-size: 0.75rem
    }

    .langsw a {
      display: inline-flex;
      align-items: center;
      min-height: var(--tap);
      color: var(--muted);
      text-decoration: none;
      padding: 0 var(--sp-3);
      border-radius: 999px;
      border: 1px solid transparent
    }

    .langsw a.on {
      color: var(--fg);
      font-weight: 700;
      background: var(--card);
      border-color: var(--line)
    }

    h1 {
      font-size: 1.125rem;
      line-height: 1.4;
      margin: 0 0 var(--sp-4);
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 0 var(--sp-2)
    }

    h2 {
      font-size: 0.875rem;
      margin: 0 0 var(--sp-2);
      display: flex;
      align-items: center;
      gap: var(--sp-2)
    }

    .warn {
      border: 1px solid var(--accent);
      color: var(--accent);
      border-radius: var(--sp-3);
      padding: var(--sp-3) var(--sp-4);
      font-size: 0.8125rem;
      margin-bottom: var(--sp-4)
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 0.875rem;
      padding: var(--sp-4) var(--sp-5);
      margin-bottom: var(--sp-4)
    }

    label {
      display: block;
      font-size: 0.8125rem;
      color: var(--muted);
      margin-bottom: var(--sp-1)
    }

    select,
    input[type=text],
    input[type=number] {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 0.625rem;
      background: var(--bg);
      color: var(--fg);
      padding: var(--sp-2) var(--sp-3);
      font-size: 0.875rem;
      min-height: 2.25rem
    }

    /* 兩欄以上的欄位排成流動格線，窄螢幕自動疊成一欄 */
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
      gap: var(--sp-3);
      margin-bottom: var(--sp-3)
    }

    .grid>div {
      min-width: 0
    }

    button {
      border: none;
      background: var(--accent);
      color: var(--accent-fg);
      border-radius: 0.625rem;
      padding: var(--sp-2) var(--sp-4);
      min-height: 2.25rem;
      font-size: 0.875rem;
      font-weight: 700;
      cursor: pointer
    }

    button.ghost {
      background: var(--card);
      color: var(--fg);
      border: 1px solid var(--line);
      font-weight: 600
    }

    button:disabled {
      opacity: .5;
      cursor: default
    }

    .row {
      display: flex;
      flex-wrap: wrap;
      gap: var(--sp-2);
      align-items: center
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .hint:empty {
      display: none
    }

    .filebtn {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      cursor: pointer;
      margin: 0
    }

    .chk {
      display: flex;
      align-items: center;
      gap: var(--sp-2);
      font-size: 0.8125rem;
      color: var(--fg);
      margin: 0
    }

    .chk input {
      width: auto;
      min-height: 0
    }

    #map {
      height: 22rem;
      border-radius: 0.75rem;
      border: 1px solid var(--line);
      margin-bottom: var(--sp-3);
      background: var(--bg)
    }

    /* 進度條：外框固定、內條寬度由 JS 給，避免每格磚都動到版面 */
    .bar {
      height: 0.5rem;
      border-radius: 999px;
      background: var(--line);
      overflow: hidden;
      margin: var(--sp-3) 0 var(--sp-2)
    }

    .bar i {
      display: block;
      height: 100%;
      width: 0;
      background: var(--accent);
      transition: width .2s
    }

    .mono {
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
      font-size: 0.75rem
    }

    .ok {
      color: #2a8a4a
    }

    .no {
      color: var(--accent)
    }

    a {
      color: var(--accent)
    }

    .backlink {
      margin-top: var(--sp-5)
    }

    .backlink a {
      display: inline-flex;
      align-items: center;
      gap: var(--sp-2);
      min-height: 2.25rem;
      padding: 0 var(--sp-4);
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--fg);
      font-size: 0.8125rem;
      font-weight: 600;
      text-decoration: none
    }

    .backlink a:hover {
      border-color: var(--accent);
      color: var(--accent)
    }

    a:focus-visible,
    button:focus-visible,
    select:focus-visible,
    input:focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px
    }
  </style>
</head>

<body>
  <div class="langsw">
    <a href="?<?= $backProject !== '' ? 'project=' . rawurlencode($backProject) . '&' : '' ?>lang=zh_TW" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
    <a href="?<?= $backProject !== '' ? 'project=' . rawurlencode($backProject) . '&' : '' ?>lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
  </div>
  <div class="wrap">
    <h1><i class="fa-solid fa-scissors"></i> <?= $t('tilecut_h1') ?></h1>
    <div class="warn"><?= $t('tilecut_warn') ?></div>

    <div class="card">
      <h2><i class="fa-solid fa-1"></i> <?= $t('tilecut_step_source') ?></h2>
      <div class="grid">
        <div>
          <label for="project"><?= $t('tool_select_project_label') ?></label>
          <select id="project">
            <?php foreach ($allProjects as $p): ?><option value="<?= $esc($p) ?>" <?= $p === $reqProject ? 'selected' : '' ?>><?= $esc($p) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="lid"><?= $t('tilecut_layer_id_label') ?></label>
          <input type="text" id="lid" value="artwork" pattern="[a-z0-9][a-z0-9_-]*" maxlength="32" spellcheck="false">
        </div>
        <div>
          <label for="llabel"><?= $t('tilecut_label_label') ?></label>
          <input type="text" id="llabel" maxlength="60">
        </div>
      </div>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_layer_id_hint') ?></div>
      <div class="row">
        <label class="filebtn"><span class="ghost" style="display:inline-flex;align-items:center;gap:.5rem;border:1px solid var(--line);border-radius:.625rem;padding:.5rem 1rem;background:var(--card)"><i class="fa-solid fa-folder-open"></i> <span id="fname"><?= $t('tilecut_choose_image_btn') ?></span></span>
          <input type="file" id="src" accept="image/png,image/webp,image/jpeg,image/svg+xml" hidden></label>
        <span class="hint mono" id="srcinfo"></span>
      </div>
      <div class="hint" style="margin-top:var(--sp-2)"><?= $t('tilecut_image_hint') ?></div>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-2"></i> <?= $t('tilecut_step_place') ?></h2>
      <div class="hint" style="margin-bottom:var(--sp-3)"><?= $t('tilecut_place_hint') ?></div>
      <div id="map"></div>
      <div class="row" style="margin-bottom:var(--sp-3)">
        <button type="button" class="ghost" id="useview"><i class="fa-solid fa-crop-simple"></i> <?= $t('tilecut_use_view_btn') ?></button>
        <button type="button" class="ghost" id="fitov"><i class="fa-solid fa-magnifying-glass-location"></i> <?= $t('tilecut_fit_btn') ?></button>
        <label class="chk"><input type="checkbox" id="lockar" checked> <?= $t('tilecut_lock_aspect_label') ?></label>
      </div>
      <div class="grid">
        <div><label for="north"><?= $t('tilecut_north') ?></label><input type="number" id="north" step="0.000001"></div>
        <div><label for="south"><?= $t('tilecut_south') ?></label><input type="number" id="south" step="0.000001"></div>
        <div><label for="west"><?= $t('tilecut_west') ?></label><input type="number" id="west" step="0.000001"></div>
        <div><label for="east"><?= $t('tilecut_east') ?></label><input type="number" id="east" step="0.000001"></div>
      </div>
    </div>

    <div class="card">
      <h2><i class="fa-solid fa-3"></i> <?= $t('tilecut_step_output') ?></h2>
      <div class="grid">
        <div><label for="zmin"><?= $t('tilecut_zoom_min') ?></label><input type="number" id="zmin" min="0" max="22" step="1" value="12"></div>
        <div><label for="zmax"><?= $t('tilecut_zoom_max') ?></label><input type="number" id="zmax" min="0" max="22" step="1" value="17"></div>
        <div>
          <label for="pane"><?= $t('tilecut_pane_label') ?></label>
          <select id="pane">
            <option value="art" selected><?= $t('tilecut_pane_art') ?></option>
            <option value="road"><?= $t('tilecut_pane_road') ?></option>
            <option value="paper"><?= $t('tilecut_pane_paper') ?></option>
            <option value="base"><?= $t('tilecut_pane_base') ?></option>
          </select>
        </div>
        <div><label for="opacity"><?= $t('tilecut_opacity_label') ?></label><input type="number" id="opacity" min="0" max="1" step="0.05" value="1"></div>
      </div>
      <div>
        <label for="attr"><?= $t('tilecut_attr_label') ?></label>
        <input type="text" id="attr" maxlength="200">
      </div>
      <div class="hint" style="margin-top:var(--sp-2)"><?= $t('tilecut_zoom_hint') ?></div>
      <div class="hint" id="estimate" style="margin-top:var(--sp-3)"></div>
      <div class="row" style="margin-top:var(--sp-3)">
        <label class="chk"><input type="checkbox" id="overwrite"> <?= $t('tilecut_overwrite_label') ?></label>
      </div>
      <div class="row" style="margin-top:var(--sp-3)">
        <button id="go"><i class="fa-solid fa-scissors"></i> <?= $t('tilecut_start_btn') ?></button>
        <button id="stop" class="ghost" disabled><i class="fa-solid fa-stop"></i> <?= $t('tilecut_stop_btn') ?></button>
      </div>
      <div class="bar"><i id="barfill"></i></div>
      <div class="hint" id="status"></div>
      <div class="hint" id="done" style="margin-top:var(--sp-2)"></div>
      <p class="hint" style="margin-top:var(--sp-3)"><?= $t('tilecut_keep_open_hint') ?></p>
    </div>

    <p class="backlink"><a href="<?= $adminUrl ?>"><i class="fa-solid fa-arrow-left"></i> <?= $t("back_to_admin") ?></a></p>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    // 未套變數的原始字串（含 {var} 佔位符），前端用 fmt() 自行代換——進度與計數只有 JS 迴圈裡才知道
    const I18N = <?= json_encode([
      'need_image'   => i18n_t($DICT, 'tilecut_need_image_msg'),
      'need_id'      => i18n_t($DICT, 'tilecut_need_id_msg'),
      'bad_bounds'   => i18n_t($DICT, 'tilecut_bad_bounds_msg'),
      'too_many'     => i18n_t($DICT, 'tilecut_too_many_msg'),
      'estimate'     => i18n_t($DICT, 'tilecut_estimate_msg'),
      'native_hint'  => i18n_t($DICT, 'tilecut_native_hint'),
      'cutting'      => i18n_t($DICT, 'tilecut_cutting_msg'),
      'uploading'    => i18n_t($DICT, 'tilecut_uploading_msg'),
      'rate_limited' => i18n_t($DICT, 'tilecut_rate_limited_msg'),
      'stopped'      => i18n_t($DICT, 'tilecut_stopped_msg'),
      'complete'     => i18n_t($DICT, 'tilecut_complete_msg'),
      'next_step'    => i18n_t($DICT, 'tilecut_next_step_msg'),
      'rejected'     => i18n_t($DICT, 'tilecut_rejected_msg'),
      'error_prefix' => i18n_t($DICT, 'error_prefix_label'),
      'conn_failed'  => i18n_t($DICT, 'connection_failed_retry_msg'),
      'img_failed'   => i18n_t($DICT, 'tilecut_image_load_failed'),
      'choose_image' => i18n_t($DICT, 'tilecut_choose_image_btn'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    const fmt = (str, vars) => str.replace(/\{(\w+)\}/g, (_, k) => (vars[k] != null ? vars[k] : ''));
    const csrf = <?= json_encode($csrf) ?>;
    // 跨端點請求一律用絕對 base（同 thumbfix.php：這頁可能從 <base>/tilecut 進來，相對 ?api= 會被路徑路由搶走）
    const BASE = <?= json_encode($origin . $basePath) ?>;

    const TILE = 256;         // 標準圖磚邊長；Leaflet 預設也是 256
    const BATCH = 16;         // 一次 POST 幾張。PHP 的 max_file_uploads 常見上限是 20，留些餘裕
    const MAX_TILES = 20000;  // 超過就不讓開始：再多就該考慮降一級 zoom，而不是讓人等半小時

    // ── Web Mercator：經緯度 ↔ 單位世界座標 [0,1] ──
    // 圖磚系統與 Leaflet 的 ImageOverlay 都在這個空間裡線性運作，所以整支工具只需要這四個函式。
    const wx = lng => (lng + 180) / 360;
    const wy = lat => { const s = Math.sin(lat * Math.PI / 180); return 0.5 - Math.log((1 + s) / (1 - s)) / (4 * Math.PI); };
    const lngOf = x => x * 360 - 180;
    const latOf = y => Math.atan(Math.sinh(Math.PI * (1 - 2 * y))) * 180 / Math.PI;

    const $ = id => document.getElementById(id);
    const statusEl = $('status'), doneEl = $('done'), estEl = $('estimate'), barEl = $('barfill');

    let img = null, imgW = 0, imgH = 0, objUrl = null;
    let overlay = null, rect = null, swM = null, neM = null;
    let running = false, aborted = false;

    // ── 地圖 ──
    const map = L.map('map', { center: [23.95, 120.69], zoom: 14 });
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
      { subdomains: 'abcd', detectRetina: true, maxZoom: 20, attribution: '&copy; OpenStreetMap, CARTO' }).addTo(map);

    function bounds() {
      const n = parseFloat($('north').value), s = parseFloat($('south').value);
      const w = parseFloat($('west').value), e = parseFloat($('east').value);
      if ([n, s, w, e].some(v => !isFinite(v)) || s >= n || w >= e
        || Math.abs(n) > 85.0511 || Math.abs(s) > 85.0511 || Math.abs(w) > 180 || Math.abs(e) > 180) return null;
      return { n, s, w, e };
    }
    function writeBounds(b) {
      $('north').value = b.n.toFixed(6); $('south').value = b.s.toFixed(6);
      $('west').value = b.w.toFixed(6); $('east').value = b.e.toFixed(6);
    }
    function setBounds(b) { writeBounds(b); redraw(); }

    /**
     * 鎖長寬比：以某一角為支點，把另一角拉到「與來源圖同比例」的位置。
     * 比例要在投影空間算而不是經緯度——同一塊經緯度矩形在不同緯度的實際形狀不同，
     * 用經緯度算出來的圖在台灣會被壓扁約 8%。
     */
    function applyAspect(b, anchor) {
      if (!imgW || !imgH || !$('lockar').checked) return b;
      const x0 = wx(b.w), x1 = wx(b.e), y0 = wy(b.n), y1 = wy(b.s);
      const need = (x1 - x0) * imgH / imgW;   // 應有的世界縱向跨距
      if (!(need > 0) || !isFinite(need)) return b;
      return (anchor === 'sw')
        ? { w: b.w, s: b.s, e: b.e, n: latOf(y1 - need) }    // 西南固定，調北緣
        : { w: b.w, n: b.n, e: b.e, s: latOf(y0 + need) };   // 西北固定，調南緣
    }

    /** 只更新疊圖與外框，不碰把手——拖曳進行中重設把手位置會跟滑鼠搶。 */
    function drawShapes(b) {
      const ll = L.latLngBounds([[b.s, b.w], [b.n, b.e]]);
      if (objUrl) {
        if (!overlay) overlay = L.imageOverlay(objUrl, ll, { opacity: 0.75, interactive: false }).addTo(map);
        else { overlay.setUrl(objUrl); overlay.setBounds(ll); }
      }
      if (!rect) rect = L.rectangle(ll, { color: '#b5482e', weight: 1, fill: false, dashArray: '4 3' }).addTo(map);
      else rect.setBounds(ll);
      return ll;
    }

    /**
     * 兩個角落把手：拖曳比在四個數字框裡試誤快得多。
     * 拖曳中只跟著畫，放開才套用長寬比並把把手校正回去（live=true 就是拖曳中那一段）。
     */
    function ensureHandles(ll) {
      if (swM) { swM.setLatLng(ll.getSouthWest()); neM.setLatLng(ll.getNorthEast()); return; }
      swM = L.marker(ll.getSouthWest(), { draggable: true }).addTo(map);
      neM = L.marker(ll.getNorthEast(), { draggable: true }).addTo(map);
      const handler = (anchor, live) => () => {
        const sw = swM.getLatLng(), ne = neM.getLatLng();
        let nb = { s: Math.min(sw.lat, ne.lat), n: Math.max(sw.lat, ne.lat), w: Math.min(sw.lng, ne.lng), e: Math.max(sw.lng, ne.lng) };
        if (!live) nb = applyAspect(nb, anchor);
        writeBounds(nb);
        const b2 = drawShapes(nb);
        if (!live) { swM.setLatLng(b2.getSouthWest()); neM.setLatLng(b2.getNorthEast()); }
        estimate();
      };
      // 拖西南角時以西北角為支點（南緣由長寬比算出來），拖東北角時以西南角為支點
      swM.on('drag', handler('nw', true)).on('dragend', handler('nw', false));
      neM.on('drag', handler('sw', true)).on('dragend', handler('sw', false));
    }

    function redraw() {
      const b = bounds();
      if (!b) { estimate(); return; }
      ensureHandles(drawShapes(b));
      estimate();
    }

    /** 某個 zoom 下這張圖蓋到的圖磚範圍（含端點）。 */
    function tileRange(b, z) {
      const n = 1 << z;
      const cl = (v, hi) => Math.max(0, Math.min(hi, v));
      return {
        x0: cl(Math.floor(wx(b.w) * n), n - 1), x1: cl(Math.ceil(wx(b.e) * n) - 1, n - 1),
        y0: cl(Math.floor(wy(b.n) * n), n - 1), y1: cl(Math.ceil(wy(b.s) * n) - 1, n - 1)
      };
    }
    function totalTiles(b, z0, z1) {
      let sum = 0;
      for (let z = z0; z <= z1; z++) { const r = tileRange(b, z); sum += (r.x1 - r.x0 + 1) * (r.y1 - r.y0 + 1); }
      return sum;
    }
    /** 影像原生解析度大約對應哪一級 zoom：再切上去只是把同一批像素放大，白佔硬碟。 */
    function nativeZoom(b) {
      if (!imgW) return null;
      const span = wx(b.e) - wx(b.w);
      if (!(span > 0)) return null;
      return Math.max(0, Math.min(22, Math.round(Math.log2(imgW / (span * TILE)))));
    }

    function estimate() {
      const b = bounds();
      const z0 = parseInt($('zmin').value, 10), z1 = parseInt($('zmax').value, 10);
      if (!b || !isFinite(z0) || !isFinite(z1) || z0 > z1) { estEl.textContent = ''; $('go').disabled = running; return; }
      const total = totalTiles(b, z0, z1);
      const nz = nativeZoom(b);
      let msg = fmt(I18N.estimate, { tiles: total.toLocaleString() });
      if (nz !== null) msg += ' · ' + fmt(I18N.native_hint, { z: nz });
      if (total > MAX_TILES) { msg = fmt(I18N.too_many, { tiles: total.toLocaleString(), max: MAX_TILES.toLocaleString() }); estEl.className = 'hint no'; }
      else estEl.className = 'hint';
      estEl.textContent = msg;
      $('go').disabled = running || total > MAX_TILES;
    }

    ['north', 'south', 'west', 'east'].forEach(k => $(k).addEventListener('input', redraw));
    ['zmin', 'zmax'].forEach(k => $(k).addEventListener('input', estimate));
    $('useview').addEventListener('click', () => {
      const b = map.getBounds();
      setBounds(applyAspect({ n: b.getNorth(), s: b.getSouth(), w: b.getWest(), e: b.getEast() }, 'sw'));
    });
    $('fitov').addEventListener('click', () => { const b = bounds(); if (b) map.fitBounds([[b.s, b.w], [b.n, b.e]]); });
    $('lockar').addEventListener('change', () => { const b = bounds(); if (b) setBounds(applyAspect(b, 'sw')); });

    // ── 選圖 ──
    $('src').addEventListener('change', async ev => {
      const f = ev.target.files && ev.target.files[0];
      $('fname').textContent = f ? f.name : I18N.choose_image;
      if (!f) return;
      if (objUrl) URL.revokeObjectURL(objUrl);
      objUrl = URL.createObjectURL(f);
      try {
        // SVG 沒有像素尺寸的保證，createImageBitmap 對它的支援也不一致；一律走 <img> 取
        // naturalWidth/Height，SVG 會用它自己宣告的 width/height（沒宣告則瀏覽器給預設值）。
        img = await new Promise((res, rej) => {
          const i = new Image();
          i.onload = () => res(i); i.onerror = () => rej(new Error('decode'));
          i.src = objUrl;
        });
        imgW = img.naturalWidth || img.width; imgH = img.naturalHeight || img.height;
        // 沒有內建尺寸的 SVG（只給 viewBox、不給 width/height）量不出長寬比，也就無從對位
        if (!imgW || !imgH) throw new Error('no intrinsic size');
      } catch (e) { statusEl.textContent = I18N.img_failed; img = null; imgW = imgH = 0; return; }
      $('srcinfo').textContent = imgW + ' x ' + imgH;
      if (!$('llabel').value) $('llabel').value = f.name.replace(/\.[^.]+$/, '');
      // 還沒對位過就先把圖擺在目前視野裡，讓人有東西可以拖
      if (!bounds()) {
        const b = map.getBounds();
        const pad = (v0, v1) => [v0 + (v1 - v0) * 0.2, v1 - (v1 - v0) * 0.2];
        const [s, n] = pad(b.getSouth(), b.getNorth());
        const [w, e] = pad(b.getWest(), b.getEast());
        setBounds(applyAspect({ n, s, w, e }, 'sw'));
      } else redraw();
      const bb = bounds(), nz = bb ? nativeZoom(bb) : null;
      if (nz !== null) { $('zmax').value = nz; $('zmin').value = Math.max(0, nz - 5); }
      estimate();
    });

    // ── 切磚 ──
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = TILE;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });

    // WebP 帶 alpha 的體積遠小於 PNG；不支援的瀏覽器（Safari 舊版）靜默退回 PNG。
    // 格式在開工前決定一次，整個金字塔統一，layer.json 的副檔名才不會對不上。
    async function pickFormat() {
      const c = document.createElement('canvas'); c.width = c.height = 1;
      const b = await new Promise(r => c.toBlob(r, 'image/webp', 0.9));
      return (b && b.type === 'image/webp') ? { mime: 'image/webp', ext: 'webp', q: 0.85 } : { mime: 'image/png', ext: 'png', q: undefined };
    }

    /** 畫一格。全透明回 null——稀疏金字塔靠這個判斷省掉大半的檔案。 */
    function cutTile(b, z, x, y, fmtInfo) {
      const n = 1 << z;
      const x0 = wx(b.w), x1 = wx(b.e), y0 = wy(b.n), y1 = wy(b.s);
      const px = v => (v - x0) / (x1 - x0) * imgW;
      const py = v => (v - y0) / (y1 - y0) * imgH;
      const sx = px(x / n), sxe = px((x + 1) / n), sy = py(y / n), sye = py((y + 1) / n);
      if (sxe <= 0 || sx >= imgW || sye <= 0 || sy >= imgH) return null;
      ctx.clearRect(0, 0, TILE, TILE);
      // 來源矩形超出影像時瀏覽器會照比例裁切目的地，所以不必自己算交集
      ctx.drawImage(img, sx, sy, sxe - sx, sye - sy, 0, 0, TILE, TILE);
      const buf = ctx.getImageData(0, 0, TILE, TILE).data;
      const u32 = new Uint32Array(buf.buffer);
      let any = false;
      for (let i = 0; i < u32.length; i++) { if (u32[i] !== 0) { any = true; break; } }
      if (!any) return null;
      return new Promise(r => canvas.toBlob(r, fmtInfo.mime, fmtInfo.q));
    }

    async function post(body) {
      // 磚很多時一定會撞到限流（admin bucket 120/分）：照 Retry-After 等一下再送，不放棄整批
      for (let attempt = 0; attempt < 6; attempt++) {
        const res = await fetch(BASE + '?api=tilecut', { method: 'POST', body });
        if (res.status !== 429) {
          const j = await res.json().catch(() => ({}));
          if (!res.ok || !j.ok) throw new Error(j.error || ('HTTP ' + res.status));
          return j;
        }
        const wait = parseInt(res.headers.get('Retry-After') || '10', 10) || 10;
        statusEl.textContent = fmt(I18N.rate_limited, { wait });
        await new Promise(r => setTimeout(r, wait * 1000));
      }
      throw new Error('429');
    }

    $('stop').addEventListener('click', () => { aborted = true; });

    $('go').addEventListener('click', async () => {
      const b = bounds();
      const project = $('project').value;
      const id = $('lid').value.trim().toLowerCase();
      const z0 = parseInt($('zmin').value, 10), z1 = parseInt($('zmax').value, 10);
      doneEl.textContent = '';
      if (!img) { statusEl.textContent = I18N.need_image; return; }
      if (!/^[a-z0-9][a-z0-9_-]{0,31}$/.test(id)) { statusEl.textContent = I18N.need_id; return; }
      if (!b || !(z0 <= z1)) { statusEl.textContent = I18N.bad_bounds; return; }
      const total = totalTiles(b, z0, z1);
      if (total > MAX_TILES) { statusEl.textContent = fmt(I18N.too_many, { tiles: total, max: MAX_TILES }); return; }

      running = true; aborted = false;
      $('go').disabled = true; $('stop').disabled = false;
      const fmtInfo = await pickFormat();
      let cut = 0, sent = 0, skipped = 0, rejected = 0;
      try {
        const fd0 = new FormData();
        fd0.append('action', 'begin'); fd0.append('csrf', csrf);
        fd0.append('project', project); fd0.append('id', id);
        if ($('overwrite').checked) fd0.append('overwrite', '1');
        await post(fd0);

        let batch = new FormData(), inBatch = 0;
        const flush = async () => {
          if (!inBatch) return;
          batch.append('action', 'tile'); batch.append('csrf', csrf);
          batch.append('project', project); batch.append('id', id);
          statusEl.textContent = fmt(I18N.uploading, { sent, total });
          const j = await post(batch);
          sent += j.saved || 0; rejected += j.rejected || 0;
          batch = new FormData(); inBatch = 0;
        };

        outer:
        for (let z = z0; z <= z1; z++) {
          const r = tileRange(b, z);
          for (let x = r.x0; x <= r.x1; x++) {
            for (let y = r.y0; y <= r.y1; y++) {
              if (aborted) break outer;
              cut++;
              const blob = await cutTile(b, z, x, y, fmtInfo);
              if (!blob) { skipped++; }
              else {
                batch.append('tiles[' + z + '_' + x + '_' + y + ']', blob, z + '_' + x + '_' + y + '.' + fmtInfo.ext);
                inBatch++;
                if (inBatch >= BATCH) await flush();
              }
              if (cut % 25 === 0) {
                statusEl.textContent = fmt(I18N.cutting, { z, i: cut, total, sent, skipped });
                barEl.style.width = (cut / total * 100).toFixed(1) + '%';
                await new Promise(r => setTimeout(r, 0));   // 讓出主執行緒，畫面才不會凍住
              }
            }
          }
        }
        await flush();
        barEl.style.width = '100%';

        if (aborted) { statusEl.textContent = fmt(I18N.stopped, { sent }); }
        else {
          const fdF = new FormData();
          fdF.append('action', 'finish'); fdF.append('csrf', csrf);
          fdF.append('project', project); fdF.append('id', id);
          fdF.append('label', $('llabel').value.trim());
          fdF.append('pane', $('pane').value);
          fdF.append('opacity', $('opacity').value);
          fdF.append('attribution', $('attr').value.trim());
          fdF.append('ext', fmtInfo.ext);
          fdF.append('south', b.s); fdF.append('west', b.w); fdF.append('north', b.n); fdF.append('east', b.e);
          fdF.append('minZoom', z0); fdF.append('maxZoom', z1);
          fdF.append('count', sent);
          await post(fdF);
          statusEl.textContent = fmt(I18N.complete, { sent, skipped, ext: fmtInfo.ext });
          doneEl.className = 'hint ok';
          doneEl.textContent = fmt(I18N.next_step, { id, project });
          if (rejected) doneEl.textContent += ' ' + fmt(I18N.rejected, { rejected });
        }
      } catch (e) {
        statusEl.textContent = I18N.error_prefix + (e && e.message ? e.message : I18N.conn_failed);
      }
      running = false; $('stop').disabled = true; estimate();
    });

    redraw();
  </script>
</body>

</html>
