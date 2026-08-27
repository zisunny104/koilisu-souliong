<?php
// 由 PHP 輸出自訂 3D 模型檔（框架不供應靜態檔，理由同 api/photo.php／api/layerfile.php）。
// 用法：<base>/model3d/<project>/<id>/model.glb
//
// 跟 layerfile.php 的差別：glTF 二進位（.glb）本身就是幾何/貼圖打包成單一檔案的格式，一個區域
// 固定只有一個檔名，不像圖層有圖磚金字塔那種深路徑，所以這裡不需要遞迴路徑白名單，直接鎖死
// 檔名為 model.glb。
require_once __DIR__ . '/regions3d.php';
$cfg = require __DIR__ . '/config.php';

$raw = (string)($_GET['f'] ?? '');
if (!preg_match('#^([a-z0-9_-]+)/([a-z0-9][a-z0-9_-]{0,31})/model\.glb$#', $raw, $m)) {
    http_response_code(404);
    exit;
}
[, $proj, $id] = $m;

$dir  = souliong_region3d_dir($cfg, $proj, $id);
$real = ($dir === null) ? false : realpath($dir . '/model.glb');
$root = ($dir === null) ? false : realpath($dir);
// realpath 收尾：就算前面的字元白名單哪天被繞過，實體路徑也必須落在該區域資料夾之內
$norm = fn($p) => str_replace('\\', '/', (string)$p);
$ok = $real !== false && $root !== false
    && strpos($norm($real), $norm($root) . '/') === 0
    && is_file($real);

if (!$ok) {
    http_response_code(404);
    exit;
}

header('Content-Type: model/gltf-binary');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($real));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($real);
