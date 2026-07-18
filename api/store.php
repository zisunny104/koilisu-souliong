<?php
/**
 * 純 PHP 檔案儲存（零擴充依賴，取代 SQLite）。
 * 每個項目一個 JSON-Lines 檔：data/<project>.jsonl，一行一筆記錄。
 * 寫入用 LOCK_EX 附加、讀取用 LOCK_SH，append-only。
 */

function store_dir(array $cfg): string {
    $d = $cfg['store_dir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}
function store_file(array $cfg, string $project): string {
    return store_dir($cfg) . '/' . $project . '.jsonl';
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

function store_projects(array $cfg): array {
    $out = [];
    foreach (glob(store_dir($cfg) . '/*.jsonl') as $f) $out[] = basename($f, '.jsonl');
    return $out;
}

function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
