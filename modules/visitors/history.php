<?php
require_once '../../config/db.php';
requireLogin();
checkOverstays();
$user = currentUser();
$db   = getDB();
$s    = getSettings();

// ── Filters (all from GET, no hardcoded values) ───────────────
$search   = trim($_GET['q']      ?? '');
$status   = trim($_GET['status'] ?? '');
$date     = trim($_GET['date']   ?? '');
$hostId   = (int)($_GET['host']  ?? 0);
$purpose  = trim($_GET['purpose'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = (int)($s['per_page'] ?? 15);

// ── Dynamic where conditions ─────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]   = "(vi.full_name ILIKE :q OR vi.phone ILIKE :q OR v.flat_number ILIKE :q OR vi.vehicle_number ILIKE :q)";
    $params[':q'] = "%$search%";
}
if ($status) { $where[] = "v.status = :status"; $params[':status'] = $status; }
if ($date)   { $where[] = "v.check_in::date = :date"; $params[':date'] = $date; }
if ($hostId) { $where[] = "v.host_id = :host"; $params[':host'] = $hostId; }
if ($purpose){ $where[] = "vi.purpose = :purpose"; $params[':purpose'] = $purpose; }

// Hosts only see their own visitors
if ($user['role'] === 'host') {
    $where[] = "v.host_id = :myid";
    $params[':myid'] = $user['id'];
}

$whereSQL = implode(' AND ', $where);

// ── Core query ────────────────────────────────────────────────
$baseSql = "
    SELECT v.id, vi.full_name, vi.phone, vi.photo, vi.purpose, vi.vehicle_number,
           vi.id_proof_type, vi.is_blacklisted,
           v.flat_number, v.check_in, v.check_out, v.status, v.overstay_flagged, v.notes,
           u.name AS host_name, u.flat_number AS host_flat,
           g1.name AS guard_in_name, g2.name AS guard_out_name,
           ps.slot_number AS parking_slot
    FROM visits v
    JOIN visitors vi ON v.visitor_id = vi.id
    LEFT JOIN users u  ON v.host_id = u.id
    LEFT JOIN users g1 ON v.guard_checkin_id = g1.id
    LEFT JOIN users g2 ON v.guard_checkout_id = g2.id
    LEFT JOIN parking_slots ps ON v.parking_slot_id = ps.id
    WHERE $whereSQL
    ORDER BY v.check_in DESC
";

$result = paginateQuery($db, $baseSql, $params, $page, $perPage);
$visits = $result['data'];
$total  = $result['total'];
$pages  = $result['pages'];

// ── Dynamic dropdowns for filters ───────────────────────────
$hosts    = $db->query("SELECT id,name,flat_number FROM users WHERE role_id=4 AND is_active=TRUE ORDER BY flat_number")->fetchAll();
$purposes = $db->query("SELECT DISTINCT vi.purpose FROM visitors vi WHERE vi.purpose IS NOT NULL ORDER BY vi.purpose")->fetchAll(PDO::FETCH_COLUMN);

// ── Summary counts for filter strip ──────────────────────────
$counts = $db->query("
    SELECT
      COUNT(*) AS total_all,
      COUNT(*) FILTER(WHERE status='inside') AS cnt_inside,
      COUNT(*) FILTER(WHERE status='checked_out') AS cnt_out,
      COUNT(*) FILTER(WHERE check_in::date=CURRENT_DATE) AS cnt_today,
      COUNT(*) FILTER(WHERE overstay_flagged=TRUE AND status='inside') AS cnt_overstay
    FROM visits
")->fetch();

pageHead('Visitor History');
renderSidebar('history');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Visitor History</h1>
      <p class="page-sub"><?= number_format($total) ?> records matching current filters</p>
    </div>
    <div class="header-actions">
      <a href="<?= rootPath() ?>modules/reports/export.php" class="btn-secondary">⬇️ Export</a>
      <?php if (in_array($user['role'],['admin','receptionist','guard'])): ?>
      <a href="register.php" class="btn-primary">+ Register Visitor</a>
      <?php endif; ?>
    </div>
  </header>

  <!-- SUMMARY PILLS -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
    <a href="?" class="tag <?= !$status?'tag-active':'' ?>">All <strong><?= $counts['total_all'] ?></strong></a>
    <a href="?status=inside" class="tag" style="color:var(--success);">Inside <strong><?= $counts['cnt_inside'] ?></strong></a>
    <a href="?status=checked_out" class="tag">Checked Out <strong><?= $counts['cnt_out'] ?></strong></a>
    <a href="?date=<?= date('Y-m-d') ?>" class="tag" style="color:var(--accent);">Today <strong><?= $counts['cnt_today'] ?></strong></a>
    <?php if ($counts['cnt_overstay'] > 0): ?>
    <a href="?status=inside" class="tag" style="color:var(--danger);">⚠️ Overstay <strong><?= $counts['cnt_overstay'] ?></strong></a>
    <?php endif; ?>
  </div>

  <!-- FILTERS — dynamic options from DB -->
  <div class="card" style="margin-bottom:18px;">
    <form method="GET" class="filter-bar">
      <div class="form-group" style="flex:2;min-width:200px;margin-bottom:0;">
        <label>Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name, phone, flat, vehicle...">
      </div>
      <div class="form-group" style="flex:1;min-width:130px;margin-bottom:0;">
        <label>Status</label>
        <select name="status">
          <option value="">All Statuses</option>
          <option value="inside"      <?= $status==='inside'?'selected':'' ?>>Inside</option>
          <option value="checked_out" <?= $status==='checked_out'?'selected':'' ?>>Checked Out</option>
        </select>
      </div>
      <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
        <label>Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
      </div>
      <!-- Purpose dropdown — built from actual DB data -->
      <div class="form-group" style="flex:1;min-width:160px;margin-bottom:0;">
        <label>Purpose</label>
        <select name="purpose">
          <option value="">All Purposes</option>
          <?php foreach ($purposes as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>" <?= $purpose===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($user['role'] !== 'host'): ?>
      <!-- Host dropdown — built from actual DB users -->
      <div class="form-group" style="flex:1;min-width:180px;margin-bottom:0;">
        <label>Resident/Host</label>
        <select name="host">
          <option value="">All Residents</option>
          <?php foreach ($hosts as $h): ?>
          <option value="<?= $h['id'] ?>" <?= $hostId==$h['id']?'selected':'' ?>>
            <?= htmlspecialchars($h['name']) ?> (<?= htmlspecialchars($h['flat_number']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div style="display:flex;gap:8px;align-self:flex-end;">
        <button type="submit" class="btn-primary">🔍 Filter</button>
        <a href="history.php" class="btn-secondary">Reset</a>
      </div>
    </form>
  </div>

  <!-- TABLE — all data dynamic from DB -->
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th><th>Visitor</th><th>Purpose</th><th>Host / Flat</th>
            <th>Check-In</th><th>Check-Out</th><th>Duration</th>
            <th>Parking</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($visits)): ?>
          <tr><td colspan="10" class="empty-cell">No records found for the selected filters.</td></tr>
          <?php else: foreach ($visits as $v):
            $duration = timeDiff($v['check_in'], $v['check_out'] ?: null);
            $durationHtml = $v['status'] === 'inside'
              ? "<span style='color:var(--success);'>$duration ▲</span>"
              : $duration;
          ?>
          <tr <?= $v['overstay_flagged'] ? 'class="overstay-tr"' : '' ?>>
            <td style="color:var(--muted);">#<?= $v['id'] ?></td>
            <td>
              <div class="visitor-cell">
                <?php
                $photoPath = rootPath() . 'assets/uploads/' . $v['photo'];
                if ($v['photo'] && file_exists(realpath(__DIR__ . '/../../assets/uploads/' . $v['photo']))):
                ?>
                  <img src="<?= $photoPath ?>" class="avatar-sm" alt="">
                <?php else: ?>
                  <div class="avatar-placeholder sm"><?= strtoupper(substr($v['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <div>
                  <div style="font-weight:500;"><?= htmlspecialchars($v['full_name']) ?></div>
                  <div style="font-size:.73rem;color:var(--muted);">
                    <?= htmlspecialchars($v['phone'] ?? '—') ?>
                    <?php if ($v['vehicle_number']): ?>· <?= htmlspecialchars($v['vehicle_number']) ?><?php endif; ?>
                  </div>
                </div>
              </div>
            </td>
            <td><?= htmlspecialchars($v['purpose'] ?? '—') ?></td>
            <td>
              <div><?= htmlspecialchars($v['flat_number'] ?? '—') ?></div>
              <div style="font-size:.73rem;color:var(--muted);"><?= htmlspecialchars($v['host_name'] ?? '—') ?></div>
            </td>
            <td>
              <?= date('d M, H:i', strtotime($v['check_in'])) ?>
              <?php if ($v['guard_in_name']): ?>
              <div style="font-size:.72rem;color:var(--muted);">by <?= htmlspecialchars($v['guard_in_name']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= $v['check_out'] ? date('d M, H:i', strtotime($v['check_out'])) : '—' ?>
              <?php if ($v['guard_out_name']): ?>
              <div style="font-size:.72rem;color:var(--muted);">by <?= htmlspecialchars($v['guard_out_name']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= $durationHtml ?>
              <?php if ($v['overstay_flagged']): ?>
              <div><span class="badge badge-danger" style="margin-top:3px;">Overstay</span></div>
              <?php endif; ?>
            </td>
            <td><?= $v['parking_slot'] ? htmlspecialchars($v['parking_slot']) : '—' ?></td>
            <td><span class="status-badge status-<?= $v['status'] ?>"><?= ucfirst(str_replace('_',' ',$v['status'])) ?></span></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <a href="<?= rootPath() ?>modules/badge/print_badge.php?visit=<?= $v['id'] ?>" class="btn-sm" title="Print Badge">🪪</a>
                <?php if ($v['status'] === 'inside' && in_array($user['role'],['admin','receptionist','guard'])): ?>
                <a href="checkin.php?checkout_visit=<?= $v['id'] ?>" class="btn-sm" style="color:var(--success);">✓ Out</a>
                <?php endif; ?>
                <?php if ($user['role'] === 'admin'): ?>
                <a href="<?= rootPath() ?>api/visitor_api.php?action=blacklist_form&visitor_id=<?= $v['id'] ?>" class="btn-sm" style="color:var(--danger);" title="Blacklist">🚫</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?= pagerHtml($pages, $page, array_filter(['q'=>$search,'status'=>$status,'date'=>$date,'host'=>$hostId?:null,'purpose'=>$purpose])) ?>
  </div>
</main>
</body>
</html>
