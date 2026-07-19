<?php
// 範本：部署時複製成 config.php 並填入你的機密值（config.php 已被 .gitignore）
return [
    'projects_dir'    => __DIR__ . '/../projects',
    'state_dir'       => __DIR__ . '/../state',
    'max_bytes'       => 12 * 1024 * 1024,
    'allowed_mime'    => ['image/webp' => 'webp', 'image/jpeg' => 'jpg', 'image/png' => 'png'],
    'name_max'        => 60,
    'comment_max'     => 1000,

    // 管理 PIN（人輸入；驗證後以 httpOnly cookie 保持登入，PIN 不進網址）
    'admin_pin'       => 'CHANGE-ME',

    // 韌性 / 資安
    'rate_max'        => 40,
    'rate_window'     => 60,
    'trust_forwarded' => false,   // ★ 位於 Nginx 反代後請設 true
    'debug'           => true,    // ★ 上線穩定後設 false

    // 冒名鑑識：加鹽 IP 雜湊（僅管理端可見）
    'log_src'         => true,
    'ip_salt'         => 'CHANGE-ME-隨機鹽值',
];
