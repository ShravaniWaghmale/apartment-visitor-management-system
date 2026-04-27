<?php
// ============================================================
//  config/db.php — PostgreSQL + all global dynamic helpers
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'visitor_management');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: 'admin123');

// ── Singleton PDO ────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=require',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('<h3 style="font-family:sans-serif;color:red;">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</h3>');
        }
    }
    return $pdo;
}

// ── Dynamic settings (cached) ─────────────────────────────────
function getSettings(): array {
    static $s = null;
    if ($s === null) {
        try { $s = getDB()->query("SELECT * FROM settings LIMIT 1")->fetch() ?: []; }
        catch (Throwable $e) { $s = []; }
    }
    return $s;
}

// ── Session ───────────────────────────────────────────────────
function bootSession(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function requireLogin(): void {
    bootSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . rootPath() . 'index.php');
        exit();
    }
    // Refresh user data from DB on each request
    try {
        $row = getDB()->prepare("SELECT u.*,r.name AS role_name FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=:id AND u.is_active=TRUE");
        $row->execute([':id' => $_SESSION['user_id']]);
        $u = $row->fetch();
        if (!$u) { session_destroy(); header('Location: ' . rootPath() . 'index.php'); exit(); }
        $_SESSION['name']  = $u['name'];
        $_SESSION['role']  = $u['role_name'];
        $_SESSION['email'] = $u['email'];
        $_SESSION['flat']  = $u['flat_number'];
    } catch (Throwable $e) { /* keep existing session */ }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        echo '<div style="font-family:sans-serif;padding:40px;"><h2>Access Denied</h2><p>You do not have permission to view this page.</p><a href="' . rootPath() . 'dashboard.php">← Dashboard</a></div>';
        exit();
    }
}

function currentUser(): array {
    bootSession();
    return [
        'id'    => (int)($_SESSION['user_id'] ?? 0),
        'name'  => $_SESSION['name']   ?? '',
        'role'  => $_SESSION['role']   ?? '',
        'email' => $_SESSION['email']  ?? '',
        'flat'  => $_SESSION['flat']   ?? '',
    ];
}

// ── Relative path to project root ────────────────────────────
function rootPath(): string {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $rootDir   = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');
    if (!$rootDir || $scriptDir === $rootDir) return '';
    $rel = str_replace($rootDir . '/', '', $scriptDir . '/');
    $depth = substr_count(rtrim($rel, '/'), '/');
    return str_repeat('../', max(0, $depth));
}

function assetPath(string $path): string {
    return '/visitor-management/' . ltrim($path, '/');
}

