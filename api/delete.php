<?php
// 刪除自己的投稿：POST project, id, owner(token)。僅 owner_hash 相符才可刪。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'POST only'], 405); }
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
$id      = (string)($_POST['id'] ?? '');
$owner   = (string)($_POST['owner'] ?? '');
if ($project === '' || $id === '' || $owner === '' || strlen($id) > 64 || !is_dir($cfg['projects_dir'] . '/' . $project)) {
    json_out(['error' => 'bad request'], 400);
}

try {
    $rec = null;
    foreach (store_all($cfg, $project) as $r) {
        if ((string)($r['id'] ?? '') === $id) { $rec = $r; break; }
    }
    if (!$rec) { json_out(['error' => 'not found'], 404); }
    $stored = (string)($rec['owner_hash'] ?? '');
    if ($stored === '' || !hash_equals($stored, hash('sha256', $owner))) {
        json_out(['error' => '沒有權限刪除這則（可能是別人上傳的，或此裝置的標記已更換）'], 403);
    }
    $removed = store_delete($cfg, $project, $id);
    if ($removed && !empty($removed['photo'])) { @unlink($cfg['photos_dir'] . '/' . $removed['photo']); }
    json_out(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    error_log('souliong delete: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
