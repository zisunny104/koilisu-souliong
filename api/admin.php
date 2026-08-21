<?php
// 管理頁：?api=admin （httpOnly cookie 認證；PIN 走 POST。主 PIN 全域、各專案 PIN 僅該專案）
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
require __DIR__ . '/packs.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/../pages/error.php';
require_once __DIR__ . '/i18n.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
[$LANG, $DICT] = i18n_init();
$t  = fn(string $key, array $vars = []): string => htmlspecialchars(i18n_t($DICT, $key, $vars), ENT_QUOTES);
$tr = fn(string $key, array $vars = []): string => i18n_t($DICT, $key, $vars);   // 內容含固定 HTML 標籤（非使用者輸入），此頁自行保證安全，不做二次跳脫
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// PIN／碼是唯一輸入憑證，後台一律預設遮罩、按眼睛才顯示（固定六點，不洩漏長度）；
// 例外：常駐投稿碼與「剛建立」區塊屬於正在分享的內容，維持明碼。
$secret = fn($v) => '<span class="secretwrap"><span class="mono secretval" data-val="' . $esc($v) . '">••••••</span><button type="button" class="eyebtn" title="' . $t('eye_toggle_title') . '"><i class="fa-solid fa-eye"></i></button></span>';

// ── 登入（POST pin[, project]）→ 種 cookie ──
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
  $pin = (string)($_POST['pin'] ?? '');
  $proj = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
  $userid = trim((string)($_POST['userid'] ?? ''));
  $pw = (string)($_POST['pw'] ?? '');
  $ok = false;
  $go = '?api=admin';
  $label = '';
  if ($userid !== '') {
    // 帳號登入（userid+密碼）：與 PIN 互斥的另一種憑證，欄位有填 userid 就走這條，不落入下面的 PIN 分支
    $r = account_login($cfg, $userid, $pw);
    if ($r['ok']) {
      $acc = $r['account'];
      account_set_cookie($cfg, (string)$acc['id']);
      $ok = true;
      $label = (string)($acc['label'] !== '' ? $acc['label'] : $acc['userid']);
      if (($acc['role'] ?? '') !== 'master') {
        $aprojects = account_project_list($cfg, (string)$acc['id']);
        if (count($aprojects) === 1) $go = '?api=admin&project=' . urlencode($aprojects[0]);
      }
    } else {
      $loginErrMsg = i18n_t($DICT, $r['error'] === 'locked' ? 'account_locked_msg' : 'account_login_failed_msg');
    }
  } elseif (check_master_pin($cfg, $pin)) {
    admin_set_cookie($cfg);
    $ok = true;
    $label = master_pin_label($cfg, $pin);
  } elseif ($proj !== '' && ($ppMatch = project_pin_match($cfg, $proj, $pin)) !== null) {
    if (pins_check_and_bump($cfg, $proj, (string)$ppMatch['id'])) {
      padm_set_cookie($cfg, $proj, (string)$ppMatch['id']);
      $ok = true;
      $go = '?api=admin&project=' . urlencode($proj);
      $label = project_pin_label($cfg, $proj, $pin);
    } else {
      $loginErrMsg = i18n_t($DICT, 'share_link_expired');
    }
  }
  if (isset($_POST['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) echo json_encode(['ok' => true, 'label' => $label]);
    else {
      http_response_code(403);
      echo json_encode(['error' => $loginErrMsg ?? i18n_t($DICT, 'admin_pin_incorrect')]);
    }
    exit;
  }
  if ($ok) {
    header('Location: ' . $go);
    exit;
  }
  $loginErr = $loginErrMsg ?? i18n_t($DICT, 'admin_pin_incorrect');
}
if (isset($_GET['logout'])) {
  admin_clear_cookie();
  account_clear_cookie();
  header('Location: ?api=admin');
  exit;
}

// 帳號系統的錯誤代碼 → 顯示訊息；跟登入頁一樣走純表單送出（不需要 JS/fetch），未對應到的代碼一律退回通用訊息
$accountErrMsg = function (string $err) use ($DICT): string {
  $map = [
    'userid_invalid' => 'account_err_userid_invalid', 'userid_taken' => 'account_err_userid_taken',
    'pw_short' => 'account_err_pw_short', 'pw_same_as_userid' => 'account_err_pw_same_as_userid', 'pw_weak' => 'account_err_pw_weak',
    'invalid_token' => 'account_err_invalid_token', 'legacy_pin_wrong' => 'account_err_legacy_pin_wrong',
  ];
  return i18n_t($DICT, $map[$err] ?? 'account_err_generic');
};

// ── 帳號自助註冊（POST userid, pw[, label]）→ 僅 registration_open 開啟時允許；新帳號預設無任何專案權限 ──
$registerErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'account_register') {
  if (!souliong_registration_open($cfg)) {
    $registerErr = i18n_t($DICT, 'registration_closed_msg');
  } else {
    $res = account_register($cfg, (string)($_POST['userid'] ?? ''), (string)($_POST['pw'] ?? ''), (string)($_POST['label'] ?? ''));
    if ($res['ok']) {
      account_set_cookie($cfg, (string)$res['account']['id']);
      header('Location: ?api=admin');
      exit;
    }
    $registerErr = $accountErrMsg((string)$res['error']);
  }
}

// ── 舊 PIN 轉換為帳號（POST token, legacy_pin, userid, pw）→ 核對舊 PIN 證明本人後建立新帳號 ──
// 公開端點（未登入者用，token 只透過網址 fragment 帶出），比照 admin_redeem：不做 CSRF，rate_limit($cfg,'admin') 已節流。
$activateErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'account_activate') {
  $res = account_migrate_activate(
    $cfg,
    (string)($_POST['token'] ?? ''),
    (string)($_POST['legacy_pin'] ?? ''),
    (string)($_POST['userid'] ?? ''),
    (string)($_POST['pw'] ?? '')
  );
  if ($res['ok']) {
    account_set_cookie($cfg, (string)$res['account']['id']);
    header('Location: ?api=admin');
    exit;
  }
  $activateErr = $accountErrMsg((string)$res['error']);
}

// ── 管理 PIN 邀請兌換（POST project, token, pin[, label]）→ 收件人自己設 PIN／暱稱，成功即種 cookie ──
// 公開端點（未登入者用），刻意不做 CSRF 檢查：跟 action=login 同一層級，呼叫端本來就沒有已登入頁面可嵌 token；
// 最多只是幫別人兌換一個權限全關的身分，rate_limit($cfg,'admin')（見本檔開頭）已足以節流亂猜。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_redeem') {
  header('Content-Type: application/json; charset=utf-8');
  $rProj = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
  $rToken = (string)($_POST['token'] ?? '');
  $rPin = (string)($_POST['pin'] ?? '');
  $rLabel = isset($_POST['label']) ? (string)$_POST['label'] : null;
  if ($rProj === '' || !is_dir($cfg['projects_dir'] . '/' . $rProj)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
  }
  $res = pins_redeem($cfg, $rProj, $rToken, $rPin, $rLabel);
  if ($res['ok']) {
    padm_set_cookie($cfg, $rProj, (string)$res['id']);
  } else {
    http_response_code(403);
  }
  echo json_encode($res);
  exit;
}

// ── 認證與範圍 ──
$reqProject = preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '');
$master = admin_authed($cfg);
// 帳號登入者可能同時管理多個專案，不像 PIN 綁死單一 $reqProject：$acctProjects 是他有權限的專案清單，
// 沒有 ?project= 時（例如剛登入、或多專案帳號查看總覽）也要能通過 $authed。
$acct = $master ? null : account_current($cfg);
$acctProjects = $acct !== null ? account_project_list($cfg, (string)$acct['id']) : [];
$authed = $master
  || ($reqProject !== '' && admin_can($cfg, $reqProject))
  || ($acct !== null && $acctProjects !== []);
