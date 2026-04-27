<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin');
$user = currentUser();
$db   = getDB();

$uploadDir = realpath(__DIR__ . '/../../assets/uploads') . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle logo upload
        $logo = $_POST['current_logo'] ?? null;
        if (!empty($_FILES['logo']['name'])) {
            $logo = uploadFile('logo', $uploadDir, ['jpg','jpeg','png','svg','webp']);
        }

        $db->prepare("
            UPDATE settings SET
                org_name           = :org_name,
                org_address        = :org_address,
                org_phone          = :org_phone,
                org_email          = :org_email,
                logo               = :logo,
                max_visit_hours    = :max_visit_hours,
                allowed_purposes   = :allowed_purposes,
                allowed_id_types   = :allowed_id_types,
                smtp_host          = :smtp_host,
                smtp_port          = :smtp_port,
                smtp_user          = :smtp_user,
                smtp_pass          = :smtp_pass,
                smtp_from_name     = :smtp_from_name,
                allow_self_checkin = :allow_self_checkin,
                require_photo      = :require_photo,
                require_id_proof   = :require_id_proof,
                updated_at         = NOW()
        ")->execute([
            ':org_name'          => trim($_POST['org_name']),
            ':org_address'       => trim($_POST['org_address']),
            ':org_phone'         => trim($_POST['org_phone']),
            ':org_email'         => trim($_POST['org_email']),
            ':logo'              => $logo,
            ':max_visit_hours'   => (int)$_POST['max_visit_hours'],
            ':allowed_purposes'  => trim($_POST['allowed_purposes']),
            ':allowed_id_types'  => trim($_POST['allowed_id_types']),
            ':smtp_host'         => trim($_POST['smtp_host']),
            ':smtp_port'         => (int)($_POST['smtp_port'] ?: 587),
            ':smtp_user'         => trim($_POST['smtp_user']),
            ':smtp_pass'         => $_POST['smtp_pass'] ?: null,
            ':smtp_from_name'    => trim($_POST['smtp_from_name']),
            ':allow_self_checkin'=> !empty($_POST['allow_self_checkin']),
            ':require_photo'     => !empty($_POST['require_photo']),
            ':require_id_proof'  => !empty($_POST['require_id_proof']),
        ]);
        flash('success', '✅ Settings saved successfully.');
        header('Location: settings.php'); exit();
    } catch (Throwable $e) {
        flash('danger', '❌ ' . $e->getMessage());
    }
}

$s = $db->query("SELECT * FROM settings LIMIT 1")->fetch();

// Dynamic stats for info panel
$totalVisits = $db->query("SELECT COUNT(*) FROM visits")->fetchColumn();
$totalUsers  = $db->query("SELECT COUNT(*) FROM users WHERE is_active=TRUE")->fetchColumn();
$totalSlots  = $db->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();

