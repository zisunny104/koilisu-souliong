<?php
// 投稿種類（kind）與功能使用統計（feature）的中央註冊表。
//
// 這兩樣東西原本分散寫死在好幾個檔案裡：upload.php 驗證 kind、stat.php 的 $FEATURES 白名單、
// admin.php 的 $featLabels 中文說明——三邊各自維護，容易漏改（例如 share 這個 feature key
// 就曾經只在 viewer.leaflet.js 呼叫、admin.php 準備了標籤，卻沒被 stat.php 的白名單放行）。
//
// 這是「預留插件接口」的第一步：先把 metadata 集中一份，之後要加打卡／明信片／集章／聲音
// 這類新投稿種類時，理想上只需要在 souliong_kinds() 新增一筆定義；真正的驗證/渲染邏輯目前
// 仍留在各自檔案裡（因為 photo/desc 的處理方式差異太大），等真的有第一個新種類要落地時，
// 再視情況把渲染邏輯也一併抽成獨立檔案。

// 投稿種類：key => 中繼資料（label 是後台顯示用中文、has_photo 決定是否走照片上傳流程）
function souliong_kinds(): array
{
    return [
        'photo' => ['label' => '照片', 'has_photo' => true],
        'desc'  => ['label' => '文字紀錄', 'has_photo' => false],
    ];
}

function souliong_kind_label(string $kind): string
{
    return souliong_kinds()[$kind]['label'] ?? $kind;
}

// 功能使用統計：key => 後台「數字說明」區塊要顯示的中文說明
function souliong_features(): array
{
    return [
        'route'  => '路線導覽',
        'photos' => '照片瀏覽',
        'filter' => '套用篩選',
        'embed'  => '嵌入載入',
        'random' => '隨機探索',
        'upload' => '上傳投稿',
        'story'  => '地點故事',
        'theme'  => '主題切換',
        'info'   => '照片資訊',
        'share'  => '分享',
    ];
}

// 每張地圖可獨立開關的功能模組：key => 後台勾選 UI 用的中繼資料。
// 這是「這張地圖要不要有這個功能」的開關（view.php 用它決定渲不渲染、viewer.leaflet.js
// 用它決定要不要啟用行為），跟上面 souliong_features() 那份「事後統計要顯示的名稱」是兩件事。
// 未在 meta.json 出現的 key 一律視為 default 值，舊專案（meta.json 沒有 features 欄位）
// 因此完全不受影響、行為與拆分之前一致。
function souliong_modules(): array
{
    return [
        'route'  => ['label' => '路線導覽', 'desc' => '依編號的路徑導覽，及連點路線鈕的時間軸動畫彩蛋。', 'default' => true],
        'story'  => ['label' => '地點故事編輯', 'desc' => '訪客可送出新版地點故事文字（關閉後地點故事唯讀）。', 'default' => true],
        'upload' => ['label' => '上傳投稿', 'desc' => '訪客上傳照片／文字紀錄；關閉後整張地圖唯讀，投稿碼與解鎖流程一併隱藏。', 'default' => true],
        'embed'  => ['label' => '嵌入載入', 'desc' => '產生可嵌入其他網站的 iframe 碼。', 'default' => true],
        'share'  => ['label' => '分享', 'desc' => '分享連結／QR Code 彈窗。', 'default' => true],
        'identity' => ['label' => '投稿者身分', 'desc' => '右上角身分小標籤（暱稱／管理者／匿名預覽名）與建立身分（PIN）欄位。關閉後依序探索也會一併隱藏。', 'default' => true, 'dependsOn' => 'upload'],
        'personExplore' => ['label' => '依序探索（插件）', 'desc' => '選了投稿者後，可依序探索他的地標／零散照片時間軸。', 'default' => false, 'dependsOn' => 'identity'],
        'delegation' => ['label' => '管理者邀請登入', 'desc' => '地圖頁上的管理者登入／邀請兌換彈窗。關閉後這張地圖不再產生新的專案 PIN 或邀請連結，只能用主 PIN 從後台網址（/manager）登入管理，適合純檢視、僅超級管理者更新內容的部署。', 'default' => true],
    ];
}

// $meta 是該專案 meta.json 解析後的陣列（可能是 null）。
// personExplore 沿用既有的扁平旗標寫法（$meta['personExplore']），其餘模組存在 $meta['features'][$key]。
function souliong_module_on(?array $meta, string $key): bool
{
    $dep = souliong_modules()[$key]['dependsOn'] ?? null;
    if ($dep !== null && !souliong_module_on($meta, $dep)) {
        return false;
    }
    $def = souliong_modules()[$key]['default'] ?? true;
    if (!is_array($meta)) {
        return $def;
    }
    if ($key === 'personExplore') {
        return (bool)($meta['personExplore'] ?? $def);
    }
    if (isset($meta['features'][$key])) {
        return (bool)$meta['features'][$key];
    }
    return $def;
}
