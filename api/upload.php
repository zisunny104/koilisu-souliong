<?php
// POST upload.php  (multipart/form-data)
// 欄位：project, item_num, name, comment, photo_time(ISO), lat, lon, loc_source, photo(檔案)
// append-only：本後端無刪除/修改端點。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = $_POST['project'] ?? '';
if (!preg_match('/^[a-z0-9_-]{1,40}$/', $project) || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'unknown project'], 400);
}

// 投稿碼：限特定人上傳（碼存在 meta.json，前端拿不到，這裡才是真正把關）
$metaU = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $project . '/meta.json'), true);
$needCode = project_code($cfg, $project, is_array($metaU) ? $metaU : null);
if ($needCode !== '' && !hash_equals($needCode, preg_replace('/\D/', '', (string)($_POST['code'] ?? '')))) {
    json_out(['error' => '需要正確的投稿碼才能上傳'], 403);
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

$kind       = ($_POST['kind'] ?? 'photo') === 'desc' ? 'desc' : 'photo';
$name       = clean_str($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
$comment    = clean_str($_POST['comment'] ?? null, $cfg['comment_max']);
$item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
$lat        = num_or_null($_POST['lat'] ?? null);
$lon        = num_or_null($_POST['lon'] ?? null);
$loc_source = clean_str($_POST['loc_source'] ?? null, 16);
$photo_time = clean_str($_POST['photo_time'] ?? null, 40);
if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

$photoRel = null;
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
    $destDir = $cfg['photos_dir'] . '/' . $project;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destAbs = $destDir . '/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
        json_out(['error' => 'save failed'], 500);
    }
    $photoRel = $project . '/' . $fname;
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
    $contribId = $ctoken !== '' ? contrib_id_of($ctoken) : null;
    $contribHash = $ctoken !== '' ? contrib_hash_of($ctoken) : null;

    $record = [
        'id'         => bin2hex(random_bytes(8)),
        'project'    => $project,
        'item_num'   => $item_num,
        'kind'       => $kind,
        'name'       => $name,
        'comment'    => $comment,
        'photo'      => $photoRel,
        'photo_time' => $photo_time,
        'lat'        => $lat,
        'lon'        => $lon,
        'loc_source' => $loc_source,
        'exif'       => $exif,
        'owner_hash' => $owner !== '' ? hash('sha256', $owner) : null,   // 用於「只刪自己的」與同源追蹤
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
