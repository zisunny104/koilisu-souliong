<?php
// 地圖圖層（底圖／疊圖）註冊表。跟 packs.php 是同一套精神——註冊表就是圖層目錄底下的
// 資料夾本身，沒有中央 index 檔，新增一層只要新增一個資料夾。
//
// 跟主題包的差別在「數量與順序」：一張地圖只套一個 pack，卻可以疊好幾層圖層，而且由下往上的
// 順序有意義，所以 meta.json 存的是有序陣列 "layers": ["carto-voyager", "chungshing-art"]。
// 沒有這個欄位 → 退回 config 的 default_layers，舊地圖行為與拆分之前完全一致。
//
// 為什麼底圖不做成插件（見 docs/EXTENDING.md 第七節的判準）：插件是「可以整包關掉、關掉後
// 核心不認得它」的功能，底圖關掉地圖就沒了。它的形狀跟 pack 一樣是「用哪一包」而非布林開關。
//
// ── 兩層作用域 ──
// site    : <app>/layers/<id>/          平台內建、所有地圖共用，隨程式碼進版控
// project : projects/<proj>/layers/<id>/  這張地圖專屬（自繪插畫多半屬於這類）
//
// 專案層之所以放在 projects/ 底下，是因為那整棵目錄本來就在 .gitignore：自繪插畫、切好的
// 圖磚金字塔這種「內容而非程式」的檔案因此天然不進版控，不必為了體積另立規則。同名時專案層
// 覆蓋全站層，讓單一地圖能在不影響其他地圖的前提下改掉內建圖層。
//
// 圖檔怎麼送出去：url 若是相對路徑（不含 "://"），代表圖檔就放在該層自己的資料夾裡，由
// <base>/layer/<project>/<id>/<路徑> 端點輸出——框架不供應靜態檔，理由同 api/photo.php。
// 全站層也走同一條網址，<project> 只是決定解析範圍。反過來說，url 直接指向外部圖磚服務的
// 圖層（CARTO、國土測繪中心…）一個檔案都不落地，自然也沒有檔案數量的問題。

/** 平台內建 layers/ 目錄；layers_dir 沒設也要能運作（舊部署的 api/config.php 不會有這個 key）。 */
function souliong_layers_dir(array $cfg): string
{
    $dir = (string)($cfg['layers_dir'] ?? '');
    return rtrim($dir !== '' ? $dir : __DIR__ . '/../layers', "/\\");
}

/**
 * 圖層搜尋路徑，由低優先到高優先（後面的同名 id 覆蓋前面的）。
 * 回傳 [ scope => 絕對路徑 ]，scope 為 'site' 或 'project'。
 */
function souliong_layer_roots(array $cfg, string $proj = ''): array
{
    $roots = [];
    $site = souliong_layers_dir($cfg);
    if ($site !== '') {
        $roots['site'] = $site;
    }
    $pdir = rtrim((string)($cfg['projects_dir'] ?? ''), "/\\");
    if ($proj !== '' && $pdir !== '' && preg_match('/^[a-z0-9_-]+$/', $proj)) {
        $roots['project'] = $pdir . '/' . $proj . '/layers';
    }
    return $roots;
}

/**
 * 掃所有搜尋路徑，回傳 [ id => layer.json 解析後的陣列 ]；沒有合法 layer.json 就跳過。
 * manifest 會被補上 id 與 scope 兩個欄位，端點靠 scope 才知道該去哪個目錄拿圖檔。
 */
function souliong_layer_list(array $cfg, string $proj = ''): array
{
    $out = [];
    foreach (souliong_layer_roots($cfg, $proj) as $scope => $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $id) {
            if ($id === '.' || $id === '..' || !preg_match('/^[a-z0-9_-]+$/', $id)) {
                continue;
            }
            $mf = $dir . '/' . $id . '/layer.json';
            if (!is_file($mf)) {
                continue;
            }
            $manifest = json_decode((string)@file_get_contents($mf), true);
            if (!is_array($manifest)) {
                continue;
            }
            // 資料夾名稱與所在作用域才是真的，layer.json 內容不可覆寫（匯入時避免偽造）
            $manifest['id'] = $id;
            $manifest['scope'] = $scope;
            $out[$id] = $manifest;   // 專案層排在後面，同名時自然覆蓋全站層
        }
    }
    return $out;
}

/** 單層的資料夾絕對路徑（專案層優先）；id 不合法或哪邊都沒有回 null。 */
function souliong_layer_dir(array $cfg, string $id, string $proj = ''): ?string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $id)) {
        return null;
    }
    $found = null;
    foreach (souliong_layer_roots($cfg, $proj) as $dir) {
        if (is_dir($dir . '/' . $id)) {
            $found = $dir . '/' . $id;   // 不 return，讓後面的專案層蓋掉前面的全站層
        }
    }
    return $found;
}

/**
 * $meta 是專案 meta.json 解析後的陣列（可能是 null）。
 * 回傳該地圖生效的圖層 manifest 陣列，由下往上排序；選到不存在的 id 會被靜靜略過
 * （比照 souliong_pack_for()：資料指到已刪除的資源時退回預設，不讓整張地圖開天窗）。
 *
 * default_layers 的預設值不是空陣列而是 ['carto-voyager']：這是「圖層化之前寫死在
 * viewer.leaflet.js 裡的那張底圖」，沒設定的舊部署與舊地圖因此得到與從前完全相同的畫面。
 */
function souliong_layers_for(array $cfg, ?array $meta, string $proj = ''): array
{
    $all = souliong_layer_list($cfg, $proj);
    $want = (is_array($meta) && isset($meta['layers']) && is_array($meta['layers']) && $meta['layers'])
        ? $meta['layers']
        : (array)($cfg['default_layers'] ?? ['carto-voyager']);
    $out = [];
    foreach ($want as $id) {
        if (is_string($id) && isset($all[$id])) {
            $out[] = $all[$id];
        }
    }
    return $out;
}

/** 這個 manifest 的圖檔是不是放在自己資料夾裡（相對 url）＝要走 layer 端點輸出。 */
function souliong_layer_is_local(array $manifest): bool
{
    $url = (string)($manifest['url'] ?? '');
    return $url !== '' && strpos($url, '://') === false && strpos($url, '//') !== 0;
}

/**
 * 前端用的 manifest：把相對 url 改寫成 <base>/layer/<project>/<id>/... 的絕對路徑。
 * 在伺服器端解析掉的用意是「前端完全不必知道 local 與 remote、site 與 project 的差別」——
 * viewer 拿到的永遠是一條可以直接餵給 L.tileLayer 的網址。
 */
function souliong_layer_public(array $manifest, string $base, string $proj): array
{
    $id = (string)($manifest['id'] ?? '');
    foreach (['url', 'urlDark'] as $k) {
        $v = (string)($manifest[$k] ?? '');
        // "//host/..." 是協定相對的外部網址，不是本地檔
        if ($v === '' || strpos($v, '://') !== false || strpos($v, '//') === 0) {
            continue;
        }
        $manifest[$k] = $base . 'layer/' . rawurlencode($proj) . '/' . rawurlencode($id) . '/' . ltrim($v, '/');
    }
    unset($manifest['scope']);   // 伺服器端解析用的欄位，前端不需要也不該知道檔案放在哪
    return $manifest;
}

/** souliong_layers_for() 的前端版本：解析順序相同，額外套用 souliong_layer_public()。 */
function souliong_layers_public(array $cfg, ?array $meta, string $proj, string $base): array
{
    return array_map(
        fn(array $m): array => souliong_layer_public($m, $base, $proj),
        souliong_layers_for($cfg, $meta, $proj)
    );
}
