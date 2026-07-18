<?php
/**
 * 通用錯誤頁 —— 全站共用一個模板，用「代碼＋說明」替換，並依代碼給不同動畫。
 * 延續平台視覺：淡淡的「循跡」地圖背景（虛線路徑＋散點）、中央毛玻璃浮空卡片、
 * 深淺主題自動切換。純自帶（inline CSS/SVG，零外部相依），即使其他東西壞了也能顯示。
 *
 * 用法：
 *   require __DIR__ . '/error.php';
 *   error_page(403, '沒有權限', '這個區域需要對應的鑰匙。', $homeUrl);   // 會 echo 並 exit
 */
function error_page(int $code = 404, ?string $title = null, ?string $msg = null, string $home = '/', string $homeLabel = '返回地圖'): void {
    http_response_code($code);
    $preset = [
        400 => ['請求有誤', '這個請求我看不太懂，換個方式再試一次。', 'dot'],
        401 => ['需要登入', '先證明您是誰，才能繼續往前走。', 'lock'],
        403 => ['沒有權限', '這個區域需要對應的鑰匙，您目前的身分還打不開。', 'lock'],
        404 => ['找不到這個地方', '這條路好像走到底了，地圖上沒有這個點。', 'pin'],
        405 => ['方法不對', '這扇門不是這樣開的。', 'dot'],
        413 => ['檔案太大了', '這張照片超過可接受的大小，換張小一點的。', 'dot'],
        429 => ['太快了，喘口氣', '您在很短時間內來了很多次，稍等一下再繼續。', 'timer'],
        500 => ['伺服器絆倒了', '我們這邊出了點狀況，椅子翻倒了，正在扶起來。', 'chair'],
        503 => ['暫時休息中', '服務正在維護或稍微過載，等一下再回來。', 'chair'],
    ];
    [$dt, $dm, $anim] = $preset[$code] ?? ['發生錯誤', '出了一點狀況。', 'dot'];
    $title = $title ?? $dt;
    $msg = $msg ?? $dm;
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $glyph = error__glyph($anim);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . $e($code . ' · ' . $title) . ' · Souliong</title>'
       . '<script>try{var t=localStorage.getItem("theme");if(t&&t!=="system")document.documentElement.dataset.theme=t;}catch(e){}</script>'
       . '<style>' . error__css() . '</style></head><body>'
       . '<svg class="bgmap" viewBox="0 0 1200 800" preserveAspectRatio="xMidYMid slice" aria-hidden="true">'
       .   '<path class="route" d="M120 620 Q 300 500 380 560 T 640 460 Q 780 400 860 300 T 1080 180" />'
       .   '<circle class="node" cx="120" cy="620" r="7"/><circle class="node n2" cx="380" cy="560" r="7"/>'
       .   '<circle class="node n3" cx="640" cy="460" r="7"/><circle class="node n4" cx="860" cy="300" r="7"/>'
       .   '<circle class="node n5" cx="1080" cy="180" r="7"/>'
       . '</svg>'
       . '<main class="card" role="alert">'
       .   '<div class="glyph g-' . $e($anim) . '">' . $glyph . '</div>'
       .   '<div class="code">' . $e($code) . '</div>'
       .   '<h1>' . $e($title) . '</h1>'
       .   '<p>' . $e($msg) . '</p>'
       .   '<a class="btn" href="' . $e($home) . '">' . $e($homeLabel) . '</a>'
       . '</main></body></html>';
    exit;
}

/** 各代碼對應的 SVG 圖形（動畫由 CSS 驅動） */
function error__glyph(string $anim): string {
    switch ($anim) {
        case 'pin':   // 走失的圖釘：沿虛線跳動
            return '<svg viewBox="0 0 120 120"><path class="trail" d="M12 104 H108"/>'
                 . '<g class="hop"><path d="M60 30 C44 30 32 42 32 58 C32 78 60 96 60 96 C60 96 88 78 88 58 C88 42 76 30 60 30 Z"/>'
                 . '<circle class="dot" cx="60" cy="56" r="10"/></g></svg>';
        case 'lock':  // 上鎖：鎖體＋輕輕搖晃的鎖環
            return '<svg viewBox="0 0 120 120"><path class="shackle" d="M40 56 V44 a20 20 0 0 1 40 0 V56"/>'
                 . '<rect class="body" x="30" y="56" width="60" height="46" rx="12"/>'
                 . '<circle class="keyhole" cx="60" cy="76" r="6"/><rect x="57" y="78" width="6" height="14" rx="3"/></svg>';
        case 'timer': // 冷卻計時：掃描弧線
            return '<svg viewBox="0 0 120 120"><circle class="ring" cx="60" cy="64" r="34"/>'
                 . '<circle class="sweep" cx="60" cy="64" r="34"/>'
                 . '<line class="hand" x1="60" y1="64" x2="60" y2="38"/><rect x="50" y="16" width="20" height="8" rx="4"/></svg>';
        case 'chair': // 椅子翻倒：整體傾倒晃動（呼應椅子地圖）
            return '<svg viewBox="0 0 120 120"><g class="tip">'
                 . '<path d="M44 40 V84"/><path d="M76 40 V84"/><path d="M44 84 V102"/><path d="M76 84 V102"/>'
                 . '<path d="M40 64 H80"/><path d="M44 40 H76"/></g></svg>';
        default:      // 脈動圓點
            return '<svg viewBox="0 0 120 120"><circle class="ripple" cx="60" cy="64" r="16"/>'
                 . '<circle class="ripple r2" cx="60" cy="64" r="16"/><circle class="core" cx="60" cy="64" r="8"/></svg>';
    }
}

