<?php
require_once '../../config/db.php';
requireLogin();
$user = currentUser();
$db   = getDB();
$s    = getSettings();

$visit = null;
if (isset($_GET['visit'])) {
    $stmt = $db->prepare("
        SELECT v.id, v.check_in, v.check_out, v.status, v.flat_number, v.notes,
               vi.full_name, vi.phone, vi.photo, vi.purpose, vi.vehicle_number,
               vi.id_proof_type, vi.qr_token,
               u.name AS host_name, u.flat_number AS host_flat,
               ps.slot_number AS parking_slot
        FROM visits v
        JOIN visitors vi ON v.visitor_id=vi.id
        LEFT JOIN users u ON v.host_id=u.id
        LEFT JOIN parking_slots ps ON v.parking_slot_id=ps.id
        WHERE v.id=:id
    ");
    $stmt->execute([':id'=>(int)$_GET['visit']]);
    $visit = $stmt->fetch();
}

// Recent visits for selector
$recent = $db->query("
    SELECT v.id, vi.full_name, v.flat_number, v.check_in, v.status
    FROM visits v JOIN visitors vi ON v.visitor_id=vi.id
    ORDER BY v.check_in DESC LIMIT 10
")->fetchAll();

$orgName  = $s['org_name']    ?? 'ResidentGuard';
$orgAddr  = $s['org_address'] ?? '';

pageHead('Badge Printing', '
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
@media print {
  .no-print { display:none!important; }
  .main-content { margin:0!important; padding:20px!important; }
  body { background:#fff!important; color:#000!important; }
  .badge-wrapper { page-break-inside:avoid; }
}
</style>');
renderSidebar('print_badge');
?>
<main class="main-content">
  <header class="page-header no-print">
    <div><h1 class="page-title">Badge Printing</h1>
    <p class="page-sub">Generate & print visitor badges with QR code</p></div>
  </header>

  <?php if (!$visit): ?>
  <!-- Search / Select Visit -->
  <div class="two-col" style="align-items:start;">
    <div class="card no-print">
      <div class="card-header"><h3>Find Visit</h3></div>
      <form method="GET">
        <div class="form-group">
          <label>Visit ID</label>
          <input type="number" name="visit" placeholder="Enter visit ID">
        </div>
        <button type="submit" class="btn-primary">Load Badge →</button>
      </form>
    </div>
    <div class="card no-print">
      <div class="card-header"><h3>Recent Visits</h3></div>
      <?php foreach ($recent as $r): ?>
      <a href="?visit=<?= $r['id'] ?>" style="display:flex;align-items:center;justify-content:space-between;padding:10px;border-radius:8px;border:1px solid var(--border);margin-bottom:6px;transition:background .15s;" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
        <div>
          <div style="font-size:.86rem;font-weight:500;"><?= htmlspecialchars($r['full_name']) ?></div>
          <div style="font-size:.73rem;color:var(--muted);">Flat <?= htmlspecialchars($r['flat_number']) ?></div>
        </div>
        <div style="text-align:right;">
          <span class="status-badge status-<?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
          <div style="font-size:.72rem;color:var(--muted);margin-top:2px;"><?= date('d M, H:i',strtotime($r['check_in'])) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- Badge Display -->
  <div class="no-print" style="margin-bottom:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <button onclick="window.print()" class="btn-primary">🖨️ Print Badge</button>
    <a href="print_badge.php" class="btn-secondary">← All Visits</a>
    <a href="<?= rootPath().'modules/visitors/history.php' ?>" class="btn-secondary">📋 History</a>
    <?php if ($visit['status']==='inside'): ?>
    <a href="<?= rootPath().'modules/visitors/checkin.php?checkout_visit='.$visit['id'] ?>" class="btn-success">✓ Check Out</a>
    <?php endif; ?>
  </div>

  <div class="badge-wrapper" style="display:flex;gap:24px;align-items:start;flex-wrap:wrap;">

    <!-- The Badge Card (prints) -->
    <div id="badge" style="
      width:340px; background:#fff; color:#111; border-radius:16px;
      overflow:hidden; font-family:'DM Sans',sans-serif;
      box-shadow:0 8px 40px rgba(0,0,0,.4);
    ">
      <!-- Header -->
      <div style="background:linear-gradient(135deg,#1a1f32,#2d3555);padding:16px 20px;display:flex;align-items:center;gap:10px;">
        <div style="font-size:22px;">🏘️</div>
        <div style="flex:1;">
          <div style="color:#fff;font-family:'DM Serif Display',serif;font-size:1.05rem;"><?= htmlspecialchars($orgName) ?></div>
          <?php if ($orgAddr): ?>
          <div style="color:rgba(255,255,255,.5);font-size:.62rem;margin-top:1px;"><?= htmlspecialchars($orgAddr) ?></div>
          <?php endif; ?>
        </div>
        <div style="background:<?= $visit['status']==='inside'?'#3ecf8e':'#888' ?>;color:#fff;font-size:.6rem;padding:3px 8px;border-radius:10px;font-weight:600;text-transform:uppercase;">
          <?= $visit['status']==='inside'?'ACTIVE':'EXPIRED' ?>
        </div>
      </div>

      <!-- Photo + Name -->
      <div style="padding:20px;text-align:center;border-bottom:1px solid #eee;">
        <?php
        $photoFile = realpath(__DIR__ . '/../../assets/uploads/' . ($visit['photo'] ?? ''));
        if ($visit['photo'] && $photoFile && file_exists($photoFile)):
        ?>
          <img src="<?= rootPath().'assets/uploads/'.htmlspecialchars($visit['photo']) ?>"
               style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid #e8a838;display:block;margin:0 auto 12px;">
        <?php else: ?>
          <div style="width:84px;height:84px;border-radius:50%;background:linear-gradient(135deg,#e8a838,#f0c060);
               display:flex;align-items:center;justify-content:center;font-size:32px;color:#1a1200;margin:0 auto 12px;">
            <?= strtoupper(substr($visit['full_name'],0,1)) ?>
          </div>
        <?php endif; ?>
        <div style="font-family:'DM Serif Display',serif;font-size:1.4rem;color:#111;margin-bottom:2px;"><?= htmlspecialchars($visit['full_name']) ?></div>
        <div style="font-size:.78rem;color:#888;"><?= htmlspecialchars($visit['phone'] ?? '') ?></div>
      </div>

      <!-- Details grid -->
      <div style="padding:16px 20px;">
        <table style="width:100%;font-size:.78rem;">
          <tr>
            <td style="color:#888;padding:5px 0;width:35%;">Purpose</td>
            <td style="font-weight:500;color:#333;"><?= htmlspecialchars($visit['purpose'] ?? '—') ?></td>
          </tr>
          <tr>
            <td style="color:#888;padding:5px 0;">Visiting</td>
            <td style="font-weight:500;color:#333;">
              <?= htmlspecialchars($visit['host_name'] ?? '') ?>
              <?php if ($visit['flat_number']): ?> · Flat <?= htmlspecialchars($visit['flat_number']) ?><?php endif; ?>
            </td>
          </tr>
          <tr>
            <td style="color:#888;padding:5px 0;">Check-In</td>
            <td style="font-weight:500;color:#333;"><?= date('d M Y, H:i',strtotime($visit['check_in'])) ?></td>
          </tr>
          <?php if ($visit['check_out']): ?>
          <tr>
            <td style="color:#888;padding:5px 0;">Check-Out</td>
            <td style="font-weight:500;color:#333;"><?= date('d M Y, H:i',strtotime($visit['check_out'])) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($visit['vehicle_number']): ?>
          <tr>
            <td style="color:#888;padding:5px 0;">Vehicle</td>
            <td style="font-weight:500;color:#333;"><?= htmlspecialchars($visit['vehicle_number']) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($visit['parking_slot']): ?>
          <tr>
            <td style="color:#888;padding:5px 0;">Parking</td>
            <td style="font-weight:500;color:#333;"><?= htmlspecialchars($visit['parking_slot']) ?></td>
          </tr>
          <?php endif; ?>
          <?php if ($visit['id_proof_type']): ?>
          <tr>
            <td style="color:#888;padding:5px 0;">ID Type</td>
            <td style="font-weight:500;color:#333;"><?= htmlspecialchars($visit['id_proof_type']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td style="color:#888;padding:5px 0;">Visit ID</td>
            <td style="font-weight:500;color:#333;">#<?= $visit['id'] ?></td>
          </tr>
        </table>
      </div>

      <!-- QR Code -->
      <div style="background:#f8f8f8;padding:16px;text-align:center;border-top:1px solid #eee;">
        <div id="qrCode" style="display:inline-block;background:#fff;padding:8px;border-radius:6px;"></div>
        <div style="color:#999;font-size:.62rem;margin-top:6px;letter-spacing:.5px;">Scan to verify · Visit #<?= $visit['id'] ?></div>
      </div>
    </div>

    <!-- Info Panel (no-print) -->
    <div class="card no-print" style="flex:1;min-width:260px;max-width:380px;">
      <div class="card-header"><h3>Badge Details</h3></div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <div style="background:var(--surface2);border-radius:8px;padding:14px;">
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:5px;">QR Token</div>
          <code style="font-size:.72rem;word-break:break-all;color:var(--text);"><?= htmlspecialchars($visit['qr_token']) ?></code>
        </div>
        <div style="background:var(--surface2);border-radius:8px;padding:14px;">
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:5px;">Duration</div>
          <div style="font-size:.9rem;"><?= timeDiff($visit['check_in'], $visit['check_out'] ?: null) ?></div>
        </div>
        <?php if ($visit['notes']): ?>
        <div style="background:var(--surface2);border-radius:8px;padding:14px;">
          <div style="font-size:.72rem;color:var(--muted);margin-bottom:5px;">Notes</div>
          <div style="font-size:.82rem;"><?= htmlspecialchars($visit['notes']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
  new QRCode(document.getElementById("qrCode"), {
    text: "<?= addslashes($visit['qr_token']) ?>",
    width: 100, height: 100,
    colorDark: "#000000", colorLight: "#ffffff",
    correctLevel: QRCode.CorrectLevel.M
  });
  </script>
  <?php endif; ?>
</main>
</body>
</html>