// ── Overstay checker ─────────────────────────────────────────
function checkOverstays(): void {
    try {
        $hrs = (int)(getSettings()['max_visit_hours'] ?? 4);
        getDB()->exec("
            UPDATE visits SET overstay_flagged=TRUE
            WHERE status='inside' AND overstay_flagged=FALSE
            AND check_in < NOW() - INTERVAL '$hrs hours'
        ");
    } catch (Throwable $e) {}
}

// ── Flash messages ────────────────────────────────────────────
function flash(string $type, string $msg): void {
    bootSession();
    $_SESSION['flash'] = compact('type', 'msg');
}

function renderFlash(): string {
    bootSession();
    if (empty($_SESSION['flash'])) return '';
    ['type'=>$t, 'msg'=>$m] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $map = ['success'=>'62,207,142','danger'=>'224,82,82','warning'=>'245,158,11','info'=>'91,156,246'];
    $rgb = $map[$t] ?? $map['info'];
    return "<div class='flash-msg' style='background:rgba($rgb,0.1);border:1px solid rgba($rgb,0.3);color:var(--$t,#fff);padding:13px 16px;border-radius:9px;margin-bottom:20px;font-size:.9rem;'>" . htmlspecialchars($m) . "</div>";
}

// ── Dynamic Navigation builder ───────────────────────────────
function navItems(): array {
    return [
        ['📊','Dashboard',           'dashboard.php',                              ['admin','receptionist','guard','host']],
        ['➕','Register Visitor',    'modules/visitors/register.php',             ['admin','receptionist','guard']],
        ['📲','Check-in / QR Scan',  'modules/visitors/checkin.php',              ['admin','receptionist','guard']],
        ['📋','Visitor History',     'modules/visitors/history.php',              ['admin','receptionist','guard','host']],
        ['📅','Appointments',        'modules/preregistration/appointments.php',  ['admin','receptionist','host']],
        ['🪪','Badge Printing',      'modules/badge/print_badge.php',             ['admin','receptionist','guard']],
        ['🚫','Blacklist',           'modules/visitors/blacklist.php',            ['admin','receptionist','guard']],
        ['🚗','Parking',             'modules/parking/parking.php',               ['admin','receptionist','guard']],
        ['📈','Analytics',           'modules/reports/analytics.php',             ['admin','receptionist']],
        ['⬇️','Export Reports',      'modules/reports/export.php',                ['admin','receptionist']],
        ['👥','User Management',     'modules/users/manage_users.php',            ['admin']],
        ['⚙️','Settings',            'modules/settings/settings.php',             ['admin']],
    ];
}

function renderNav(string $currentFile = ''): string {
    $role = currentUser()['role'];
    $root = '/visitor-management/';
    $html = '';
    foreach (navItems() as [$icon, $label, $href, $roles]) {
        if (!in_array($role, $roles, true)) continue;
        $active = (basename($currentFile ?: $_SERVER['SCRIPT_FILENAME'], '.php') === basename($href, '.php')) ? ' active' : '';
        $url    = $root . $href;
        $html  .= "<a href=\"$url\" class=\"nav-item$active\"><span class=\"nav-icon\">$icon</span>" . htmlspecialchars($label) . "</a>\n";
    }
    return $html;
}

// ── Full sidebar render ───────────────────────────────────────
function renderSidebar(string $current = ''): void {
    $u   = currentUser();
    $s   = getSettings();
    $org = htmlspecialchars($s['org_name'] ?? 'ResidentGuard');
    $ini = strtoupper(substr($u['name'], 0, 1));
    $logout = '/visitor-management/auth/logout.php';
    // unread notifications count
    $notifCount = 0;
    try {
        $n = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:uid AND is_read=FALSE");
        $n->execute([':uid' => $u['id']]);
        $notifCount = (int)$n->fetchColumn();
    } catch (Throwable $e) {}
    $badge = $notifCount > 0 ? "<span class='notif-counter'>$notifCount</span>" : '';
    echo <<<HTML
<aside class="sidebar">
  <div class="sidebar-brand">
    <div class="brand-icon">🏘️</div>
    <div><span class="brand-name">$org</span></div>
  </div>
  <nav class="sidebar-nav">
HTML;
    echo renderNav($current);
    echo <<<HTML
  </nav>
  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar">$ini</div>
      <div class="user-info">
        <div class="user-name">{$u['name']}</div>
        <div class="user-role">{$u['role']}</div>
      </div>
      $badge
    </div>
    <a href="$logout" class="btn-logout">Sign Out</a>
  </div>
</aside>
HTML;
}

// ── Page HTML head ────────────────────────────────────────────
function pageHead(string $title, string $extra = ''): void {
    $s   = getSettings();
    $org = htmlspecialchars($s['org_name'] ?? 'ResidentGuard');
    $css = assetPath('assets/css/style.css');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>$title — $org</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="$css">
$extra
</head>
<body>
HTML;
}

// ── Helpers ───────────────────────────────────────────────────
function timeDiff(string $from, ?string $to = null): string {
    $start = strtotime($from);
    $end   = $to ? strtotime($to) : time();
    $diff  = max(0, $end - $start);
    $h     = floor($diff / 3600);
    $m     = floor(($diff % 3600) / 60);
    return "{$h}h {$m}m";
}

function genToken(): string {
    return bin2hex(random_bytes(20));
}

function uploadFile(string $key, string $dest, array $allowed = ['jpg','jpeg','png','pdf']): ?string {
    if (empty($_FILES[$key]['name'])) return null;
    $ext = strtolower(pathinfo($_FILES[$key]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) return null;
    $fname = $key . '_' . uniqid() . '.' . $ext;
    $target = rtrim($dest, '/') . '/' . $fname;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
    return move_uploaded_file($_FILES[$key]['tmp_name'], $target) ? $fname : null;
}

function saveBase64Photo(string $data, string $dest): ?string {
    if (empty($data) || !str_starts_with($data, 'data:image')) return null;
    $bin   = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $data));
    $fname = 'photo_' . uniqid() . '.png';
    if (!is_dir($dest)) mkdir($dest, 0755, true);
    return file_put_contents($dest . '/' . $fname, $bin) !== false ? $fname : null;
}

function paginateQuery(PDO $db, string $sql, array $params, int $page, int $perPage): array {
    $countSql = "SELECT COUNT(*) FROM ($sql) AS _c";
    $cs = $db->prepare($countSql);
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page  = max(1, min($page, $pages));
    $offset = ($page - 1) * $perPage;
    $ds = $db->prepare("$sql LIMIT $perPage OFFSET $offset");
    $ds->execute($params);
    return ['data' => $ds->fetchAll(), 'total' => $total, 'pages' => $pages, 'page' => $page];
}

function pagerHtml(int $pages, int $current, array $query = []): string {
    if ($pages <= 1) return '';
    $html = "<div class='pager'>";
    for ($i = 1; $i <= $pages; $i++) {
        $q = http_build_query(array_merge($query, ['page' => $i]));
        $active = $i === $current ? ' pager-active' : '';
        $html .= "<a href='?$q' class='pager-btn$active'>$i</a>";
    }
    return $html . "</div>";
}

function notifyUser(int $userId, string $type, string $msg, int $refId = 0): void {
    try {
        getDB()->prepare("INSERT INTO notifications (user_id,type,message,ref_id) VALUES (:u,:t,:m,:r)")
               ->execute([':u'=>$userId,':t'=>$type,':m'=>$msg,':r'=>$refId]);
    } catch (Throwable $e) {}
}
