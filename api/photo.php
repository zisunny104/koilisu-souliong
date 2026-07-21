<?php
// 由 PHP 輸出照片（框架不供應靜態檔）。用法：?api=photo&f=<project>/<file>
// &th=1 輸出縮圖：用既有 <檔名>_t.* 檔，沒有就以 GD 產生一次存檔；產不出來（無 GD/WebP）退回原圖。
require __DIR__ . '/store.php';
$cfg = require __DIR__ . '/config.php';

$f = $_GET['f'] ?? '';
// 僅允許 <project>/<檔名>，禁止路徑穿越
if (strpos($f, '..') !== false || !preg_match('#^[a-z0-9_-]+/[A-Za-z0-9_.-]+$#', $f)) {
    http_response_code(400);
    exit;
}
$path = photo_abs_path($cfg, $f);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}
if (!empty($_GET['th'])) {
    $t = photo_thumb_of($path);
    if ($t !== null) $path = $t;
}
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$mime = $mimes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);

/** 既有或新產生的縮圖檔路徑；產不出來（無 GD、格式不支援、寫檔失敗…）回 null，由呼叫端輸出原圖 */
function photo_thumb_of(string $path): ?string {
    $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $path);
    if (substr($base, -2) === '_t') return null;   // 要的本來就是縮圖檔，別再產「縮圖的縮圖」
    foreach (['webp', 'jpg', 'png'] as $te) {
        if (is_file($base . '_t.' . $te)) return $base . '_t.' . $te;
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) return null;
    $info = @getimagesize($path);
    // 防止對超大圖解壓（GD 會整張展開進記憶體）；正常投稿都是前端縮過的 ≤1600px
    if (!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) return null;
    $loaders = ['image/webp' => 'imagecreatefromwebp', 'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng'];
    $loader = $loaders[$info['mime'] ?? ''] ?? null;
    if ($loader === null || !function_exists($loader)) return null;
    $src = @$loader($path);
    if (!$src) return null;
    $w = $info[0];
    $h = $info[1];
    $max = 640;   // 跟前端上傳縮圖同規格（最長邊 640）
    if (max($w, $h) > $max) {
        $s = $max / max($w, $h);
        $tw = max(1, (int)round($w * $s));
        $th = max(1, (int)round($h * $s));
    } else {
        $tw = $w;
        $th = $h;
    }
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // 透明 PNG 壓白底（縮圖僅供顯示）
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($src);
    if (function_exists('imagewebp')) {
        $out = $base . '_t.webp';
        $write = fn(string $p): bool => imagewebp($dst, $p, 78);
    } elseif (function_exists('imagejpeg')) {
        $out = $base . '_t.jpg';
        $write = fn(string $p): bool => imagejpeg($dst, $p, 80);
    } else {
        imagedestroy($dst);
        return null;
    }
    // 先寫暫存檔再 rename，避免同時兩個請求產同一張時互吃半成品
    $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @$write($tmp);
    imagedestroy($dst);
    if (!$ok || !@rename($tmp, $out)) {
        @unlink($tmp);
        return null;
    }
    return $out;
}
