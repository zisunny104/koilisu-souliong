<?php
/**
 * 純 PHP ZIP 讀寫 —— 零擴充依賴（不需 ext-zip）。
 *
 * 寫入採「store」儲存法（不壓縮）：照片本身已是 WebP/JPG 壓縮格式，
 * 再壓幾乎不會變小，因此 store 幾乎無損體積、且不需 zlib。
 * 讀取支援 store（本工具產生）；若伺服器剛好有 zlib 的 gzinflate，
 * 也能讀 deflate 壓縮的舊備份（例如以前用 ZipArchive 產生的檔）。
 *
 * 只用到 PHP 核心函式（pack/unpack、hash_file('crc32b')、fopen…），
 * 因此與本平台「零額外擴充」的原則一致，無第三方程式碼、無額外授權需標註。
 */

/** 將 unix 時間轉為 DOS 日期/時間（ZIP 格式用） */
function zip__dostime(int $ts): array {
    $t = getdate($ts > 315532800 ? $ts : 315532800); // ZIP 最早只到 1980-01-01
    $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
    $date = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    return [$time & 0xffff, $date & 0xffff];
}

/**
 * 寫出 ZIP 到 $out。$files = [ zip內名稱 => 磁碟絕對路徑 ]。
 * 以串流複製寫入，不會把整個檔案讀進記憶體。成功回傳 true。
 */
function zip_pack(string $out, array $files): bool {
    $fp = @fopen($out, 'wb');
    if (!$fp) return false;
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $src) {
        if (!is_file($src)) continue;
        $name = str_replace('\\', '/', ltrim((string)$name, '/'));
        $size = (int)filesize($src);
        $crc = (int)hexdec(hash_file('crc32b', $src));
        $nlen = strlen($name);
        [$dtime, $ddate] = zip__dostime((int)@filemtime($src) ?: time());
        $lh = pack('V', 0x04034b50) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $dtime) . pack('v', $ddate) . pack('V', $crc)
            . pack('V', $size) . pack('V', $size) . pack('v', $nlen) . pack('v', 0) . $name;
        fwrite($fp, $lh);
        $in = @fopen($src, 'rb');
        if ($in) { stream_copy_to_stream($in, $fp); fclose($in); }
        $central .= pack('V', 0x02014b50) . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', $dtime) . pack('v', $ddate) . pack('V', $crc)
            . pack('V', $size) . pack('V', $size) . pack('v', $nlen) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', $offset) . $name;
        $offset += strlen($lh) + $size;
        $count++;
    }
    $cdlen = strlen($central);
    fwrite($fp, $central);
    fwrite($fp, pack('V', 0x06054b50) . pack('v', 0) . pack('v', 0)
        . pack('v', $count) . pack('v', $count)
        . pack('V', $cdlen) . pack('V', $offset) . pack('v', 0));
    fclose($fp);
    return true;
}

/**
 * 讀取 ZIP，回傳 [ 名稱 => 內容 ]，只回傳 $accept($name) 為真者。
 * 為求簡潔會把整個備份讀進記憶體（備份規模不大，足堪使用）。
 */
function zip_unpack(string $path, callable $accept): array {
    $data = @file_get_contents($path);
    if ($data === false || $data === '') return [];
    $eocd = strrpos($data, "PK\x05\x06");
    if ($eocd === false) return [];
    $total = unpack('v', substr($data, $eocd + 10, 2))[1];
    $cdoff = unpack('V', substr($data, $eocd + 16, 4))[1];
    $out = [];
    $q = $cdoff;
    for ($i = 0; $i < $total; $i++) {
        if (substr($data, $q, 4) !== "PK\x01\x02") break;
        $method = unpack('v', substr($data, $q + 10, 2))[1];
        $csize  = unpack('V', substr($data, $q + 20, 4))[1];
        $nlen   = unpack('v', substr($data, $q + 28, 2))[1];
        $elen   = unpack('v', substr($data, $q + 30, 2))[1];
        $clen   = unpack('v', substr($data, $q + 32, 2))[1];
        $lho    = unpack('V', substr($data, $q + 42, 4))[1];
        $name   = substr($data, $q + 46, $nlen);
        $q += 46 + $nlen + $elen + $clen;
        if (!$accept($name)) continue;
        if (substr($data, $lho, 4) !== "PK\x03\x04") continue;
        $lnlen = unpack('v', substr($data, $lho + 26, 2))[1];
        $lelen = unpack('v', substr($data, $lho + 28, 2))[1];
        $start = $lho + 30 + $lnlen + $lelen;
        $raw = substr($data, $start, $csize);
        if ($method === 0) { $out[$name] = $raw; }
        elseif ($method === 8 && function_exists('gzinflate')) { $out[$name] = gzinflate($raw); }
        // 其他壓縮法（無 zlib 時的 deflate）跳過
    }
    return $out;
}
