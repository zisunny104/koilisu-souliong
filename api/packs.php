<?php
// 資源包（面板材質／外框／字體）註冊表。跟 features.php 的模組開關不同：
// 這裡選的不是布林值，是「用哪一包」，所以檔案分開放，機制卻刻意跟模組系統同一套精神——
// 註冊表就是 packs_dir 底下的資料夾本身（比照 store_projects() 把 projects_dir 當註冊表的做法），
// 沒有中央 index 檔，新增一包只要新增一個資料夾。
//
// 一包最少要有 pack.json（給後台列表/匯出檔名用的中繼資料）跟 pack.css（真正的樣式，含材質、
// 外框、字體）。pack.css 由 view.php 在既有的 base 主題 CSS 之後原樣 readfile() 進同一個
// <style> 區塊，純靠 cascade 順序覆寫、不需要 !important；沒選包時這個 readfile() 根本不會執行，
// 因此舊專案（meta.json 沒有 pack 欄位）行為與拆分之前完全一致。

/** 掃 packs_dir，回傳 [ id => pack.json 解析後的陣列 ]；資料夾裡沒有合法 pack.json 就跳過。 */
function souliong_pack_list(array $cfg): array
{
    $dir = rtrim((string)($cfg['packs_dir'] ?? ''), '/\\');
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $id) {
        if ($id === '.' || $id === '..' || !preg_match('/^[a-z0-9_-]+$/', $id)) {
            continue;
        }
        $mf = $dir . '/' . $id . '/pack.json';
        if (!is_file($mf)) {
            continue;
        }
        $manifest = json_decode((string)@file_get_contents($mf), true);
        if (!is_array($manifest)) {
            continue;
        }
        $manifest['id'] = $id;   // 資料夾名稱才是真正的 id，pack.json 內容不可覆寫（匯入時避免偽造）
        $out[$id] = $manifest;
    }
    return $out;
}

/**
 * $meta 是專案 meta.json 解析後的陣列（可能是 null）。
 * 回傳該專案目前生效的資源包 manifest；沒選、選的包已不存在、或 $meta 不是陣列 → null（維持黑白預設）。
 */
function souliong_pack_for(array $cfg, ?array $meta): ?array
{
    if (!is_array($meta) || empty($meta['pack']) || !is_string($meta['pack'])) {
        return null;
    }
    return souliong_pack_list($cfg)[$meta['pack']] ?? null;
}
