<?php
/**
 * 簡易資安/韌性工具：每 IP 檔案式速率限制（滑動視窗），緩解洪水與掃描。
 * 無外部相依；限流本身失敗時「放行」而非拒服務（避免自我 DoS）。
 * 位於 Nginx 反代後，需在 config 開 trust_forwarded 才會用 X-Forwarded-For。
 */

function client_ip(array $cfg): string {
    if (!empty($cfg['trust_forwarded']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** 產生好念、無易混字元的投稿碼 */
function gen_code(int $len = 8): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // 去掉 I O 0 1 等易混
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}

/**
 * 取得某項目目前的投稿碼（純後端檔案管理）：
 * meta.gated 為真才需碼；碼存 data/<project>.code.txt，不存在則自動產生。
 * 要換碼 → 直接刪掉該檔，下次呼叫會產生新碼。未 gated 回空字串（開放上傳）。
 */
function project_code(array $cfg, string $project, ?array $meta): string {
    if (empty($meta['gated'])) return '';
    $f = rtrim($cfg['store_dir'], '/\\') . '/' . $project . '.code.txt';
    $c = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if ($c === '') {
        $c = gen_code();
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, $c, LOCK_EX);
    }
    return $c;
}

/** 超過限制時直接以 429 結束請求 */
function rate_limit(array $cfg, string $bucket = 'default'): void {
    $max = $cfg['rate_max'] ?? 40;
    $win = $cfg['rate_window'] ?? 60;
    $dir = rtrim($cfg['store_dir'], '/\\') . '/.rate';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ip = preg_replace('/[^0-9a-f:.]/i', '', client_ip($cfg));
    $f = $dir . '/' . substr(hash('sha256', $bucket . '|' . $ip), 0, 32) . '.txt';
    $now = time();
    $fp = @fopen($f, 'c+');
    if (!$fp) return;                       // 開檔失敗 → 放行
    if (!flock($fp, LOCK_EX)) { fclose($fp); return; }
    $raw = stream_get_contents($fp);
    $hits = array_values(array_filter(array_map('intval', array_filter(explode(',', trim((string)$raw)), 'strlen')), fn($t) => $t > $now - $win));
    if (count($hits) >= $max) {
        flock($fp, LOCK_UN); fclose($fp);
        header('Retry-After: ' . $win);
        json_out(['error' => '請求過於頻繁，請稍後再試'], 429);
    }
    $hits[] = $now;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $hits));
    flock($fp, LOCK_UN); fclose($fp);
}
