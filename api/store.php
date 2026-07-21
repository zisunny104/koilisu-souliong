<?php
/**
 * 純 PHP 檔案儲存（零擴充依賴，取代 SQLite）。
 * 每個項目一個 JSON-Lines 檔：projects/<project>/data.jsonl，一行一筆記錄。
 * 寫入用 LOCK_EX 附加、讀取用 LOCK_SH，append-only。
 */

function project_dir(array $cfg, string $project): string {
    return rtrim($cfg['projects_dir'], '/\\') . '/' . $project;
}
function state_dir(array $cfg): string {
    $d = $cfg['state_dir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}
function store_file(array $cfg, string $project): string {
    return project_dir($cfg, $project) . '/data.jsonl';
}

/** 把 jsonl 裡存的 "<project>/<檔名>" 解析成照片實際檔案路徑；格式不符回 null */
function photo_abs_path(array $cfg, string $photoRel): ?string {
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $photoRel, $m)) return null;
    return project_dir($cfg, $m[1]) . '/photos/' . $m[2];
}

function store_all(array $cfg, string $project): array {
    $f = store_file($cfg, $project);
    if (!is_file($f)) return [];
    $fp = fopen($f, 'rb');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $out = [];
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $rec = json_decode($line, true);
        if (is_array($rec)) $out[] = $rec;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $out;
}

function store_append(array $cfg, string $project, array $record): array {
    $f = store_file($cfg, $project);
    $fp = fopen($f, 'ab');
    if (!$fp) throw new RuntimeException('cannot open store file');
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $record;
}

function store_delete(array $cfg, string $project, string $id): ?array {
    $f = store_file($cfg, $project);
    if (!is_file($f)) return null;
    $fp = fopen($f, 'c+b');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $keep = [];
    $removed = null;
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '') continue;
        $rec = json_decode($t, true);
        if (is_array($rec) && (string)($rec['id'] ?? '') === (string)$id) { $removed = $rec; continue; }
        $keep[] = $t;
    }
    ftruncate($fp, 0);
    rewind($fp);
    foreach ($keep as $l) fwrite($fp, $l . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $removed;
}

/** 依欄位值批次刪除（例如某個 contrib_id 或 owner_hash 的全部投稿）；回傳被刪除的記錄陣列供呼叫端清照片檔。 */
function store_delete_by(array $cfg, string $project, string $field, string $value): array {
    $f = store_file($cfg, $project);
    if (!is_file($f) || $value === '') return [];
    $fp = fopen($f, 'c+b');
    if (!$fp) return [];
    flock($fp, LOCK_EX);
    $keep = [];
    $removed = [];
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '') continue;
        $rec = json_decode($t, true);
        if (is_array($rec) && (string)($rec[$field] ?? '') === $value) { $removed[] = $rec; continue; }
        $keep[] = $t;
    }
    ftruncate($fp, 0);
    rewind($fp);
    foreach ($keep as $l) fwrite($fp, $l . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $removed;
}

/**
 * 就地修補單筆記錄的指定欄位（唯一打破 append-only 的例外，僅供資料修復工具（如 exiffix.php）使用，
 * 例如補救誤存為 null 的欄位；一般編輯一律走 store_append 版本化，不要用這個）。
 */
function store_patch(array $cfg, string $project, string $id, array $fields): ?array {
    $f = store_file($cfg, $project);
    if (!is_file($f)) return null;
    $fp = fopen($f, 'c+b');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $lines = [];
    $patched = null;
    while (($line = fgets($fp)) !== false) {
        $t = trim($line);
        if ($t === '') continue;
        $rec = json_decode($t, true);
        if (is_array($rec) && (string)($rec['id'] ?? '') === $id) {
            $rec = array_merge($rec, $fields);
            $patched = $rec;
            $t = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $lines[] = $t;
    }
    if ($patched !== null) {
        ftruncate($fp, 0);
        rewind($fp);
        foreach ($lines as $l) fwrite($fp, $l . "\n");
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $patched;
}

function store_projects(array $cfg): array {
    $dir = rtrim($cfg['projects_dir'], '/\\');
    $out = [];
    foreach ((array)@scandir($dir) as $name) {
        if ($name === '.' || $name === '..') continue;
        if (is_dir($dir . '/' . $name)) $out[] = $name;
    }
    return $out;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
