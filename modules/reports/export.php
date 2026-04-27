<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin', 'receptionist');
$user = currentUser();
$db   = getDB();

// Handle CSV download
if (isset($_GET['download'])) {
    $from   = $_GET['from']   ?? date('Y-m-01');
    $to     = $_GET['to']     ?? date('Y-m-d');
    $type   = $_GET['type']   ?? 'visits';

    if ($type === 'visits') {
        $rows = $db->prepare("
            SELECT
                v.id AS visit_id,
                vi.full_name AS visitor_name,
                vi.phone,
                vi.purpose,
                vi.id_proof_type,
                vi.vehicle_number,
                v.flat_number,
                u.name AS host_name,
                v.check_in,
                v.check_out,
                CASE WHEN v.check_out IS NOT NULL
                     THEN ROUND(EXTRACT(EPOCH FROM (v.check_out - v.check_in))/3600::numeric,2)
                     ELSE NULL END AS duration_hours,
                v.status,
                v.overstay_flagged,
                ps.slot_number AS parking_slot,
                g1.name AS guard_in,
                g2.name AS guard_out
            FROM visits v
            JOIN visitors vi ON v.visitor_id=vi.id
            LEFT JOIN users u  ON v.host_id=u.id
            LEFT JOIN users g1 ON v.guard_checkin_id=g1.id
            LEFT JOIN users g2 ON v.guard_checkout_id=g2.id
            LEFT JOIN parking_slots ps ON v.parking_slot_id=ps.id
            WHERE v.check_in::date BETWEEN :from AND :to
            ORDER BY v.check_in DESC
        ");
        $rows->execute([':from'=>$from,':to'=>$to]);
        $headers = ['Visit ID','Visitor Name','Phone','Purpose','ID Type','Vehicle','Flat',
                    'Host Name','Check-In','Check-Out','Duration(hrs)','Status','Overstay',
                    'Parking Slot','Guard In','Guard Out'];
    } elseif ($type === 'appointments') {
        $rows = $db->prepare("
            SELECT a.id, a.visitor_name, a.visitor_phone, u.name AS host_name,
                   a.flat_number, a.expected_date, a.expected_time, a.purpose,
                   a.status, a.notes, cb.name AS created_by, a.created_at
            FROM appointments a
            JOIN users u ON a.host_id=u.id
            LEFT JOIN users cb ON a.created_by=cb.id
            WHERE a.expected_date BETWEEN :from AND :to
            ORDER BY a.expected_date DESC
        ");
        $rows->execute([':from'=>$from,':to'=>$to]);
        $headers = ['ID','Visitor Name','Phone','Host','Flat','Expected Date','Expected Time',
                    'Purpose','Status','Notes','Created By','Created At'];
    } elseif ($type === 'blacklist') {
        $rows = $db->query("
            SELECT vi.id, vi.full_name, vi.phone, vi.blacklist_reason,
                   u.name AS blacklisted_by, vi.blacklisted_at, vi.created_at
            FROM visitors vi LEFT JOIN users u ON vi.blacklisted_by=u.id
            WHERE vi.is_blacklisted=TRUE ORDER BY vi.blacklisted_at DESC
        ");
        $headers = ['ID','Name','Phone','Reason','Blacklisted By','Blacklisted At','Registered At'];
    }

    $s = getSettings();
    $orgName = $s['org_name'] ?? 'ResidentGuard';
    $fname = strtolower(str_replace(' ','_',$orgName)) . "_{$type}_{$from}_to_{$to}.csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows->fetchAll() as $row) {
        fputcsv($out, array_values($row));
    }
    fclose($out);
    exit();
}

// Page load — summary stats for UI
$stats = $db->query("
    SELECT
        COUNT(*) AS total_visits,
        COUNT(*) FILTER(WHERE check_in::date=CURRENT_DATE) AS today_visits,
        COUNT(*) FILTER(WHERE check_in >= DATE_TRUNC('week',NOW())) AS week_visits,
        COUNT(*) FILTER(WHERE check_in >= DATE_TRUNC('month',NOW())) AS month_visits,
        COUNT(*) FILTER(WHERE overstay_flagged=TRUE) AS overstay_total,
        COUNT(DISTINCT visitor_id) AS unique_visitors
    FROM visits
")->fetch();

$aptStats = $db->query("
    SELECT COUNT(*) AS total_apts,
           COUNT(*) FILTER(WHERE status='pending' AND expected_date >= CURRENT_DATE) AS upcoming,
           COUNT(*) FILTER(WHERE expected_date=CURRENT_DATE) AS today_apts
    FROM appointments
")->fetch();

$blacklistCount = $db->query("SELECT COUNT(*) FROM visitors WHERE is_blacklisted=TRUE")->fetchColumn();

// Date range defaults
$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-d');

pageHead('Export Reports');
renderSidebar('export');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Export Reports</h1>
      <p class="page-sub">Download visitor data in CSV format</p>
    </div>
  </header>

  <!-- Summary stats — all dynamic -->
  <div class="stats-grid" style="margin-bottom:24px;">
    <div class="stat-card stat-blue">
      <div class="stat-icon">👥</div>
      <div class="stat-num"><?= number_format($stats['total_visits']) ?></div>
      <div class="stat-label">All-time Visits</div>
    </div>
    <div class="stat-card stat-green">
      <div class="stat-icon">📅</div>
      <div class="stat-num"><?= $stats['today_visits'] ?></div>
      <div class="stat-label">Today's Visits</div>
    </div>
    <div class="stat-card stat-amber">
      <div class="stat-icon">📆</div>
      <div class="stat-num"><?= $stats['month_visits'] ?></div>
      <div class="stat-label">This Month</div>
    </div>
    <div class="stat-card stat-purple">
      <div class="stat-icon">🔄</div>
      <div class="stat-num"><?= number_format($stats['unique_visitors']) ?></div>
      <div class="stat-label">Unique Visitors</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon">⚠️</div>
      <div class="stat-num"><?= $stats['overstay_total'] ?></div>
      <div class="stat-label">Overstay Total</div>
    </div>
    <div class="stat-card stat-dark">
      <div class="stat-icon">🚫</div>
      <div class="stat-num"><?= $blacklistCount ?></div>
      <div class="stat-label">Blacklisted</div>
    </div>
  </div>

  <div class="two-col" style="align-items:start;margin-bottom:20px;">

    <!-- Custom date export -->
    <div class="card">
      <div class="card-header"><h3>📊 Custom Export</h3></div>
      <form method="GET" id="exportForm">
        <input type="hidden" name="download" value="1">
        <div class="form-group">
          <label>Export Type</label>
          <select name="type" required>
            <option value="visits">Visitor Visits Log</option>
            <option value="appointments">Appointments Log</option>
            <option value="blacklist">Blacklist Report</option>
          </select>
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label>From Date</label>
            <input type="date" name="from" value="<?= $defaultFrom ?>">
          </div>
          <div class="form-group">
            <label>To Date</label>
            <input type="date" name="to" value="<?= $defaultTo ?>">
          </div>
        </div>
        <button type="submit" class="btn-primary" style="width:100%;">⬇️ Download CSV</button>
      </form>
    </div>

    <!-- Quick exports -->
    <div class="card">
      <div class="card-header"><h3>⚡ Quick Reports</h3></div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a href="?download=1&type=visits&from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="qa-btn">
          📋 Today's Visitor Report
          <span class="badge badge-muted" style="margin-left:auto;"><?= $stats['today_visits'] ?> records</span>
        </a>
        <a href="?download=1&type=visits&from=<?= date('Y-m-d',strtotime('monday this week')) ?>&to=<?= date('Y-m-d') ?>" class="qa-btn">
          📅 This Week's Visitors
          <span class="badge badge-muted" style="margin-left:auto;"><?= $stats['week_visits'] ?> records</span>
        </a>
        <a href="?download=1&type=visits&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="qa-btn">
          📆 This Month's Report
          <span class="badge badge-muted" style="margin-left:auto;"><?= $stats['month_visits'] ?> records</span>
        </a>
        <a href="?download=1&type=visits&from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>" class="qa-btn">
          📁 Full Year Report
          <span class="badge badge-muted" style="margin-left:auto;"><?= $stats['total_visits'] ?> records</span>
        </a>
        <a href="?download=1&type=appointments&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="qa-btn">
          📅 Appointments This Month
          <span class="badge badge-muted" style="margin-left:auto;"><?= $aptStats['total_apts'] ?> records</span>
        </a>
        <a href="?download=1&type=blacklist&from=2000-01-01&to=<?= date('Y-m-d') ?>" class="qa-btn">
          🚫 Full Blacklist
          <span class="badge badge-danger" style="margin-left:auto;"><?= $blacklistCount ?> records</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Recent visits preview — last 10 from DB -->
  <div class="card">
    <div class="card-header">
      <h3>Recent Visits Preview</h3>
      <a href="<?= rootPath() ?>modules/visitors/history.php" class="link-more">Full history →</a>
    </div>
    <?php
    $preview = $db->query("
        SELECT v.id, vi.full_name, vi.purpose, v.flat_number, v.check_in, v.check_out, v.status,
               u.name AS host_name, v.overstay_flagged
        FROM visits v JOIN visitors vi ON v.visitor_id=vi.id LEFT JOIN users u ON v.host_id=u.id
        ORDER BY v.check_in DESC LIMIT 10
    ")->fetchAll();
    ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>Visitor</th><th>Purpose</th><th>Host/Flat</th><th>Check-In</th><th>Duration</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($preview as $r): ?>
          <tr <?= $r['overstay_flagged'] ? 'class="overstay-tr"' : '' ?>>
            <td style="color:var(--muted);">#<?= $r['id'] ?></td>
            <td><?= htmlspecialchars($r['full_name']) ?></td>
            <td><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['flat_number'] ?? '—') ?> / <?= htmlspecialchars($r['host_name'] ?? '—') ?></td>
            <td><?= date('d M, H:i', strtotime($r['check_in'])) ?></td>
            <td><?= timeDiff($r['check_in'], $r['check_out'] ?: null) ?></td>
            <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($preview)): ?><tr><td colspan="7" class="empty-cell">No visits yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>
</body>
</html>
