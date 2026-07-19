<?php
/**
 * Souliong 循跡 — KoiLiSu app 入口
 * KoiLiSu 框架走「路徑」路由且不供應靜態檔，所以：
 *   - API 用路徑式：<base>/list/<project>、<base>/upload(POST)、<base>/photo/<project>/<file>、<base>/admin
 *   - CSS/JS/資料由 view.php 內嵌輸出
 * 路徑參數自 REQUEST_URI 解析（不依賴 query string 是否被保留）。
 */
$config = include __DIR__ . '/config.php';

$appName = $_APP['name'] ?? basename(__DIR__);
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$m = strpos($reqPath, '/' . $appName);
if ($m !== false) {
    $after = ltrim(substr($reqPath, $m + strlen($appName) + 1), '/');
} else {
    $after = ltrim($reqPath, '/');   // 獨立部署
}
$seg = array_values(array_filter(explode('/', rawurldecode($after)), 'strlen'));
// 動作來源優先序：路徑段 → ?api= → 框架 action → index（兩種寫法都相容）
$action = $seg[0] ?? null;
if ($action === null || $action === '') { $action = $_GET['api'] ?? ($_APP['action'] ?? 'index'); }

switch ($action) {
    case 'list':
        $_GET['project'] = $seg[1] ?? ($_GET['project'] ?? '');
        require __DIR__ . '/api/list.php';
        return;
    case 'upload':
        require __DIR__ . '/api/upload.php';
        return;
    case 'photo':
        $_GET['f'] = count($seg) > 1 ? implode('/', array_slice($seg, 1)) : ($_GET['f'] ?? '');
        require __DIR__ . '/api/photo.php';
        return;
    case 'delete':
        require __DIR__ . '/api/delete.php';
        return;
    case 'editentry':
        require __DIR__ . '/api/editentry.php';
        return;
    case 'editpoint':
        require __DIR__ . '/api/editpoint.php';
        return;
    case 'unlock':
        require __DIR__ . '/api/unlock.php';
        return;
    case 'redeem':
        require __DIR__ . '/api/redeem.php';
        return;
    case 'exiffix':
        require __DIR__ . '/api/exiffix.php';   // 常駐維護工具：修復投稿缺少相機 EXIF 資訊
        return;
    case 'stat':
        require __DIR__ . '/api/stat.php';
        return;
    case 'admin':
    case 'manager':
        require __DIR__ . '/api/admin.php';        // 主站管理：<base>/manager（相容 /admin、?api=admin）
        return;
    case 'privacy':
        include __DIR__ . '/pages/privacy.php';    // 隱私與資料說明：<base>/privacy
        return;
    default:
        // 地圖或首頁：<base>/<mapid> 開該地圖；<base>/ 顯示地圖清單
        $proj = ($action !== 'index' && $action !== '') ? $action : ($_GET['p'] ?? '');
        $proj = preg_replace('/[^a-z0-9_-]/', '', $proj);
        if ($proj !== '' && is_dir(__DIR__ . '/projects/' . $proj)) {
            // <base>/<mapid>/manager|edit → 該專案管理
            if (isset($seg[1]) && in_array($seg[1], ['manager', 'edit', 'admin'], true)) {
                $_GET['project'] = $proj;
                require __DIR__ . '/api/admin.php';
                return;
            }
            $_GET['p'] = $proj;
            include __DIR__ . '/pages/view.php';
        } else {
            include __DIR__ . '/pages/landing.php';
        }
        return;
}