/** 頁面樣式（用 rem、深淺主題、reduced-motion 尊重） */
function error__css(): string {
    return <<<CSS
:root{color-scheme:light dark;--bg:#fafafa;--fg:#1b1b1d;--muted:#6b6b70;--line:#e7e7ea;--card:rgba(255,255,255,.82);--accent:#1b1b1d;--accent-fg:#fff;--stroke:#c9c9cf;--r:1.5rem}
@media (prefers-color-scheme:dark){:root{--bg:#141416;--fg:#f1f1f3;--muted:#9c9ca3;--line:#2e2e31;--card:rgba(29,29,32,.82);--accent:#f1f1f3;--accent-fg:#141416;--stroke:#3a3a3e}}
:root[data-theme=light]{--bg:#fafafa;--fg:#1b1b1d;--muted:#6b6b70;--line:#e7e7ea;--card:rgba(255,255,255,.82);--accent:#1b1b1d;--accent-fg:#fff;--stroke:#c9c9cf}
:root[data-theme=dark]{--bg:#141416;--fg:#f1f1f3;--muted:#9c9ca3;--line:#2e2e31;--card:rgba(29,29,32,.82);--accent:#f1f1f3;--accent-fg:#141416;--stroke:#3a3a3e}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:system-ui,sans-serif;background:var(--bg);color:var(--fg);display:flex;align-items:center;justify-content:center;min-height:100dvh;padding:1.25rem;-webkit-font-smoothing:antialiased}
.bgmap{position:fixed;inset:0;width:100%;height:100%;z-index:0;opacity:.5}
.bgmap .route{fill:none;stroke:var(--stroke);stroke-width:3;stroke-linecap:round;stroke-dasharray:2 14;animation:flow 14s linear infinite}
.bgmap .node{fill:var(--stroke);animation:blink 3.2s ease-in-out infinite}
.bgmap .n2{animation-delay:.4s}.bgmap .n3{animation-delay:.8s}.bgmap .n4{animation-delay:1.2s}.bgmap .n5{animation-delay:1.6s}
@keyframes flow{to{stroke-dashoffset:-160}}
@keyframes blink{0%,100%{opacity:.35}50%{opacity:1}}
.card{position:relative;z-index:1;background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:0 1.25rem 3.5rem rgba(0,0,0,.18);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);padding:2.25rem 2rem 2rem;max-width:24rem;width:100%;text-align:center}
.glyph{width:6rem;height:6rem;margin:0 auto .5rem}
.glyph svg{width:100%;height:100%;overflow:visible}
.glyph svg [class]{}
.glyph path,.glyph rect,.glyph line,.glyph circle{fill:none;stroke:var(--fg);stroke-width:5;stroke-linecap:round;stroke-linejoin:round}
.glyph .dot,.glyph .keyhole,.glyph .core,.glyph .node{fill:var(--fg);stroke:none}
.code{font-size:2.75rem;font-weight:800;letter-spacing:-.02em;line-height:1}
h1{font-size:1.25rem;font-weight:800;margin:.5rem 0 .35rem}
p{color:var(--muted);font-size:.95rem;line-height:1.7;margin:0 0 1.4rem}
.btn{display:inline-block;background:var(--accent);color:var(--accent-fg);text-decoration:none;font-weight:700;font-size:.95rem;padding:.7rem 1.4rem;border-radius:999px;transition:transform .15s}
.btn:hover{transform:translateY(-1px)}
/* 404 圖釘沿虛線跳 */
.g-pin .trail{stroke:var(--stroke);stroke-width:4;stroke-dasharray:2 12}
.g-pin .hop{animation:hop 1.4s ease-in-out infinite;transform-origin:60px 96px}
@keyframes hop{0%,100%{transform:translateX(-28px) translateY(0)}50%{transform:translateX(28px) translateY(-14px)}}
/* 403 鎖環搖晃 */
.g-lock .shackle{animation:jiggle 2.4s ease-in-out infinite;transform-origin:60px 56px}
@keyframes jiggle{0%,88%,100%{transform:rotate(0)}92%{transform:rotate(-7deg)}96%{transform:rotate(7deg)}}
/* 429 掃描弧 */
.g-timer .ring{opacity:.3}
.g-timer .sweep{stroke-dasharray:214;stroke-dashoffset:214;animation:sweep 2.6s ease-in-out infinite}
.g-timer .hand{animation:tick 2.6s linear infinite;transform-origin:60px 64px}
@keyframes sweep{0%{stroke-dashoffset:214}70%,100%{stroke-dashoffset:0}}
@keyframes tick{to{transform:rotate(360deg)}}
/* 500 椅子翻倒 */
.g-chair .tip{animation:tip 3s ease-in-out infinite;transform-origin:60px 96px}
@keyframes tip{0%,60%,100%{transform:rotate(0)}75%{transform:rotate(-18deg)}85%{transform:rotate(-14deg)}}
/* 預設脈動 */
.g-dot .ripple{stroke:var(--fg);opacity:.5;animation:ripple 2s ease-out infinite}
.g-dot .r2{animation-delay:1s}
@keyframes ripple{0%{transform:scale(.4);opacity:.6;transform-origin:60px 64px}100%{transform:scale(1.8);opacity:0;transform-origin:60px 64px}}
@media (prefers-reduced-motion:reduce){*{animation:none!important}}
CSS;
}
