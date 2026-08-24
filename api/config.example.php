<?php
// 範本：部署時複製成 config.php 並填入你的機密值（config.php 已被 .gitignore）
return [
    'projects_dir'    => __DIR__ . '/../projects',
    'state_dir'       => __DIR__ . '/../state',
    'packs_dir'       => __DIR__ . '/../packs',              // 主題包（面板材質／外框／字體），每個子目錄一包
    'max_bytes'       => 12 * 1024 * 1024,
    'allowed_mime'    => ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
    'name_max'        => 60,
    'comment_max'     => 1000,

    // 管理 PIN（人輸入；驗證後以 httpOnly cookie 保持登入，PIN 不進網址）
    'admin_pin'       => 'CHANGE-ME',
    'admin_pin_label' => '',   // 用這把主 PIN 登入後要帶入的顯示名稱（留空則顯示「管理者」）

    // 韌性 / 資安
    'rate_max'        => 40,      // 每 IP 於視窗內最多寫入次數（預設；刪除/換鎖等低頻動作用這個）
    'rate_window'     => 60,      // 視窗秒數
    'rate_limits'     => [        // 個別動作覆寫預設值；上傳是批次投稿常態，量遠高於刪除/換鎖，需要獨立且更高的上限
        'upload' => ['max' => 300, 'window' => 60],
        'admin'  => ['max' => 120, 'window' => 60],   // 後台頁面＋臨時工具共用同一個 bucket，管理者密集操作/測試時預設 40 太容易誤擋自己
    ],
    'trust_forwarded' => false,   // ★ 位於 Nginx 反代後請設 true
    'debug'           => true,    // ★ 上線穩定後設 false

    // 冒名鑑識：加鹽 IP 雜湊（僅管理端可見）
    'log_src'         => true,
    'ip_salt'         => 'CHANGE-ME-隨機鹽值',
];
