/* 數字鍵盤／一般鍵盤切換輸入元件。
   用法：在 <input> 加上 data-pin-toggle 屬性即可，載入本檔會自動掃描套用。
   預設呼出手機原生數字鍵盤；按切換鈕可換成一般鍵盤直接輸入英數字。
   無外部依賴，view.php（有 window.I18N）與 admin.php（無 I18N，純中文頁面）都能共用。 */
(function () {
  const I18N = window.I18N || {};
  const t = (key, fallback) => (I18N[key] != null ? I18N[key] : fallback);
  // 不依賴 Font Awesome：admin.php 未登入前的頁面刻意不載入外部資源，用行內 SVG 確保一定看得到圖示
  const ICON_KEYBOARD = '<svg viewBox="0 0 20 14" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="1" width="18" height="12" rx="2"/><path d="M4 5h.01M8 5h.01M12 5h.01M16 5h.01M4 9h.01M8 9h8"/></svg>';
  const ICON_NUMERIC = '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M4 2v12M10 2v12M1.5 6h13M1.5 11h13"/></svg>';

  // 遮罩用的幾何圖案（取代瀏覽器原生密碼圓點）；沿用專案品牌圖形語彙：圓角多邊形，顏色跟隨 currentColor
  const MASK_KINDS = ['dot', 'triangle', 'square', 'pentagon', 'hexagon', 'line'];
  const MASK_MAX = 20;
  function polyPoints(sides, R, rot) {
    rot = rot || 0;
    const p = [];
    for (let i = 0; i < sides; i++) { const a = (-90 + rot + i * 360 / sides) * Math.PI / 180; p.push((12 + R * Math.cos(a)).toFixed(1) + ',' + (12 + R * Math.sin(a)).toFixed(1)); }
    return p.join(' ');
  }
  function maskShapeSVG(kind) {
    const open = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round">';
    const inner = {
      dot: '<circle cx="12" cy="12" r="5.5" stroke="none"/>',
      line: '<rect x="3.5" y="8.5" width="17" height="7" rx="3.5" stroke="none"/>',
      triangle: '<polygon points="' + polyPoints(3, 8.2, 0) + '"/>',
      square: '<rect x="5.5" y="5.5" width="13" height="13" rx="4.5" transform="rotate(7 12 12)"/>',
      pentagon: '<polygon points="' + polyPoints(5, 8, -8) + '"/>',
      hexagon: '<polygon points="' + polyPoints(6, 8, 30) + '"/>',
    }[kind] || '';
    return open + inner + '</svg>';
  }

  // type="password" 的欄位：真正輸入仍走原生欄位（含數字鍵盤/切換），只是把原生圓點遮罩換成幾何圖案疊層
  function buildMaskOverlay(input, wrap) {
    if (input.type !== 'password') return;
    input.style.color = 'transparent';
    input.style.caretColor = 'currentColor';
    const overlay = document.createElement('span');
    overlay.className = 'pin-mask-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    wrap.appendChild(overlay);
    function render() {
      const n = Math.min(input.value.length, MASK_MAX);
      let html = '';
      for (let i = 0; i < n; i++) {
        html += '<span class="pin-mask-dot' + (i === n - 1 ? ' pop' : '') + '">' + maskShapeSVG(MASK_KINDS[i % MASK_KINDS.length]) + '</span>';
      }
      overlay.innerHTML = html;
    }
    input.addEventListener('input', render);
    render();
  }

  function upgrade(input) {
    if (input.dataset.pinToggled) return;
    input.dataset.pinToggled = '1';

    const wrap = document.createElement('span');
    wrap.className = 'pin-toggle-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('pattern', '[0-9]*');

    buildMaskOverlay(input, wrap);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pin-toggle-btn';
    wrap.appendChild(btn);

    let keyboardMode = false;
    function render() {
      const label = keyboardMode
        ? t('pin_toggle_numeric', '切換成數字鍵盤')
        : t('pin_toggle_keyboard', '切換成鍵盤輸入');
      btn.title = label;
      btn.setAttribute('aria-label', label);
      btn.innerHTML = keyboardMode ? ICON_NUMERIC : ICON_KEYBOARD;
    }
    btn.addEventListener('click', function () {
      keyboardMode = !keyboardMode;
      if (keyboardMode) {
        input.setAttribute('inputmode', 'text');
        input.removeAttribute('pattern');
      } else {
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('pattern', '[0-9]*');
      }
      render();
      input.focus();
    });
    render();
  }

  function scan(root) {
    (root || document).querySelectorAll('input[data-pin-toggle]').forEach(upgrade);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  // 供動態插入的欄位（例如對話框開啟時才建立的 input）事後呼叫
  window.PinInput = { scan: scan };
})();
