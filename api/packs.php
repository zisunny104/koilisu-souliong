<?php
// 主題包（面板材質／外框／字體）註冊表。跟 features.php 的模組開關不同：
// 這裡選的不是布林值，是「用哪一包」，所以檔案分開放，機制卻刻意跟模組系統同一套精神——
// 註冊表就是 packs_dir 底下的資料夾本身（比照 store_projects() 把 projects_dir 當註冊表的做法），
// 沒有中央 index 檔，新增一包只要新增一個資料夾。
//
// 一包最少要有 pack.json（給後台列表/匯出檔名用的中繼資料）跟 pack.css（真正的樣式，含材質、
// 外框、字體）。pack.css 由 view.php 在既有的 base 主題 CSS 之後原樣 readfile() 進同一個
// <style> 區塊，純靠 cascade 順序覆寫、不需要 !important；沒選包時這個 readfile() 根本不會執行，
// 因此舊專案（meta.json 沒有 pack 欄位）行為與拆分之前完全一致。
//
// 作用範圍以專案為主，另有一層全站預設（state/settings.json 的 pack，後台「工具」分頁設定），
// 供「整個平台想有一致外觀」的部署使用；解析順序見 souliong_pack_for()。

require_once __DIR__ . '/settings.php';

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
 * 回傳該專案目前生效的主題包 manifest；選的包已不存在或最後解析成空 → null（維持黑白預設）。
 *
 * 解析順序（以專案為主，全站只是沒指定時的退路）：
 *   1. meta.json 有 pack 欄位 → 以它為準。**值是空字串代表「這張地圖明確不套用」**，
 *      即使全站設了包也不跟——想維持黑白的地圖要能拒絕全站換皮。
 *   2. 沒有 pack 欄位 → 用全站預設（state/settings.json 的 pack）。舊地圖都是這一類，
 *      全站沒設包時結果仍是 null，行為與加入這層之前完全一致。
 */
function souliong_pack_for(array $cfg, ?array $meta): ?array
{
    $id = (is_array($meta) && array_key_exists('pack', $meta) && is_string($meta['pack']))
        ? $meta['pack']
        : souliong_site_pack($cfg);
    if ($id === '') {
        return null;
    }
    return souliong_pack_list($cfg)[$id] ?? null;
}
