<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin');
$user = currentUser();
$db   = getDB();
$uploadDir = realpath(__DIR__ . '/../../assets/uploads') . '/';

// ── Actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add') {
            $hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
            $db->prepare("INSERT INTO users (name,email,password,role_id,flat_number,phone) VALUES (:n,:e,:p,:r,:f,:ph)")
               ->execute([':n'=>trim($_POST['name']),':e'=>strtolower(trim($_POST['email'])),
                          ':p'=>$hash,':r'=>(int)$_POST['role_id'],
                          ':f'=>trim($_POST['flat_number']),':ph'=>trim($_POST['phone'])]);
            flash('success','✅ User created successfully.');
        } elseif ($action === 'toggle') {
            $uid = (int)$_POST['user_id'];
            if ($uid !== 1) { // never deactivate super admin
                $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=:id")->execute([':id'=>$uid]);
                flash('success','User status toggled.');
            }
        } elseif ($action === 'reset_password') {
            $uid = (int)$_POST['user_id'];
            $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            $db->prepare("UPDATE users SET password=:p WHERE id=:id")->execute([':p'=>$hash,':id'=>$uid]);
            flash('success','✅ Password reset successfully.');
        } elseif ($action === 'delete') {
            $uid = (int)$_POST['user_id'];
            if ($uid !== $user['id'] && $uid !== 1) {
                $db->prepare("DELETE FROM users WHERE id=:id")->execute([':id'=>$uid]);
                flash('success','User deleted.');
            } else {
                flash('danger','Cannot delete your own account or the primary admin.');
            }
        }
    } catch (Throwable $e) {
        flash('danger', '❌ ' . $e->getMessage());
    }
    header('Location: manage_users.php'); exit();
}

// ── Load all data from DB ─────────────────────────────────────
$roles     = $db->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$users     = $db->query("
    SELECT u.*, r.name AS role_name,
           (SELECT COUNT(*) FROM visits v WHERE v.host_id=u.id) AS visit_count
    FROM users u JOIN roles r ON u.role_id=r.id
    ORDER BY r.id, u.name
")->fetchAll();

// Stats per role
$roleCounts = $db->query("
    SELECT r.name, COUNT(u.id) AS cnt
    FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.is_active=TRUE
    GROUP BY r.name,r.id ORDER BY r.id
")->fetchAll();

pageHead('User Management');
renderSidebar('manage_users');
?>
<main class="main-content">
  <header class="page-header">
    <div><h1 class="page-title">User Management</h1>
    <p class="page-sub"><?= count($users) ?> users · <?= count(array_filter($users,fn($u)=>$u['is_active'])) ?> active</p></div>
  </header>

  <?= renderFlash() ?>

  <!-- Role counts — dynamic from DB -->
  <div class="stats-grid" style="margin-bottom:20px;">
    <?php foreach ($roleCounts as $rc): ?>
    <div class="stat-card stat-blue">
      <div class="stat-icon">👤</div>
      <div class="stat-num"><?= $rc['cnt'] ?></div>
      <div class="stat-label"><?= ucfirst(htmlspecialchars($rc['name'])) ?>s</div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="two-col" style="align-items:start;">

    <!-- Add User Form -->
    <div class="card">
      <div class="card-header"><h3>➕ Add New User</h3></div>
      <form method="POST">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
          <label>Full Name *</label>
          <input type="text" name="name" required placeholder="Full name">
        </div>
        <div class="form-group">
          <label>Email Address *</label>
          <input type="email" name="email" required placeholder="user@society.com">
        </div>
        <div class="form-group">
          <label>Password *</label>
          <input type="password" name="password" required placeholder="Min 8 characters" minlength="8">
        </div>
        <!-- Roles loaded dynamically from DB -->
        <div class="form-group">
          <label>Role *</label>
          <select name="role_id" required>
            <option value="">— Select Role —</option>
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r['id'] ?>"><?= ucfirst(htmlspecialchars($r['name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>Flat Number</label>
            <input type="text" name="flat_number" placeholder="A-201">
          </div>
          <div class="form-group">
            <label>Phone</label>
            <input type="tel" name="phone" placeholder="+91 9876543210">
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-primary">➕ Create User</button>
        </div>
      </form>
    </div>

    <!-- Users List -->
    <div class="card">
      <div class="card-header"><h3>All Users</h3></div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>User</th><th>Role</th><th>Flat</th><th>Visits</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <div class="visitor-cell">
                  <div class="avatar-placeholder sm"><?= strtoupper(substr($u['name'],0,1)) ?></div>
                  <div>
                    <div style="font-weight:500;"><?= htmlspecialchars($u['name']) ?></div>
                    <div style="font-size:.73rem;color:var(--muted);"><?= htmlspecialchars($u['email']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge badge-info"><?= ucfirst(htmlspecialchars($u['role_name'])) ?></span></td>
              <td><?= htmlspecialchars($u['flat_number'] ?: '—') ?></td>
              <td><?= $u['visit_count'] ?></td>
              <td>
                <span class="status-badge <?= $u['is_active']?'status-active':'status-cancelled' ?>">
                  <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td>
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                  <!-- Toggle active -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn-sm"
                      title="<?= $u['is_active']?'Deactivate':'Activate' ?>"
                      <?= $u['id']===1?'disabled':'' ?>>
                      <?= $u['is_active'] ? '🔒' : '🔓' ?>
                    </button>
                  </form>
                  <!-- Reset password -->
                  <button class="btn-sm" onclick="showReset(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>')">🔑</button>
                  <!-- Delete -->
                  <?php if ($u['id'] !== $user['id'] && $u['id'] !== 1): ?>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($u['name'])) ?>?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn-sm" style="color:var(--danger);">🗑️</button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<!-- Reset Password Modal -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:300;align-items:center;justify-content:center;">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:28px;width:380px;max-width:90vw;">
    <h3 style="margin-bottom:16px;">🔑 Reset Password</h3>
    <p style="color:var(--muted);font-size:.85rem;margin-bottom:18px;">Resetting password for: <strong id="resetName"></strong></p>
    <form method="POST">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId">
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_password" required minlength="8" placeholder="Min 8 characters">
      </div>
      <div style="display:flex;gap:10px;margin-top:14px;">
        <button type="submit" class="btn-primary">Save</button>
        <button type="button" class="btn-secondary" onclick="closeReset()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function showReset(id, name) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetName').textContent = name;
  document.getElementById('resetModal').style.display = 'flex';
}
function closeReset() {
  document.getElementById('resetModal').style.display = 'none';
}
</script>
</body>
</html>
