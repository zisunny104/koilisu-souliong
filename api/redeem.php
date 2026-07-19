<?php
// 兌換「分享編輯連結」給的投稿身分（私人PIN／僅限匿名）。POST project, ctoken → 回傳 contrib{token,id,label}。
// token 由前端從網址 fragment（#redeem=...）讀出後帶來，本身即是祕密，不記錄在網址/伺服器存取記錄。
// 到期時間／次數上限只擋「新增投稿」（見 upload.php 的 contrib_check_and_bump），兌換身分本身永遠成功，
// 讓已到期的分享連結持有者仍可用同一身分編輯/移除自己先前投稿過的內容。
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
$cfg = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_out(['error' => 'POST only'], 405); }
rate_limit($cfg, 'write');

$project = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
$ctoken = (string)($_POST['ctoken'] ?? '');
if ($project === '' || !is_dir($cfg['projects_dir'] . '/' . $project) || $ctoken === '' || strlen($ctoken) > 128) {
    json_out(['error' => 'bad request'], 400);
}

[$cid, $label] = contrib_register($cfg, $project, $ctoken, null);
json_out(['ok' => true, 'contrib' => ['token' => $ctoken, 'id' => $cid, 'label' => $label]]);
