<?php
/**
 * 純 PHP 檔案儲存（零擴充依賴，取代 SQLite）。
 * 每個項目一個 JSON-Lines 檔：projects/<project>/data.jsonl，一行一筆記錄。
 * 寫入用 LOCK_EX 附加、讀取用 LOCK_SH，append-only。
 */

function project_dir(array $cfg, string $project): string {
    return rtrim($cfg['projects_dir'], '/\\') . '/' . $project;
}
function store_file(array $cfg, string $project): string {
    return project_dir($cfg, $project) . '/data.jsonl';
}

/** 把 jsonl 裡存的 "<project>/<檔名>" 解析成照片實際檔案路徑；格式不符回 null */
function photo_abs_path(array $cfg, string $photoRel): ?string {
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $photoRel, $m)) return null;
    return project_dir($cfg, $m[1]) . '/photos/' . $m[2];
}

/**
 * 同 photo_abs_path()，但指向 media/（影片與音訊）。
 * 影音沒有跟照片共用 photos/ 目錄，是為了讓既有的照片工具（exiffix.php／thumbfix.php、
 * 以及所有把 photos/ 當「全都是圖檔」在掃的程式）不用學會忽略非圖檔，見 features.php
 * 的 souliong_kinds() 說明。
 */
function media_abs_path(array $cfg, string $mediaRel): ?string {
    if (!preg_match('#^([a-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $mediaRel, $m)) return null;
    return project_dir($cfg, $m[1]) . '/media/' . $m[2];
}

/**
 * 刪掉一筆記錄附帶的檔案（主檔 ＋ 縮圖）。刪投稿的地方有三處（delete.php、admin.php 的
 * 單筆刪除與整批刪某身分），影音上線後每一處都要多記得清 media/ 一次；集中在這裡，
 * 之後再加新的檔案欄位也只有這一個地方要改。
 * 縮圖一律是 <主檔名>_t.<ext>（photo.php 自動產生的、上傳附帶的、影片抽幀的都同一套命名）。
 */
function store_purge_files(array $cfg, ?array $record): void {
    if (!$record) return;
    $paths = [];
    if (!empty($record['photo'])) $paths[] = photo_abs_path($cfg, (string)$record['photo']);
    if (!empty($record['media'])) $paths[] = media_abs_path($cfg, (string)$record['media']);
    foreach ($paths as $abs) {
        if (!$abs) continue;
        @unlink($abs);
        $base = preg_replace('/\.[A-Za-z0-9]+$/', '', $abs);
        foreach (['webp', 'jpg', 'png'] as $te) @unlink($base . '_t.' . $te);
    }
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

/**
 * 「要先看過現有資料才能決定寫什麼」的附加（例如建立地點要配一個沒被用過的 num）。
 *
 * 不能用 store_all() + store_append() 兜出來：那是兩段各自上鎖的區間，兩個人同時建點會
 * 各自讀到同一個 max num、配出重複號碼。這裡把讀與寫包在同一個 LOCK_EX 區間裡，
 * 後到的那個一定看得到先到的那筆。
 *
 * $build(array $records): array —— 收到目前檔案裡的全部記錄，回傳要附加的那一筆。
 * 想中止就在 $build 裡丟例外（此時什麼都不會寫入）。
 */
function store_append_locked(array $cfg, string $project, callable $build): array {
    $f = store_file($cfg, $project);
    $fp = fopen($f, 'c+b'); // c+：不存在就建、存在也不截斷（'a+' 在部分平台讀取位置不可靠）
    if (!$fp) throw new RuntimeException('cannot open store file');
    flock($fp, LOCK_EX);
    try {
        $records = [];
        rewind($fp);
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $rec = json_decode($line, true);
            if (is_array($rec)) $records[] = $rec;
        }
        $record = $build($records);
        fseek($fp, 0, SEEK_END);
        fwrite($fp, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($fp);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
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
