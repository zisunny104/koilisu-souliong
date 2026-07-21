<?php
// 管理頁：?api=admin （httpOnly cookie 認證；PIN 走 POST。主 PIN 全域、各專案 PIN 僅該專案）
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/features.php';
require __DIR__ . '/../pages/error.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
// PIN／碼是唯一輸入憑證，後台一律預設遮罩、按眼睛才顯示（固定六點，不洩漏長度）；
// 例外：常駐投稿碼與「剛建立」區塊屬於正在分享的內容，維持明碼。
$secret = fn($v) => '<span class="secretwrap"><span class="mono secretval" data-val="' . $esc($v) . '">••••••</span><button type="button" class="eyebtn" title="顯示／隱藏"><i class="fa-solid fa-eye"></i></button></span>';

// ── 登入（POST pin[, project]）→ 種 cookie ──
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
  $pin = (string)($_POST['pin'] ?? '');
  $proj = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
  $ok = false;
  $go = '?api=admin';
  $label = '';
  if (check_master_pin($cfg, $pin)) {
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
      $loginErrMsg = '此分享連結已到期或已達使用次數上限';
    }
  }
  if (isset($_POST['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) echo json_encode(['ok' => true, 'label' => $label]);
    else {
      http_response_code(403);
      echo json_encode(['error' => $loginErrMsg ?? 'PIN 不正確']);
    }
    exit;
  }
  if ($ok) {
    header('Location: ' . $go);
    exit;
  }
  $loginErr = $loginErrMsg ?? 'PIN 不正確';
}
if (isset($_GET['logout'])) {
  admin_clear_cookie();
  header('Location: ?api=admin');
  exit;
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
$authed = $master || ($reqProject !== '' && admin_can($cfg, $reqProject));
if (!$authed) {
  http_response_code(401);
  header('Content-Type: text/html; charset=utf-8');
?>
  <!DOCTYPE html>
  <html lang="zh-Hant">

  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理登入</title>
    <style>
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
    </style>
  </head>

  <body>
    <form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="project" value="<?= $esc($reqProject) ?>">
      <h1>Souliong 循跡</h1>
      <div class="s"><?= $reqProject !== '' ? $esc($reqProject) . ' 專案管理 · ' : '主要管理 · ' ?>輸入管理 PIN</div>
      <div class="err"><?= $esc($loginErr) ?></div>
      <input name="pin" type="password" inputmode="numeric" autocomplete="off" autofocus placeholder="PIN">
      <button>登入</button>
    </form>
  </body>

  </html><?php
          exit;
        }

        // 範圍：主 PIN → 可全部（?project= 選填）；專案 PIN → 鎖定該專案
        $scopeProject = $master ? $reqProject : $reqProject;   // 專案管理者恆為 $reqProject
        $csrf = $master ? admin_derived($cfg) : padm_derived($cfg, $reqProject, (string)padm_pin_id($cfg, $reqProject));
        $esc_csrf = $esc($csrf);
        function need_csrf(string $csrf): void
        {
          if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            error_page(403, '憑證失效', '請重新整理頁面後再操作一次。', '?api=admin', '返回後台');
          }
        }

        // 允許操作某專案？（主全通；專案管理者只能動自己的）
        $canProject = fn($p) => $master || ($p === $reqProject && admin_can($cfg, $p));

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
          if ($p !== '' && $id !== '' && ($master || ($p === $reqProject && admin_perm($cfg, $p, 'delete_others')))) {
            $removed = store_delete($cfg, $p, $id);
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
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
          exit;
        }
        // 編輯專案描述（只改標題/副標/說明/資料來源，其餘欄位保留；免手改 meta.json）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'meta') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          if ($p === '' || !$canProject($p)) {
            error_page(403, '沒有權限', '您沒有這個專案的管理權限。', '?api=admin', '返回後台');
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
          $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          if ($json !== false && is_dir(dirname($mf))) {
            @file_put_contents($mf, $json, LOCK_EX);
          }   // 編碼失敗絕不覆寫，避免清空 meta
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($p) : ''));
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['addpin', 'delpin', 'setperm'], true)) {
          need_csrf($csrf);
          if (!$master) {
            error_page(403, '沒有權限', 'PIN 權限管理僅限主要管理者。', '?api=admin', '返回後台');
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
          header('Location: ?api=admin' . ($scope === 'project' && $tp !== '' ? '&project=' . urlencode($tp) : ''));
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
            error_page(403, '沒有權限', '您沒有這個專案的管理權限。', '?api=admin', '返回後台');
          }
          $kind = in_array(($_POST['kind'] ?? ''), ['code', 'admin'], true) ? $_POST['kind'] : 'code';
          if ($kind === 'admin' && !($master || admin_perm($cfg, $p, 'delegate_admin'))) {
            error_page(403, '沒有權限', '只有主要管理者，或已被授權「可建管理PIN連結」的專案管理者，才能建立管理PIN分享連結。', '?api=admin&project=' . urlencode($p), '返回後台');
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
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
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
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
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
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
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
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
          exit;
        }
        // 刪除某身分的全部投稿：跟單筆刪除同一權限規則（動別人的東西預設限主 PIN，或已授權 delete_others 的專案 PIN）
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delbyid') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $field = ($_POST['kind'] ?? '') === 'owner' ? 'owner_hash' : 'contrib_id';
          $key = (string)($_POST['key'] ?? '');
          if ($p !== '' && $key !== '' && ($master || ($p === $reqProject && admin_perm($cfg, $p, 'delete_others')))) {
            foreach (store_delete_by($cfg, $p, $field, $key) as $removed) {
              if (!empty($removed['photo'])) {
                $pp = photo_abs_path($cfg, $removed['photo']);
                if ($pp) {
                  @unlink($pp);
                  $ppBase = preg_replace('/\.[A-Za-z0-9]+$/', '', $pp);
                  foreach (['webp', 'jpg', 'png'] as $te) @unlink($ppBase . '_t.' . $te);
                }
              }
            }
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
          exit;
        }
        // ── 備份（ZIP，含照片；純 PHP zip，零擴充依賴） ──
        if (isset($_GET['backup'])) {
          require_once __DIR__ . '/zip.php';
          $bp = $_GET['backup'] === 'project' ? preg_replace('/[^a-z0-9_-]/', '', $_GET['project'] ?? '') : null;
          if ($bp === null && !$master) {
            error_page(403, '沒有權限', '只有主要管理者可以備份全部專案。', '?api=admin', '返回後台');
          }
          if ($bp !== null && !$canProject($bp)) {
            error_page(403, '沒有權限', '您沒有這個專案的管理權限。', '?api=admin', '返回後台');
          }
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
            exit('備份失敗（暫存無法寫入）');
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
            error_page(403, '沒有權限', '只有主要管理者可以匯入還原。', '?api=admin', '返回後台');
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
          }
          header('Location: ?api=admin');
          exit;
        }

        // ── 資料 ──
        $allProjects = store_projects($cfg);
        $viewProjects = $master ? ($scopeProject !== '' ? [$scopeProject] : $allProjects) : [$reqProject];

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
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Souliong 循跡 · 管理</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
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
      gap: 8px;
      flex-basis: 100%;
      margin-top: 10px;
      border-top: 1px solid var(--line);
      padding-top: 10px
    }

    .metaform label {
      display: flex;
      flex-direction: column;
      gap: 4px;
      font-size: 0.6875rem;
      color: var(--muted)
    }

    .metaform input,
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
      width: 100%
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

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 12px
    }

    .tile {
      background: var(--card);
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

    .statdetail {
      margin-top: 8px
    }

    .statdetail>summary {
      cursor: pointer;
      font-size: 0.7813rem;
      font-weight: 600;
      color: var(--muted);
      list-style: none
    }

    .statdetail>summary::-webkit-details-marker {
      display: none
    }

    .statdetail>summary:hover {
      color: var(--fg)
    }

    .statdetail .cols {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 12px;
      margin-top: 10px
    }

    .statdetail .col {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--r-md);
      padding: 12px 14px;
      font-size: 0.75rem
    }

    .statdetail .col h4 {
      margin: 0 0 6px;
      font-size: 0.75rem
    }

    .statdetail .col ol {
      margin: 0;
      padding-left: 18px;
      line-height: 1.8
    }

    .statdetail .col p {
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
      color: var(--muted)
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

    .pinchip .x {
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
    <div class="top">
      <h1><i class="fa-solid fa-gauge-high"></i> Souliong 循跡 <span class="sub"><?= $master ? '主要管理' : $esc($reqProject) . ' 專案管理' ?> · <?= count($rows) ?> 筆</span></h1>
      <a class="btn" href="?api=admin&logout=1"><i class="fa-solid fa-right-from-bracket"></i> 登出</a>
    </div>

    <?php if (!is_writable($cfg['projects_dir'])): ?>
      <div class="card" style="border-color:var(--danger);color:var(--danger);padding:14px 18px;margin-top:12px;font-size:.85rem;line-height:1.7">
        <b><i class="fa-solid fa-triangle-exclamation"></i> 資料夾無法寫入：<?= $esc($cfg['projects_dir']) ?></b><br>
        投稿碼／統計無法儲存，會導致「投稿碼永遠驗證失敗」。請讓 PHP 對 <code>projects/</code> 與 <code>state/</code> 有寫入權限（例如 <code>chown -R www-data projects state</code> 或 <code>chmod</code>）。
      </div>
    <?php endif; ?>

    <?php if ($master): ?>
      <div class="tabs">
        <a class="tab<?= $scopeProject === '' ? ' on' : '' ?>" href="?api=admin">全部（<?= count($allProjects) ?>）</a>
        <?php foreach ($allProjects as $tp): ?><a class="tab<?= $scopeProject === $tp ? ' on' : '' ?>" href="?api=admin&project=<?= $esc($tp) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
      // 表單送出後回到對應分頁，避免每次都跳回總覽
      $paneOfAction = ['sharelink' => 'access', 'addpin' => 'access', 'delpin' => 'access', 'setperm' => 'access', 'delinvite' => 'access', 'delcontrib' => 'access', 'delcode' => 'access', 'meta' => 'access', 'rotate' => 'access', 'delete' => 'records', 'import' => 'tools'];
      $forcePane = $paneOfAction[$_POST['action'] ?? ''] ?? '';
    ?>
    <div class="subnav">
      <button type="button" class="subtab on" data-pane="overview"><i class="fa-solid fa-chart-simple"></i> 總覽</button>
      <button type="button" class="subtab" data-pane="records"><i class="fa-solid fa-table-list"></i> 投稿紀錄（<?= count($rows) ?>）</button>
      <button type="button" class="subtab" data-pane="access"><i class="fa-solid fa-key"></i> 專案與權限</button>
      <?php if ($master): ?><button type="button" class="subtab" data-pane="tools"><i class="fa-solid fa-screwdriver-wrench"></i> 工具</button><?php endif; ?>
    </div>

    <div class="pane" id="pane-access">
    <?php if ($master && $scopeProject === ''): $mpins = pins_load($cfg)['master']; ?>
      <h2>主要管理 PIN（可進入所有專案）</h2>
      <div class="hint" style="margin:-6px 0 12px">主要管理層級：僅在此「全部」頁管理；進入單一專案後不會顯示。</div>
      <div class="card" style="padding:16px 18px">
        <div class="pinlist">
          <span class="pinchip">主設定 · <span class="mono">config</span></span>
          <?php foreach ($mpins as $e): ?><span class="pinchip"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : '（無暱稱）') ?> · <?= $secret($e['pin'] ?? '') ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="master"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="移除">×</button></form>
            </span>
          <?php endforeach; ?>
        </div>
        <form class="row" method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="master">
          <input name="pin_new" placeholder="新增主要管理 PIN" autocomplete="off"><input name="label" placeholder="暱稱（可選）" autocomplete="off"><button class="btn">新增</button>
        </form>
      </div>
    <?php endif; ?>

    <h2>專案存取與權限</h2>
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
          <a class="btn" href="?api=admin&backup=project&project=<?= $esc($p) ?>"><i class="fa-solid fa-download"></i> 備份此專案</a>
          <?php if ($canProject($p)): ?>
          <details class="metaedit">
            <summary class="btn"><i class="fa-solid fa-pen-to-square"></i> 編輯專案描述</summary>
            <form method="post" class="metaform">
              <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="meta"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <label>標題<input name="title" maxlength="300" value="<?= $esc($meta['title'] ?? '') ?>" placeholder="地圖標題"></label>
              <label>副標<input name="subtitle" maxlength="300" value="<?= $esc($meta['subtitle'] ?? '') ?>" placeholder="副標題（可選）"></label>
              <label>說明<textarea name="desc" rows="2" maxlength="300" placeholder="首頁卡片的簡短說明（可選）"><?= $esc($meta['desc'] ?? '') ?></textarea></label>
              <label>資料來源<input name="source" maxlength="300" value="<?= $esc($meta['source'] ?? '') ?>" placeholder="例：ArcGIS StoryMaps「…」（可選）"></label>
              <label>原始單位署名<input name="credit" maxlength="300" value="<?= $esc($meta['credit'] ?? '') ?>" placeholder="頁尾顯示的原始單位／團隊（可選）"></label>
              <div class="badge">來源連結（可展開的多個 StoryMaps）為 meta.json 的 <code>sources</code> 陣列，格式見 EXTENDING.md。</div>
              <button class="btn primary"><i class="fa-solid fa-floppy-disk"></i> 儲存描述</button>
            </form>
          </details>
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
        $shareNew = function (array $s, string $kindLabel) use ($esc) { ?>
          <div class="card sharenew">
            <div class="badge"><i class="fa-solid fa-circle-check"></i> 已建立：<?= $esc($kindLabel) ?>——把連結或 QR 給對方<?= $s['kind'] === 'code' ? '' : '（只顯示這一次，離開頁面就不再出現）' ?></div>
            <div class="sharenew-body">
              <div class="qr" data-url="<?= $esc($s['url']) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= isset($s['code']) ? $esc($s['code']) : '' ?>"></div>
              <div class="sharenew-info">
                <div class="invite"><?= $esc($s['url']) ?></div>
                <div class="row"><button type="button" class="btn" data-copy="<?= $esc($s['url']) ?>"><i class="fa-solid fa-copy"></i> 複製連結</button></div>
                <?php if (isset($s['code'])): ?><div class="hint" style="margin-top:6px">碼：<span class="mono"><?= $esc($s['code']) ?></span>（也可口頭告知，對方在地圖的解鎖視窗輸入）</div><?php endif; ?>
                <?php if (isset($s['pin'])): ?><div class="hint" style="margin-top:6px">PIN：<span class="mono"><?= $esc($s['pin']) ?></span>（可另外告知對方，供他在其他裝置手動輸入使用）</div><?php endif; ?>
              </div>
            </div>
          </div>
        <?php };
      ?>
        <!-- 投稿碼：一碼一張卡，連結／QR／限制／用量都在同一張卡上 -->
        <div class="sechead"><i class="fa-solid fa-ticket"></i> 投稿碼<?= $gated ? '' : '（此地圖目前未啟用，任何人皆可直接上傳；碼會在 meta.json 設 "gated": true 後生效）' ?></div>
        <?php if ($codesList): ?>
          <div class="code-grid">
            <?php foreach ($codesList as $ce2): $cc = (string)($ce2['code'] ?? ''); $inviteC = $mapUrl($p) . '?code=' . $cc; ?>
              <div class="card codecard">
                <div class="qr" data-url="<?= $esc($inviteC) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" data-code="<?= $esc($cc) ?>"></div>
                <div class="codecard-info">
                  <div><?= $esc(($ce2['label'] ?? '') !== '' ? $ce2['label'] : '（無暱稱）') ?> · <?= $secret($cc) ?>
                    <button type="button" class="chipbtn" data-copy="<?= $esc($inviteC) ?>" title="複製此碼的邀請連結"><i class="fa-solid fa-link"></i></button>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcode"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="code_del" value="<?= $esc($cc) ?>"><button class="x" title="移除此投稿碼（立即失效，不影響已投稿內容）">×</button></form>
                  </div>
                  <div class="invite"><?= $esc($inviteC) ?></div>
                  <div class="badge">
                    <?= !empty($ce2['expires_at']) ? '到期：' . $esc(substr((string)$ce2['expires_at'], 0, 16)) : '不限期' ?>
                    ・<?= isset($ce2['max_uses']) && $ce2['max_uses'] !== null ? '已傳 ' . (int)($ce2['used_count'] ?? 0) . '/' . (int)$ce2['max_uses'] . ' 張' : '不限次數（已傳 ' . (int)($ce2['used_count'] ?? 0) . ' 張）' ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="emptystate">尚無投稿碼，用下方表單新增第一組</div>
        <?php endif; ?>
        <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:8px">
          <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="code"><input type="hidden" name="project" value="<?= $esc($p) ?>">
          <label class="fieldlabel">投稿碼<input name="pin_new" inputmode="numeric" autocomplete="off" placeholder="留空自動產生"></label>
          <label class="fieldlabel">暱稱<input name="label" autocomplete="off" placeholder="可選"></label>
          <label class="fieldlabel">到期時間
            <div class="expirywidget">
              <div class="expirychips">
                <button type="button" class="chip" data-preset="none">不限期</button>
                <button type="button" class="chip" data-preset="1h">1小時</button>
                <button type="button" class="chip" data-preset="1d">1天</button>
                <button type="button" class="chip" data-preset="1w">1週</button>
              </div>
              <input name="expires_at" type="datetime-local" class="expirycustom" title="到期時間（留空＝不限期）">
            </div>
          </label>
          <label class="fieldlabel">張數上限<input name="max_uses" type="number" min="1" placeholder="留空＝不限"></label>
          <button class="btn"><i class="fa-solid fa-plus"></i> 新增投稿碼</button>
        </form>
        <?php if ($justHere('code')) $shareNew($justCreatedShare, '投稿碼'); ?>

        <!-- 身分管理：投稿者（純自助）與管理 PIN 並列 -->
        <div class="sechead" style="margin-top:24px"><i class="fa-solid fa-users"></i> 身分管理</div>
        <div class="idgrid">
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-id-badge"></i> 投稿者</div>
            <?php if ($cList || $ownerGroups): ?>
              <div class="pinlist">
                <?php foreach ($cList as $cid => $ce):
                  $cnt = $contribCounts[$cid] ?? 0;
                  $isBlocked = in_array($cid, $blocked['contribs'], true);
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div><?= $esc(($ce['label'] ?? '') !== '' ? $ce['label'] : '（無暱稱投稿者）') ?> · <span class="mono"><?= $esc($cid) ?></span> · <?= $cnt ?> 則<?php if ($isBlocked): ?> · <span class="tag">已鎖定</span><?php endif; ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delcontrib"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="contrib_id" value="<?= $esc($cid) ?>"><button class="x" title="撤銷此投稿身分（不影響已投稿內容，也不擋日後用投稿碼再次上傳）">×</button></form>
                    </div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="鎖定後此身分無法再投稿，不影響已投稿內容"><?= $isBlocked ? '✓ 已鎖定' : '鎖定' ?></button></form>
                      <?php if ($cnt > 0 && $canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('確定刪除此身分的全部 <?= $cnt ?> 則投稿？此動作無法復原')"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="contrib"><input type="hidden" name="key" value="<?= $esc($cid) ?>"><button class="permtoggle" title="刪除此身分的全部投稿"><i class="fa-solid fa-trash"></i> 刪除全部</button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <?php foreach ($ownerGroups as $oh => $og):
                  $isBlocked = in_array($oh, $blocked['owners'], true);
                  $nameNote = ($og['last_name'] !== '' && $og['last_name'] !== '匿名') ? '曾用「' . $esc($og['last_name']) . '」' : '匿名投稿者';
                ?>
                  <div class="pinchip pinchip-block<?= $isBlocked ? ' blocked' : '' ?>">
                    <div>（<?= $nameNote ?>） · <span class="mono"><?= $esc(substr($oh, 0, 8)) ?></span> · <?= $og['count'] ?> 則<?php if ($isBlocked): ?> · <span class="tag">已鎖定</span><?php endif; ?></div>
                    <div class="permrow">
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="<?= $isBlocked ? 'unblockid' : 'blockid' ?>"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle<?= $isBlocked ? ' on' : '' ?>" title="鎖定後此裝置無法再投稿，不影響已投稿內容"><?= $isBlocked ? '✓ 已鎖定' : '鎖定' ?></button></form>
                      <?php if ($canDeleteOthers): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('確定刪除此身分的全部 <?= $og['count'] ?> 則投稿？此動作無法復原')"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delbyid"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="kind" value="owner"><input type="hidden" name="key" value="<?= $esc($oh) ?>"><button class="permtoggle" title="刪除此身分的全部投稿"><i class="fa-solid fa-trash"></i> 刪除全部</button></form>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="emptystate">尚無投稿紀錄</div>
            <?php endif; ?>
          </div>

          <?php if ($master || $canDelegateAdmin): ?>
          <div class="idgroup">
            <div class="sechead"><i class="fa-solid fa-user-gear"></i> 管理 PIN</div>
            <?php if ($realPins): ?>
              <div class="pinlist">
                <?php if ($master):
                  $permLabels = ['delete_others' => '刪別人投稿', 'edit_others' => '編別人照片', 'edit_points' => '編定位點', 'delegate_admin' => '可建管理PIN連結'];
                  foreach ($realPins as $e): $pid = (string)($e['id'] ?? ''); $perms = $e['perms'] ?? pin_default_perms(); ?>
                  <div class="pinchip pinchip-block">
                    <div><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : '（無暱稱）') ?> · <?= $secret($e['pin'] ?? '') ?>
                      <?php if (!empty($e['via_link'])): ?><span class="tag">邀請兌換</span><?php endif; ?>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="移除">×</button></form>
                    </div>
                    <?php if ($pid !== ''): ?>
                    <div class="permrow">
                      <?php foreach ($permLabels as $pk => $ptext): $on = !empty($perms[$pk]); ?>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="setperm"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_id" value="<?= $esc($pid) ?>"><input type="hidden" name="perm" value="<?= $esc($pk) ?>"><input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                          <button class="permtoggle<?= $on ? ' on' : '' ?>" title="下放權限給此專案 PIN；預設關閉，只有主 PIN 能切換"><?= $on ? '✓ ' : '' ?><?= $esc($ptext) ?></button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; else: ?>
                  <?php foreach ($realPins as $e): ?>
                    <span class="pinchip" title="非主要管理者只看得到暱稱，看不到 PIN"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : '（無暱稱）') ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($master): ?>
              <form class="row" method="post" style="flex-wrap:wrap;margin-top:8px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="fieldlabel">直接新增 PIN<input name="pin_new" autocomplete="off" placeholder="不產生連結、不設限"></label>
                <label class="fieldlabel">暱稱<input name="label" autocomplete="off" placeholder="可選"></label>
                <button class="btn"><i class="fa-solid fa-plus"></i> 直接新增</button>
              </form>
            <?php endif; ?>
            <?php if ($master && $invites): ?>
              <div class="sechead" style="margin-top:14px;font-size:0.8125rem"><i class="fa-solid fa-envelope-open-text"></i> 待兌換邀請</div>
              <div class="pinlist">
                <?php foreach ($invites as $e): $inviteId = (string)($e['id'] ?? ''); $inviteUrl = $mapUrl($p) . '#redeem=' . rawurlencode((string)($e['token'] ?? '')) . '&rmode=admin'; ?>
                  <div class="pinchip pinchip-block">
                    <div>待兌換邀請
                      <button type="button" class="chipbtn qr-trigger" data-url="<?= $esc($inviteUrl) ?>" data-title="<?= $esc($meta['title'] ?? $p) ?>" title="顯示 QR"><i class="fa-solid fa-qrcode"></i></button>
                      <button type="button" class="chipbtn" data-copy="<?= $esc($inviteUrl) ?>" title="複製邀請連結"><i class="fa-solid fa-link"></i></button>
                      <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delinvite"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="invite_id" value="<?= $esc($inviteId) ?>"><button class="x" title="撤銷此邀請連結">×</button></form>
                    </div>
                    <div class="badge">
                      <?= !empty($e['expires_at']) ? '到期：' . $esc(substr((string)$e['expires_at'], 0, 16)) : '不限期' ?>
                      ・<?= isset($e['max_uses']) && $e['max_uses'] !== null ? '已兌換 ' . (int)($e['used_count'] ?? 0) . '/' . (int)$e['max_uses'] : '已兌換 ' . (int)($e['used_count'] ?? 0) . '（不限人數）' ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($canDelegateAdmin): ?>
              <form class="row expirywidget-row" method="post" style="flex-wrap:wrap;margin-top:8px">
                <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="sharelink"><input type="hidden" name="kind" value="admin"><input type="hidden" name="project" value="<?= $esc($p) ?>">
                <label class="fieldlabel">到期時間
                  <div class="expirywidget">
                    <div class="expirychips">
                      <button type="button" class="chip" data-preset="none">不限期</button>
                      <button type="button" class="chip" data-preset="1h">1小時</button>
                      <button type="button" class="chip" data-preset="1d">1天</button>
                      <button type="button" class="chip" data-preset="1w">1週</button>
                    </div>
                    <input name="expires_at" type="datetime-local" class="expirycustom" title="到期時間（留空＝不限期）">
                  </div>
                </label>
                <label class="fieldlabel">兌換人數上限<input name="max_uses" type="number" min="1" placeholder="留空＝不限"></label>
                <button class="btn"><i class="fa-solid fa-share-nodes"></i> 建立邀請連結</button>
              </form>
            <?php endif; ?>
            <?php if ($justHere('admin')) $shareNew($justCreatedShare, '管理 PIN 邀請連結'); ?>
          </div>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    <?php endforeach; ?>
    </div><!-- /pane-access -->

    <div class="pane on" id="pane-overview">
    <h2>統計摘要</h2>
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
      $dowNames = ['日', '一', '二', '三', '四', '五', '六'];
      $browsers = $s['browser'] ?? [];
      arsort($browsers);
      $oses = $s['os'] ?? [];
      arsort($oses);
      $bLabels = ['chrome' => 'Chrome', 'safari' => 'Safari', 'edge' => 'Edge', 'firefox' => 'Firefox', 'samsung' => 'Samsung Internet', 'opera' => 'Opera', 'line' => 'LINE 內建', 'facebook' => 'FB/Messenger 內建', 'instagram' => 'IG/Threads 內建', 'wechat' => 'WeChat 內建', 'duckduckgo' => 'DuckDuckGo', 'other' => '其他'];
      $oLabels = ['ios' => 'iOS', 'android' => 'Android', 'windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'other' => '其他'];
    ?>
      <div style="margin-bottom:14px">
        <div style="font-size:0.8125rem;font-weight:700;margin-bottom:8px"><?= $esc($p) ?></div>
        <div class="stats-grid">
          <div class="tile">
            <div class="n"><?= (int)($s['views'] ?? 0) ?></div>
            <div class="l">瀏覽</div>
          </div>
          <div class="tile">
            <div class="n"><?= (int)($s['sessions'] ?? 0) ?></div>
            <div class="l">工作階段</div>
          </div>
          <div class="tile">
            <div class="n"><?= (int)($s['uploads'] ?? 0) ?></div>
            <div class="l">上傳</div>
          </div>
          <div class="tile">
            <div class="n"><?= (int)(($s['device']['mobile'] ?? 0)) ?>/<?= (int)(($s['device']['desktop'] ?? 0)) ?></div>
            <div class="l">手機/桌機</div>
          </div>
        </div>
        <div class="break">熱門點位：<b><?= $esc($top($s['points'])) ?></b><br>相機：<b><?= $esc($top($s['cameras'])) ?></b></div>
        <details class="statdetail">
          <summary><i class="fa-solid fa-chart-simple"></i> 展開完整排行與數字說明</summary>
          <div class="cols">
            <div class="col">
              <h4>這些數字的意思</h4>
              <p>瀏覽＝地圖頁被載入的次數。<br>工作階段＝不重複的造訪（同一次開啟頁面只算一次）。<br>上傳＝成功送出的投稿數。<br>手機/桌機＝造訪裝置的類型分佈。<br>排行的數字＝該項被使用/點開的累計次數。</p>
            </div>
            <div class="col">
              <h4>點位排行（被點開次數）</h4>
              <?php if ($s['points']): ?><ol><?php foreach (array_slice($s['points'], 0, 20, true) as $k => $v): ?><li>點 <?= $esc($k) ?>：<?= (int)$v ?> 次</li><?php endforeach; ?></ol><?php else: ?><p>尚無資料</p><?php endif; ?>
            </div>
            <div class="col">
              <h4>相機排行（投稿使用機型）</h4>
              <?php if ($s['cameras']): ?><ol><?php foreach (array_slice($s['cameras'], 0, 20, true) as $k => $v): ?><li><?= $esc($k) ?>：<?= (int)$v ?> 次</li><?php endforeach; ?></ol><?php else: ?><p>尚無資料</p><?php endif; ?>
            </div>
            <div class="col">
              <h4>功能使用次數</h4>
              <?php if ($feats): ?><ol><?php foreach ($feats as $k => $v): ?><li><?= $esc($featLabels[$k] ?? $k) ?>：<?= (int)$v ?> 次</li><?php endforeach; ?></ol><?php else: ?><p>尚無資料</p><?php endif; ?>
            </div>
            <div class="col">
              <h4>瀏覽器／作業系統</h4>
              <?php if ($browsers || $oses): ?>
                <p>瀏覽器：<?php $bb = []; foreach ($browsers as $k => $v) $bb[] = ($bLabels[$k] ?? $k) . '（' . (int)$v . '）'; echo $esc(implode('、', $bb)); ?><br>
                  系統：<?php $oo = []; foreach ($oses as $k => $v) $oo[] = ($oLabels[$k] ?? $k) . '（' . (int)$v . '）'; echo $esc(implode('、', $oo)); ?></p>
              <?php else: ?><p>尚無資料（此統計上線後的新造訪才會開始累計）</p><?php endif; ?>
            </div>
            <div class="col">
              <h4>造訪時段</h4>
              <p>熱門時段（當地時間）：<?php $hh = []; foreach (array_slice($byHour, 0, 3, true) as $k => $v) $hh[] = (int)$k . ' 點（' . (int)$v . '）'; echo $esc($hh ? implode('、', $hh) : '尚無資料'); ?><br>
                熱門星期：<?php $dd = []; foreach (array_slice($byDow, 0, 3, true) as $k => $v) $dd[] = '週' . ($dowNames[(int)$k] ?? $k) . '（' . (int)$v . '）'; echo $esc($dd ? implode('、', $dd) : '尚無資料'); ?></p>
            </div>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
    </div><!-- /pane-overview -->

    <div class="pane" id="pane-records">
    <h2>投稿紀錄</h2>
    <div class="searchbar"><i class="fa-solid fa-magnifying-glass"></i><input id="recsearch" type="search" placeholder="搜尋投稿（專案、點位、暱稱、內容、相機、時間…）" autocomplete="off"></div>
    <div class="tablewrap">
      <table id="rectable">
        <tr>
          <th>#</th>
          <th>項目</th>
          <th>點</th>
          <th>類型</th>
          <th>照片</th>
          <th>暱稱</th>
          <th>內容</th>
          <th>相機</th>
          <th>時間</th>
          <th>座標</th>
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
            <td><span class="tag"><?= $esc(souliong_kind_label($r['kind'] ?? 'photo')) ?></span><?= !empty($r['edit_of']) ? '<br><span class="tag">編修</span>' : '' ?></td>
            <td><?= $photoUrl ? '<a href="' . $esc($photoUrl) . '" target="_blank"><img loading="lazy" src="' . $esc($thumbUrl) . '" alt=""></a>' : '' ?></td>
            <td><?= $esc($r['name'] ?? '') ?></td>
            <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
            <td class="mono"><?= $esc($dispExif ? implode(' ', $dispExif) : '') ?></td>
            <td class="mono"><?= $esc(substr((string)($r['photo_time'] ?? $r['created_at'] ?? ''), 0, 16)) ?></td>
            <td class="mono"><?= $esc(round((float)($r['lat'] ?? 0), 4)) ?>,<?= $esc(round((float)($r['lon'] ?? 0), 4)) ?><br><?= $esc($r['loc_source'] ?? '') ?></td>
            <td class="mono"><?= $esc($short($r['owner_hash'] ?? '')) ?><br><?= $esc($short($r['src_hash'] ?? '')) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('確定刪除？')"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delete">
                <input type="hidden" name="project" value="<?= $esc($r['project']) ?>"><input type="hidden" name="id" value="<?= $esc($r['id'] ?? '') ?>">
                <button class="iconbtn" title="刪除"><i class="fa-solid fa-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <div class="hint">o＝owner（同源可群組追蹤）、s＝加鹽 IP 雜湊；同一 owner 出現多個不同 s 可能是投稿碼外流／冒名。<?= $master ? '　主 PIN 可管理所有專案並設定各專案 PIN；專案 PIN 僅能管理該專案。' : '' ?></div>
    </div><!-- /pane-records -->

    <?php if ($master): ?>
    <div class="pane" id="pane-tools">
      <h2>工具</h2>
      <form class="card section-card" method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="import">
        <span class="badge"><i class="fa-solid fa-upload"></i> 匯入備份 ZIP</span>
        <label class="btn" style="cursor:pointer"><i class="fa-solid fa-folder-open"></i> <span data-file>選擇 ZIP 檔</span>
          <input type="file" name="backup" accept=".zip" required hidden onchange="this.parentNode.querySelector('[data-file]').textContent=this.files[0]?this.files[0].name:'選擇 ZIP 檔'"></label>
        <select name="mode" style="border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--fg);padding:7px 10px;font-size:0.8125rem">
          <option value="merge">合併（依 id 聯集，不重複）</option>
          <option value="replace">覆蓋</option>
        </select>
        <button class="btn">還原</button>
      </form>
      <div class="card section-card">
        <div class="badge"><i class="fa-solid fa-kit-medical"></i> 資料修復</div>
        <div class="hint" style="margin-top:6px">投稿的相機資訊（EXIF）缺漏時，用「修復相機資訊」工具補齊：方式一直接由原始版本繼承，方式二上傳原始檔比對。照片縮圖平時會自動產生；只有自動產生失敗（例如主機不支援 WebP）才需要「補產縮圖」。</div>
        <div class="row" style="margin-top:8px"><a class="btn" href="<?= $esc($origin . $basePath . 'exiffix') ?>"><i class="fa-solid fa-kit-medical"></i> 開啟修復相機資訊</a>
          <a class="btn" href="<?= $esc($origin . $basePath . 'thumbfix') ?>"><i class="fa-solid fa-images"></i> 補產縮圖（備援）</a>
          <a class="btn" href="?api=admin&backup=all"><i class="fa-solid fa-download"></i> 備份全部</a></div>
      </div>
    </div><!-- /pane-tools -->
    <?php endif; ?>
  </div>
  <script>
    <?php readfile(__DIR__ . '/../assets/vendor/qrcode-generator.js'); ?>
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
        el.textContent = 'QR 產生失敗';
        return;
      }
      el.title = '點一下全螢幕顯示，方便給人掃描';
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
        + '<div class="qr-modal-guide">請用相機或掃碼 App 掃描這個 QR</div>'
        + '<div class="qr-modal-box"></div>'
        + '<div class="qr-modal-url"></div>'
        + (codeText ? '<div class="qr-modal-code"></div>' : '')
        + '<div class="qr-modal-hint">點任意處關閉</div></div>';
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
          btn.innerHTML = '<i class="fa-solid fa-check"></i> 已複製';
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