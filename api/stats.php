<?php
/**
 * 聚合統計工具：只累加「計數」，不存個資、不存逐筆事件列。
 * 每個項目一個極小的 <project>.stats.json，例如：
 *   {
 *     "views": 1234, "sessions": 320, "uploads": 88,
 *     "points": {"9": 41, "23": 77},        // 各點位被點開次數（熱門點）
 *     "by_hour": {"14": 90, ...},           // 依「使用者本地小時」分佈（探索時段）
 *     "by_dow":  {"6": 210, ...},           // 依星期（0=日）
 *     "device":  {"mobile": 900, "desktop": 334},
 *     "features":{"route": 40, "photos": 120, "filter": 22, "embed": 5, "random": 18, "upload": 88}
 *   }
 * 效能：單一小檔、加鎖讀寫、有 key 數上限，攻擊者無法灌爆。
 *
 * 之後要「顯示」怎麼做（不需另建資料表）：
 *   讀取：登入管理後（cookie）GET  ?api=stat&project=<id>&read=1   （見 stat.php）
 *   前端可用回傳 JSON 畫圖，例如：
 *     - points 由大到小排序 → 熱門點位長條圖 / 在地圖上用大小標記
 *     - by_hour → 24 格熱力/折線；by_dow → 一週長條
 *     - device / features → 圓餅或數字卡
 *   也可在 admin.php 內加一段 <script> fetch 這個 read API 後用 <canvas> 畫。
 */

function stats_file(array $cfg, string $project): string {
    return rtrim($cfg['store_dir'], '/\\') . '/' . $project . '.stats.json';
}

function stats_read(array $cfg, string $project): array {
    $f = stats_file($cfg, $project);
    $s = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return is_array($s) ? $s : [];
}

/** 以檔案鎖套用一批遞增（$fn 收到 &$s 陣列） */
function stats_apply(array $cfg, string $project, callable $fn): void {
    $f = stats_file($cfg, $project);
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $s = json_decode(trim((string)stream_get_contents($fp)), true);
    if (!is_array($s)) $s = [];
    $fn($s);
    $s['updated'] = gmdate('c');
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN); fclose($fp);
}

/** 遞增一個計數；$sub 為子鍵（如點位編號）；$capKeys 限制子鍵數量以防膨脹 */
function stats_bump(array &$s, string $key, $sub = null, int $capKeys = 0): void {
    if ($sub === null) { $s[$key] = ($s[$key] ?? 0) + 1; return; }
    if (!isset($s[$key]) || !is_array($s[$key])) $s[$key] = [];
    if ($capKeys > 0 && !isset($s[$key][$sub]) && count($s[$key]) >= $capKeys) return;
    $s[$key][$sub] = ($s[$key][$sub] ?? 0) + 1;
}
