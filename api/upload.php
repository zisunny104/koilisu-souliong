<?php
// POST upload.php  (multipart/form-data)
// 欄位：project, item_num, name, comment, photo_time(ISO), lat, lon, loc_source, photo(檔案)
// append-only：本後端無刪除/修改端點。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'upload');

$project = $_POST['project'] ?? '';
if (!preg_match('/^[a-z0-9_-]{1,40}$/', $project) || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'unknown project'], 400);
}

// 停權檢查：被主辦者鎖定的身分（PIN 投稿者或匿名裝置）一律擋下，不論投稿碼是否有效（不影響已投稿內容）。
$blockOwnerHash = !empty($_POST['owner']) ? hash('sha256', (string)$_POST['owner']) : null;
$blockContribId = !empty($_POST['ctoken']) ? contrib_id_of((string)$_POST['ctoken']) : null;
if (is_blocked($cfg, $project, $blockOwnerHash, $blockContribId)) {
    json_out(['error' => '此身分已被主辦者停權，無法繼續投稿'], 403);
}

// 投稿碼：限特定人上傳（碼存後端檔案，前端拿不到，這裡才是真正把關）。已用管理 PIN 登入者視為已解鎖。
// 能不能投稿完全看投稿碼（codes.json，各自可設到期/次數）；這裡計一次使用。
$metaU = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $project . '/meta.json'), true);
$gated = !empty($metaU['gated']);
$givenCode = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
if ($gated && !admin_can($cfg, $project) && !code_check($cfg, $project, $givenCode, true)) {
    json_out(['error' => '需要正確的投稿碼才能上傳（碼可能已到期或用完次數）'], 403);
}

function clean_str(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s); // 去除控制字元
    $s = trim($s);
    if ($s === '') return null;
    // 以 UTF-8 為單位截斷（用 PCRE /u，不依賴 mbstring）
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}
function num_or_null($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}

$kind       = array_key_exists($_POST['kind'] ?? '', souliong_kinds()) ? (string)$_POST['kind'] : 'photo';
$name       = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
$comment    = clean_str($_POST['comment'] ?? null, $cfg['comment_max']);
$item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
$lat        = num_or_null($_POST['lat'] ?? null);
$lon        = num_or_null($_POST['lon'] ?? null);
$loc_source = clean_str($_POST['loc_source'] ?? null, 16);
$photo_time = clean_str($_POST['photo_time'] ?? null, 40);
if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

// 授權：CC BY（姓名標示）只對已建立身分（有 ctoken）的投稿者開放，沒有穩定身分就沒有名字可標示，
// 一律以伺服器端這裡認定的身分為準，不採信前端畫面上勾選框當下是否可見。
$hasIdentity = !empty($_POST['ctoken']);
$license     = ($hasIdentity && ($_POST['license'] ?? '') === 'cc-by') ? 'cc-by' : 'cc0';
$wikidataOk  = !empty($_POST['wikidata_ok']);

$photoRel = null;
$thumbRel = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['photo'];
    if ($f['size'] > $cfg['max_bytes']) {
        json_out(['error' => 'file too large'], 413);
    }
    // 以 getimagesize 判斷實際影像類型（不依賴 fileinfo 擴充，較可攜）
    $info = @getimagesize($f['tmp_name']);
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    if ($mime === '' && class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    }
    if (!isset($cfg['allowed_mime'][$mime])) {
        json_out(['error' => 'unsupported type: ' . $mime], 415);
    }
    $ext = $cfg['allowed_mime'][$mime];
    $destDir = project_dir($cfg, $project) . '/photos';
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    // 檔名用「照片實際拍攝時間」（EXIF／裝置時間），不是伺服器收到上傳的時間，方便直接依檔名辨識拍攝先後
    $shotTs = $photo_time !== null ? strtotime($photo_time) : false;
    $fbase = date('Ymd_His', $shotTs !== false ? $shotTs : time()) . '_' . bin2hex(random_bytes(4));
    $fname = $fbase . '.' . $ext;
    $destAbs = $destDir . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
        json_out(['error' => 'save failed'], 500);
    }
    $photoRel = $project . '/' . $fname;

    // 顯示用縮圖（前端隨主圖一起產生；沒有或存失敗都不影響投稿本身，顯示端會 fallback 用原圖）
    if (isset($_FILES['thumb']) && $_FILES['thumb']['error'] === UPLOAD_ERR_OK) {
        $tf = $_FILES['thumb'];
        $tinfo = @getimagesize($tf['tmp_name']);
        $tmime = is_array($tinfo) ? ($tinfo['mime'] ?? '') : '';
        if ($tf['size'] <= 1024 * 1024 && isset($cfg['allowed_mime'][$tmime])) {
            $tname = $fbase . '_t.' . $cfg['allowed_mime'][$tmime];
            if (@move_uploaded_file($tf['tmp_name'], $destDir . '/' . $tname)) {
                $thumbRel = $project . '/' . $tname;
            }
        }
    }
}

