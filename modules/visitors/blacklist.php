<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin','receptionist','guard');
$user = currentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'blacklist') {
            $vid = (int)$_POST['visitor_id'];
            $reason = trim($_POST['reason']);
            if (!$reason) throw new RuntimeException('Reason is required.');
            $db->prepare("UPDATE visitors SET is_blacklisted=TRUE,blacklist_reason=:r,blacklisted_by=:b,blacklisted_at=NOW(),status='blacklisted' WHERE id=:id")
               ->execute([':r'=>$reason,':b'=>$user['id'],':id'=>$vid]);
            flash('success',"Visitor blacklisted successfully.");
        } elseif ($action === 'unblacklist') {
            $db->prepare("UPDATE visitors SET is_blacklisted=FALSE,blacklist_reason=NULL,blacklisted_by=NULL,blacklisted_at=NULL,status='active' WHERE id=:id")
               ->execute([':id'=>(int)$_POST['visitor_id']]);
            flash('success','Visitor removed from blacklist.');
        }
    } catch (Throwable $e) { flash('danger','❌ '.$e->getMessage()); }
    header('Location: blacklist.php'); exit();
}

// Search
$search = trim($_GET['q'] ?? '');
$where  = "vi.is_blacklisted=TRUE";
$params = [];
if ($search) {
    $where .= " AND (vi.full_name ILIKE :q OR vi.phone ILIKE :q)";
    $params[':q'] = "%$search%";
}

$blacklisted = $db->prepare("
    SELECT vi.*, u.name AS blacklisted_by_name
    FROM visitors vi LEFT JOIN users u ON vi.blacklisted_by=u.id
    WHERE $where ORDER BY vi.blacklisted_at DESC NULLS LAST
");
$blacklisted->execute($params);
$blacklisted = $blacklisted->fetchAll();

// Active visitors for add-to-blacklist
$active = $db->query("SELECT id,full_name,phone FROM visitors WHERE is_blacklisted=FALSE ORDER BY full_name")->fetchAll();

pageHead('Blacklist');
renderSidebar('blacklist');
?>
<main class="main-content">
  <header class="page-header">
    <div><h1 class="page-title">Blacklist Management</h1>
    <p class="page-sub"><?= count($blacklisted) ?> blacklisted visitors</p></div>
  </header>

  <?= renderFlash() ?>

  <div class="two-col" style="align-items:start;">

    <!-- Add to Blacklist -->
    <div class="card">
      <div class="card-header"><h3>🚫 Add to Blacklist</h3></div>
      <form method="POST">
        <input type="hidden" name="action" value="blacklist">
        <div class="form-group">
          <label>Select Visitor *</label>
          <select name="visitor_id" required>
            <option value="">— Choose Visitor —</option>
            <?php foreach ($active as $v): ?>
            <option value="<?= $v['id'] ?>">
              <?= htmlspecialchars($v['full_name']) ?><?= $v['phone']?' (' . htmlspecialchars($v['phone']) . ')':'' ?>
            </option>
            <?php endforeach; ?>
            <?php if (empty($active)): ?><option disabled>No active visitors found</option><?php endif; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Reason *</label>
          <textarea name="reason" rows="3" required placeholder="Reason for blacklisting..."></textarea>
        </div>
        <button type="submit" class="btn-danger" style="width:100%;padding:11px;">🚫 Blacklist Visitor</button>
      </form>

      <!-- Search blacklist -->
      <hr class="section-divider">
      <form method="GET" style="display:flex;gap:8px;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search blacklisted..." style="flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px;color:var(--text);outline:none;">
        <button type="submit" class="btn-secondary">🔍</button>
        <?php if ($search): ?><a href="blacklist.php" class="btn-secondary">✕</a><?php endif; ?>
      </form>
    </div>

    <!-- Blacklisted List — all from DB -->
    <div class="card">
      <div class="card-header"><h3>Blacklisted Visitors</h3><span class="badge badge-danger"><?= count($blacklisted) ?></span></div>
      <?php if (empty($blacklisted)): ?>
      <p style="text-align:center;color:var(--muted);padding:40px;">
        <?= $search ? 'No results for "' . htmlspecialchars($search) . '"' : 'No blacklisted visitors' ?>
      </p>
      <?php else: foreach ($blacklisted as $b): ?>
      <div style="border:1px solid rgba(224,82,82,.25);border-radius:9px;padding:15px;margin-bottom:10px;background:rgba(224,82,82,.04);">
        <div style="display:flex;justify-content:space-between;align-items:start;gap:8px;margin-bottom:8px;">
          <div style="display:flex;align-items:center;gap:10px;">
            <div class="avatar-placeholder sm" style="background:linear-gradient(135deg,var(--danger),#f87171);">
              <?= strtoupper(substr($b['full_name'],0,1)) ?>
            </div>
            <div>
              <div style="font-weight:600;"><?= htmlspecialchars($b['full_name']) ?></div>
              <div style="font-size:.75rem;color:var(--muted);"><?= htmlspecialchars($b['phone'] ?? '—') ?></div>
            </div>
          </div>
          <?php if (in_array($user['role'],['admin','receptionist'])): ?>
          <form method="POST" onsubmit="return confirm('Remove from blacklist?')">
            <input type="hidden" name="action" value="unblacklist">
            <input type="hidden" name="visitor_id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn-sm" style="color:var(--success);">✓ Unblacklist</button>
          </form>
          <?php endif; ?>
        </div>
        <div style="background:rgba(224,82,82,.08);border-radius:6px;padding:9px;font-size:.82rem;color:var(--danger);margin-bottom:8px;">
          📝 <?= htmlspecialchars($b['blacklist_reason'] ?? '—') ?>
        </div>
        <div style="font-size:.73rem;color:var(--muted);">
          By <?= htmlspecialchars($b['blacklisted_by_name'] ?? 'System') ?>
          <?php if ($b['blacklisted_at']): ?>· <?= date('d M Y, H:i',strtotime($b['blacklisted_at'])) ?><?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</main>
</body>
</html>
