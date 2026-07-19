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
