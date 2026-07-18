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

/**
 * 管理登入：多 PIN + 簡易權限管理。
 * - 主 PIN：config['admin_pin']（bootstrap）+ data/admin_pins.json 的 master 清單，皆為全域權限。
 * - 各專案 PIN：data/admin_pins.json 的 projects[<id>] 清單，僅該專案。
 * - 每把 PIN 可帶暱稱 label。
 * - cookie 由「鹽值」衍生（代表已通過某範圍），與特定 PIN 解耦；移除某 PIN 不影響既有登入，
 *   需全面登出可更換 ip_salt。
 */
define('ADMIN_COOKIE', 'souliong_admin');
function admin_derived(array $cfg): string { return hash_hmac('sha256', 'souliong-admin', (string)($cfg['ip_salt'] ?? '')); }
function padm_cookie_name(string $project): string { return 'souliong_padm_' . preg_replace('/[^a-z0-9_-]/', '', $project); }
function padm_derived(array $cfg, string $project): string { return hash_hmac('sha256', 'souliong-padm', $project . '|' . (string)($cfg['ip_salt'] ?? '')); }

function admin_authed(array $cfg): bool { return hash_equals(admin_derived($cfg), (string)($_COOKIE[ADMIN_COOKIE] ?? '')); }
function admin_can(array $cfg, string $project): bool {
    if (admin_authed($cfg)) return true;
    return hash_equals(padm_derived($cfg, $project), (string)($_COOKIE[padm_cookie_name($project)] ?? ''));
}
function _cookie_opts(): array { return ['expires' => time() + 7 * 86400, 'path' => '/', 'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Lax']; }
function admin_set_cookie(array $cfg): void { setcookie(ADMIN_COOKIE, admin_derived($cfg), _cookie_opts()); }
function padm_set_cookie(array $cfg, string $project): void { setcookie(padm_cookie_name($project), padm_derived($cfg, $project), _cookie_opts()); }
function admin_clear_cookie(): void {
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    foreach ($_COOKIE as $k => $v) { if (strpos($k, 'souliong_padm_') === 0) setcookie($k, '', ['expires' => time() - 3600, 'path' => '/']); }
}

// ── PIN 清單（data/admin_pins.json） ──
function pins_file(array $cfg): string { return rtrim($cfg['store_dir'], '/\\') . '/admin_pins.json'; }
function pins_load(array $cfg): array {
    $d = is_file(pins_file($cfg)) ? json_decode((string)@file_get_contents(pins_file($cfg)), true) : null;
    if (!is_array($d)) $d = [];
    $d['master'] = $d['master'] ?? [];
    $d['projects'] = $d['projects'] ?? [];
    return $d;
}
function pins_save(array $cfg, array $d): void { @file_put_contents(pins_file($cfg), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
function _pin_in(array $list, string $pin): bool { foreach ($list as $e) { if (isset($e['pin']) && $pin !== '' && hash_equals((string)$e['pin'], $pin)) return true; } return false; }
function check_master_pin(array $cfg, string $pin): bool {
    if ($pin === '') return false;
    if (($cfg['admin_pin'] ?? '') !== '' && hash_equals((string)$cfg['admin_pin'], $pin)) return true;
    return _pin_in(pins_load($cfg)['master'], $pin);
}
function check_project_pin(array $cfg, string $project, string $pin): bool {
    if ($pin === '') return false;
    $d = pins_load($cfg);
    return _pin_in($d['projects'][$project] ?? [], $pin);
}

/**
 * 產生投稿碼：純數字，方便手機數字鍵盤輸入、避免自動大寫/自動更正把英數碼改壞。
 * 純數字＋速率限制（每分鐘上限）對「社群上傳閘門」這類低風險場景足夠；碼本就以連結/QR 公開分享。
 */
function gen_code(int $len = 6): string {
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= (string)random_int(0, 9);
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
