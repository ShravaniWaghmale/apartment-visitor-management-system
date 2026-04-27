<?php
require_once '../../config/db.php';
requireLogin();
$user = currentUser();
$db   = getDB();
$s    = getSettings();

// Purposes from DB settings
$purposes = array_filter(array_map('trim', explode(',', $s['allowed_purposes'] ?? '')));

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    try {
        if ($action === 'create') {
            $hostId = (int)$_POST['host_id'];
            if (!$hostId) throw new RuntimeException('Please select a host/resident.');
            $token = genToken();
            $db->prepare("
                INSERT INTO appointments
                  (visitor_name,visitor_phone,host_id,flat_number,expected_date,expected_time,purpose,qr_token,notes,created_by)
                VALUES (:vn,:vp,:hi,:fn,:ed,:et,:pu,:qt,:no,:cb)
            ")->execute([
                ':vn' => trim($_POST['visitor_name']),
                ':vp' => trim($_POST['visitor_phone']),
                ':hi' => $hostId,
                ':fn' => trim($_POST['flat_number']),
                ':ed' => $_POST['expected_date'],
                ':et' => $_POST['expected_time'],
                ':pu' => trim($_POST['purpose']),
                ':qt' => $token,
                ':no' => trim($_POST['notes'] ?? ''),
                ':cb' => $user['id'],
            ]);
            flash('success', "✅ Appointment created! QR Token generated.");
        } elseif ($action === 'cancel') {
            $aptId = (int)$_POST['apt_id'];
            $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=:id")
               ->execute([':id' => $aptId]);
            flash('success', 'Appointment cancelled.');
        }
    } catch (Throwable $e) {
        flash('danger', '❌ ' . $e->getMessage());
    }
    header('Location: appointments.php'); exit();
}

// Filters
$filterDate   = $_GET['date']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterHost   = (int)($_GET['host'] ?? 0);
$page         = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];

// Hosts only see their own
if ($user['role'] === 'host') {
    $where[] = 'a.host_id=:myid';
    $params[':myid'] = $user['id'];
} elseif ($filterHost) {
    $where[] = 'a.host_id=:host';
    $params[':host'] = $filterHost;
}
if ($filterDate)   { $where[] = 'a.expected_date=:date';     $params[':date']   = $filterDate; }
if ($filterStatus) { $where[] = 'a.status=:status';           $params[':status'] = $filterStatus; }

$whereSQL = implode(' AND ', $where);

$baseSql = "
    SELECT a.*, u.name AS host_name, u.flat_number AS host_flat,
           cb.name AS created_by_name
    FROM appointments a
    JOIN users u ON a.host_id=u.id
    LEFT JOIN users cb ON a.created_by=cb.id
    WHERE $whereSQL
    ORDER BY a.expected_date DESC, a.expected_time DESC
";

$result = paginateQuery($db, $baseSql, $params, $page, 12);
$apts   = $result['data'];
$total  = $result['total'];
$pages  = $result['pages'];

// Hosts for dropdowns
$hosts = $db->query("SELECT id,name,flat_number FROM users WHERE role_id=4 AND is_active=TRUE ORDER BY flat_number")->fetchAll();

