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
// cookie 值＝"<pinId>.<簽章>"：簽章綁定 project+pinId，讓 cookie 記得「用哪一把專案 PIN 登入」，
// 才能做到權限旗標可個別下放到特定專案 PIN（而非只要有登入任一把就視為同權）。
function padm_derived(array $cfg, string $project, string $pinId): string { return hash_hmac('sha256', 'souliong-padm', $project . '|' . $pinId . '|' . (string)($cfg['ip_salt'] ?? '')); }

function admin_authed(array $cfg): bool { return hash_equals(admin_derived($cfg), (string)($_COOKIE[ADMIN_COOKIE] ?? '')); }

/** 解析目前這個專案的 padm cookie，回傳驗證通過的 pinId；未登入或簽章不符回傳 null。 */
function padm_pin_id(array $cfg, string $project): ?string {
    $raw = (string)($_COOKIE[padm_cookie_name($project)] ?? '');
    $dot = strrpos($raw, '.');
    if ($dot === false) return null;
    $pinId = substr($raw, 0, $dot);
    $sig = substr($raw, $dot + 1);
    if ($pinId === '' || !hash_equals(padm_derived($cfg, $project, $pinId), $sig)) return null;
    return $pinId;
}
function admin_can(array $cfg, string $project): bool {
    if (admin_authed($cfg)) return true;
    return padm_pin_id($cfg, $project) !== null;
}
/** 權限式判斷：主 PIN 永遠通過；專案 PIN 則需該把 PIN 的 perms[$permKey] 已被主 PIN 開啟才通過。 */
function admin_perm(array $cfg, string $project, string $permKey): bool {
    if (admin_authed($cfg)) return true;
    $pinId = padm_pin_id($cfg, $project);
    if ($pinId === null) return false;
    foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
        if ((string)($e['id'] ?? '') === $pinId) return !empty($e['perms'][$permKey]);
    }
    return false;
}
function _cookie_opts(): array { return ['expires' => time() + 7 * 86400, 'path' => '/', 'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), 'samesite' => 'Lax']; }
function admin_set_cookie(array $cfg): void { setcookie(ADMIN_COOKIE, admin_derived($cfg), _cookie_opts()); }
function padm_set_cookie(array $cfg, string $project, string $pinId): void { setcookie(padm_cookie_name($project), $pinId . '.' . padm_derived($cfg, $project, $pinId), _cookie_opts()); }
function admin_clear_cookie(): void {
    setcookie(ADMIN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    foreach ($_COOKIE as $k => $v) { if (strpos($k, 'souliong_padm_') === 0) setcookie($k, '', ['expires' => time() - 3600, 'path' => '/']); }
}

// ── PIN 清單（state/admin_pins.json） ──
function pins_file(array $cfg): string { return rtrim($cfg['state_dir'], '/\\') . '/admin_pins.json'; }
/** 新專案 PIN 的預設權限：一律從全關始（等同僅主 PIN 才能動別人的東西），需主 PIN 逐項開啟下放。 */
function pin_default_perms(): array { return ['delete_others' => false, 'edit_others' => false, 'edit_points' => false, 'delegate_admin' => false]; }
function pins_load(array $cfg): array {
    $d = is_file(pins_file($cfg)) ? json_decode((string)@file_get_contents(pins_file($cfg)), true) : null;
    if (!is_array($d)) $d = [];
    $d['master'] = $d['master'] ?? [];
    $d['projects'] = $d['projects'] ?? [];
    // 舊資料補齊 id/perms（一次性、自我修復）
    $dirty = false;
    foreach ($d['projects'] as $p => &$list) {
        foreach ($list as &$e) {
            if (empty($e['id'])) { $e['id'] = bin2hex(random_bytes(4)); $dirty = true; }
            if (!isset($e['perms']) || !is_array($e['perms'])) { $e['perms'] = pin_default_perms(); $dirty = true; }
        }
        unset($e);
    }
    unset($list);
    if ($dirty) pins_save($cfg, $d);
    return $d;
}
function pins_save(array $cfg, array $d): void { @file_put_contents(pins_file($cfg), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
function _pin_in(array $list, string $pin): bool { foreach ($list as $e) { if (isset($e['pin']) && $pin !== '' && hash_equals((string)$e['pin'], $pin)) return true; } return false; }
function check_master_pin(array $cfg, string $pin): bool {
    if ($pin === '') return false;
    if (($cfg['admin_pin'] ?? '') !== '' && hash_equals((string)$cfg['admin_pin'], $pin)) return true;
    return _pin_in(pins_load($cfg)['master'], $pin);
}
/** 找出符合此 PIN 的專案 PIN 紀錄（含 id/perms），供登入時決定 cookie 要記哪把；不符合回傳 null。 */
function project_pin_match(array $cfg, string $project, string $pin): ?array {
    if ($pin === '') return null;
    foreach (pins_load($cfg)['projects'][$project] ?? [] as $e) {
        if (isset($e['pin']) && hash_equals((string)$e['pin'], $pin)) return $e;
    }
    return null;
}
function check_project_pin(array $cfg, string $project, string $pin): bool { return project_pin_match($cfg, $project, $pin) !== null; }
/**
 * 登入時的到期/次數檢查（僅對有設定 expires_at/max_uses 的專案 PIN 有效，例如分享建立的「管理PIN」連結；
 * 一般專案 PIN 兩者皆為 null，恆放行）。超過限制回傳 false；否則（若有 max_uses）used_count++ 並存檔。
 */
function pins_check_and_bump(array $cfg, string $project, string $pinId): bool {
    $d = pins_load($cfg);
    $list = $d['projects'][$project] ?? [];
    foreach ($list as $i => $e) {
        if ((string)($e['id'] ?? '') !== $pinId) continue;
        if (!empty($e['expires_at']) && gmdate('c') > (string)$e['expires_at']) return false;
        $max = $e['max_uses'] ?? null;
        $used = (int)($e['used_count'] ?? 0);
        if ($max !== null && $used >= (int)$max) return false;
        if ($max !== null) {
            $d['projects'][$project][$i]['used_count'] = $used + 1;
            pins_save($cfg, $d);
        }
        return true;
    }
    return true;   // 找不到 id（理論上不會發生）：不因此擋登入
}
/**
 * 後台「分享編輯連結」建立管理PIN用：寫入一筆專案 PIN entry，perms 一律從全關始（下放權限需另用 setperm 逐項開啟）。
 * 呼叫端須自行檢查「只有主 PIN 或已被授權 delegate_admin 的專案 PIN 才能建立」。
 * 回傳 [pin, id] 供後台組出分享連結／QR。
 */
function pins_grant_create(array $cfg, string $project, ?string $pin, ?string $label, ?string $expiresAt, ?int $maxUses): array {
    $pin = ($pin !== null && $pin !== '') ? $pin : bin2hex(random_bytes(6));
    $d = pins_load($cfg);
    $entry = [
        'pin'        => $pin,
        'label'      => $label !== null ? substr(trim((string)$label), 0, 80) : '',
        'id'         => bin2hex(random_bytes(4)),
        'perms'      => pin_default_perms(),
        'expires_at' => $expiresAt,
        'max_uses'   => $maxUses,
        'used_count' => 0,
        'via_link'   => true,
    ];
    $d['projects'][$project] = $d['projects'][$project] ?? [];
    $d['projects'][$project][] = $entry;
    pins_save($cfg, $d);
    return [$pin, (string)$entry['id']];
}
function _label_in(array $list, string $pin): string {
    foreach ($list as $e) { if (isset($e['pin']) && $pin !== '' && hash_equals((string)$e['pin'], $pin)) return trim((string)($e['label'] ?? '')); }
    return '';
}
/** 登入用的這把 PIN 若有設定暱稱，回傳暱稱；bootstrap 主 PIN 對應 config['admin_pin_label']。供登入後帶入投稿身分。 */
function master_pin_label(array $cfg, string $pin): string {
    if (($cfg['admin_pin'] ?? '') !== '' && hash_equals((string)$cfg['admin_pin'], $pin)) {
        return trim((string)($cfg['admin_pin_label'] ?? ''));
    }
    return _label_in(pins_load($cfg)['master'], $pin);
}
function project_pin_label(array $cfg, string $project, string $pin): string {
    return _label_in(pins_load($cfg)['projects'][$project] ?? [], $pin);
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
 * meta.gated 為真才需碼；碼存 projects/<project>/code.txt，不存在則自動產生。
 * 要換碼 → 直接刪掉該檔，下次呼叫會產生新碼。未 gated 回空字串（開放上傳）。
 */
function project_code(array $cfg, string $project, ?array $meta): string {
    if (empty($meta['gated'])) return '';
    $f = project_dir($cfg, $project) . '/code.txt';
    $c = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if ($c === '') {
        $c = gen_code();
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, $c, LOCK_EX);
    }
    return $c;
}

/**
 * 投稿者身分（可選，設 PIN 才有；匿名投稿者無此身分）：可用一組 PIN 建立跨裝置的身分，用來在別的裝置管理自己的投稿。
 * - token：由 PIN 衍生（同 PIN 同 token），存於使用者 localStorage，是真正的祕密。
 * - contrib_id：token 的短雜湊，作為對外可見的「投稿者ID」（假名、可分組，無法反推 PIN）。
 * - contrib_hash：token 的完整雜湊，存於投稿以驗證刪除；list 不外流。
 */
function contrib_token(array $cfg, string $project, string $pin): string {
    return hash_hmac('sha256', 'souliong-contrib', $project . '|' . $pin . '|' . (string)($cfg['ip_salt'] ?? ''));
}
function contrib_id_of(string $token): string { return substr(hash('sha256', 'cid|' . $token), 0, 12); }
function contrib_hash_of(string $token): string { return hash('sha256', $token); }

// 投稿者名冊：projects/<project>/contrib.json = { <contrib_id>: {label, created, assigned} }
function contrib_file(array $cfg, string $project): string { return project_dir($cfg, $project) . '/contrib.json'; }
function contrib_load(array $cfg, string $project): array {
    $f = contrib_file($cfg, $project);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}
function contrib_save(array $cfg, string $project, array $d): void { @file_put_contents(contrib_file($cfg, $project), json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); }
/** 註冊或取得投稿者；回傳 [id, label]。label 僅在首次或本人更新時寫入。 */
function contrib_register(array $cfg, string $project, string $token, ?string $label): array {
    $id = contrib_id_of($token);
    $d = contrib_load($cfg, $project);
    $label = $label !== null ? preg_replace('/^(.{0,40}).*$/su', '$1', trim($label)) : null;
    if ($label === null) $label = '';
    if (!isset($d[$id])) {
        $d[$id] = [
            'label'        => $label,
            'created'      => gmdate('c'),
            'expires_at'   => null,
            'max_uses'     => null,
            'used_count'   => 0,
            'grants_admin' => false,
            'via_link'     => false,
        ];
        contrib_save($cfg, $project, $d);
    }
    elseif ($label !== '' && ($d[$id]['label'] ?? '') !== $label && empty($d[$id]['assigned'])) { $d[$id]['label'] = $label; contrib_save($cfg, $project, $d); }
    return [$id, $d[$id]['label'] ?? ''];
}

/**
 * 新增投稿前檢查（僅擋「新增投稿」，不影響本人編輯/刪除已投稿過的內容）：
 * entry 不存在（尚未登記過，例如全新自訂投稿 PIN）視為可投稿，交由 upload 流程走既有 contrib_register 首次建立；
 * entry 存在則檢查 expires_at/max_uses：超過回傳 false，否則 used_count++ 並存檔、回傳 true。
 */
function contrib_check_and_bump(array $cfg, string $project, string $token): bool {
    $id = contrib_id_of($token);
    $d = contrib_load($cfg, $project);
    if (!isset($d[$id])) return true;
    $e = $d[$id];
    if (!empty($e['expires_at']) && gmdate('c') > (string)$e['expires_at']) return false;
    $max = $e['max_uses'] ?? null;
    $used = (int)($e['used_count'] ?? 0);
    if ($max !== null && $used >= (int)$max) return false;
    $d[$id]['used_count'] = $used + 1;
    contrib_save($cfg, $project, $d);
    return true;
}

/**
 * 後台「分享編輯連結」建立投稿身分用：直接寫入一筆 contrib.json entry，不需使用者事先知道 PIN。
 * $pin 留空（「僅限匿名」）時自動產生一組隨機 PIN；PIN 本身不回傳給前台顯示，只靠連結 token 兌換身分。
 * 回傳 [token, id, pin] 供後台組出分享連結／QR；grants_admin 一律 false（管理身分改走 admin_pins.json，見 pins_grant_create）。
 */
function contrib_grant_create(array $cfg, string $project, ?string $pin, ?string $label, ?string $expiresAt, ?int $maxUses): array {
    $pin = ($pin !== null && $pin !== '') ? $pin : bin2hex(random_bytes(16));
    $token = contrib_token($cfg, $project, $pin);
    $id = contrib_id_of($token);
    $d = contrib_load($cfg, $project);
    $d[$id] = [
        'label'        => $label !== null ? trim((string)$label) : '',
        'created'      => gmdate('c'),
        'expires_at'   => $expiresAt,
        'max_uses'     => $maxUses,
        'used_count'   => 0,
        'grants_admin' => false,
        'via_link'     => true,
    ];
    contrib_save($cfg, $project, $d);
    return [$token, $id, $pin];
}

/** 超過限制時直接以 429 結束請求。個別 bucket 可在 config['rate_limits'][$bucket] 覆寫 max/window（例如批次投稿量遠高於刪除/換鎖等低頻動作）。 */
function rate_limit(array $cfg, string $bucket = 'default'): void {
    $override = $cfg['rate_limits'][$bucket] ?? [];
    $max = $override['max']    ?? $cfg['rate_max']    ?? 40;
    $win = $override['window'] ?? $cfg['rate_window'] ?? 60;
    $dir = rtrim($cfg['state_dir'], '/\\') . '/.rate';
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
