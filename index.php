<?php
require_once 'config/db.php';
bootSession();
if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit(); }

// Load everything dynamically from DB
$settings = [];
$roles    = [];
$error    = $_GET['error'] ?? '';
try {
    $settings = getDB()->query("SELECT * FROM settings LIMIT 1")->fetch() ?: [];
    $roles    = getDB()->query("SELECT DISTINCT r.name FROM roles r JOIN users u ON u.role_id=r.id WHERE u.is_active=TRUE ORDER BY r.name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $error = 'db';
}
$orgName    = htmlspecialchars($settings['org_name']    ?? 'ResidentGuard');
$orgAddress = htmlspecialchars($settings['org_address'] ?? '');
$orgPhone   = htmlspecialchars($settings['org_phone']   ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Login — <?= $orgName ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
  body { display:flex; min-height:100vh; overflow:hidden; }
  .login-left {
    flex:1; background:linear-gradient(135deg,#0f1117 0%,#1a1f32 50%,#0d1520 100%);
    display:flex; flex-direction:column; justify-content:center; padding:60px;
    position:relative; overflow:hidden;
  }
  .login-left::before { content:''; position:absolute; width:500px; height:500px; border-radius:50%;
    background:radial-gradient(circle,rgba(232,168,56,.12) 0%,transparent 70%); top:-100px; left:-100px; }
  .login-left::after  { content:''; position:absolute; width:400px; height:400px; border-radius:50%;
    background:radial-gradient(circle,rgba(62,207,142,.08) 0%,transparent 70%); bottom:-80px; right:20px; }
  .brand { position:relative; z-index:1; margin-bottom:50px; }
  .brand-logo { width:54px; height:54px; background:linear-gradient(135deg,var(--accent),var(--accent2));
    border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px;
    margin-bottom:16px; box-shadow:0 8px 32px rgba(232,168,56,.35); }
  .brand h1 { font-family:'DM Serif Display',serif; font-size:2rem; }
  .brand .brand-sub { color:var(--muted); font-size:.88rem; margin-top:4px; }
  .hero { position:relative; z-index:1; }
  .hero h2 { font-family:'DM Serif Display',serif; font-size:3rem; line-height:1.15; margin-bottom:18px; }
  .hero h2 em { color:var(--accent); font-style:italic; }
  .hero p { color:var(--muted); font-size:.95rem; line-height:1.7; max-width:400px; }
  .feature-list { margin-top:36px; display:flex; flex-direction:column; gap:12px; position:relative; z-index:1; }
  .feature-item { display:flex; align-items:center; gap:12px; }
  .feature-dot { width:7px; height:7px; border-radius:50%; background:var(--accent); flex-shrink:0; }
  .feature-item span { color:var(--muted); font-size:.88rem; }
  .org-footer { position:relative; z-index:1; margin-top:auto; padding-top:40px; }
  .org-footer p { color:var(--muted); font-size:.78rem; line-height:1.6; }

  .login-right { width:480px; background:var(--surface); display:flex; align-items:center; justify-content:center;
    padding:60px 50px; border-left:1px solid var(--border); }
  .form-wrap { width:100%; }
  .form-title { font-family:'DM Serif Display',serif; font-size:2rem; margin-bottom:6px; }
  .form-subtitle { color:var(--muted); font-size:.88rem; margin-bottom:32px; }
  .f-group { margin-bottom:20px; }
  .f-group label { display:block; font-size:.75rem; font-weight:500; color:var(--muted); margin-bottom:8px;
    letter-spacing:.5px; text-transform:uppercase; }
  .f-group input, .f-group select { width:100%; background:var(--surface2); border:1px solid var(--border);
    border-radius:10px; padding:14px 16px; color:var(--text); font-family:'DM Sans',sans-serif; font-size:.93rem;
    outline:none; transition:border-color .2s,box-shadow .2s; }
  .f-group input:focus, .f-group select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(232,168,56,.12); }
  .f-group select option { background:var(--surface2); }
  .btn-login { width:100%; background:linear-gradient(135deg,var(--accent),var(--accent2)); color:#1a1200;
    font-weight:600; font-size:1rem; border:none; border-radius:10px; padding:15px; cursor:pointer;
    transition:transform .15s,box-shadow .2s; margin-top:6px; }
  .btn-login:hover { transform:translateY(-1px); box-shadow:0 8px 24px rgba(232,168,56,.35); }
  .err { background:rgba(224,82,82,.1); border:1px solid rgba(224,82,82,.3); color:var(--danger);
    padding:12px 16px; border-radius:8px; font-size:.85rem; margin-bottom:20px; }
  .divider { text-align:center; color:var(--muted); font-size:.78rem; margin:22px 0; position:relative; }
  .divider::before,.divider::after { content:''; position:absolute; top:50%; width:42%; height:1px; background:var(--border); }
  .divider::before { left:0; } .divider::after { right:0; }
  .demo-box { background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:14px; }
  .demo-box p { font-size:.75rem; color:var(--muted); margin-bottom:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
  .demo-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0;
    border-bottom:1px solid var(--border); }
  .demo-row:last-child { border-bottom:none; }
  .demo-role { font-size:.76rem; color:var(--accent); font-weight:600; text-transform:capitalize; }
  .demo-pass { font-size:.74rem; color:var(--muted); font-family:monospace; }
  @media(max-width:900px){.login-left{display:none;}.login-right{width:100%;}}
</style>
</head>
<body>

<!-- LEFT PANEL — dynamic org content -->
<div class="login-left">
  <div class="brand">
    <?php if (!empty($settings['logo']) && file_exists('assets/uploads/' . $settings['logo'])): ?>
      <img src="assets/uploads/<?= htmlspecialchars($settings['logo']) ?>" class="brand-logo" style="border-radius:14px;object-fit:cover;">
    <?php else: ?>
      <div class="brand-logo">🏘️</div>
    <?php endif; ?>
    <h1><?= $orgName ?></h1>
    <p class="brand-sub">Visitor Management System</p>
  </div>
  <div class="hero">
    <h2>Secure. Smart.<br><em>Simple.</em></h2>
    <p>Complete visitor management for residential societies — track every entry, notify residents instantly, keep your community safe.</p>
  </div>
  <div class="feature-list">
    <?php
    // Dynamic features pulled from settings capabilities
    $features = [
        'QR-code based instant check-in & check-out',
        'Pre-registration & appointment scheduling',
        'Automated host notifications via email',
        'Analytics dashboard & exportable reports',
        'Blacklist management & overstay alerts',
        'Parking slot assignment & tracking',
    ];
    foreach ($features as $f): ?>
    <div class="feature-item"><div class="feature-dot"></div><span><?= htmlspecialchars($f) ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php if ($orgAddress || $orgPhone): ?>
  <div class="org-footer">
    <p><?= $orgAddress ?><?= ($orgAddress && $orgPhone) ? ' · ' : '' ?><?= $orgPhone ?></p>
  </div>
  <?php endif; ?>
</div>

<!-- RIGHT PANEL — login form -->
<div class="login-right">
  <div class="form-wrap">
    <h2 class="form-title">Welcome back</h2>
    <p class="form-subtitle">Sign in to continue to <?= $orgName ?></p>

    <?php if ($error === '1'): ?>
    <div class="err">❌ Invalid email or password. Please try again.</div>
    <?php elseif ($error === 'role'): ?>
    <div class="err">⚠️ Role mismatch. Please select your correct role.</div>
    <?php elseif ($error === 'inactive'): ?>
    <div class="err">🚫 Your account has been deactivated. Contact admin.</div>
    <?php elseif ($error === 'db'): ?>
    <div class="err">⚠️ Database connection error. Please check your configuration.</div>
    <?php endif; ?>

    <form action="auth/login.php" method="POST">
      <div class="f-group">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@society.com" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="f-group">
        <label>Password</label>
        <input type="password" name="password" placeholder="Enter your password" required>
      </div>
      <div class="f-group">
        <label>Login As</label>
        <select name="role" required>
          <option value="">— Select Your Role —</option>
          <?php foreach ($roles as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>"><?= ucfirst(htmlspecialchars($r)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn-login">Sign In →</button>
    </form>

    <!-- Demo credentials pulled dynamically from DB -->
    <?php
    $demoUsers = [];
    try {
        $demoUsers = getDB()->query("
            SELECT u.email, r.name AS role FROM users u JOIN roles r ON u.role_id=r.id
            WHERE u.is_active=TRUE ORDER BY r.id LIMIT 5
        ")->fetchAll();
    } catch (Throwable $e) {}
    ?>
    <?php if (!empty($demoUsers)): ?>
    <div class="divider">Demo Credentials</div>
    <div class="demo-box">
      <p>🔑 Default password: <code>password123</code></p>
      <?php foreach ($demoUsers as $d): ?>
      <div class="demo-row">
        <span class="demo-role"><?= htmlspecialchars($d['role']) ?></span>
        <span class="demo-pass"><?= htmlspecialchars($d['email']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
