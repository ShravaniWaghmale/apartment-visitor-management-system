<?php
// modules/parking/parking.php — Fully dynamic
require_once '../../config/db.php';
requireLogin();
$user = currentUser();
$db   = getDB();

// All slot types from DB
$slotTypes = $db->query("SELECT DISTINCT slot_type FROM parking_slots ORDER BY slot_type")->fetchAll(PDO::FETCH_COLUMN);

// Per-type stats
$typeStats = $db->query("
    SELECT slot_type,
           COUNT(*) AS total,
           COUNT(*) FILTER(WHERE is_occupied=FALSE) AS free,
           COUNT(*) FILTER(WHERE is_occupied=TRUE)  AS occupied
    FROM parking_slots GROUP BY slot_type ORDER BY slot_type
")->fetchAll();

// All slots with occupant info
$allSlots = $db->query("
    SELECT ps.*, vi.full_name, vi.vehicle_number, vi.phone,
           v.check_in, v.flat_number, ps.assigned_at
    FROM parking_slots ps
    LEFT JOIN visits v ON ps.visit_id=v.id AND v.status='inside'
    LEFT JOIN visitors vi ON v.visitor_id=vi.id
    ORDER BY ps.slot_type, ps.slot_number
")->fetchAll();

// Group by type
$slotsByType = [];
foreach ($allSlots as $slot) {
    $slotsByType[$slot['slot_type']][] = $slot;
}

pageHead('Parking');
renderSidebar('parking');
?>
<main class="main-content">
  <header class="page-header">
    <div><h1 class="page-title">Parking Management</h1>
    <p class="page-sub">Live slot availability — auto-updated on check-in/check-out</p></div>
    <button onclick="location.reload()" class="btn-secondary">🔄 Refresh</button>
  </header>

  <!-- Stats by type — dynamic -->
  <div class="stats-grid" style="margin-bottom:22px;">
    <?php foreach ($typeStats as $ts): ?>
    <div class="stat-card stat-green">
      <div class="stat-icon"><?= $ts['slot_type']==='4-wheeler'?'🚗':'🛵' ?></div>
      <div class="stat-num"><?= $ts['free'] ?> / <?= $ts['total'] ?></div>
      <div class="stat-label"><?= htmlspecialchars($ts['slot_type']) ?> Free</div>
    </div>
    <div class="stat-card stat-red">
      <div class="stat-icon">🔴</div>
      <div class="stat-num"><?= $ts['occupied'] ?></div>
      <div class="stat-label"><?= htmlspecialchars($ts['slot_type']) ?> Occupied</div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Slot grids by type — all from DB -->
  <?php foreach ($slotsByType as $type => $slots): ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-header">
      <h3><?= $type==='4-wheeler'?'🚗':'🛵' ?> <?= htmlspecialchars(ucfirst($type)) ?> Slots</h3>
      <span class="badge badge-muted">
        <?= count(array_filter($slots, fn($s)=>!$s['is_occupied'])) ?> / <?= count($slots) ?> free
      </span>
    </div>
    <div class="slot-grid">
      <?php foreach ($slots as $sl): ?>
      <div class="slot-card <?= $sl['is_occupied']?'occupied':'free' ?>">
        <div style="font-size:1.6rem;"><?= $sl['is_occupied']?'🔴':'🟢' ?></div>
        <div class="slot-num"><?= htmlspecialchars($sl['slot_number']) ?></div>
        <?php if ($sl['is_occupied'] && $sl['full_name']): ?>
          <div class="slot-label" style="color:var(--text);font-size:.7rem;margin-top:2px;"><?= htmlspecialchars(explode(' ',$sl['full_name'])[0]) ?></div>
          <?php if ($sl['vehicle_number']): ?>
          <div class="slot-label"><?= htmlspecialchars($sl['vehicle_number']) ?></div>
          <?php endif; ?>
          <?php if ($sl['check_in']): ?>
          <div class="slot-label"><?= timeDiff($sl['check_in']) ?></div>
          <?php endif; ?>
          <?php if ($sl['flat_number']): ?>
          <div class="slot-label">Flat <?= htmlspecialchars($sl['flat_number']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="slot-label" style="color:var(--success);">Available</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Occupied slots detail table -->
  <?php
  $occupied = array_filter($allSlots, fn($s)=>$s['is_occupied']);
  if (!empty($occupied)):
  ?>
  <div class="card">
    <div class="card-header"><h3>Currently Occupied Slots (<?= count($occupied) ?>)</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Slot</th><th>Type</th><th>Visitor</th><th>Vehicle</th><th>Flat</th><th>Since</th><th>Duration</th></tr></thead>
        <tbody>
          <?php foreach ($occupied as $o): ?>
          <tr>
            <td><strong><?= htmlspecialchars($o['slot_number']) ?></strong></td>
            <td><?= htmlspecialchars($o['slot_type']) ?></td>
            <td><?= htmlspecialchars($o['full_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($o['vehicle_number'] ?? '—') ?></td>
            <td><?= htmlspecialchars($o['flat_number'] ?? '—') ?></td>
            <td><?= $o['check_in'] ? date('d M, H:i',strtotime($o['check_in'])) : '—' ?></td>
            <td><?= $o['check_in'] ? timeDiff($o['check_in']) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</main>
</body>
</html>