if (!$authed) {
  http_response_code(401);
  header('Content-Type: text/html; charset=utf-8');
?>
  <!DOCTYPE html>
  <html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $t('admin_login_title') ?></title>
    <style>
      .langsw{position:fixed;top:16px;right:16px;z-index:3;display:flex;gap:2px;font-size:0.75rem}
      .langsw a{color:var(--muted);text-decoration:none;padding:4px 8px;border-radius:999px}
      .langsw a.on{color:var(--fg);font-weight:700;background:var(--card);border:1px solid var(--line)}
      :root {
        color-scheme: light dark;
        --bg: #111113;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2b2b2f;
        --card: #1c1c1f;
        --accent: #f1f1f3;
        --accent-fg: #151517
      }

      @media(prefers-color-scheme:light) {
        :root {
          --bg: #f6f6f7;
          --fg: #1b1b1d;
          --muted: #6b6b70;
          --line: #e7e7ea;
          --card: #fff;
          --accent: #1b1b1d;
          --accent-fg: #fff
        }
      }

      * {
        box-sizing: border-box
      }

      body {
        margin: 0;
        height: 100vh;
        display: grid;
        place-items: center;
        background: var(--bg);
        color: var(--fg);
        font-family: system-ui, sans-serif
      }

      form {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 20px;
        padding: 28px;
        width: min(320px, 90vw);
        box-shadow: 0 12px 40px rgba(0, 0, 0, .3);
        text-align: center
      }

      h1 {
        font-size: 1.125rem;
        margin: 0 0 4px
      }

      .s {
        font-size: 0.75rem;
        color: var(--muted);
        margin-bottom: 18px
      }

      input {
        width: 100%;
        text-align: center;
        letter-spacing: 4px;
        font-size: 1.25rem;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: 12px;
        background: var(--bg);
        color: var(--fg);
        margin-bottom: 12px
      }

      button {
        width: 100%;
        border: none;
        border-radius: 12px;
        background: var(--accent);
        color: var(--accent-fg);
        font-size: 0.9375rem;
        font-weight: 700;
        padding: 12px;
        cursor: pointer
      }

      .err {
        color: #ff6b6b;
        font-size: 0.8125rem;
        margin-bottom: 10px;
        min-height: 18px
      }

      .pin-toggle-wrap {
        position: relative;
        display: block;
        margin-bottom: 12px
      }

      .pin-toggle-wrap input {
        margin-bottom: 0;
        padding-right: 40px
      }

      .pin-toggle-btn {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        width: 28px;
        height: 28px;
        border: none;
        background: transparent;
        color: var(--muted);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px
      }

      .pin-toggle-btn:hover {
        background: var(--line)
      }

      .pin-mask-overlay {
        position: absolute;
        inset: 0;
        right: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 3px;
        overflow: hidden;
        pointer-events: none;
        color: var(--fg)
      }

      .pin-mask-dot.pop {
        animation: pinpop .2s ease
      }

      @keyframes pinpop {
        0% {
          transform: scale(1.6)
        }

        100% {
          transform: scale(1)
        }
      }

      .textfield {
        text-align: left;
        letter-spacing: normal;
        font-size: 0.9375rem
      }

      .switchlink {
        margin-top: 14px;
        font-size: 0.8125rem
      }

      .switchlink a {
        color: var(--muted);
        cursor: pointer;
        text-decoration: underline
      }
    </style>
  </head>

  <body>
    <div class="langsw">
      <a href="?<?= $reqProject !== '' ? 'project=' . rawurlencode($reqProject) . '&' : '' ?>lang=zh_TW" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
      <a href="?<?= $reqProject !== '' ? 'project=' . rawurlencode($reqProject) . '&' : '' ?>lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
    </div>

    <?php
      // 失敗重新整理後要保留原本在看的面板（否則 register/activate 送出失敗會被彈回登入面板、錯誤訊息看起來像消失了）
      $postAction = (string)($_POST['action'] ?? '');
      $initPanel = in_array($postAction, ['account_register', 'account_activate'], true) ? $postAction : 'login';
    ?>
    <form method="post" id="panel-login" style="display:<?= $initPanel === 'login' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="login"><input type="hidden" name="project" value="<?= $esc($reqProject) ?>">
      <h1><?= $t('app_title') ?></h1>
      <div class="s"><?= $reqProject !== '' ? $t('project_scope_label', ['project' => $reqProject]) : $t('master_scope_label') ?><?= $t('enter_admin_pin') ?></div>
      <div class="err"><?= $esc($loginErr) ?></div>
      <div id="loginPinFields">
        <input name="pin" type="password" autocomplete="off" autofocus placeholder="PIN" data-pin-toggle>
      </div>
      <div id="loginAcctFields" style="display:none">
        <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
        <input name="pw" type="password" class="textfield" autocomplete="current-password" placeholder="<?= $t('password_placeholder') ?>">
      </div>
      <button><?= $t('login_btn') ?></button>
      <div class="switchlink" id="toAcctWrap"><a id="toAcctLogin"><?= $t('login_with_account_link') ?></a></div>
      <div class="switchlink" id="toPinWrap" style="display:none"><a id="toPinLogin"><?= $t('login_with_pin_link') ?></a></div>
      <?php if (souliong_registration_open($cfg)): ?>
        <div class="switchlink"><a id="toRegister"><?= $t('register_link') ?></a></div>
      <?php endif; ?>
    </form>

    <form method="post" id="panel-register" style="display:<?= $initPanel === 'account_register' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="account_register">
      <h1><?= $t('register_title') ?></h1>
      <div class="s"><?= $t('register_hint') ?></div>
      <div class="err"><?= $esc($registerErr) ?></div>
      <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
      <input name="pw" type="password" class="textfield" autocomplete="new-password" placeholder="<?= $t('password_placeholder') ?>">
      <input name="label" type="text" class="textfield" autocomplete="nickname" placeholder="<?= $t('nickname_optional_placeholder') ?>">
      <button><?= $t('register_btn') ?></button>
      <div class="switchlink"><a id="backToLoginFromRegister"><?= $t('back_to_login_link') ?></a></div>
    </form>

    <form method="post" id="panel-activate" style="display:<?= $initPanel === 'account_activate' ? '' : 'none' ?>">
      <input type="hidden" name="action" value="account_activate"><input type="hidden" name="token" id="activateToken" value="<?= $postAction === 'account_activate' ? $esc($_POST['token'] ?? '') : '' ?>">
      <h1><?= $t('activate_title') ?></h1>
      <div class="s"><?= $t('activate_hint') ?></div>
      <div class="err"><?= $esc($activateErr) ?></div>
      <input name="legacy_pin" type="password" autocomplete="off" placeholder="<?= $t('legacy_pin_placeholder') ?>" data-pin-toggle>
      <input name="userid" type="text" class="textfield" autocomplete="username" placeholder="<?= $t('userid_placeholder') ?>">
      <input name="pw" type="password" class="textfield" autocomplete="new-password" placeholder="<?= $t('password_placeholder') ?>">
      <button><?= $t('activate_btn') ?></button>
    </form>

    <script>window.I18N = <?= json_encode($DICT, JSON_UNESCAPED_UNICODE) ?>; window.LANG = <?= json_encode($LANG) ?>;</script>
    <script><?php readfile(__DIR__ . '/../assets/js/pin-input.js'); ?></script>
    <script>
      (function () {
        function showPanel(id) {
          ['panel-login', 'panel-register', 'panel-activate'].forEach(function (p) {
            document.getElementById(p).style.display = (p === id) ? '' : 'none';
          });
        }
        var toAcct = document.getElementById('toAcctLogin');
        var toPin = document.getElementById('toPinLogin');
        if (toAcct) toAcct.addEventListener('click', function (e) {
          e.preventDefault();
          document.getElementById('loginPinFields').style.display = 'none';
          document.getElementById('loginAcctFields').style.display = '';
          document.getElementById('toAcctWrap').style.display = 'none';
          document.getElementById('toPinWrap').style.display = '';
          document.querySelector('#loginAcctFields input[name=userid]').focus();
        });
        if (toPin) toPin.addEventListener('click', function (e) {
          e.preventDefault();
          document.getElementById('loginAcctFields').style.display = 'none';
          document.getElementById('loginPinFields').style.display = '';
          document.getElementById('toPinWrap').style.display = 'none';
          document.getElementById('toAcctWrap').style.display = '';
          document.querySelector('#loginPinFields input[name=pin]').focus();
        });
        var toReg = document.getElementById('toRegister');
        if (toReg) toReg.addEventListener('click', function (e) { e.preventDefault(); showPanel('panel-register'); });
        var backReg = document.getElementById('backToLoginFromRegister');
        if (backReg) backReg.addEventListener('click', function (e) { e.preventDefault(); showPanel('panel-login'); });

        // 舊 PIN 轉帳號的一次性連結：token 只透過 fragment 帶出，讀出後立刻用 history.replaceState 清掉，
        // 避免重新整理或分享網址時外流／重複使用（比照管理PIN邀請連結兌換的既有作法）。
        var raw = location.hash.startsWith('#') ? location.hash.slice(1) : location.hash;
        var hp = new URLSearchParams(raw);
        var actToken = hp.get('activate');
        if (actToken) {
          hp.delete('activate');
          var rest = hp.toString();
          try { history.replaceState(null, '', location.pathname + location.search + (rest ? '#' + rest : '')); } catch (e) {}
          document.getElementById('activateToken').value = actToken;
          showPanel('panel-activate');
        }
      })();
    </script>
  </body>

  </html><?php
          exit;
        }

        // 範圍：主 PIN → 可全部（?project= 選填，空字串＝全部）；專案 PIN → $reqProject 恆為登入時鎖定的那個專案
        $scopeProject = $reqProject;
        $csrf = $master
          ? admin_derived($cfg)
          : ($acct !== null ? account_derived($cfg, (string)$acct['id']) : padm_derived($cfg, $reqProject, (string)padm_pin_id($cfg, $reqProject)));
        $esc_csrf = $esc($csrf);
        function need_csrf(string $csrf): void
        {
          if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            global $scopeProject, $t;
            error_page(403, $t('csrf_expired_title'), $t('csrf_expired_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''), $t('back_to_admin'));
          }
        }

        // 允許操作某專案？（主全通；專案管理者只能動自己的——admin_can() 本身已檢查 PIN／帳號授權，
        // 不需要再比對 $reqProject，否則多專案帳號在非目前網址那個專案上會被誤擋）
        $canProject = fn($p) => $master || admin_can($cfg, $p);

        // 稽核紀錄用的操作者識別：master／帳號 id／PIN id 三選一，供事後追查憑證外洩時歸責
        $auditWho = fn() => $master ? 'master' : ($acct !== null ? 'acct:' . $acct['id'] : 'pin:' . (string)padm_pin_id($cfg, $reqProject));

        // 專案清單（供備份/檢視）
        $allProjects = store_projects($cfg);

        // 組公開地圖網址用（分享連結／邀請碼共用）；basePath 還原成 app 掛載根目錄本身，
        // 不能只是「目前網址去掉 query string」──因為 admin 頁可能是用 /<project>/manager 這種路徑式網址進來，
        // 那樣算出來的 basePath 會多黏 manager、專案代碼等片段，組出的公開網址／分享連結會整個是壞的。
        $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $appName = $_APP['name'] ?? basename(dirname(__DIR__));
        $reqPathOnly = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $appMarkerPos = strpos($reqPathOnly, '/' . $appName);
        $basePath = $appMarkerPos !== false ? rtrim(substr($reqPathOnly, 0, $appMarkerPos + strlen($appName) + 1), '/') . '/' : '/';
        $mapUrl = fn($p) => $origin . $basePath . $p;

        // ── 動作 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $id = (string)($_POST['id'] ?? '');
          // 刪別人投稿預設僅限主 PIN；專案管理者只有在被授權 delete_others、且動的是自己已登入的專案時才可以
          if ($p !== '' && $id !== '' && ($master || admin_perm($cfg, $p, 'delete_others'))) {
            $removed = store_delete($cfg, $p, $id);
            if ($removed) audit_log($cfg, $auditWho(), 'delete_others', $p, $id);
            if ($removed && !empty($removed['photo'])) {
              $pp = photo_abs_path($cfg, $removed['photo']);
              if ($pp) {
                @unlink($pp);
                // 縮圖（上傳附帶或 photo.php 自動產生的）一律叫 <照片檔名>_t.*，跟著原圖一起清
                $ppBase = preg_replace('/\.[A-Za-z0-9]+$/', '', $pp);
                foreach (['webp', 'jpg', 'png'] as $te) @unlink($ppBase . '_t.' . $te);
              }
            }
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#records');
          exit;
        }
        // 編輯專案描述（只改標題/副標/說明/資料來源，其餘欄位保留；免手改 meta.json）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meta') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          if ($p === '' || !$canProject($p)) {
            error_page(403, $t('no_permission_title'), $t('no_project_permission_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access', $t('back_to_admin'));
          }
          $mf = $cfg['projects_dir'] . '/' . $p . '/meta.json';
          $meta = is_file($mf) ? json_decode((string)@file_get_contents($mf), true) : [];
          if (!is_array($meta)) $meta = [];
          foreach (['title', 'subtitle', 'desc', 'source', 'credit'] as $k) {
            $raw = trim((string)($_POST[$k] ?? ''));
            $v = preg_replace('/^(.{0,300}).*$/su', '$1', $raw);   // UTF-8 安全截斷
            if ($v === null) {
              continue;
            }                          // 非法 UTF-8 → 略過此欄，不動舊值
            if ($v === '') {
              unset($meta[$k]);
            } else {
              $meta[$k] = $v;
            }
          }
          // 功能模組開關：checkbox 沒勾就不會出現在 $_POST，所以「沒出現」＝關閉（非「保留原值」）
          if (isset($_POST['features']) || isset($_POST['modules_submitted'])) {
            $features = is_array($_POST['features'] ?? null) ? $_POST['features'] : [];
            foreach (souliong_modules() as $mk => $info) {
              if ($mk === 'personExplore') {
                continue;
              }
              $meta['features'][$mk] = isset($features[$mk]);
            }
            $meta['personExplore'] = isset($_POST['personExplore']);
          }
          // 資源包：只接受目前實際存在的包 id，避免存進一個已刪除／偽造的值
          if (isset($_POST['pack'])) {
            $pk = (string)$_POST['pack'];
            if ($pk === '') {
              unset($meta['pack']);
            } elseif (isset(souliong_pack_list($cfg)[$pk])) {
              $meta['pack'] = $pk;
            }
          }
          $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if ($json !== false && is_dir(dirname($mf))) {
            @file_put_contents($mf, $json, LOCK_EX);
          }   // 編碼失敗絕不覆寫，避免清空 meta
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($p) : '') . '#access');
          exit;
        }
        // 平台全域設定（跨地圖，非單一專案，僅主 PIN 可改）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settings') {
          need_csrf($csrf);
          if (!$master) {
            error_page(403, $t('no_permission_title'), $t('master_only_settings_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#tools', $t('back_to_admin'));
          }
          $s = souliong_settings_load($cfg);
          $s['random_explore'] = isset($_POST['random_explore']);
          $s['registration_open'] = isset($_POST['registration_open']);
          souliong_settings_save($cfg, $s);
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#tools');
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['addpin', 'delpin', 'setperm'], true)) {
          need_csrf($csrf);
          if (!$master) {
            error_page(403, $t('no_permission_title'), $t('master_only_pin_perm_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access', $t('back_to_admin'));
          }   // 權限管理限主 PIN
          $scope = ($_POST['scope'] ?? '') === 'master' ? 'master' : 'project';
          $tp = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $d = pins_load($cfg);
          if (($_POST['action']) === 'addpin') {
            $np = trim((string)($_POST['pin_new'] ?? ''));
            $label = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
            if ($np !== '') {
              $entry = ['pin' => $np, 'label' => $label];
              if ($scope === 'master') {
                $d['master'][] = $entry;
              } elseif ($tp !== '') {
                $entry['id'] = bin2hex(random_bytes(4));
                $entry['perms'] = pin_default_perms();   // 新專案 PIN 一律從全關始，之後在下方一覽表逐項開啟
                $d['projects'][$tp] = $d['projects'][$tp] ?? [];
                $d['projects'][$tp][] = $entry;
              }
            }
          } elseif (($_POST['action']) === 'setperm') {
            // 主 PIN 個別開關某把專案 PIN 的權限旗標（下放權限，不下放身分）
            $permKey = (string)($_POST['perm'] ?? '');
            $pinId = (string)($_POST['pin_id'] ?? '');
            $on = ($_POST['on'] ?? '') === '1';
            if ($tp !== '' && $pinId !== '' && array_key_exists($permKey, pin_default_perms())) {
              // foreach(...??[] as &$e) 是對暫存值取參照，不會寫回 $d：先確保鍵存在再用真正的變數取參照
              $d['projects'][$tp] = $d['projects'][$tp] ?? [];
              foreach ($d['projects'][$tp] as &$e) {
                if ((string)($e['id'] ?? '') === $pinId) { $e['perms'][$permKey] = $on; break; }
              }
              unset($e);
            }
          } else {
            $del = (string)($_POST['pin_del'] ?? '');
            $filter = fn($list) => array_values(array_filter($list, fn($e) => !isset($e['pin']) || (string)$e['pin'] !== $del));
            if ($scope === 'master') {
              $d['master'] = $filter($d['master']);
            } elseif ($tp !== '') {
              $d['projects'][$tp] = $filter($d['projects'][$tp] ?? []);
            }
          }
          pins_save($cfg, $d);
          header('Location: ?api=admin' . ($scope === 'project' && $tp !== '' ? '&project=' . urlencode($tp) : '') . '#access');
          exit;
        }
        // 建立「分享編輯連結」：投稿PIN（私人／僅限匿名）任何該專案管理者皆可建立；管理PIN 僅限主 PIN 或已被授權 delegate_admin 者。
        // 秘密（token／PIN）只透過網址 fragment（# 後面）帶出，伺服器與瀏覽器紀錄都不會留下──前端讀取後即用 history.replaceState 清除。
        // 特意不用 Location 導頁：導頁只能靠 query string 帶祕密回來，反而會落地在網址列/伺服器紀錄，所以留在同一次回應內顯示一次。
        $justCreatedShare = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sharelink') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          if ($p === '' || !$canProject($p)) {
            error_page(403, $t('no_permission_title'), $t('no_project_permission_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access', $t('back_to_admin'));
          }
          $kind = in_array(($_POST['kind'] ?? ''), ['code', 'admin'], true) ? $_POST['kind'] : 'code';
          if ($kind === 'admin' && !($master || admin_perm($cfg, $p, 'delegate_admin'))) {
            error_page(403, $t('no_permission_title'), $t('admin_pin_share_permission_msg'), '?api=admin&project=' . urlencode($p) . '#access', $t('back_to_admin'));
          }
          $label = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
          $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
          $expiresAt = null;
          if ($expiresRaw !== '') {
            $ts = strtotime($expiresRaw);
            if ($ts !== false) $expiresAt = gmdate('c', $ts);
          }
          $maxUsesRaw = trim((string)($_POST['max_uses'] ?? ''));
          $maxUses = ($maxUsesRaw !== '' && is_numeric($maxUsesRaw)) ? max(1, (int)$maxUsesRaw) : null;
          if ($kind === 'code') {
            $pinInput = trim((string)($_POST['pin_new'] ?? ''));
            $grantedCode = codes_grant_create($cfg, $p, $pinInput, $label, $expiresAt, $maxUses);
            $justCreatedShare = ['project' => $p, 'kind' => 'code', 'url' => $mapUrl($p) . '?code=' . $grantedCode, 'code' => $grantedCode];
          } else {
            [$grantedToken] = pins_invite_create($cfg, $p, $expiresAt, $maxUses);
            $justCreatedShare = ['project' => $p, 'kind' => 'admin', 'url' => $mapUrl($p) . '#redeem=' . rawurlencode($grantedToken) . '&rmode=admin'];
          }
        }
        // 建立「舊 PIN → 帳號」轉換連結：master（或已被授權 delegate_admin 的專案管理者，僅限 project 來源）
        // 產生一次性 token；收件人須先在啟用頁面重新輸入舊 PIN 證明本人，才能設定新的 userid/密碼。
        // 秘密（token）比照管理PIN邀請連結，只透過網址 fragment 帶出，不落地在 query string／伺服器紀錄。
        $justCreatedMigrate = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate_create') {
          need_csrf($csrf);
          $mSource = in_array(($_POST['source'] ?? ''), ['bootstrap', 'master', 'project'], true) ? $_POST['source'] : '';
          $mProject = $mSource === 'project' ? preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '') : null;
          $mLegacyId = ($_POST['legacy_id'] ?? '') !== '' ? (string)$_POST['legacy_id'] : null;
          $mLabel = substr(trim((string)($_POST['label'] ?? '')), 0, 80);
          $canMigrate = $mSource === 'project'
            ? ($mProject !== '' && ($master || admin_perm($cfg, $mProject, 'delegate_admin')))
            : $master;   // master／bootstrap 兩種來源（全域身分）僅限主 PIN 本人操作
          if ($mSource === '' || !$canMigrate) {
            error_page(403, $t('no_permission_title'), $t('master_only_pin_perm_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access', $t('back_to_admin'));
          }
          $pending = account_migrate_create($cfg, $mSource, $mProject, $mLegacyId, $mLabel !== '' ? $mLabel : 'user', $mLabel);
          audit_log($cfg, $auditWho(), 'migrate_create', $mProject, $mSource . ':' . ($mLegacyId ?? ''));
          $justCreatedMigrate = ['source' => $mSource, 'project' => $mProject, 'kind' => 'migrate', 'url' => $origin . $basePath . '?api=admin#activate=' . rawurlencode($pending['token']), 'note' => $t('migrate_link_hint')];
        }
        // 撤銷管理PIN邀請連結（尚未兌換）：跟建立邀請同一權限門檻——主 PIN 或已被授權 delegate_admin 的專案 PIN 皆可
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delinvite') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $iid = (string)($_POST['invite_id'] ?? '');
          if ($p !== '' && $iid !== '' && ($master || admin_perm($cfg, $p, 'delegate_admin'))) {
            $d = pins_load($cfg);
            $d['projects'][$p] = array_values(array_filter($d['projects'][$p] ?? [], fn($e) => !(($e['kind'] ?? '') === 'invite' && (string)($e['id'] ?? '') === $iid)));
            pins_save($cfg, $d);
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access');
          exit;
        }
        // 移除附加投稿碼（立即失效；常駐碼另走 rotate）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delcode') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $dc = preg_replace('/\D/', '', (string)($_POST['code_del'] ?? ''));
          if ($p !== '' && $dc !== '' && $canProject($p)) {
            codes_save($cfg, $p, array_values(array_filter(codes_load($cfg, $p), fn($e) => (string)($e['code'] ?? '') !== $dc)));
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access');
          exit;
        }
        // 移除投稿身分（含分享連結建立的、使用者自行設定的皆可）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delcontrib') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $cid = (string)($_POST['contrib_id'] ?? '');
          if ($p !== '' && $cid !== '' && $canProject($p)) {
            $cd = contrib_load($cfg, $p);
            if (isset($cd[$cid])) { unset($cd[$cid]); contrib_save($cfg, $p, $cd); }
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access');
          exit;
        }
        // 鎖定／解除鎖定某個投稿身分（PIN 投稿者用 contrib_id、匿名裝置用 owner_hash）：只擋日後投稿，不影響已投稿內容
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['blockid', 'unblockid'], true)) {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $kind = ($_POST['kind'] ?? '') === 'owner' ? 'owner' : 'contrib';
          $key = (string)($_POST['key'] ?? '');
          if ($p !== '' && $key !== '' && $canProject($p)) {
            if (($_POST['action']) === 'blockid') block_add($cfg, $p, $kind === 'owner' ? $key : null, $kind === 'contrib' ? $key : null);
            else block_remove($cfg, $p, $kind === 'owner' ? $key : null, $kind === 'contrib' ? $key : null);
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access');
          exit;
        }
        // 刪除某身分的全部投稿：跟單筆刪除同一權限規則（動別人的東西預設限主 PIN，或已授權 delete_others 的專案 PIN）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delbyid') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $field = ($_POST['kind'] ?? '') === 'owner' ? 'owner_hash' : 'contrib_id';
          $key = (string)($_POST['key'] ?? '');
          if ($p !== '' && $key !== '' && ($master || admin_perm($cfg, $p, 'delete_others'))) {
            $removedList = store_delete_by($cfg, $p, $field, $key);
            foreach ($removedList as $removed) {
              if (!empty($removed['photo'])) {
                $pp = photo_abs_path($cfg, $removed['photo']);
                if ($pp) {
                  @unlink($pp);
                  $ppBase = preg_replace('/\.[A-Za-z0-9]+$/', '', $pp);
                  foreach (['webp', 'jpg', 'png'] as $te) @unlink($ppBase . '_t.' . $te);
                }
              }
            }
            if ($removedList) audit_log($cfg, $auditWho(), 'delete_by_' . $field, $p, $key . ' (' . count($removedList) . ')');
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#access');
          exit;
        }
        // ── 備份（ZIP，含照片；純 PHP zip，零擴充依賴） ──
        if (isset($_GET['backup'])) {
          require_once __DIR__ . '/zip.php';
          // ── 資源包匯出：單一 pack 資料夾打包，路徑內含 <id>/ 前綴（比照 projects/<id>/ 的做法） ──
          if ($_GET['backup'] === 'pack') {
            if (!$master) {
              error_page(403, $t('no_permission_title'), $t('master_only_packs_msg'), '?api=admin#tools', $t('back_to_admin'));
            }
            $pid = preg_replace('/[^a-z0-9_-]/', '', $_GET['pack'] ?? '');
            $packs = souliong_pack_list($cfg);
            if ($pid === '' || !isset($packs[$pid])) {
              error_page(404, $t('error_404_title'), $t('pack_not_found_msg'), '?api=admin#tools', $t('back_to_admin'));
            }
            $pdir = rtrim($cfg['packs_dir'], '/\\') . '/' . $pid;
            $files = [];
            foreach (['pack.json', 'pack.css'] as $fn) {
              if (is_file($pdir . '/' . $fn)) $files[$pid . '/' . $fn] = $pdir . '/' . $fn;
            }
            $name = 'souliong-pack-' . $pid . '-' . date('Ymd-His') . '.zip';
            $tmp = tempnam(sys_get_temp_dir(), 'skpk');
            if (!zip_pack($tmp, $files)) {
              http_response_code(500);
              exit($t('backup_failed_msg'));
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
          }
          $bp = $_GET['backup'] === 'project' ? preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '') : null;
          if ($bp === null && !$master) {
            error_page(403, $t('no_permission_title'), $t('master_only_backup_all_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#tools', $t('back_to_admin'));
          }
          if ($bp !== null && !$canProject($bp)) {
            error_page(403, $t('no_permission_title'), $t('no_project_permission_msg'), '?api=admin' . ($bp !== '' ? '&project=' . urlencode($bp) : '') . '#tools', $t('back_to_admin'));
          }
          if ($bp === null) audit_log($cfg, $auditWho(), 'backup_all', null, '');
          $files = [];
          $addDir = function ($absDir, $prefix) use (&$files) {
            if (!is_dir($absDir)) return;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)) as $f) {
              if (!$f->isFile() || strpos($f->getPathname(), DIRECTORY_SEPARATOR . '.rate' . DIRECTORY_SEPARATOR) !== false) continue;
              $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($absDir))), '/');
              $files[$prefix . '/' . $rel] = $f->getPathname();
            }
          };
          if ($bp) {
            $addDir(project_dir($cfg, $bp), 'projects/' . $bp);
            $name = 'souliong-' . $bp . '-' . date('Ymd-His') . '.zip';
          } else {
            $addDir($cfg['projects_dir'], 'projects');
            $addDir($cfg['state_dir'], 'data');
            $name = 'souliong-all-' . date('Ymd-His') . '.zip';
          }
          $tmp = tempnam(sys_get_temp_dir(), 'skbk');
          if (!zip_pack($tmp, $files)) {
            http_response_code(500);
            exit($t('backup_failed_msg'));
          }
          header('Content-Type: application/zip');
          header('Content-Disposition: attachment; filename="' . $name . '"');
          header('Content-Length: ' . filesize($tmp));
          readfile($tmp);
          @unlink($tmp);
          exit;
        }

        // ── 匯入還原（合併／覆蓋）：主要管理者限定 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
          need_csrf($csrf);
          if (!$master) {
            error_page(403, $t('no_permission_title'), $t('master_only_import_msg'), '?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : '') . '#tools', $t('back_to_admin'));
          }
          require_once __DIR__ . '/zip.php';
          $imported = 0;
          if (isset($_FILES['backup']) && $_FILES['backup']['error'] === UPLOAD_ERR_OK) {
            $mode = ($_POST['mode'] ?? 'merge') === 'replace' ? 'replace' : 'merge';
            // 只接受 projects/、data/admin_pins.json 底下、無 .. 的安全路徑
            $accept = fn($nm) => strpos(str_replace('\\', '/', (string)$nm), '..') === false
              && preg_match('#^(projects/[A-Za-z0-9_./-]+|data/admin_pins\.json)$#', str_replace('\\', '/', (string)$nm));
            $entries = zip_unpack($_FILES['backup']['tmp_name'], $accept);
            // 1) 資料（jsonl）
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^projects/([a-z0-9_-]+)/data\.jsonl$#', str_replace('\\', '/', $nm), $mm)) continue;
              $proj = $mm[1];
              if ($mode === 'replace') {
                @file_put_contents(project_dir($cfg, $proj) . '/data.jsonl', $content, LOCK_EX);
              } else {
                $ids = [];
                foreach (store_all($cfg, $proj) as $r) {
                  $ids[(string)($r['id'] ?? '')] = true;
                }
                foreach (preg_split('/\r?\n/', (string)$content) as $ln) {
                  $ln = trim($ln);
                  if ($ln === '') continue;
                  $rec = json_decode($ln, true);
                  if (is_array($rec) && !isset($ids[(string)($rec['id'] ?? '')])) {
                    store_append($cfg, $proj, $rec);
                    $imported++;
                  }
                }
              }
            }
            // 2) 統計 / 投稿碼（僅覆蓋模式才蓋掉）
            if ($mode === 'replace') {
              foreach ($entries as $nm => $content) {
                if (preg_match('#^projects/([a-z0-9_-]+)/(stats\.json|code\.txt|codes\.json|contrib\.json)$#', str_replace('\\', '/', $nm), $mm)) @file_put_contents(project_dir($cfg, $mm[1]) . '/' . $mm[2], $content, LOCK_EX);
                elseif ($nm === 'data/admin_pins.json') {
                  @file_put_contents(pins_file($cfg), $content, LOCK_EX);
                }
              }
            }
            // 3) 照片（合併只補缺的；覆蓋才蓋）
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^projects/([a-z0-9_-]+)/photos/([A-Za-z0-9_.-]+)$#', str_replace('\\', '/', $nm), $mm)) continue;
              $destDir = project_dir($cfg, $mm[1]) . '/photos';
              if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
              $dest = $destDir . '/' . $mm[2];
              if ($mode === 'replace' || !is_file($dest)) @file_put_contents($dest, $content);
            }
            audit_log($cfg, $auditWho(), 'import', null, $mode . ', +' . $imported . ' 筆');
          }
          header('Location: ?api=admin#tools');
          exit;
        }

        // ── 資源包匯入：主要管理者限定；zip 內路徑需含 <id>/ 前綴，id 只認資料夾名稱（避免 pack.json 內容偽造） ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'packimport') {
          need_csrf($csrf);
          if (!$master) {
            error_page(403, $t('no_permission_title'), $t('master_only_packs_msg'), '?api=admin#tools', $t('back_to_admin'));
          }
          require_once __DIR__ . '/zip.php';
          if (isset($_FILES['pack']) && $_FILES['pack']['error'] === UPLOAD_ERR_OK) {
            $accept = fn($nm) => strpos(str_replace('\\', '/', (string)$nm), '..') === false
              && preg_match('#^[a-z0-9_-]+/(pack\.json|pack\.css)$#', str_replace('\\', '/', (string)$nm));
            $entries = zip_unpack($_FILES['pack']['tmp_name'], $accept);
            $ids = [];
            foreach ($entries as $nm => $content) {
              if (!preg_match('#^([a-z0-9_-]+)/(pack\.json|pack\.css)$#', str_replace('\\', '/', $nm), $mm)) continue;
              $destDir = rtrim($cfg['packs_dir'], '/\\') . '/' . $mm[1];
              if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
              @file_put_contents($destDir . '/' . $mm[2], $content, LOCK_EX);
              $ids[$mm[1]] = true;
            }
            audit_log($cfg, $auditWho(), 'pack_import', null, implode(',', array_keys($ids)));
          }
          header('Location: ?api=admin#tools');
          exit;
        }

        // ── 資料 ──（$allProjects 沿用前面「專案清單」算好的那份，中間的動作不會新增/刪除專案目錄）
        $viewProjects = $master
          ? ($scopeProject !== '' ? [$scopeProject] : $allProjects)
          : ($acct !== null ? ($scopeProject !== '' ? [$scopeProject] : $acctProjects) : [$reqProject]);

        $rows = [];
        foreach ($viewProjects as $p) {
          foreach (store_all($cfg, $p) as $r) {
            $r['project'] = $p;
            $rows[] = $r;
          }
        }
        usort($rows, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $short = fn($s) => $s ? substr((string)$s, 0, 8) : '—';
        // 編輯版本的 photo/exif 依設計為 null（沿用原始投稿），顯示時要透過 edit_of 回原始那筆取值
        $byId = [];
        foreach ($rows as $r) { $byId[$r['project'] . '/' . (string)($r['id'] ?? '')] = $r; }

        header('Content-Type: text/html; charset=utf-8');
          ?>
<!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'zh-Hant' ?>">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $t('app_title') ?> · <?= $t('admin_panel_suffix') ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    .langsw{position:fixed;top:16px;right:16px;z-index:3;display:flex;gap:2px;font-size:0.75rem}
    .langsw a{color:var(--muted);text-decoration:none;padding:4px 8px;border-radius:999px}
    .langsw a.on{color:var(--fg);font-weight:700;background:var(--card);border:1px solid var(--line)}
    :root {
      color-scheme: light dark;
      --bg: #f6f6f7;
      --fg: #1b1b1d;
      --muted: #6b6b70;
      --line: #e7e7ea;
      --card: #fff;
      --accent: #1b1b1d;
      --accent-fg: #fff;
      --r-lg: 20px;
      --r-md: 13px;
      --sh: 0 6px 24px rgba(0, 0, 0, .08);
      --danger: #c0392b
    }

    @media (prefers-color-scheme:dark) {
      :root {
        --bg: #111113;
        --fg: #f1f1f3;
        --muted: #9c9ca3;
        --line: #2b2b2f;
        --card: #1c1c1f;
        --accent: #f1f1f3;
        --accent-fg: #151517;
        --sh: 0 6px 24px rgba(0, 0, 0, .4);
        --danger: #ff6b6b
      }
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      font-family: system-ui, sans-serif;
      background: var(--bg);
      color: var(--fg);
      -webkit-font-smoothing: antialiased
    }

    .wrap {
      max-width: 1000px;
      margin: 0 auto;
      padding: 24px 20px 60px
    }

    .top {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap
    }

    h1 {
      font-size: 1.375rem;
      font-weight: 800;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1
    }

    h1 .sub {
      font-size: 0.75rem;
      font-weight: 500;
      color: var(--muted)
    }

    h2 {
      font-size: 0.8125rem;
      font-weight: 700;
      color: var(--muted);
      letter-spacing: .06em;
      text-transform: uppercase;
      margin: 28px 0 12px
    }

    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      box-shadow: var(--sh)
    }

    /* 投稿碼：一碼一張卡，排成 grid */
    .code-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 12px;
      margin-top: 8px
    }

    .codecard {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      padding: 14px 16px
    }

    .codecard .qr, .sharenew .qr {
      background: #fff;
      padding: 6px;
      border-radius: 12px;
      flex: none;
      width: 84px;
      height: 84px;
      cursor: zoom-in
    }

    /* 點 QR 展開全螢幕，方便現場給人掃描 */
    .qr-modal {
      position: fixed;
      inset: 0;
      z-index: 999;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0, 0, 0, .78);
      padding: 24px
    }

    .qr-modal .qr-modal-card {
      background: #fff;
      border-radius: 28px;
      padding: 36px 32px;
      max-width: min(90vw, 440px);
      width: 100%;
      text-align: center;
      box-shadow: 0 24px 70px rgba(0, 0, 0, .45)
    }

    .qr-modal .qr-modal-title {
      font-size: 1.0625rem;
      font-weight: 800;
      color: #1a1a1a;
      margin-bottom: 4px
    }

    .qr-modal .qr-modal-guide {
      font-size: 0.8125rem;
      color: #666;
      margin-bottom: 16px
    }

    .qr-modal .qr-modal-code {
      margin-top: 16px;
      font-family: ui-monospace, Consolas, monospace;
      font-size: 1.75rem;
      font-weight: 700;
      letter-spacing: .08em;
      color: #1a1a1a
    }

    .qr-modal .qr-modal-box {
      width: 100%;
      aspect-ratio: 1;
    }

    .qr-modal .qr-modal-box svg {
      width: 100%;
      height: 100%;
      display: block
    }

    .qr-modal .qr-modal-url {
      margin-top: 14px;
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.75rem;
      color: #444;
      word-break: break-all
    }

    .qr-modal .qr-modal-hint {
      margin-top: 10px;
      font-size: 0.75rem;
      color: #888
    }

    .codecard .qr svg, .sharenew .qr svg {
      width: 100%;
      height: 100%;
      display: block
    }

    .codecard-info {
      flex: 1;
      min-width: 0
    }

    .codecard-title {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 6px
    }

    .codecard-title .code-main {
      font-size: 0.9375rem;
      font-weight: 800;
      color: var(--fg);
      letter-spacing: .03em
    }

    .codecard-title .codecard-label {
      font-size: 0.75rem;
      color: var(--muted)
    }

    /* 身分管理：投稿者／管理 PIN 並列 */
    .idgrid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 16px;
      margin-top: 8px
    }

    .idgroup {
      min-width: 0
    }

    .invite {
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.75rem;
      color: var(--muted);
      word-break: break-all;
      margin-top: 8px;
      background: var(--bg);
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid var(--line)
    }

    .row {
      display: flex;
      gap: 6px;
      align-items: center;
      flex-wrap: wrap;
      margin-top: 8px
    }

    .metaedit {
      display: contents
    }

    .metaedit>summary {
      list-style: none
    }

    .metaedit>summary::-webkit-details-marker {
      display: none
    }

    .metaform {
      display: flex;
      flex-direction: column;
      gap: 8px
    }

    /* 編輯專案描述：原本用 <details> 內嵌展開，但 .projactions 是 flex 排版，
       表單的 flex-basis:100% 只會相對「按鈕列」換行，不是相對整列，導致跑版；改用原生 <dialog> 徹底脫離該排版脈絡 */
    .metadlg {
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      background: var(--card);
      color: var(--fg);
      box-shadow: var(--sh);
      padding: 18px 20px;
      max-width: 480px;
      width: calc(100% - 40px)
    }

    .metadlg::backdrop {
      background: rgba(0, 0, 0, .5)
    }

    .metadlg h3 {
      margin: 0 0 4px;
      font-size: 0.9375rem;
      display: flex;
      align-items: center;
      gap: 8px
    }

    .dlgactions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
      margin-top: 4px
    }

    .metaform label {
      display: flex;
      flex-direction: column;
      gap: 4px;
      font-size: 0.6875rem;
      color: var(--muted)
    }

    .metaform input,
    .metaform select,
    .metaform textarea {
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--bg);
      color: var(--fg);
      padding: 8px 10px;
      font-size: 0.8125rem;
      width: 100%;
      resize: vertical
    }

    .modfields {
      display: flex;
      flex-direction: column;
      gap: 6px;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px;
    }

    .modfields-head {
      font-size: 0.6875rem;
      color: var(--muted);
      font-weight: 600;
    }

    .metaform label.modrow {
      flex-direction: row;
      align-items: flex-start;
      gap: 8px;
      font-size: 0.8125rem;
      color: var(--fg);
    }

    .modrow input[type="checkbox"] {
      width: auto;
      margin-top: 3px;
      flex: none;
    }

    .row input {
      border: 1px solid var(--line);
      border-radius: 10px;
      background: var(--bg);
      color: var(--fg);
      padding: 7px 10px;
      font-size: 0.8125rem;
      flex: 1 1 140px;
      min-width: 120px
    }

    .row input[type="datetime-local"] {
      flex-basis: 180px
    }

    .row input[type="number"] {
      flex: 0 1 140px
    }

    .row label.fieldlabel {
      display: flex;
      flex-direction: column;
      gap: 4px;
      font-size: 0.6875rem;
      color: var(--muted);
      flex: 1 1 140px;
      min-width: 120px
    }

    .row label.fieldlabel input {
      width: 100%;
      flex: 1 1 auto
    }

    .pin-toggle-wrap {
      position: relative;
      display: inline-flex;
      width: 100%
    }

    .pin-toggle-wrap input {
      width: 100%;
      padding-right: 32px
    }

    .pin-toggle-btn {
      position: absolute;
      right: 4px;
      top: 50%;
      transform: translateY(-50%);
      width: 24px;
      height: 24px;
      border: none;
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 8px
    }

    .pin-toggle-btn:hover {
      background: var(--line)
    }

    .expirywidget {
      display: flex;
      flex-direction: column;
      gap: 4px
    }

    .expirychips {
      display: flex;
      gap: 4px;
      flex-wrap: wrap
    }

    .chip {
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--muted);
      font-size: 0.6875rem;
      padding: 3px 9px;
      cursor: pointer
    }

    .chip.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .btn {
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--fg);
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 8px 14px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      white-space: nowrap
    }

    .btn:hover {
      background: var(--bg)
    }

    .btn.danger {
      color: var(--danger)
    }

    .btn.solid {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .tabs {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      margin: 16px 0 4px
    }

    .tab {
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--card);
      color: var(--fg);
      text-decoration: none
    }

    .tab.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    .tab:hover {
      background: var(--bg)
    }

    .stat-card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 14px 16px;
      margin-bottom: 14px
    }

    .stat-card>summary {
      cursor: pointer;
      list-style: none
    }

    .stat-card>summary::-webkit-details-marker {
      display: none
    }

    .stat-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px
    }

    .stat-card-head b {
      font-size: 0.8125rem
    }

    .stat-card-head .chev {
      color: var(--muted);
      transition: transform var(--t)
    }

    .stat-card[open] .stat-card-head .chev {
      transform: rotate(180deg)
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 12px
    }

    .tile {
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 14px 16px
    }

    .tile .n {
      font-size: 1.625rem;
      font-weight: 800
    }

    .tile .l {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 2px
    }

    .break {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 10px;
      line-height: 1.7
    }

    .break b {
      color: var(--fg)
    }

    .stat-card .cols {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px dashed var(--line)
    }

    .stat-card .col {
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 12px 14px;
      font-size: 0.75rem
    }

    .stat-card .col h4 {
      margin: 0 0 6px;
      font-size: 0.75rem
    }

    .stat-card .col ol {
      margin: 0;
      padding-left: 18px;
      line-height: 1.8
    }

    .stat-card .col p {
      margin: 4px 0;
      color: var(--muted);
      line-height: 1.6
    }

    .tablewrap {
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: var(--r-lg);
      background: var(--card)
    }

    table {
      border-collapse: collapse;
      width: 100%;
      font-size: 0.8125rem;
      min-width: 820px
    }

    th {
      background: var(--bg);
      position: sticky;
      top: 0;
      text-align: left;
      font-weight: 700;
      color: var(--muted);
      font-size: 0.6875rem;
      letter-spacing: .04em;
      text-transform: uppercase
    }

    th,
    td {
      padding: 10px 12px;
      border-bottom: 1px solid var(--line);
      vertical-align: top
    }

    tr:hover td {
      background: var(--bg)
    }

    td img {
      width: 80px;
      height: 80px;
      object-fit: cover;
      border-radius: 10px;
      display: block
    }

    .mono {
      font-family: ui-monospace, Consolas, monospace;
      font-size: 0.6875rem;
      color: var(--muted)
    }

    .tag {
      display: inline-block;
      font-size: 0.6875rem;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 999px;
      background: var(--bg);
      border: 1px solid var(--line)
    }

    .hint {
      font-size: 0.75rem;
      color: var(--muted);
      margin-top: 14px;
      line-height: 1.7
    }

    .iconbtn {
      background: none;
      border: none;
      color: var(--danger);
      cursor: pointer;
      font-size: 0.9375rem
    }

    .badge {
      font-size: 0.6875rem;
      color: var(--muted);
      margin-top: 6px
    }

    .pinlist {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 8px
    }

    .pinchip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      background: var(--bg);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 5px 6px 5px 12px
    }

    .pinchip form {
      display: inline;
      margin: 0
    }

    .x {
      background: none;
      border: none;
      color: var(--danger);
      cursor: pointer;
      font-size: 1rem;
      line-height: 1;
      padding: 0 4px
    }

    .pinchip-block {
      flex-direction: column;
      align-items: flex-start;
      border-radius: 14px;
      padding: 8px 12px;
      gap: 4px
    }

    .pinchip-block.blocked {
      border-color: var(--danger)
    }

    .permrow {
      display: flex;
      gap: 4px;
      flex-wrap: wrap;
      margin-top: 2px
    }

    .permtoggle {
      border: 1px solid var(--line);
      border-radius: 999px;
      background: var(--card);
      color: var(--muted);
      font-size: 0.6875rem;
      padding: 3px 9px;
      cursor: pointer
    }

    .permtoggle.on {
      background: var(--accent);
      color: var(--accent-fg);
      border-color: var(--accent)
    }

    /* 存取與權限：投稿碼卡片 grid + 身分管理（投稿者／管理PIN）grid，見下方 .code-grid / .idgrid */
    .sechead {
      font-size: 0.8125rem;
      font-weight: 700;
      margin: 2px 0 4px
    }

    .sechead .fa-solid {
      color: var(--accent);
      margin-right: 4px
    }

    .projhead {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      margin: 22px 0 10px
    }

    .projactions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      flex: 1 1 240px;
      gap: 8px;
      flex-wrap: wrap
    }

    .projhead .projtitle {
      font-size: 0.9375rem;
      font-weight: 700
    }

    /* 剛建立的分享連結（accent 框）：QR＋連結並排 */
    .sharenew {
      padding: 12px 14px;
      margin-top: 10px;
      border-color: var(--accent)
    }

    .sharenew-body {
      display: flex;
      gap: 14px;
      align-items: flex-start;
      flex-wrap: wrap;
      margin-top: 8px
    }

    .sharenew-info {
      flex: 1;
      min-width: 220px
    }

    .sharenew-info .invite {
      margin-top: 0
    }

    /* 遮罩的 PIN／碼：按眼睛切換顯示 */
    .secretwrap {
      display: inline-flex;
      align-items: center;
      gap: 2px
    }

    .eyebtn {
      background: none;
      border: none;
      color: var(--muted);
      cursor: pointer;
      font-size: 0.75rem;
      padding: 0 3px
    }

    .eyebtn:hover {
      color: var(--fg)
    }

    /* pinchip 內的小型連結按鈕（複製邀請連結等） */
    .chipbtn {
      background: none;
      border: none;
      color: var(--accent);
      cursor: pointer;
      font-size: 0.75rem;
      padding: 0 3px
    }

    /* ── 專案總覽（scope=全部）：一張地圖一張精簡卡，細節請點進去 ── */
    .ov-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 12px
    }

    .ovcard {
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      text-decoration: none;
      color: inherit
    }

    .ovcard:hover {
      border-color: var(--fg)
    }

    .ovcard .t {
      font-weight: 700;
      font-size: 0.9375rem
    }

    .ovcard .n {
      font-size: 0.75rem;
      color: var(--muted)
    }

    .ovcard .stat-row {
      display: flex;
      gap: 12px;
      font-size: 0.75rem;
      color: var(--muted)
    }

    .ovcard .stat-row b {
      color: var(--fg);
      font-size: 0.9375rem;
      display: block
    }

    /* ── 單一專案工作區：頂端子導覽（分頁），一次只顯示一塊，減少「散落」感 ── */
    .subnav {
      display: flex;
      gap: 4px;
      flex-wrap: wrap;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 4px;
      margin: 16px 0
    }

    .subtab {
      border: none;
      background: none;
      color: var(--muted);
      font-size: 0.8125rem;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: 999px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap
    }

    .subtab.on {
      background: var(--accent);
      color: var(--accent-fg)
    }

    .pane {
      display: none
    }

    .pane.on {
      display: block
    }

    .section-card {
      padding: 18px 20px
    }

    .section-card+.section-card {
      margin-top: 12px
    }

    .danger-zone {
      border-color: var(--danger)
    }

    .searchbar {
      display: flex;
      align-items: center;
      gap: 8px;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 8px 14px;
      margin-bottom: 12px;
      color: var(--muted)
    }

    .searchbar input {
      border: none;
      background: none;
      color: var(--fg);
      font-size: 0.8125rem;
      outline: none;
      flex: 1
    }

    .emptystate {
      padding: 32px 20px;
      text-align: center;
      color: var(--muted);
      font-size: 0.8125rem
    }
  </style>
