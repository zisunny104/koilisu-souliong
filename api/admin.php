<?php
// 管理頁：?api=admin （httpOnly cookie 認證；PIN 走 POST。主 PIN 全域、各專案 PIN 僅該專案）
require __DIR__ . '/store.php';
require __DIR__ . '/security.php';
require __DIR__ . '/stats.php';
require __DIR__ . '/../pages/error.php';
$cfg = require __DIR__ . '/config.php';
rate_limit($cfg, 'admin');
$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── 登入（POST pin[, project]）→ 種 cookie ──
$loginErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
  $pin = (string)($_POST['pin'] ?? '');
  $proj = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
  $ok = false;
  $go = '?api=admin';
  if (check_master_pin($cfg, $pin)) {
    admin_set_cookie($cfg);
    $ok = true;
  } elseif ($proj !== '' && check_project_pin($cfg, $proj, $pin)) {
    padm_set_cookie($cfg, $proj);
    $ok = true;
    $go = '?api=admin&project=' . urlencode($proj);
  }
  if (isset($_POST['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($ok) echo json_encode(['ok' => true]);
    else {
      http_response_code(403);
      echo json_encode(['error' => 'PIN 不正確']);
    }
    exit;
  }
  if ($ok) {
    header('Location: ' . $go);
    exit;
  }
  $loginErr = 'PIN 不正確';
}
if (isset($_GET['logout'])) {
  admin_clear_cookie();
  header('Location: ?api=admin');
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
        $csrf = $master ? admin_derived($cfg) : padm_derived($cfg, $reqProject);
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

        // ── 動作 ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          $id = (string)($_POST['id'] ?? '');
          if ($p !== '' && $id !== '' && $canProject($p)) {
            $removed = store_delete($cfg, $p, $id);
            if ($removed && !empty($removed['photo'])) {
              $pp = photo_abs_path($cfg, $removed['photo']);
              if ($pp) @unlink($pp);
            }
          }
          header('Location: ?api=admin' . ($scopeProject !== '' ? '&project=' . urlencode($scopeProject) : ''));
          exit;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rotate') {
          need_csrf($csrf);
          $p = preg_replace('/[^a-z0-9_-]/', '', $_POST['project'] ?? '');
          if ($p !== '' && $canProject($p)) {
            @unlink(project_dir($cfg, $p) . '/code.txt');
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['addpin', 'delpin'], true)) {
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
                $d['projects'][$tp] = $d['projects'][$tp] ?? [];
                $d['projects'][$tp][] = $entry;
              }
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
                if (preg_match('#^projects/([a-z0-9_-]+)/(stats\.json|code\.txt)$#', str_replace('\\', '/', $nm), $mm)) @file_put_contents(project_dir($cfg, $mm[1]) . '/' . $mm[2], $content, LOCK_EX);
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
        $origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        $basePath = preg_replace('#\?.*$#', '', $_SERVER['REQUEST_URI'] ?? '/');
        $short = fn($s) => $s ? substr((string)$s, 0, 8) : '—';

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

    .code-card {
      display: flex;
      gap: 18px;
      align-items: flex-start;
      flex-wrap: wrap;
      padding: 16px 18px;
      margin-bottom: 12px
    }

    .code-card .qr {
      background: #fff;
      padding: 6px;
      border-radius: 12px;
      flex: none;
      width: 120px;
      height: 120px
    }

    .code-card .qr svg {
      width: 100%;
      height: 100%;
      display: block
    }

    .code-info {
      flex: 1;
      min-width: 220px
    }

    .code-info .pill {
      font-size: 0.75rem;
      color: var(--muted);
      margin-bottom: 6px
    }

    .big {
      font-family: ui-monospace, Consolas, monospace;
      font-size: 1.875rem;
      font-weight: 800;
      letter-spacing: 4px
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

    .acts {
      display: flex;
      flex-direction: column;
      gap: 8px
    }

    .row {
      display: flex;
      gap: 6px;
      align-items: center;
      margin-top: 8px
    }

    .metaedit {
      margin-top: 12px;
      border-top: 1px solid var(--line);
      padding-top: 10px
    }

    .metaedit>summary {
      cursor: pointer;
      font-size: 0.7813rem;
      font-weight: 600;
      color: var(--muted);
      list-style: none
    }

    .metaedit>summary::-webkit-details-marker {
      display: none
    }

    .metaedit>summary:hover {
      color: var(--fg)
    }

    .metaform {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-top: 10px
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
      width: 140px
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
  </style>
</head>

<body>
  <div class="wrap">
    <div class="top">
      <h1><i class="fa-solid fa-gauge-high"></i> Souliong 循跡 <span class="sub"><?= $master ? '主要管理' : $esc($reqProject) . ' 專案管理' ?> · <?= count($rows) ?> 筆</span></h1>
      <?php if ($master): ?><a class="btn solid" href="?api=admin&backup=all"><i class="fa-solid fa-download"></i> 備份全部</a><?php endif; ?>
      <a class="btn" href="?api=admin&logout=1"><i class="fa-solid fa-right-from-bracket"></i> 登出</a>
    </div>

    <?php if (!is_writable($cfg['projects_dir'])): ?>
      <div class="card" style="border-color:var(--danger);color:var(--danger);padding:14px 18px;margin-top:12px;font-size:.85rem;line-height:1.7">
        <b><i class="fa-solid fa-triangle-exclamation"></i> 資料夾無法寫入：<?= $esc($cfg['projects_dir']) ?></b><br>
        投稿碼／統計無法儲存，會導致「投稿碼永遠驗證失敗」。請讓 PHP 對 <code>projects/</code> 與 <code>state/</code> 有寫入權限（例如 <code>chown -R www-data projects state</code> 或 <code>chmod</code>）。
      </div>
    <?php endif; ?>

    <?php if ($master): ?>
      <form class="card" method="post" enctype="multipart/form-data" style="padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
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

      <div class="tabs">
        <a class="tab<?= $scopeProject === '' ? ' on' : '' ?>" href="?api=admin">全部（<?= count($allProjects) ?>）</a>
        <?php foreach ($allProjects as $tp): ?><a class="tab<?= $scopeProject === $tp ? ' on' : '' ?>" href="?api=admin&project=<?= $esc($tp) ?>"><?= $esc($tp) ?></a><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($master && $scopeProject === ''): $mpins = pins_load($cfg)['master']; ?>
      <h2>主要管理 PIN（可進入所有專案）</h2>
      <div class="hint" style="margin:-6px 0 12px">主要管理層級：僅在此「全部」頁管理；進入單一專案後不會顯示。</div>
      <div class="card" style="padding:16px 18px">
        <div class="pinlist">
          <span class="pinchip">主設定 · <span class="mono">config</span></span>
          <?php foreach ($mpins as $e): ?><span class="pinchip"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : '（無暱稱）') ?> · <span class="mono"><?= $esc($e['pin'] ?? '') ?></span>
              <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="master"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="移除">×</button></form>
            </span>
          <?php endforeach; ?>
        </div>
        <form class="row" method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="master">
          <input name="pin_new" placeholder="新增主要管理 PIN" autocomplete="off"><input name="label" placeholder="暱稱（可選）" autocomplete="off"><button class="btn">新增</button>
        </form>
      </div>
    <?php endif; ?>

    <h2>投稿碼 · 邀請<?= $master ? ' · 專案 PIN' : '' ?></h2>
    <?php foreach ($viewProjects as $p):
      $meta = json_decode((string)@file_get_contents($cfg['projects_dir'] . '/' . $p . '/meta.json'), true);
      $code = project_code($cfg, $p, is_array($meta) ? $meta : null);
      $ppins = $master ? (pins_load($cfg)['projects'][$p] ?? []) : [];
      $invite = $origin . $basePath . $p . '?code=' . $code;
    ?>
      <div class="card code-card">
        <?php if ($code !== ''): ?><div class="qr" data-url="<?= $esc($invite) ?>"></div><?php endif; ?>
        <div class="code-info">
          <div class="pill"><i class="fa-solid fa-map-location-dot"></i> <?= $esc($meta['title'] ?? $p) ?>（<?= $esc($p) ?>）</div>
          <?php if ($code !== ''): ?><div class="big"><?= $esc($code) ?></div>
            <div class="invite"><?= $esc($invite) ?></div><?php else: ?><div class="badge">此地圖未設投稿碼（meta.json 的 "gated": true 才需要）</div><?php endif; ?>
          <?php if ($master): ?>
            <div class="badge" style="margin-top:10px">專案 PIN（房間鑰匙）</div>
            <div class="pinlist">
              <?php foreach ($ppins as $e): ?><span class="pinchip"><?= $esc(($e['label'] ?? '') !== '' ? $e['label'] : '（無暱稱）') ?> · <span class="mono"><?= $esc($e['pin'] ?? '') ?></span>
                  <form method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="delpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>"><input type="hidden" name="pin_del" value="<?= $esc($e['pin'] ?? '') ?>"><button class="x" title="移除">×</button></form>
                </span>
              <?php endforeach; ?>
            </div>
            <form class="row" method="post"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="addpin"><input type="hidden" name="scope" value="project"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <input name="pin_new" placeholder="新專案 PIN" autocomplete="off"><input name="label" placeholder="暱稱（可選）" autocomplete="off"><button class="btn">新增</button>
            </form>
          <?php endif; ?>
          <details class="metaedit">
            <summary><i class="fa-solid fa-pen-to-square"></i> 編輯專案描述</summary>
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
        </div>
        <div class="acts">
          <a class="btn" href="?api=admin&backup=project&project=<?= $esc($p) ?>"><i class="fa-solid fa-download"></i> 備份此專案</a>
          <?php if ($code !== ''): ?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= $esc_csrf ?>"><input type="hidden" name="action" value="rotate"><input type="hidden" name="project" value="<?= $esc($p) ?>">
              <button class="btn danger" onclick="return confirm('重新產生會讓舊碼與邀請連結失效，確定？')"><i class="fa-solid fa-rotate"></i> 重新產生碼</button>
            </form><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

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
        <div class="break">熱門點位：<b><?= $esc($top($s['points'])) ?></b><br>相機：<b><?= $esc($top($s['cameras'])) ?></b>　功能：<b><?= $esc(json_encode($s['features'] ?? [], JSON_UNESCAPED_UNICODE)) ?></b></div>
      </div>
    <?php endforeach; ?>

    <h2>投稿紀錄</h2>
    <div class="tablewrap">
      <table>
        <tr>
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
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= $esc($r['project']) ?></td>
            <td><?= $esc($r['item_num'] ?? '') ?></td>
            <td><span class="tag"><?= $esc($r['kind'] ?? 'photo') ?></span></td>
            <td><?= !empty($r['photo']) ? '<a href="?api=photo&f=' . $esc($r['photo']) . '" target="_blank"><img src="?api=photo&f=' . $esc($r['photo']) . '" alt=""></a>' : '' ?></td>
            <td><?= $esc($r['name'] ?? '') ?></td>
            <td><?= nl2br($esc($r['comment'] ?? '')) ?></td>
            <td class="mono"><?= $esc(is_array($r['exif'] ?? null) ? implode(' ', $r['exif']) : '') ?></td>
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
      }
    });
  </script>
</body>

</html>