// Status counts
$counts = $db->query("
    SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status
")->fetchAll();
$cntMap = array_column($counts, 'cnt', 'status');

pageHead('Appointments', '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>');
renderSidebar('appointments');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Appointments</h1>
      <p class="page-sub">Pre-register expected visitors · <?= $total ?> total records</p>
    </div>
    <div class="header-actions">
      <button class="btn-primary" onclick="document.getElementById('newAptPanel').classList.toggle('hidden')">+ New Appointment</button>
    </div>
  </header>

  <?= renderFlash() ?>

  <!-- Status pills -->
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
    <a href="?" class="tag">All <strong><?= array_sum($cntMap) ?></strong></a>
    <a href="?status=pending"   class="tag" style="color:var(--warning);">Pending   <strong><?= $cntMap['pending']   ?? 0 ?></strong></a>
    <a href="?status=arrived"   class="tag" style="color:var(--success);">Arrived   <strong><?= $cntMap['arrived']   ?? 0 ?></strong></a>
    <a href="?status=cancelled" class="tag" style="color:var(--danger);">Cancelled <strong><?= $cntMap['cancelled'] ?? 0 ?></strong></a>
    <a href="?status=expired"   class="tag" style="color:var(--muted);">Expired   <strong><?= $cntMap['expired']   ?? 0 ?></strong></a>
    <a href="?date=<?= date('Y-m-d') ?>" class="tag" style="color:var(--accent);">Today <strong><?= $cntMap['pending'] ?? 0 ?></strong></a>
  </div>

  <!-- New Appointment Form (toggle) -->
  <div id="newAptPanel" class="card hidden" style="margin-bottom:20px;">
    <div class="card-header">
      <h3>📅 Schedule New Appointment</h3>
      <button class="btn-sm" onclick="document.getElementById('newAptPanel').classList.add('hidden')">✕ Close</button>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="create">
      <div class="form-grid">
        <div class="form-group">
          <label>Visitor Name *</label>
          <input type="text" name="visitor_name" required placeholder="Full name of expected visitor">
        </div>
        <div class="form-group">
          <label>Visitor Phone</label>
          <input type="tel" name="visitor_phone" placeholder="+91 9876543210">
        </div>
        <!-- Host dropdown — from DB -->
        <div class="form-group">
          <label>Host / Resident *</label>
          <select name="host_id" required
            onchange="document.getElementById('aptFlat').value=this.options[this.selectedIndex].dataset.flat||''">
            <option value="">— Select Resident —</option>
            <?php foreach ($hosts as $h): ?>
            <option value="<?= $h['id'] ?>" data-flat="<?= htmlspecialchars($h['flat_number']) ?>">
              <?= htmlspecialchars($h['name']) ?> — Flat <?= htmlspecialchars($h['flat_number']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Flat Number</label>
          <input type="text" name="flat_number" id="aptFlat" placeholder="A-201">
        </div>
        <div class="form-group">
          <label>Expected Date *</label>
          <input type="date" name="expected_date" required min="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>Expected Time *</label>
          <input type="time" name="expected_time" required>
        </div>
        <!-- Purpose from DB settings -->
        <div class="form-group">
          <label>Purpose</label>
          <select name="purpose">
            <option value="">— Select —</option>
            <?php foreach ($purposes as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Notes</label>
          <input type="text" name="notes" placeholder="Any special instructions">
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn-primary">📅 Create Appointment</button>
        <button type="button" class="btn-secondary"
          onclick="document.getElementById('newAptPanel').classList.add('hidden')">Cancel</button>
      </div>
    </form>
  </div>

  <!-- Filters -->
  <div class="card" style="margin-bottom:18px;">
    <form method="GET" class="filter-bar">
      <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
        <label>Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($filterDate) ?>">
      </div>
      <div class="form-group" style="flex:1;min-width:140px;margin-bottom:0;">
        <label>Status</label>
        <select name="status">
          <option value="">All</option>
          <option value="pending"   <?= $filterStatus==='pending'   ?'selected':'' ?>>Pending</option>
          <option value="arrived"   <?= $filterStatus==='arrived'   ?'selected':'' ?>>Arrived</option>
          <option value="cancelled" <?= $filterStatus==='cancelled' ?'selected':'' ?>>Cancelled</option>
          <option value="expired"   <?= $filterStatus==='expired'   ?'selected':'' ?>>Expired</option>
        </select>
      </div>
      <?php if ($user['role'] !== 'host'): ?>
      <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0;">
        <label>Resident</label>
        <select name="host">
          <option value="">All Residents</option>
          <?php foreach ($hosts as $h): ?>
          <option value="<?= $h['id'] ?>" <?= $filterHost==$h['id']?'selected':'' ?>>
            <?= htmlspecialchars($h['name']) ?> (<?= htmlspecialchars($h['flat_number']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="align-self:flex-end;display:flex;gap:8px;">
        <button type="submit" class="btn-primary">🔍 Filter</button>
        <a href="appointments.php" class="btn-secondary">Reset</a>
      </div>
    </form>
  </div>

  <!-- Appointments grid — all from DB -->
  <?php if (empty($apts)): ?>
  <div class="card"><p class="empty-cell">No appointments found for the selected filters.</p></div>
  <?php else: ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:20px;">
    <?php foreach ($apts as $a):
      $isPast   = strtotime($a['expected_date']) < strtotime('today');
      $isToday  = $a['expected_date'] === date('Y-m-d');
    ?>
    <div class="card" style="<?= $isToday ? 'border-color:rgba(232,168,56,.4);' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="avatar-placeholder sm"><?= strtoupper(substr($a['visitor_name'],0,1)) ?></div>
          <div>
            <div style="font-weight:600;font-size:.93rem;"><?= htmlspecialchars($a['visitor_name']) ?></div>
            <div style="font-size:.75rem;color:var(--muted);"><?= htmlspecialchars($a['visitor_phone'] ?? '—') ?></div>
          </div>
        </div>
        <span class="status-badge status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.8rem;margin-bottom:12px;">
        <div><span style="color:var(--muted);">📍 Host:</span> <?= htmlspecialchars($a['host_name']) ?></div>
        <div><span style="color:var(--muted);">🏠 Flat:</span> <?= htmlspecialchars($a['flat_number'] ?? '—') ?></div>
        <div><span style="color:var(--muted);">📅 Date:</span>
          <span style="color:<?= $isToday?'var(--accent)':($isPast?'var(--muted)':'var(--text)') ?>">
            <?= date('d M Y', strtotime($a['expected_date'])) ?><?= $isToday ? ' (Today)' : '' ?>
          </span>
        </div>
        <div><span style="color:var(--muted);">🕐 Time:</span> <?= date('H:i', strtotime($a['expected_time'])) ?></div>
        <?php if ($a['purpose']): ?>
        <div style="grid-column:1/-1;"><span style="color:var(--muted);">🎯 Purpose:</span> <?= htmlspecialchars($a['purpose']) ?></div>
        <?php endif; ?>
        <?php if ($a['notes']): ?>
        <div style="grid-column:1/-1;"><span style="color:var(--muted);">📝 Notes:</span> <?= htmlspecialchars($a['notes']) ?></div>
        <?php endif; ?>
      </div>

      <!-- QR Code — generated client-side from DB token -->
      <div style="display:flex;align-items:center;gap:12px;padding:10px;background:var(--surface2);border-radius:8px;margin-bottom:10px;">
        <div id="qr_<?= $a['id'] ?>" style="background:#fff;padding:4px;border-radius:4px;flex-shrink:0;"></div>
        <div>
          <div style="font-size:.7rem;color:var(--muted);margin-bottom:2px;">QR Token</div>
          <code style="font-size:.65rem;word-break:break-all;color:var(--text);"><?= substr($a['qr_token'],0,28) ?>...</code>
        </div>
      </div>
      <script>
      new QRCode(document.getElementById("qr_<?= $a['id'] ?>"), {
        text:"<?= addslashes($a['qr_token']) ?>",width:52,height:52,
        colorDark:"#000",colorLight:"#fff",correctLevel:QRCode.CorrectLevel.L
      });
      </script>

      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php if ($a['status'] === 'pending'): ?>
        <!-- Quick check-in using appointment QR -->
        <a href="<?= rootPath() ?>modules/visitors/register.php?from_apt=<?= $a['id'] ?>" class="btn-sm">➕ Register Now</a>
        <?php if (in_array($user['role'],['admin','receptionist','host'])): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="cancel">
          <input type="hidden" name="apt_id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn-sm" style="color:var(--danger);"
            onclick="return confirm('Cancel this appointment?')">✕ Cancel</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        <div style="margin-left:auto;font-size:.72rem;color:var(--muted);">
          By <?= htmlspecialchars($a['created_by_name'] ?? '—') ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?= pagerHtml($pages, $page, array_filter(['date'=>$filterDate,'status'=>$filterStatus,'host'=>$filterHost?:null])) ?>
  <?php endif; ?>
</main>

<style>
.hidden { display:none!important; }
</style>
</body>
</html>