pageHead('Settings');
renderSidebar('settings');
?>
<main class="main-content">
  <header class="page-header">
    <div><h1 class="page-title">Settings & Configuration</h1>
    <p class="page-sub">Configure your society's visitor management system</p></div>
  </header>

  <?= renderFlash() ?>

  <form method="POST" enctype="multipart/form-data">
    <div class="two-col" style="align-items:start;gap:20px;">

      <!-- Organisation Info -->
      <div style="display:flex;flex-direction:column;gap:20px;">
        <div class="card">
          <div class="card-header"><h3>🏘️ Organisation Details</h3></div>
          <div class="form-group">
            <label>Organisation / Society Name *</label>
            <input type="text" name="org_name" required value="<?= htmlspecialchars($s['org_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Address</label>
            <textarea name="org_address" rows="2"><?= htmlspecialchars($s['org_address'] ?? '') ?></textarea>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label>Contact Phone</label>
              <input type="tel" name="org_phone" value="<?= htmlspecialchars($s['org_phone'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Contact Email</label>
              <input type="email" name="org_email" value="<?= htmlspecialchars($s['org_email'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Society Logo</label>
            <?php if (!empty($s['logo'])): ?>
            <div style="margin-bottom:10px;">
              <img src="<?= rootPath().'assets/uploads/'.htmlspecialchars($s['logo']) ?>"
                   style="height:50px;border-radius:6px;object-fit:contain;background:var(--surface2);padding:6px;">
            </div>
            <input type="hidden" name="current_logo" value="<?= htmlspecialchars($s['logo']) ?>">
            <?php endif; ?>
            <input type="file" name="logo" accept="image/*">
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3>⚙️ Visit Rules</h3></div>
          <div class="form-group">
            <label>Max Visit Duration (hours)</label>
            <input type="number" name="max_visit_hours" min="1" max="48" value="<?= (int)($s['max_visit_hours'] ?? 4) ?>">
            <span style="font-size:.75rem;color:var(--muted);">Visitors inside longer than this will be flagged as overstay.</span>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="require_photo" style="width:16px;height:16px;" <?= !empty($s['require_photo'])?'checked':'' ?>>
              Require visitor photo at check-in
            </label>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="require_id_proof" style="width:16px;height:16px;" <?= !empty($s['require_id_proof'])?'checked':'' ?>>
              Require ID proof upload at check-in
            </label>
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;text-transform:none;">
              <input type="checkbox" name="allow_self_checkin" style="width:16px;height:16px;" <?= !empty($s['allow_self_checkin'])?'checked':'' ?>>
              Allow kiosk / self check-in mode
            </label>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3>📊 System Stats</h3></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="background:var(--surface2);border-radius:8px;padding:14px;text-align:center;">
              <div style="font-size:1.6rem;font-weight:700;color:var(--accent);"><?= $totalVisits ?></div>
              <div style="font-size:.75rem;color:var(--muted);">Total Visits</div>
            </div>
            <div style="background:var(--surface2);border-radius:8px;padding:14px;text-align:center;">
              <div style="font-size:1.6rem;font-weight:700;color:var(--info);"><?= $totalUsers ?></div>
              <div style="font-size:.75rem;color:var(--muted);">Active Users</div>
            </div>
            <div style="background:var(--surface2);border-radius:8px;padding:14px;text-align:center;">
              <div style="font-size:1.6rem;font-weight:700;color:var(--success);"><?= $totalSlots ?></div>
              <div style="font-size:.75rem;color:var(--muted);">Parking Slots</div>
            </div>
            <div style="background:var(--surface2);border-radius:8px;padding:14px;text-align:center;">
              <div style="font-size:1.6rem;font-weight:700;color:var(--warning);"><?= (int)($s['max_visit_hours']??4) ?>h</div>
              <div style="font-size:.75rem;color:var(--muted);">Max Visit Duration</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right: Dynamic Dropdowns + SMTP -->
      <div style="display:flex;flex-direction:column;gap:20px;">
        <div class="card">
          <div class="card-header"><h3>📋 Dynamic Dropdown Values</h3></div>
          <div class="form-group">
            <label>Allowed Visit Purposes</label>
            <textarea name="allowed_purposes" rows="6"><?= htmlspecialchars($s['allowed_purposes'] ?? '') ?></textarea>
            <span style="font-size:.73rem;color:var(--muted);">Comma-separated. These appear in the visitor registration dropdown.</span>
          </div>
          <div class="form-group">
            <label>ID Proof Types</label>
            <textarea name="allowed_id_types" rows="4"><?= htmlspecialchars($s['allowed_id_types'] ?? '') ?></textarea>
            <span style="font-size:.73rem;color:var(--muted);">Comma-separated. Shown in the ID proof type dropdown.</span>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3>📧 Email / SMTP Configuration</h3>
          <span class="badge badge-muted">For host notifications</span></div>
          <div class="form-grid">
            <div class="form-group">
              <label>SMTP Host</label>
              <input type="text" name="smtp_host" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($s['smtp_host'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>SMTP Port</label>
              <input type="number" name="smtp_port" value="<?= (int)($s['smtp_port'] ?? 587) ?>">
            </div>
            <div class="form-group">
              <label>SMTP Username</label>
              <input type="email" name="smtp_user" placeholder="your@email.com" value="<?= htmlspecialchars($s['smtp_user'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>SMTP Password</label>
              <input type="password" name="smtp_pass" placeholder="Leave blank to keep current">
            </div>
            <div class="form-group form-full">
              <label>From Name</label>
              <input type="text" name="smtp_from_name" placeholder="ResidentGuard Notifications" value="<?= htmlspecialchars($s['smtp_from_name'] ?? '') ?>">
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn-primary" style="padding:13px 32px;">💾 Save Settings</button>
        </div>
      </div>
    </div>
  </form>
</main>
</body>
</html>
