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
json_out(['ok' => true]);
