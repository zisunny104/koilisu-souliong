<?php
// list：回傳某項目所有投稿（依時間）。純檔案儲存，零擴充依賴。
require __DIR__ . '/store.php';
$cfg = require __DIR__ . '/config.php';

$project = $_GET['project'] ?? '';
if (!preg_match('/^[a-z0-9_-]{1,40}$/', $project)) {
    json_out(['error' => 'invalid project'], 400);
}

try {
    $rows = store_all($cfg, $project);
    usort($rows, function ($a, $b) {
        $ta = $a['photo_time'] ?? ($a['created_at'] ?? '');
        $tb = $b['photo_time'] ?? ($b['created_at'] ?? '');
        return strcmp((string)$ta, (string)$tb);
    });
    foreach ($rows as &$r) {
        unset($r['src_hash'], $r['contrib_hash']);   // 鑑識用 IP 雜湊、身分驗刪雜湊，不對外
        $r['photo_url'] = !empty($r['photo']) ? ('photos/' . $r['photo']) : null;
    }
    unset($r);
    json_out(['project' => $project, 'items' => array_values($rows)]);
} catch (Throwable $e) {
    error_log('souliong list: ' . $e->getMessage());
    json_out(['error' => 'server'] + (!empty($cfg['debug']) ? ['detail' => $e->getMessage()] : []), 500);
}
