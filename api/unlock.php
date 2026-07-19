<?php
// 驗證投稿碼（限特定人上傳）。POST project, code → ok / 403。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'POST only'], 405); }
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project)) { json_out(['error' => 'bad request'], 400); }

$meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $project . '/meta.json'), true);
$real = project_code($cfg, $project, is_array($meta) ? $meta : null);
if ($real === '') { json_out(['ok' => true, 'gated' => false]); }        // 此地圖未 gated
$given = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));   // 純數字碼：容忍空白/貼上
if (!hash_equals($real, $given)) { json_out(['error' => '投稿碼不正確'], 403); }

// 可選：以投稿者 PIN 建立跨裝置身分（cpin），並可帶暱稱（cname）。匿名則不帶。
$cpin = trim((string)($_POST['cpin'] ?? ''));
if ($cpin !== '') {
    if (strlen($cpin) < 4 || strlen($cpin) > 64) { json_out(['error' => '身分 PIN 至少 4 位'], 400); }
    $token = contrib_token($cfg, $project, $cpin);
    [$cid, $label] = contrib_register($cfg, $project, $token, $_POST['cname'] ?? null);
    json_out(['ok' => true, 'contrib' => ['token' => $token, 'id' => $cid, 'label' => $label]]);
}
json_out(['ok' => true]);
