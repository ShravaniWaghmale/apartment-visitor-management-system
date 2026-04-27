<?php
require_once 'config/db.php';
requireLogin();
checkOverstays();
$user = currentUser();
$db   = getDB();
$s    = getSettings();

// ── All stats pulled dynamically from DB ──────────────────────
$stats = $db->query("
    SELECT
        COUNT(*) FILTER (WHERE status='inside')                   AS currently_inside,
        COUNT(*) FILTER (WHERE check_in::date = CURRENT_DATE)     AS today_total,
        COUNT(*) FILTER (WHERE status='inside' AND overstay_flagged=TRUE) AS overstay,
        COUNT(*) FILTER (WHERE check_out IS NOT NULL AND check_out::date=CURRENT_DATE) AS checked_out_today
    FROM visits
")->fetch();

$expectedToday = $db->query("
    SELECT COUNT(*) FROM appointments
    WHERE expected_date=CURRENT_DATE AND status='pending'
")->fetchColumn();

$parkingOccupied = $db->query("SELECT COUNT(*) FROM parking_slots WHERE is_occupied=TRUE")->fetchColumn();
$parkingTotal    = $db->query("SELECT COUNT(*) FROM parking_slots")->fetchColumn();
$blacklisted     = $db->query("SELECT COUNT(*) FROM visitors WHERE is_blacklisted=TRUE")->fetchColumn();
$totalVisitors   = $db->query("SELECT COUNT(*) FROM visitors")->fetchColumn();

// ── Unread notifications ──────────────────────────────────────
$notifs = [];
if ($user['role'] !== 'guard') {
    $nStmt = $db->prepare("
        SELECT * FROM notifications WHERE user_id=:uid AND is_read=FALSE
        ORDER BY created_at DESC LIMIT 8
    ");
    $nStmt->execute([':uid' => $user['id']]);
    $notifs = $nStmt->fetchAll();
}
$unreadCount = count($notifs);

// ── Recent 8 visits ────────────────────────────────────────────
$recent = $db->query("
    SELECT v.id, vi.full_name, vi.photo, vi.purpose,
           v.flat_number, v.check_in, v.check_out, v.status, v.overstay_flagged,
           u.name AS host_name
    FROM visits v
    JOIN visitors vi ON v.visitor_id=vi.id
    LEFT JOIN users u ON v.host_id=u.id
    ORDER BY v.check_in DESC LIMIT 8
")->fetchAll();

// ── Currently inside list ──────────────────────────────────────
$inside = $db->query("
    SELECT vi.full_name, vi.phone, v.flat_number, v.check_in, v.id AS visit_id,
           v.overstay_flagged, u.name AS host_name
    FROM visits v
    JOIN visitors vi ON v.visitor_id=vi.id
    LEFT JOIN users u ON v.host_id=u.id
    WHERE v.status='inside'
    ORDER BY v.overstay_flagged DESC, v.check_in ASC
")->fetchAll();

// ── Today's appointments ──────────────────────────────────────
$todayApts = $db->query("
    SELECT a.*, u.name AS host_name
    FROM appointments a JOIN users u ON a.host_id=u.id
    WHERE a.expected_date=CURRENT_DATE
    ORDER BY a.expected_time
")->fetchAll();

// ── Weekly chart — dynamic last 7 days ───────────────────────
$weekly = $db->query("
    SELECT TO_CHAR(d::date,'Dy') AS day_label,
           COALESCE(c.cnt,0)     AS cnt
    FROM generate_series(NOW()-INTERVAL '6 days', NOW(), '1 day') d
    LEFT JOIN (
        SELECT check_in::date AS dt, COUNT(*) AS cnt
        FROM visits GROUP BY check_in::date
    ) c ON c.dt = d::date
    ORDER BY d
")->fetchAll();

// ── Purpose distribution this month ──────────────────────────
$purposes = $db->query("
    SELECT vi.purpose, COUNT(*) AS cnt
    FROM visits v JOIN visitors vi ON v.visitor_id=vi.id
    WHERE v.check_in >= DATE_TRUNC('month',NOW())
    GROUP BY vi.purpose ORDER BY cnt DESC LIMIT 6
")->fetchAll();

// ── Role-based greeting ───────────────────────────────────────
$hour = (int)date('H');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $user['name'])[0];

pageHead('Dashboard', '<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>');
renderSidebar('dashboard');
?>
<main class="main-content">

  <header class="page-header">
    <div>
      <h1 class="page-title">Dashboard</h1>
      <p class="page-sub"><?= $greet ?>, <?= htmlspecialchars($firstName) ?> 👋 — <?= date('l, d F Y') ?></p>
    </div>
    <div class="header-actions">
      <div class="notif-btn" onclick="toggleNotif()" id="notifToggle">
        🔔<?php if ($unreadCount > 0): ?><span class="notif-badge"><?= $unreadCount ?></span><?php endif; ?>
      </div>
      <?php if (in_array($user['role'], ['admin','receptionist','guard'])): ?>
      <a href="modules/visitors/register.php" class="btn-primary">+ Register Visitor</a>
      <?php endif; ?>
    </div>
  </header>

  <?= renderFlash() ?>

  <!-- STAT CARDS — all from DB -->
  <div class="stats-grid">
    <div class="stat-card stat-green">
      <div class="stat-icon">🏠</div>
      <div class="stat-num"><?= (int)$stats['currently_inside'] ?></div>
      <div class="stat-label">Currently Inside</div>
    </div>
    <div class="stat-card stat-blue">
      <div class="stat-icon">👥</div>
      <div class="stat-num"><?= (int)$stats['today_total'] ?></div>
      <div class="stat-label">Visitors Today</div>
    </div>
    <div class="stat-card stat-amber">
      <div class="stat-icon">📅</div>
      <div class="stat-num"><?= (int)$expectedToday ?></div>
      <div class="stat-label">Expected Today</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon">⚠️</div>
      <div class="stat-num"><?= (int)$stats['overstay'] ?></div>
      <div class="stat-label">Overstay Alerts</div>
    </div>
    <div class="stat-card stat-purple">
      <div class="stat-icon">🚗</div>
      <div class="stat-num"><?= (int)$parkingOccupied ?>/<?= (int)$parkingTotal ?></div>
      <div class="stat-label">Parking Used</div>
    </div>
    <div class="stat-card stat-dark">
      <div class="stat-icon">🚫</div>
      <div class="stat-num"><?= (int)$blacklisted ?></div>
      <div class="stat-label">Blacklisted</div>
    </div>
  </div>

  <!-- CHARTS ROW -->
  <div class="two-col" style="margin-bottom:20px;">
    <div class="card">
      <div class="card-header">
        <h3>Visitor Traffic — Last 7 Days</h3>
        <span class="badge badge-muted">Daily</span>
      </div>
      <canvas id="weeklyChart" height="200"></canvas>
    </div>
    <div class="card">
      <div class="card-header">
        <h3>Visit Purposes — This Month</h3>
        <span class="badge badge-muted"><?= date('F') ?></span>
      </div>
      <?php if (!empty($purposes)): ?>
      <canvas id="purposeChart" height="200"></canvas>
      <?php else: ?>
      <div style="text-align:center;padding:40px;color:var(--muted);">No data yet this month</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CURRENTLY INSIDE + TODAY'S APPOINTMENTS -->
  <div class="two-col" style="margin-bottom:20px;">
    <!-- Inside now -->
    <div class="card">
      <div class="card-header">
        <h3>🏠 Currently Inside (<?= count($inside) ?>)</h3>
        <a href="modules/visitors/checkin.php" class="link-more">Check-out →</a>
      </div>
      <div style="max-height:280px;overflow-y:auto;">
        <?php if (empty($inside)): ?>
        <p style="text-align:center;color:var(--muted);padding:30px;">No visitors inside</p>
        <?php else: foreach ($inside as $i):
          $dur = timeDiff($i['check_in']);
        ?>
        <div class="inside-row <?= $i['overstay_flagged'] ? 'overstay-row' : '' ?>">
          <div class="avatar-placeholder sm"><?= strtoupper(substr($i['full_name'],0,1)) ?></div>
          <div style="flex:1;">
            <div class="row-name"><?= htmlspecialchars($i['full_name']) ?>
              <?php if ($i['overstay_flagged']): ?><span class="badge badge-danger ml-4">Overstay</span><?php endif; ?>
            </div>
            <div class="row-sub">Flat <?= htmlspecialchars($i['flat_number']) ?> · <?= $dur ?></div>
          </div>
          <a href="modules/visitors/checkin.php?checkout_visit=<?= $i['visit_id'] ?>" class="btn-sm">Out</a>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Today's appointments -->
    <div class="card">
      <div class="card-header">
        <h3>📅 Today's Appointments (<?= count($todayApts) ?>)</h3>
        <a href="modules/preregistration/appointments.php" class="link-more">View all →</a>
      </div>
      <div style="max-height:280px;overflow-y:auto;">
        <?php if (empty($todayApts)): ?>
        <p style="text-align:center;color:var(--muted);padding:30px;">No appointments today</p>
        <?php else: foreach ($todayApts as $a): ?>
        <div class="inside-row">
          <div class="avatar-placeholder sm"><?= strtoupper(substr($a['visitor_name'],0,1)) ?></div>
          <div style="flex:1;">
            <div class="row-name"><?= htmlspecialchars($a['visitor_name']) ?></div>
            <div class="row-sub">
              <?= date('H:i', strtotime($a['expected_time'])) ?> · <?= htmlspecialchars($a['host_name']) ?>
              · Flat <?= htmlspecialchars($a['flat_number']) ?>
            </div>
          </div>
          <span class="status-badge status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- RECENT VISITS TABLE -->
  <div class="card">
    <div class="card-header">
      <h3>Recent Visits</h3>
      <a href="modules/visitors/history.php" class="link-more">View all →</a>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Visitor</th><th>Purpose</th><th>Flat / Host</th>
            <th>Check-In</th><th>Duration</th><th>Status</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent)): ?>
          <tr><td colspan="7" class="empty-cell">No visits recorded yet.</td></tr>
          <?php else: foreach ($recent as $r): ?>
          <tr <?= $r['overstay_flagged'] ? 'class="overstay-tr"' : '' ?>>
            <td>
              <div class="visitor-cell">
                <?php if ($r['photo'] && file_exists('assets/uploads/'.$r['photo'])): ?>
                  <img src="assets/uploads/<?= htmlspecialchars($r['photo']) ?>" class="avatar-sm">
                <?php else: ?>
                  <div class="avatar-placeholder sm"><?= strtoupper(substr($r['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <span><?= htmlspecialchars($r['full_name']) ?></span>
              </div>
            </td>
            <td><?= htmlspecialchars($r['purpose'] ?? '—') ?></td>
            <td>
              <div><?= htmlspecialchars($r['flat_number'] ?? '—') ?></div>
              <div style="font-size:.75rem;color:var(--muted);"><?= htmlspecialchars($r['host_name'] ?? '—') ?></div>
            </td>
            <td><?= date('d M, H:i', strtotime($r['check_in'])) ?></td>
            <td><?= timeDiff($r['check_in'], $r['check_out']) ?></td>
            <td><span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
            <td><a href="modules/badge/print_badge.php?visit=<?= $r['id'] ?>" class="btn-sm">🪪 Badge</a></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<!-- NOTIFICATION PANEL — dynamic from DB -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-header">
    <h4>Notifications <?php if ($unreadCount > 0): ?><span class="badge badge-danger"><?= $unreadCount ?></span><?php endif; ?></h4>
    <div style="display:flex;gap:8px;align-items:center;">
      <?php if ($unreadCount > 0): ?>
      <a href="api/notifications.php?action=mark_all_read" style="font-size:.75rem;color:var(--accent);">Mark all read</a>
      <?php endif; ?>
      <button onclick="toggleNotif()">✕</button>
    </div>
  </div>
  <?php if (empty($notifs)): ?>
  <p class="notif-empty">No new notifications</p>
  <?php else: foreach ($notifs as $n): ?>
  <div class="notif-item">
    <div style="font-size:.72rem;color:var(--accent);margin-bottom:4px;text-transform:uppercase;"><?= htmlspecialchars($n['type']) ?></div>
    <p><?= htmlspecialchars($n['message']) ?></p>
    <span><?= date('d M, H:i', strtotime($n['created_at'])) ?></span>
  </div>
  <?php endforeach; endif; ?>
</div>

<script>
// Weekly bar chart — data from PHP/DB
const weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
new Chart(weeklyCtx, {
  type:'bar',
  data:{
    labels: <?= json_encode(array_column($weekly,'day_label')) ?>,
    datasets:[{
      label:'Visitors',
      data: <?= json_encode(array_column($weekly,'cnt')) ?>,
      backgroundColor:'rgba(232,168,56,.75)',
      borderColor:'rgba(232,168,56,1)',
      borderWidth:1, borderRadius:6
    }]
  },
  options:{
    responsive:true,
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#7a8099'}},
      y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#7a8099',stepSize:1},beginAtZero:true}
    }
  }
});

<?php if (!empty($purposes)): ?>
// Purpose doughnut chart
const pCtx = document.getElementById('purposeChart').getContext('2d');
new Chart(pCtx,{
  type:'doughnut',
  data:{
    labels:<?= json_encode(array_column($purposes,'purpose')) ?>,
    datasets:[{
      data:<?= json_encode(array_column($purposes,'cnt')) ?>,
      backgroundColor:['#e8a838','#3ecf8e','#5b9cf6','#a78bfa','#f87171','#34d399'],
      borderWidth:0
    }]
  },
  options:{responsive:true,plugins:{legend:{position:'right',labels:{color:'#7a8099',font:{size:11}}}}}
});
<?php endif; ?>

function toggleNotif(){
  document.getElementById('notifPanel').classList.toggle('open');
}
// Auto-refresh stats every 30s
setInterval(()=>location.reload(), 30000);
</script>
</body>
</html>