</head>

<body>
  <div class="wrap">
    <?php $langQs = $scopeProject !== '' ? 'project=' . rawurlencode($scopeProject) . '&api=admin&' : 'api=admin&'; ?>
    <div class="langsw">
      <a href="?<?= $langQs ?>lang=zh_TW" class="<?= $LANG === 'zh_TW' ? 'on' : '' ?>">中文</a>
      <a href="?<?= $langQs ?>lang=en" class="<?= $LANG === 'en' ? 'on' : '' ?>">English</a>
    </div>
    <div class="top">
      <h1><i class="fa-solid fa-gauge-high"></i> <?= $t('app_title') ?> <span class="sub"><?= $master ? $t('master_admin_label') : $t('project_admin_label', ['project' => $reqProject]) ?> · <?= $t('records_count_suffix', ['n' => count($rows)]) ?></span></h1>
      <a class="btn" href="?api=admin&logout=1"><i class="fa-solid fa-right-from-bracket"></i> <?= $t('logout_btn') ?></a>
    </div>

    <?php if (!is_writable($cfg['projects_dir'])): ?>
      <div class="card" style="border-color:var(--danger);color:var(--danger);padding:14px 18px;margin-top:12px;font-size:.85rem;line-height:1.7">
        <b><i class="fa-solid fa-triangle-exclamation"></i> <?= $t('dir_not_writable_title', ['dir' => $cfg['projects_dir']]) ?></b><br>
        <?= $tr('dir_not_writable_detail') ?>
      </div>
    <?php endif; ?>

    <?php if ($master && $scopeProject === ''): ?>
      <div class="tabs">
        <a class="tab on"><?= $t('tab_all_count', ['n' => count($allProjects)]) ?></a>
        <?php foreach ($allProjects as $tp): ?><a class="tab" href="?api=admin&project=<?= $esc($tp) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
      </div>
    <?php elseif ($master): ?>
      <div class="tabs">
        <a class="tab" href="?api=admin"><i class="fa-solid fa-arrow-left"></i> <?= $t('back_to_main_site') ?></a>
      </div>
    <?php elseif ($acct !== null && count($acctProjects) > 1 && $scopeProject === ''): ?>
      <div class="tabs">
        <a class="tab on"><?= $t('tab_all_count', ['n' => count($acctProjects)]) ?></a>
        <?php foreach ($acctProjects as $tp): ?><a class="tab" href="?api=admin&project=<?= $esc($tp) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
      </div>
    <?php elseif ($acct !== null && count($acctProjects) > 1): ?>
      <div class="tabs">
        <a class="tab" href="?api=admin"><i class="fa-solid fa-arrow-left"></i> <?= $t('back_to_all_my_projects') ?></a>
      </div>
    <?php endif; ?>

    <?php
      // 表單送出後回到對應分頁，避免每次都跳回總覽：多數動作走 redirect，目的地已經帶好 #pane 片段（見上方各 header('Location: …#…')）；
      // 只有 sharelink 例外——它不 redirect、直接在同一次回應內把頁面畫出來，此時網址列還沒有片段，得靠這裡補上
      $forcePane = in_array(($_POST['action'] ?? ''), ['sharelink', 'migrate_create'], true) ? 'access' : '';
    ?>
    <div class="subnav">
      <button type="button" class="subtab on" data-pane="overview"><i class="fa-solid fa-chart-simple"></i> <?= $t('overview_tab') ?></button>
      <button type="button" class="subtab" data-pane="records"><i class="fa-solid fa-table-list"></i> <?= $t('records_tab_count', ['n' => count($rows)]) ?></button>
      <button type="button" class="subtab" data-pane="access"><i class="fa-solid fa-key"></i> <?= $t('access_tab') ?></button>
      <?php if ($master): ?><button type="button" class="subtab" data-pane="tools"><i class="fa-solid fa-screwdriver-wrench"></i> <?= $t('tools_tab') ?></button><?php endif; ?>
    </div>

    <div class="pane" id="pane-access">
    <?php if ($master && $scopeProject === ''): $mpins = pins_load($cfg)['master']; ?>
      <h2><?= $t('master_pins_heading') ?></h2>
      <div class="hint" style="margin:-6px 0 12px"><?= $t('master_pins_hint') ?></div>
      <div class="card" style="padding:16px 18px">
        <div class="pinlist">
          <span class="pinchip"><?= $t('master_config_label') ?> · <span class="mono">config</span>
            <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="bootstrap"><input type="hidden" name="label" value="<?= $esc($cfg['admin_pin_label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
          </span>
          <?php foreach ($mpins as $e): ?><span class="pinchip"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?> · <?= $secret($e['pin'] ?? '') ?>
              <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="master"><input type="hidden" name="legacy_id" value="<?= $esc($e['id'] ?? '') ?>"><input type="hidden" name="label" value="<?= $esc($e['label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
              <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="master"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="<?= $t('remove_title') ?>">×</button></form>
            </span>
          <?php endforeach; ?>
        </div>
        <form class="row" method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="master">
          <input name="pin_new" placeholder="<?= $t('add_master_pin_placeholder') ?>" autocomplete="off"><input name="label" placeholder="<?= $t('nickname_optional_placeholder') ?>" autocomplete="off"><button class="btn"><?= $t('add_btn') ?></button>
        </form>
      </div>
      <?php if ($justCreatedMigrate && $justCreatedMigrate['project'] === null): ?>
        <div class="card sharenew">
          <div class="badge"><i class="fa-solid fa-circle-check"></i> <?= $t('migrate_link_created_badge') ?><?= $t('share_created_once_note') ?></div>
          <div class="sharenew-body">
            <div class="qr" data-url="<?= $esc($justCreatedMigrate['url']) ?>" data-title="<?= $t('migrate_to_account_title') ?>"></div>
            <div class="sharenew-info">
              <div class="invite"><?= $esc($justCreatedMigrate['url']) ?></div>
              <div class="row"><button type="button" class="btn" data-copy="<?= $esc($justCreatedMigrate['url']) ?>"><i class="fa-solid fa-copy"></i> <?= $t('copy_link') ?></button></div>
              <div class="hint" style="margin-top:6px"><?= $t('migrate_link_hint') ?></div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <h2><?= $t('project_access_heading') ?></h2>
    <?php foreach ($viewProjects as $p):
      $meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $p . '/meta.json'), true);
      $gated = !empty($meta['gated']);
      $ppinsAll = pins_load($cfg)['projects'][$p] ?? [];
      $realPins = array_values(array_filter($ppinsAll, fn($e) => ($e['kind'] ?? 'pin') !== 'invite'));
      $invites = array_values(array_filter($ppinsAll, fn($e) => ($e['kind'] ?? 'pin') === 'invite'));
    ?>
      <div class="projhead">
        <div class="projtitle"><i class="fa-solid fa-map-location-dot"></i> <?= $esc($meta['title'] ?? $p) ?>（<?= $esc($p) ?>）</div>
        <div class="projactions">
          <a class="btn" href="?api=admin&backup=project&project=<?= $esc($p) ?>"><i class="fa-solid fa-download"></i> <?= $t('backup_project_btn') ?></a>
          <?php if ($canProject($p)): ?>
          <button type="button" class="btn" onclick="document.getElementById('metadlg-<?= $esc($p) ?>').showModal()"><i class="fa-solid fa-pen-to-square"></i> <?= $t('edit_project_desc_btn') ?></button>
          <dialog id="metadlg-<?= $esc($p) ?>" class="metadlg" onclick="if(event.target===this)this.close()">
            <form method="post" class="metaform">
              <h3><i class="fa-solid fa-pen-to-square"></i> <?= $t('edit_project_desc_btn') ?></h3>
              <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="meta"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <label><?= $t('field_title_label') ?><input name="title" maxlength="300" value="<?= $esc($meta['title'] ?? '') ?>" placeholder="<?= $t('map_title_placeholder') ?>"></label>
              <label><?= $t('field_subtitle_label') ?><input name="subtitle" maxlength="300" value="<?= $esc($meta['subtitle'] ?? '') ?>" placeholder="<?= $t('subtitle_placeholder') ?>"></label>
              <label><?= $t('field_desc_label') ?><textarea name="desc" rows="2" maxlength="300" placeholder="<?= $t('desc_optional_placeholder') ?>"><?= $esc($meta['desc'] ?? '') ?></textarea></label>
              <label><?= $t('field_source_label') ?><input name="source" maxlength="300" value="<?= $esc($meta['source'] ?? '') ?>" placeholder="<?= $t('source_placeholder') ?>"></label>
              <label><?= $t('field_credit_label') ?><input name="credit" maxlength="300" value="<?= $esc($meta['credit'] ?? '') ?>" placeholder="<?= $t('credit_placeholder') ?>"></label>
              <label><?= $t('field_pack_label') ?>
                <select name="pack">
                  <option value=""><?= $t('no_pack_option') ?></option>
                  <?php foreach (souliong_pack_list($cfg) as $pid => $pinfo): ?>
                  <option value="<?= $esc($pid) ?>" <?= (($meta['pack'] ?? '') === $pid) ? 'selected' : '' ?>><?= $esc($pinfo['label'] ?? $pid) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <div class="badge"><?= $tr('sources_field_hint') ?></div>
              <input type="hidden" name="modules_submitted" value="1">
              <div class="modfields">
                <div class="modfields-head"><?= $t('feature_modules_heading') ?></div>
                <?php foreach (souliong_modules() as $mk => $minfo): $mon = souliong_module_on($meta, $mk); ?>
                <label class="modrow">
                  <input type="checkbox" name="<?= $mk === 'personExplore' ? 'personExplore' : 'features[' . $esc($mk) . ']' ?>" <?= $mon ? 'checked' : '' ?>>
                  <span><b><?= $esc($minfo['label']) ?></b><br><span class="hint"><?= $esc($minfo['desc']) ?></span></span>
                </label>
                <?php endforeach; ?>
              </div>
              <div class="dlgactions">
                <button type="button" class="btn" onclick="this.closest('dialog').close()"><?= $t('cancel') ?></button>
                <button class="btn solid"><i class="fa-solid fa-floppy-disk"></i> <?= $t('save_desc_btn') ?></button>
              </div>
            </form>
          </dialog>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canProject($p)):
        $canDelegateAdmin = $master || admin_perm($cfg, $p, 'delegate_admin');
        $cList = contrib_load($cfg, $p);
        $codesList = codes_load($cfg, $p);
        $blocked = blocked_load($cfg, $p);
        // 依實際投稿紀錄統計每個身分的則數：有 PIN 身分的算 contrib_id，其餘（含匿名）算 owner_hash 分組。
        $contribCounts = [];
        $ownerGroups = [];
        foreach ($rows as $r) {
          if (($r['project'] ?? '') !== $p) continue;
          $cid = $r['contrib_id'] ?? null;
          if ($cid) { $contribCounts[$cid] = ($contribCounts[$cid] ?? 0) + 1; continue; }
          $oh = $r['owner_hash'] ?? null;
          if (!$oh) continue;
          if (!isset($ownerGroups[$oh])) $ownerGroups[$oh] = ['count' => 0, 'last_name' => '', 'last_at' => ''];
          $ownerGroups[$oh]['count']++;
          $at = (string)($r['created_at'] ?? '');
          if ($at > $ownerGroups[$oh]['last_at']) { $ownerGroups[$oh]['last_at'] = $at; $ownerGroups[$oh]['last_name'] = (string)($r['name'] ?? ''); }
        }
        $canDeleteOthers = $master || admin_perm($cfg, $p, 'delete_others');
        // 剛建立的憑證：只在本次回應顯示一次，畫在所屬區塊內（屬「正在分享」，維持明碼）
        $justHere = fn(...$kinds) => $justCreatedShare && $justCreatedShare['project'] === $p && in_array($justCreatedShare['kind'], $kinds, true);
        $shareNew = function (array $s, string $kindLabel) use ($esc, $p, $meta, $t) { ?>
          <div class="card sharenew">
            <div class="badge"><i class="fa-solid fa-circle-check"></i> <?= $t('share_created_badge', ['kind' => $kindLabel]) ?><?= $s['kind'] === 'code' ? '' : $t('share_created_once_note') ?></div>
            <div class="sharenew-body">
              <div class="qr" data-url="<?= $esc($s['url']) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= isset($s['code']) ? $esc($s['code']) : '' ?>"></div>
              <div class="sharenew-info">
                <div class="invite"><?= $esc($s['url']) ?></div>
                <div class="row"><button type="button" class="btn" data-copy="<?= $esc($s['url']) ?>"><i class="fa-solid fa-copy"></i> <?= $t('copy_link') ?></button></div>
                <?php if (isset($s['code'])): ?><div class="hint" style="margin-top:6px"><?= $t('share_code_hint', ['code' => $s['code']]) ?></div><?php endif; ?>
                <?php if (isset($s['pin'])): ?><div class="hint" style="margin-top:6px"><?= $t('share_pin_hint', ['pin' => $s['pin']]) ?></div><?php endif; ?>
                <?php if (isset($s['note'])): ?><div class="hint" style="margin-top:6px"><?= $s['note'] ?></div><?php endif; ?>
              </div>
            </div>
          </div>
        <?php };
      ?>
        <!-- 投稿碼：一碼一張卡，連結／QR／限制／用量都在同一張卡上 -->
        <div class="sechead"><i class="fa-solid fa-ticket"></i> <?= $t('contrib_code') ?><?= $gated ? '' : $t('codes_ungated_hint') ?></div>
        <?php if ($codesList): ?>
          <div class="code-grid">
            <?php foreach ($codesList as $ce2): $cc = (string)($ce2['code'] ?? ''); $inviteC = $mapUrl($p) . '?code=' . $cc; ?>
              <div class="card codecard">
                <div class="qr" data-url="<?= $esc($inviteC) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= $esc($cc) ?>"></div>
                <div class="codecard-info">
                  <div class="codecard-title">
                    <span class="mono code-main"><?= $esc($cc) ?></span>
                    <?php if (($ce2['label'] ?? '') !== ''): ?><span class="codecard-label"><?= $esc($ce2['label']) ?></span><?php endif; ?>
                    <button type="button" class="chipbtn" data-copy="<?= $esc($inviteC) ?>" title="<?= $t('copy_code_invite_link_title') ?>"><i class="fa-solid fa-link"></i></button>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcode"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="code_del" value="<?= $esc($cc) ?>"><button class="x" title="<?= $t('remove_code_title') ?>">×</button></form>
                  </div>
                  <div class="badge">
                    <?= !empty($ce2['expires_at']) ? $t('expires_at_label', ['date' => substr((string)$ce2['expires_at'], 0, 16)]) : $t('no_expiry_label') ?>
                    ・<?= isset($ce2['max_uses']) && $ce2['max_uses'] !== null ? $t('used_of_max_label', ['used' => (int)($ce2['used_count'] ?? 0), 'max' => (int)$ce2['max_uses']]) : $t('unlimited_used_label', ['used' => (int)($ce2['used_count'] ?? 0)]) ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="emptystate"><?= $t('no_codes_yet_msg') ?></div>
        <?php endif; ?>
        <details class="metaedit">
          <summary class="btn"><i class="fa-solid fa-plus"></i> <?= $t('add_code_btn') ?></summary>
          <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
            <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="code"><input type="hidden" name="project" value="<?= $esc($p) ?>">
            <label class="fieldlabel"><?= $t('contrib_code') ?><input name="pin_new" autocomplete="off" placeholder="<?= $t('code_field_placeholder') ?>" data-pin-toggle></label>
            <label class="fieldlabel"><?= $t('col_nickname') ?><input name="label" autocomplete="off" placeholder="<?= $t('optional_placeholder') ?>"></label>
            <label class="fieldlabel"><?= $t('expiry_time_label') ?>
              <div class="expirywidget">
                <div class="expirychips">
                  <button type="button" class="chip" data-preset="none"><?= $t('no_expiry_label') ?></button>
                  <button type="button" class="chip" data-preset="1h"><?= $t('preset_1h') ?></button>
                  <button type="button" class="chip" data-preset="1d"><?= $t('preset_1d') ?></button>
                  <button type="button" class="chip" data-preset="1w"><?= $t('preset_1w') ?></button>
                </div>
                <input name="expires_at" type="datetime-local" class="expirycustom" title="<?= $t('expiry_input_title') ?>">
              </div>
            </label>
            <label class="fieldlabel"><?= $t('max_uses_label') ?><input name="max_uses" type="number" min="1" placeholder="<?= $t('max_uses_placeholder') ?>"></label>
            <button class="btn solid"><i class="fa-solid fa-check"></i> <?= $t('confirm_add_btn') ?></button>
          </form>
        </details>
        <?php if ($justHere('code')) $shareNew($justCreatedShare, $t('contrib_code')); ?>

        <!-- 身分管理：投稿者（純自助）與管理 PIN 並列 -->
        <div class="sechead" style="margin-top:24px"><i class="fa-solid fa-users"></i> <?= $t('identity_management_heading') ?></div>
        <div class="idgrid">
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-id-badge"></i> <?= $t('contributors_heading') ?></div>
            <?php if ($cList || $ownerGroups): ?>
              <div class="pinlist">
                <?php foreach ($cList as $cid => $ce):
                  $cnt = $contribCounts[$cid] ?? 0;
                  $isBlocked = in_array($cid, $blocked['contribs'], true);
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div><?= $esc(($ce['label'] ?? '') !== '' ? $ce['label'] : $t('no_nickname_contributor_label')) ?> · <span class="mono"><?= $esc($cid) ?></span> · <?= $t('record_count_suffix', ['n' => $cnt]) ?><?php if ($isBlocked): ?> · <span class="tag"><?= $t('locked_tag') ?></span><?php endif; ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcontrib"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="contrib_id" value="<?= $esc($cid) ?>"><button class="x" title="<?= $t('revoke_contrib_title') ?>">×</button></form>
                    </div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="<?= $t('lock_after_title') ?>"><?= $isBlocked ? $t('locked_btn') : $t('lock_btn') ?></button></form>
                      <?php if ($cnt > 0 && $canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm(<?= $esc(json_encode(i18n_t($DICT, 'confirm_delete_all_contrib', ['project' => $p, 'n' => $cnt]), JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle" title="<?= $t('delete_all_title') ?>"><i class="fa-solid fa-trash"></i> <?= $t('delete_all_btn') ?></button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php foreach ($ownerGroups as $oh => $og):
                  $isBlocked = in_array($oh, $blocked['owners'], true);
                  $nameNote = ($og['last_name'] !== '' && $og['last_name'] !== '匿名') ? $t('used_name_note', ['name' => $og['last_name']]) : $t('anon_contributor_label');
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div><?= $nameNote ?> · <span class="mono"><?= $esc(substr($oh, 0, 8)) ?></span> · <?= $t('record_count_suffix', ['n' => $og['count']]) ?><?php if ($isBlocked): ?> · <span class="tag"><?= $t('locked_tag') ?></span><?php endif; ?></div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="<?= $t('lock_after_owner_title') ?>"><?= $isBlocked ? $t('locked_btn') : $t('lock_btn') ?></button></form>
                      <?php if ($canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm(<?= $esc(json_encode(i18n_t($DICT, 'confirm_delete_all_contrib', ['project' => $p, 'n' => $og['count']]), JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle" title="<?= $t('delete_all_title') ?>"><i class="fa-solid fa-trash"></i> <?= $t('delete_all_btn') ?></button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="emptystate"><?= $t('no_records_yet_msg') ?></div>
            <?php endif; ?>
          </div>

          <?php if ($master || $canDelegateAdmin): ?>
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-user-gear"></i> <?= $t('admin_pins_heading') ?></div>
            <?php if ($realPins): ?>
              <div class="pinlist">
                <?php if ($master):
                  $permLabels = ['delete_others' => $t('perm_delete_others'), 'edit_others' => $t('perm_edit_others'), 'edit_points' => $t('perm_edit_points'), 'delegate_admin' => $t('perm_delegate_admin')];
                  foreach ($realPins as $e): $pid = (string)($e['id'] ?? ''); $perms = $e['perms'] ?? pin_default_perms(); ?>
                  <div class="pinchip pinchip-block">
                    <div><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?> · <?= $secret($e['pin'] ?? '') ?>
                      <?php if (!empty($e['via_link'])): ?><span class="tag"><?= $t('invite_redeemed_tag') ?></span><?php endif; ?>
                      <?php if ($pid !== ''): ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="migrate_create"><input type="hidden" name="source" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="legacy_id" value="<?= $esc($pid) ?>"><input type="hidden" name="label" value="<?= $esc($e['label'] ?? '') ?>"><button type="submit" class="chipbtn" title="<?= $t('migrate_to_account_title') ?>"><i class="fa-solid fa-right-left"></i></button></form>
                      <?php endif; ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="<?= $t('remove_title') ?>">×</button></form>
                    </div>
                    <?php if ($pid !== ''): ?>
                    <div class="permrow">
                      <?php foreach ($permLabels as $pk => $ptext): $on = !empty($perms[$pk]); ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="setperm"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_id" value="<?= $esc($pid) ?>"><input type="hidden" name="perm" value="<?= $esc($pk) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                          <button class="permtoggle<?= $on ? ' on' : '' ?>" title="<?= $t('grant_perm_title') ?>"><?= $on ? '✓ ' : '' ?><?= $esc($ptext) ?></button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; else: ?>
                  <?php foreach ($realPins as $e): ?>
                    <span class="pinchip" title="<?= $t('master_only_pin_visible_title') ?>"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : $t('no_nickname_label')) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($master): ?>
              <form class="row" method="post" style="flex-wrap:wrap;margin-top:8px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="fieldlabel"><?= $t('add_pin_direct_label') ?><input name="pin_new" autocomplete="off" placeholder="<?= $t('add_pin_direct_placeholder') ?>"></label>
                <label class="fieldlabel"><?= $t('col_nickname') ?><input name="label" autocomplete="off" placeholder="<?= $t('optional_placeholder') ?>"></label>
                <button class="btn"><i class="fa-solid fa-plus"></i> <?= $t('add_pin_direct_btn') ?></button>
              </form>
            <?php endif; ?>
            <?php if ($master && $invites): ?>
              <div class="sechead" style="margin-top:14px;font-size:0.8125rem"><i class="fa-solid fa-envelope-open-text"></i> <?= $t('pending_invites_heading') ?></div>
              <div class="pinlist">
                <?php foreach ($invites as $e): $inviteId = (string)($e['id'] ?? ''); $inviteUrl = $mapUrl($p) . '#redeem=' . rawurlencode((string)($e['token'] ?? '')) . '&rmode=admin'; ?>
                  <div class="pinchip pinchip-block">
                    <div><?= $t('pending_invite_label') ?>
                      <button type="button" class="chipbtn qr-trigger" data-url="<?= $esc($inviteUrl) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" title="<?= $t('show_qr_title') ?>"><i class="fa-solid fa-qrcode"></i></button>
                      <button type="button" class="chipbtn" data-copy="<?= $esc($inviteUrl) ?>" title="<?= $t('copy_invite_link_title') ?>"><i class="fa-solid fa-link"></i></button>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delinvite"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="invite_id" value="<?= $esc($inviteId) ?>"><button class="x" title="<?= $t('revoke_invite_title') ?>">×</button></form>
                    </div>
                    <div class="badge">
                      <?= !empty($e['expires_at']) ? $t('expires_at_label', ['date' => substr((string)$e['expires_at'], 0, 16)]) : $t('no_expiry_label') ?>
                      ・<?= isset($e['max_uses']) && $e['max_uses'] !== null ? $t('redeemed_of_max_label', ['used' => (int)($e['used_count'] ?? 0), 'max' => (int)$e['max_uses']]) : $t('redeemed_unlimited_label', ['used' => (int)($e['used_count'] ?? 0)]) ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($canDelegateAdmin): ?>
              <details class="metaedit">
                <summary class="btn"><i class="fa-solid fa-share-nodes"></i> <?= $t('create_invite_link_btn') ?></summary>
                <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
                  <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="admin"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                  <label class="fieldlabel"><?= $t('expiry_time_label') ?>
                    <div class="expirywidget">
                      <div class="expirychips">
                        <button type="button" class="chip" data-preset="none"><?= $t('no_expiry_label') ?></button>
                        <button type="button" class="chip" data-preset="1h"><?= $t('preset_1h') ?></button>
                        <button type="button" class="chip" data-preset="1d"><?= $t('preset_1d') ?></button>
                        <button type="button" class="chip" data-preset="1w"><?= $t('preset_1w') ?></button>
                      </div>
                      <input name="expires_at" type="datetime-local" class="expirycustom" title="<?= $t('expiry_input_title') ?>">
                    </div>
                  </label>
                  <label class="fieldlabel"><?= $t('max_redeem_label') ?><input name="max_uses" type="number" min="1" placeholder="<?= $t('max_uses_placeholder') ?>"></label>
                  <button class="btn solid"><i class="fa-solid fa-check"></i> <?= $t('confirm_create_btn') ?></button>
                </form>
              </details>
            <?php endif; ?>
            <?php if ($justHere('admin')) $shareNew($justCreatedShare, $t('admin_pin_invite_share_label')); ?>
            <?php if ($justCreatedMigrate && $justCreatedMigrate['project'] === $p) $shareNew($justCreatedMigrate, $t('migrate_to_account_title')); ?>
          </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    <?php endforeach; ?>
    </div><!-- /pane-access -->

    <div class="pane on" id="pane-overview">
    <h2><?= $t('stats_summary_heading') ?></h2>
    <?php foreach ($viewProjects as $p): $s = stats_read($cfg, $p);
      if (!$s) continue;
      $s['points'] = $s['points'] ?? [];
      $s['cameras'] = $s['cameras'] ?? [];
      arsort($s['points']);
      arsort($s['cameras']);
      $top = function ($arr, $n = 5) {
        $o = [];
        foreach (array_slice($arr, 0, $n, true) as $k => $v) $o[] = $k . '·' . $v;
        return $o ? implode('、', $o) : '—';
      };
      $featLabels = souliong_features();
      $feats = $s['features'] ?? [];
      arsort($feats);
      $byHour = $s['by_hour'] ?? [];
      arsort($byHour);
      $byDow = $s['by_dow'] ?? [];
      arsort($byDow);
      $dowNames = explode(',', i18n_t($DICT, 'weekday_short_csv'));
      $browsers = $s['browser'] ?? [];
      arsort($browsers);
      $oses = $s['os'] ?? [];
      arsort($oses);
      $bLabels = ['chrome' => 'Chrome', 'safari' => 'Safari', 'edge' => 'Edge', 'firefox' => 'Firefox', 'samsung' => 'Samsung Internet', 'opera' => 'Opera', 'line' => $t('browser_line'), 'facebook' => $t('browser_fb'), 'instagram' => $t('browser_ig'), 'wechat' => $t('browser_wechat'), 'duckduckgo' => 'DuckDuckGo', 'other' => $t('other_label')];
      $oLabels = ['ios' => 'iOS', 'android' => 'Android', 'windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'other' => $t('other_label')];
    ?>
      <details class="stat-card">
        <summary>
          <div class="stat-card-head"><b><?= $esc($p) ?></b><i class="fa-solid fa-chevron-down chev" aria-hidden="true"></i></div>
          <div class="stats-grid">
            <div class="tile">
              <div class="n"><?= (int)($s['views'] ?? 0) ?></div>
              <div class="l"><?= $t('stat_views_label') ?></div>
            </div>
            <div class="tile">
              <div class="n"><?= (int)($s['sessions'] ?? 0) ?></div>
              <div class="l"><?= $t('stat_sessions_label') ?></div>
            </div>
            <div class="tile">
              <div class="n"><?= (int)($s['uploads'] ?? 0) ?></div>
              <div class="l"><?= $t('stat_uploads_label') ?></div>
            </div>
            <div class="tile">
              <div class="n"><?= (int)(($s['device']['mobile'] ?? 0)) ?>/<?= (int)(($s['device']['desktop'] ?? 0)) ?></div>
              <div class="l"><?= $t('stat_mobile_desktop_label') ?></div>
            </div>
          </div>
          <div class="break"><?= $t('top_points_label') ?><b><?= $esc($top($s['points'])) ?></b><br><?= $t('top_cameras_label') ?><b><?= $esc($top($s['cameras'])) ?></b></div>
        </summary>
        <div class="cols">
          <div class="col">
            <h4><?= $t('stats_explain_heading') ?></h4>
            <p><?= $tr('stats_explain_body') ?></p>
          </div>
          <div class="col">
            <h4><?= $t('points_rank_heading') ?></h4>
            <?php if ($s['points']): ?><ol><?php foreach (array_slice($s['points'], 0, 20, true) as $k => $v): ?><li><?= $t('point_rank_item', ['k' => $k, 'n' => (int)$v]) ?></li><?php endforeach; ?></ol><?php else: ?><p><?= $t('no_data_msg') ?></p><?php endif; ?>
          </div>
          <div class="col">
            <h4><?= $t('cameras_rank_heading') ?></h4>
            <?php if ($s['cameras']): ?><ol><?php foreach (array_slice($s['cameras'], 0, 20, true) as $k => $v): ?><li><?= $t('rank_item_generic', ['k' => $k, 'n' => (int)$v]) ?></li><?php endforeach; ?></ol><?php else: ?><p><?= $t('no_data_msg') ?></p><?php endif; ?>
          </div>
          <div class="col">
            <h4><?= $t('feature_usage_heading') ?></h4>
            <?php if ($feats): ?><ol><?php foreach ($feats as $k => $v): ?><li><?= $t('rank_item_generic', ['k' => $featLabels[$k] ?? $k, 'n' => (int)$v]) ?></li><?php endforeach; ?></ol><?php else: ?><p><?= $t('no_data_msg') ?></p><?php endif; ?>
          </div>
          <div class="col">
            <h4><?= $t('browser_os_heading') ?></h4>
            <?php if ($browsers || $oses): ?>
              <p><?= $t('browser_label') ?><?php $bb = []; foreach ($browsers as $k => $v) $bb[] = ($bLabels[$k] ?? $k) . '（' . (int)$v . '）'; echo $esc(implode('、', $bb)); ?><br>
                <?= $t('os_label') ?><?php $oo = []; foreach ($oses as $k => $v) $oo[] = ($oLabels[$k] ?? $k) . '（' . (int)$v . '）'; echo $esc(implode('、', $oo)); ?></p>
            <?php else: ?><p><?= $t('no_data_since_stat_msg') ?></p><?php endif; ?>
          </div>
          <div class="col">
            <h4><?= $t('visit_time_heading') ?></h4>
            <p><?= $t('top_hours_label') ?><?php $hh = []; foreach (array_slice($byHour, 0, 3, true) as $k => $v) $hh[] = $t('hour_item', ['h' => (int)$k, 'n' => (int)$v]); echo $hh ? implode('、', $hh) : $t('no_data_msg'); ?><br>
              <?= $t('top_weekdays_label') ?><?php $dd = []; foreach (array_slice($byDow, 0, 3, true) as $k => $v) $dd[] = $t('weekday_item', ['d' => $dowNames[(int)$k] ?? $k, 'n' => (int)$v]); echo $dd ? implode('、', $dd) : $t('no_data_msg'); ?></p>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
    </div><!-- /pane-overview -->

    <div class="pane" id="pane-records">
    <h2><?= $t('records_heading') ?></h2>
    <div class="searchbar"><i class="fa-solid fa-magnifying-glass"></i><input id="recsearch" type="search" placeholder="<?= $t('records_search_placeholder') ?>" autocomplete="off"></div>
    <div class="tablewrap">
      <table id="rectable">
        <tr>
          <th><?= $t('col_num') ?></th>
          <th><?= $t('col_project') ?></th>
          <th><?= $t('col_point') ?></th>
          <th><?= $t('col_type') ?></th>
          <th><?= $t('col_photo') ?></th>
          <th><?= $t('col_nickname') ?></th>
          <th><?= $t('col_content') ?></th>
          <th><?= $t('col_camera') ?></th>
          <th><?= $t('col_time') ?></th>
          <th><?= $t('col_coords') ?></th>
          <th>o/s</th>
          <th></th>
        </tr>
        <?php $idx = count($rows);
        foreach ($rows as $r):
          $refOrig = !empty($r['edit_of']) ? ($byId[$r['project'] . '/' . $r['edit_of']] ?? null) : null;
          $dispPhoto = !empty($r['photo']) ? $r['photo'] : ($refOrig['photo'] ?? null);
          $dispThumb = !empty($r['thumb']) ? $r['thumb'] : ($refOrig['thumb'] ?? null);
          $dispExif  = is_array($r['exif'] ?? null) ? $r['exif'] : (is_array($refOrig['exif'] ?? null) ? $refOrig['exif'] : null);
          $photoUrl  = $dispPhoto ? $basePath . '?api=photo&f=' . rawurlencode($dispPhoto) : null;
          $thumbUrl  = $dispThumb ? $basePath . '?api=photo&f=' . rawurlencode($dispThumb) : ($photoUrl !== null ? $photoUrl . '&th=1' : null);   // 縮圖預覽，點開連到原圖；舊投稿沒 thumb 欄位就請 photo.php 自動產（&th=1）
        ?>
          <tr data-row>
            <td class="mono"><?= $idx-- ?></td>
            <td><?= $esc($r['project']) ?></td>
            <td><?= $esc($r['item_num'] ?? '') ?></td>
            <td><span class="tag"><?= $esc(souliong_kind_label($r['kind'] ?? 'photo')) ?></span><?= !empty($r['edit_of']) ? '<br><span class="tag">' . $t('edited_record_tag') . '</span>' : '' ?></td>
            <td><?= $photoUrl ? '<a href="' . $esc($photoUrl) . '" target="_blank"><img loading="lazy" src="' . $esc($thumbUrl) . '" alt=""></a>' : '' ?></td>
            <td><?= $esc($r['name'] ?? '') ?></td>
            <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
            <td class="mono"><?= $esc($dispExif ? implode(' ', $dispExif) : '') ?></td>
            <td class="mono"><?= $esc(substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16)) ?></td>
            <td class="mono"><?= $esc(round((float)($r['lat'] ?? 0), 4)) ?>,<?= $esc(round((float)($r['lon'] ?? 0), 4)) ?><br><?= $esc($r['loc_source'] ?? '') ?></td>
            <td class="mono"><?= $esc($short($r['owner_hash'] ?? '')) ?><br><?= $esc($short($r['src_hash'] ?? '')) ?></td>
            <?php
              // 「全部」檢視時各專案投稿混排在同一張表，只寫「確定刪除？」容易點錯專案，訊息裡把專案名複述一次
              $delConfirm = i18n_t($DICT, 'confirm_delete_record', ['project' => $r['project'], 'name' => (($r['name'] ?? '') !== '' ? '（' . $r['name'] . '）' : '')]);
            ?>
            <td>
              <form method="post" onsubmit="return confirm(<?= $esc(json_encode($delConfirm, JSON_UNESCAPED_UNICODE)) ?>)"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delete">
                <input type="hidden" name="project" value="<?= $esc($r['project']) ?>"><input type="hidden" name="id" value="<?= $esc($r['id'] ?? '') ?>">
                <button class="iconbtn" title="<?= $t('delete_record_title') ?>"><i class="fa-solid fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="hint"><?= $t('record_hash_legend') ?><?= $master ? $t('master_scope_note') : '' ?></div>
    </div><!-- /pane-records -->

    <?php if ($master): ?>
    <div class="pane" id="pane-tools">
      <h2><?= $t('tools_heading') ?></h2>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-toggle-on"></i> <?= $t('global_features_badge') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('global_features_hint') ?></div>
        <form method="post" style="margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="settings">
          <label class="modrow" style="display:flex;gap:8px;align-items:flex-start">
            <input type="checkbox" name="random_explore" style="width:auto;margin-top:3px" <?= souliong_random_explore_on($cfg) ? 'checked' : '' ?>>
            <span><b><?= $t('random_explore_toggle_label') ?></b><br><span class="hint"><?= $t('random_explore_toggle_hint') ?></span></span>
          </label>
          <label class="modrow" style="display:flex;gap:8px;align-items:flex-start;margin-top:10px">
            <input type="checkbox" name="registration_open" style="width:auto;margin-top:3px" <?= souliong_registration_open($cfg) ? 'checked' : '' ?>>
            <span><b><?= $t('registration_open_toggle_label') ?></b><br><span class="hint"><?= $t('registration_open_toggle_hint') ?></span></span>
          </label>
          <div class="dlgactions" style="justify-content:flex-start;margin-top:8px"><button class="btn solid"><i class="fa-solid fa-floppy-disk"></i> <?= $t('save_settings_btn') ?></button></div>
        </form>
      </div>
      <form class="card section-card danger-zone" method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="import">
        <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('import_backup_badge') ?></span>
        <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
          <input type="file" name="backup" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
        <select name="mode" style="border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--fg);padding:7px 10px;font-size:0.8125rem">
          <option value="merge"><?= $t('import_mode_merge') ?></option>
          <option value="replace"><?= $t('import_mode_replace') ?></option>
        </select>
        <button class="btn"><?= $t('restore_btn') ?></button>
      </form>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-kit-medical"></i> <?= $t('data_repair_badge') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('data_repair_hint') ?></div>
        <?php $toolQs = $scopeProject !== '' ? '?project=' . urlencode($scopeProject) : ''; // 目前在看哪個專案就帶著走；「全部」時不硬塞一個專案給工具頁 ?>
        <div class="row" style="margin-top:8px"><a class="btn" href="<?= $esc($origin . $basePath . 'exiffix' . $toolQs) ?>"><i class="fa-solid fa-kit-medical"></i> <?= $t('open_exiffix_btn') ?></a>
          <a class="btn" href="<?= $esc($origin . $basePath . 'thumbfix' . $toolQs) ?>"><i class="fa-solid fa-images"></i> <?= $t('open_thumbfix_btn') ?></a>
          <a class="btn" href="?api=admin&backup=all"><i class="fa-solid fa-download"></i> <?= $t('backup_all_btn') ?></a></div>
      </div>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-swatchbook"></i> <?= $t('packs_heading') ?></div>
        <div class="hint" style="margin-top:6px"><?= $t('packs_hint') ?></div>
        <?php $installedPacks = souliong_pack_list($cfg); ?>
        <?php if ($installedPacks): ?>
        <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px">
          <?php foreach ($installedPacks as $pid => $pinfo): ?>
          <div style="display:flex;align-items:center;gap:8px;justify-content:space-between">
            <span><b><?= $esc($pinfo['label'] ?? $pid) ?></b> <span class="hint mono"><?= $esc($pid) ?></span></span>
            <a class="btn" href="?api=admin&backup=pack&pack=<?= $esc($pid) ?>"><i class="fa-solid fa-download"></i> <?= $t('pack_export_btn') ?></a>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="hint" style="margin-top:8px"><?= $t('no_packs_msg') ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="packimport">
          <span class="badge"><i class="fa-solid fa-upload"></i> <?= $t('pack_import_badge') ?></span>
          <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file><?= $t('choose_zip_btn') ?></span>
            <input type="file" name="pack" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:<?= json_encode(i18n_t($DICT, 'choose_zip_btn'), JSON_UNESCAPED_UNICODE) ?>"></label>
          <button class="btn"><i class="fa-solid fa-upload"></i> <?= $t('restore_btn') ?></button>
        </form>
      </div>
    </div><!-- /pane-tools -->
    <?php endif; ?>
  </div>
  <script>window.I18N = <?= json_encode($DICT, JSON_UNESCAPED_UNICODE) ?>; window.LANG = <?= json_encode($LANG) ?>;</script>
  <script>
    <?php readfile(__DIR__ . '/../assets/js/vendor/qrcode-generator.js'); ?>
  </script>
  <script>
    <?php readfile(__DIR__ . '/../assets/js/pin-input.js'); ?>
  </script>
  <script>
    document.querySelectorAll('.qr').forEach(function(el) {
      try {
        var qr = qrcode(0, 'M');
        qr.addData(el.dataset.url);
        qr.make();
        el.innerHTML = qr.createSvgTag({
          cellSize: 4,
          margin: 2,
          scalable: true
        });
      } catch (e) {
        el.textContent = <?= json_encode(i18n_t($DICT, 'qr_generate_failed'), JSON_UNESCAPED_UNICODE) ?>;
        return;
      }
      el.title = <?= json_encode(i18n_t($DICT, 'qr_click_hint'), JSON_UNESCAPED_UNICODE) ?>;
      el.addEventListener('click', function() { openQrModal(el.dataset.url, el.dataset.title, el.dataset.code); });
    });
    document.querySelectorAll('.qr-trigger').forEach(function(btn) {
      btn.addEventListener('click', function() { openQrModal(btn.dataset.url, btn.dataset.title, btn.dataset.code); });
    });
    function openQrModal(url, title, codeText) {
      var wrap = document.createElement('div');
      wrap.className = 'qr-modal';
      wrap.innerHTML = '<div class="qr-modal-card">'
        + (title ? '<div class="qr-modal-title"></div>' : '')
        + '<div class="qr-modal-guide">' + <?= json_encode(i18n_t($DICT, 'qr_scan_guide'), JSON_UNESCAPED_UNICODE) ?> + '</div>'
        + '<div class="qr-modal-box"></div>'
        + '<div class="qr-modal-url"></div>'
        + (codeText ? '<div class="qr-modal-code"></div>' : '')
        + '<div class="qr-modal-hint">' + <?= json_encode(i18n_t($DICT, 'qr_click_anywhere_close'), JSON_UNESCAPED_UNICODE) ?> + '</div></div>';
      if (title) wrap.querySelector('.qr-modal-title').textContent = title;
      wrap.querySelector('.qr-modal-url').textContent = url;
      if (codeText) wrap.querySelector('.qr-modal-code').textContent = codeText;
      try {
        var qr = qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        wrap.querySelector('.qr-modal-box').innerHTML = qr.createSvgTag({ cellSize: 8, margin: 2, scalable: true });
      } catch (e) {}
      function close() { wrap.remove(); document.removeEventListener('keydown', onKey); }
      function onKey(e) { if (e.key === 'Escape') close(); }
      wrap.addEventListener('click', close);
      document.addEventListener('keydown', onKey);
      document.body.appendChild(wrap);
    }
    document.querySelectorAll('.expirywidget').forEach(function(widget) {
      var input = widget.querySelector('.expirycustom');
      var chips = widget.querySelectorAll('.chip');
      function pad(n) { return String(n).padStart(2, '0'); }
      function toLocalValue(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
      function markOn(chip) { chips.forEach(function(c) { c.classList.toggle('on', c === chip); }); }
      chips.forEach(function(chip) {
        chip.addEventListener('click', function() {
          var preset = chip.dataset.preset;
          if (preset === 'none') { input.value = ''; }
          else {
            var ms = { '1h': 3600e3, '1d': 86400e3, '1w': 604800e3 }[preset] || 0;
            input.value = toLocalValue(new Date(Date.now() + ms));
          }
          markOn(chip);
        });
      });
      input.addEventListener('input', function() {
        var noneChip = widget.querySelector('.chip[data-preset="none"]');
        markOn(input.value === '' ? noneChip : null);
      });
      if (input.value === '') markOn(widget.querySelector('.chip[data-preset="none"]'));
    });
    document.querySelectorAll('[data-copy]').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var v = btn.getAttribute('data-copy');
        (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function() {
          var t = btn.innerHTML;
          btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + <?= json_encode(i18n_t($DICT, 'copied'), JSON_UNESCAPED_UNICODE) ?>;
          setTimeout(function() { btn.innerHTML = t; }, 1500);
        }).catch(function() { alert(v); });
      });
    });

    // ── 遮罩的 PIN／碼：按眼睛切換顯示（預設隱藏，正在分享的區塊除外） ──
    document.querySelectorAll('.eyebtn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var val = btn.parentElement.querySelector('.secretval');
        if (!val) return;
        var show = btn.classList.toggle('on');
        val.textContent = show ? val.dataset.val : '••••••';
        btn.innerHTML = show ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
      });
    });

    // ── 分頁切換：hash > 表單送出後指定 > 上次停留，重新整理不迷路 ──
    function showPane(name) {
      if (!document.getElementById('pane-' + name)) return;
      document.querySelectorAll('.subtab').forEach(function(b) { b.classList.toggle('on', b.dataset.pane === name); });
      document.querySelectorAll('.pane').forEach(function(p) { p.classList.toggle('on', p.id === 'pane-' + name); });
      try { sessionStorage.setItem('adminTab', name); } catch (e) {}
      try { history.replaceState(null, '', '#' + name); } catch (e) {}
    }
    document.querySelectorAll('.subtab').forEach(function(b) {
      b.addEventListener('click', function() { showPane(b.dataset.pane); });
    });
    var forcePane = <?= json_encode($forcePane) ?>;
    var initPane = forcePane || (location.hash || '').replace('#', '');
    if (!document.getElementById('pane-' + initPane)) {
      try { initPane = sessionStorage.getItem('adminTab') || ''; } catch (e) { initPane = ''; }
    }
    if (initPane) showPane(initPane);

    // ── 投稿紀錄即時搜尋 ──
    var recSearch = document.getElementById('recsearch');
    if (recSearch) recSearch.addEventListener('input', function() {
      var v = recSearch.value.trim().toLowerCase();
      document.querySelectorAll('#rectable tr[data-row]').forEach(function(tr) {
        tr.style.display = !v || tr.textContent.toLowerCase().indexOf(v) !== -1 ? '' : 'none';
      });
    });
  </script>
</body>

</html>