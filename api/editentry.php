<?php
// 編輯自己（或管理者可管理的）照片投稿：只能改文字/關聯地點/定位，不能換照片本身。
// POST project, edit_of(原始投稿 id), item_num(可留空), comment, lat, lon, loc_source, name, owner 或 ctoken（需與原投稿相符，或具管理權限）。
// 比照「故事」的版本化精神：不覆寫舊資料，而是新增一筆引用原始 id 的版本紀錄；原始紀錄與所有舊版本永久保留。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
$editOf  = (string)($_POST['edit_of'] ?? '');
if ($project === '' || $editOf === '' || strlen($editOf) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

function clean_str_ee(?string $s, int $max): ?string {
    if ($s === null) return null;
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^.{0,' . $max . '}/us', $s, $m)) $s = $m[0];
    return $s;
}
function num_or_null_ee($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}

try {
    // 找出原始紀錄：只能編輯「照片投稿」，不能編輯「故事」（故事已有自己的版本機制）
    $orig = null;
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $editOf) { $orig = $r; break; }
    }
    if (!$orig || ($orig['kind'] ?? 'photo') === 'desc' || empty($orig['photo'])) {
        json_out(['error' => 'not found'], 404);
    }

    // 權限：原投稿者本人（owner 或 ctoken 相符）或管理者（主 PIN 或此專案 PIN）
    $owner  = (string)($_POST['owner'] ?? '');
    $ctoken = (string)($_POST['ctoken'] ?? '');
    $ownerStored   = (string)($orig['owner_hash'] ?? '');
    $contribStored = (string)($orig['contrib_hash'] ?? '');
    $ownerOk   = $owner !== '' && $ownerStored !== '' && hash_equals($ownerStored, hash('sha256', $owner));
    $contribOk = $ctoken !== '' && $contribStored !== '' && hash_equals($contribStored, contrib_hash_of($ctoken));
    if (!$ownerOk && !$contribOk && !admin_perm($cfg, $project, 'edit_others')) {
        json_out(['error' => '沒有權限編輯這則（只有原投稿者本人或管理者可以）'], 403);
    }

    $comment    = clean_str_ee($_POST['comment'] ?? null, $cfg['comment_max']);
    $item_num   = (isset($_POST['item_num']) && $_POST['item_num'] !== '') ? (int)$_POST['item_num'] : null;
    $lat        = num_or_null_ee($_POST['lat'] ?? null);
    $lon        = num_or_null_ee($_POST['lon'] ?? null);
    $loc_source = clean_str_ee($_POST['loc_source'] ?? null, 16) ?? 'manual';
    if ($lat !== null && ($lat < -90 || $lat > 90)) $lat = null;
    if ($lon !== null && ($lon < -180 || $lon > 180)) $lon = null;

    $editorName  = clean_str_ee($_POST['name'] ?? null, $cfg['name_max']) ?? '匿名';
    $contribId   = $ctoken !== '' ? contrib_id_of($ctoken) : null;
    $contribHash = $ctoken !== '' ? contrib_hash_of($ctoken) : null;
    $srcHash     = !empty($cfg['log_src']) ? substr(hash('sha256', ($cfg['ip_salt'] ?? '') . '|' . client_ip($cfg)), 0, 16) : null;

    $record = [
        'id'           => bin2hex(random_bytes(8)),
        'project'      => $project,
        'item_num'     => $item_num,
        'kind'         => 'photo',
        'edit_of'      => $editOf,               // 指向被編輯的原始投稿 id，供前端組出「最新版本」
        'name'         => $editorName,
        'comment'      => $comment,
        'photo'        => null,                  // 編輯不換照片本身，顯示時沿用原始那張
        'photo_time'   => $orig['photo_time'] ?? null,
        'lat'          => $lat,
        'lon'          => $lon,
        'loc_source'   => $loc_source,
        'exif'         => null,
        'owner_hash'   => $owner !== '' ? hash('sha256', $owner) : null,
        'src_hash'     => $srcHash,
        'contrib_id'   => $contribId,
        'contrib_hash' => $contribHash,
        'created_at'   => gmdate('c'),
    ];
    store_append($cfg, $project, $record);

    $out = $record;
    unset($out['src_hash'], $out['contrib_hash']);
    json_out(['ok' => true, 'item' => $out]);
} catch (Throwable $e) {
    error_log('souliong editentry: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