if ($kind === 'desc') {
    // 說明版本：必須有文字與所屬點位
    if ($comment === null) { json_out(['error' => 'need comment'], 400); }
    if ($item_num === null) { json_out(['error' => 'need item_num'], 400); }
} elseif ($photoRel === null && $comment === null) {
    json_out(['error' => 'need photo or comment'], 400);
}

try {
    $owner = (string)($_POST['owner'] ?? '');
    $ownerHash = $blockOwnerHash;

    // 相機 EXIF 參數（前端送 JSON；白名單欄位、限縮長度與大小）
    $exif = null;
    if (!empty($_POST['exif']) && strlen((string)$_POST['exif']) <= 600) {
        $e = json_decode((string)$_POST['exif'], true);
        if (is_array($e)) {
            $exif = [];
            foreach (['make', 'model', 'lens', 'sw'] as $k) {
                if (isset($e[$k])) { $v = clean_str((string)$e[$k], 60); if ($v !== null) $exif[$k] = $v; }
            }
            foreach (['f', 'exp', 'focal'] as $k) {
                if (isset($e[$k]) && is_numeric($e[$k])) $exif[$k] = (float)$e[$k];
            }
            if (isset($e['iso']) && is_numeric($e['iso'])) $exif['iso'] = (int)$e['iso'];
            if (!$exif) $exif = null;
        }
    }

    // 冒名鑑識：加鹽來源 IP 雜湊（僅管理端可見，list 不外流；可於 config 關閉）
    $srcHash = !empty($cfg['log_src']) ? substr(hash('sha256', ($cfg['ip_salt'] ?? '') . '|' . client_ip($cfg)), 0, 16) : null;

    // 可選的投稿者身分（ctoken 由 localStorage 帶入）：存公開短 ID 供分組顯示、存 hash 供跨裝置驗刪
    $ctoken = (string)($_POST['ctoken'] ?? '');
    $contribId = $blockContribId;
    $contribHash = $ctoken !== '' ? contrib_hash_of($ctoken) : null;

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'item_num'   => $item_num,
        'kind'       => $kind,
        'name'       => $name,
        'comment'    => $comment,
        'photo'      => $photoRel,
        'thumb'      => $thumbRel,
        'photo_time' => $photo_time,
        'lat'        => $lat,
        'lon'        => $lon,
        'loc_source' => $loc_source,
        'license'    => $license,
        'wikidata_ok'=> $wikidataOk,
        'exif'       => $exif,
        'owner_hash' => $ownerHash,                                       // 用於「只刪自己的」與同源追蹤
        'src_hash'   => $srcHash,                                        // 冒名鑑識用，不外流
        'contrib_id' => $contribId,                                      // 可選：對外可見的假名投稿者ID（分組用）
        'contrib_hash' => $contribHash,                                 // 可選：跨裝置驗刪用，不外流
        'created_at' => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    // 統計：上傳數 + 相機型號（有上限，防膨脹）
    stats_apply($cfg, $project, function (&$s) use ($exif) {
        stats_bump($s, 'uploads');
        if ($exif && !empty($exif['model'])) stats_bump($s, 'cameras', $exif['model'], 300);
    });

    $out = $record;
    unset($out['src_hash'], $out['contrib_hash']);                      // 不外流 IP 雜湊與身分驗刪雜湊
    $out['photo_url'] = $photoRel ? ('photos/' . $photoRel) : null;
    json_out(['ok' => true, 'item' => $out]);
} catch (Throwable $e) {
    error_log('souliong upload: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
