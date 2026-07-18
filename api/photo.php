<?php
// 由 PHP 輸出照片（框架不供應靜態檔）。用法：?api=photo&f=<project>/<file>
$cfg = require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
// 僅允許 <project>/<檔名>，禁止路徑穿越
if (strpos($f, '..') !== false || !preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_.-]+$#', $f)) {
    http_response_code(400);
    exit;
}
$path = $cfg['photos_dir'] . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $mimes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);
