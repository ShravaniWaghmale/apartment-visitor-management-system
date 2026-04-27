<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin','receptionist','guard');
$user = currentUser();
$db   = getDB();

$result = null;

// ── Handle QR/token lookup ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['qr_token'])) {
    $token  = trim($_POST['qr_token']);
    $action = trim($_POST['action'] ?? 'checkin');

    // Check blacklist first
    $bl = $db->prepare("SELECT full_name,blacklist_reason FROM visitors WHERE qr_token=:t AND is_blacklisted=TRUE");
    $bl->execute([':t'=>$token]);
    if ($blv = $bl->fetch()) {
        $result = ['type'=>'danger','msg'=>"🚫 BLACKLISTED: <strong>".htmlspecialchars($blv['full_name'])."</strong> — ".htmlspecialchars($blv['blacklist_reason'])];
    } else {
        // Look up visitor by QR
        $vs = $db->prepare("SELECT * FROM visitors WHERE qr_token=:t AND is_blacklisted=FALSE");
        $vs->execute([':t'=>$token]);
        $visitor = $vs->fetch();

        // Try appointment QR
        if (!$visitor) {
            $ap = $db->prepare("SELECT * FROM appointments WHERE qr_token=:t AND status='pending' AND expected_date=CURRENT_DATE");
            $ap->execute([':t'=>$token]);
            $apt = $ap->fetch();
            if ($apt) {
                // Convert appointment to visitor+check-in
                $newToken = genToken();
                $ins = $db->prepare("INSERT INTO visitors (full_name,phone,purpose,host_id,flat_number,qr_token) VALUES (:n,:p,:pu,:h,:f,:q) RETURNING id");
                $ins->execute([':n'=>$apt['visitor_name'],':p'=>$apt['visitor_phone'],':pu'=>$apt['purpose'],':h'=>$apt['host_id'],':f'=>$apt['flat_number'],':q'=>$newToken]);
                $visitorId = $ins->fetchColumn();
                $db->prepare("UPDATE appointments SET status='arrived' WHERE id=:id")->execute([':id'=>$apt['id']]);
                $visitor = $db->query("SELECT * FROM visitors WHERE id=$visitorId")->fetch();
            }
        }

        if (!$visitor) {
            $result = ['type'=>'danger','msg'=>'❌ Invalid QR code or visitor not found.'];
        } elseif ($action === 'checkin') {
            $existing = $db->prepare("SELECT id FROM visits WHERE visitor_id=:vid AND status='inside'");
            $existing->execute([':vid'=>$visitor['id']]);
            if ($existing->fetch()) {
                $result = ['type'=>'warning','msg'=>"⚠️ <strong>".htmlspecialchars($visitor['full_name'])."</strong> is already inside."];
            } else {
                $vi = $db->prepare("INSERT INTO visits (visitor_id,host_id,flat_number,guard_checkin_id) VALUES (:vi,:h,:f,:g) RETURNING id");
                $vi->execute([':vi'=>$visitor['id'],':h'=>$visitor['host_id'],':f'=>$visitor['flat_number'],':g'=>$user['id']]);
                $visitId = $vi->fetchColumn();
                if ($visitor['host_id']) notifyUser($visitor['host_id'],'visitor_arrived',"Visitor {$visitor['full_name']} has arrived at flat {$visitor['flat_number']}.",(int)$visitId);
                $result = ['type'=>'success','msg'=>"✅ <strong>".htmlspecialchars($visitor['full_name'])."</strong> checked in. Visit #$visitId. <a href='../badge/print_badge.php?visit=$visitId' style='color:var(--accent)'>Print Badge</a>"];
            }
        } elseif ($action === 'checkout') {
            $ov = $db->prepare("SELECT * FROM visits WHERE visitor_id=:vid AND status='inside'");
            $ov->execute([':vid'=>$visitor['id']]);
            $v = $ov->fetch();
            if ($v) {
                $db->prepare("UPDATE visits SET check_out=NOW(),status='checked_out',guard_checkout_id=:g WHERE id=:id")
                   ->execute([':g'=>$user['id'],':id'=>$v['id']]);
                if ($v['parking_slot_id']) {
                    $db->prepare("UPDATE parking_slots SET is_occupied=FALSE,visit_id=NULL,assigned_at=NULL WHERE id=:p")->execute([':p'=>$v['parking_slot_id']]);
                }
                $result = ['type'=>'success','msg'=>"✅ <strong>".htmlspecialchars($visitor['full_name'])."</strong> checked out. Duration: ".timeDiff($v['check_in'])];
            } else {
                $result = ['type'=>'warning','msg'=>"Visitor is not currently inside."];
            }
        }
    }
}

// ── Handle direct checkout by visit ID (from dashboard/history) ──
if (isset($_GET['checkout_visit'])) {
    $vid = (int)$_GET['checkout_visit'];
    $ov = $db->prepare("SELECT v.*,vi.full_name FROM visits v JOIN visitors vi ON v.visitor_id=vi.id WHERE v.id=:id AND v.status='inside'");
    $ov->execute([':id'=>$vid]);
    $v = $ov->fetch();
    if ($v) {
        $db->prepare("UPDATE visits SET check_out=NOW(),status='checked_out',guard_checkout_id=:g WHERE id=:id")
           ->execute([':g'=>$user['id'],':id'=>$vid]);
        if ($v['parking_slot_id']) {
            $db->prepare("UPDATE parking_slots SET is_occupied=FALSE,visit_id=NULL,assigned_at=NULL WHERE id=:p")->execute([':p'=>$v['parking_slot_id']]);
        }
        flash('success', "✅ {$v['full_name']} checked out successfully.");
    }
    header('Location: checkin.php'); exit();
}

// ── Live inside list ──────────────────────────────────────────
$inside = $db->query("
    SELECT v.id AS visit_id, vi.full_name, vi.photo, v.flat_number, v.check_in,
           v.overstay_flagged, u.name AS host_name, vi.qr_token
    FROM visits v
    JOIN visitors vi ON v.visitor_id=vi.id
    LEFT JOIN users u ON v.host_id=u.id
    WHERE v.status='inside'
    ORDER BY v.overstay_flagged DESC, v.check_in ASC
")->fetchAll();

// ── Today's appointment quick list ────────────────────────────
$todayApts = $db->query("
    SELECT a.visitor_name,a.visitor_phone,a.expected_time,a.purpose,a.status,a.flat_number,a.qr_token,u.name AS host_name
    FROM appointments a JOIN users u ON a.host_id=u.id
    WHERE a.expected_date=CURRENT_DATE AND a.status IN ('pending','arrived')
    ORDER BY a.expected_time
")->fetchAll();

pageHead('Check-in / QR Scan', '<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>');
renderSidebar('checkin');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Check-in / Check-out</h1>
      <p class="page-sub">Scan QR code or enter token manually · <?= count($inside) ?> visitors inside now</p>
    </div>
    <a href="register.php" class="btn-primary">+ New Visitor</a>
  </header>

  <?= renderFlash() ?>

  <?php if ($result): ?>
  <div class="flash-msg" style="background:rgba(<?= $result['type']==='success'?'62,207,142':($result['type']==='warning'?'245,158,11':'224,82,82') ?>,0.1);border:1px solid rgba(<?= $result['type']==='success'?'62,207,142':($result['type']==='warning'?'245,158,11':'224,82,82') ?>,0.3);color:var(--<?= $result['type']==='warning'?'warning':($result['type']==='success'?'success':'danger') ?>);">
    <?= $result['msg'] ?>
  </div>
  <?php endif; ?>

  <div class="two-col" style="align-items:start;">

    <!-- LEFT: QR Scanner + Manual Entry -->
    <div style="display:flex;flex-direction:column;gap:16px;">
      <div class="card">
        <div class="card-header"><h3>📷 QR Code Scanner</h3><span class="badge badge-muted" id="scanStatus">Ready</span></div>
        <div id="qr-reader" style="width:100%;border-radius:8px;overflow:hidden;min-height:60px;"></div>
        <div style="display:flex;gap:8px;margin-top:12px;">
          <button type="button" class="btn-success" style="flex:1;" onclick="startScan('checkin')">▶ Check-In Scan</button>
          <button type="button" class="btn-secondary" style="flex:1;" onclick="startScan('checkout')">▶ Check-Out Scan</button>
          <button type="button" class="btn-danger" onclick="stopScan()">■</button>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3>⌨️ Manual Token Entry</h3></div>
        <form method="POST">
          <div class="form-group">
            <label>QR Token</label>
            <input type="text" name="qr_token" id="tokenInput" placeholder="Paste or type QR token" required>
          </div>
          <div class="form-group">
            <label>Action</label>
            <select name="action" id="actionSelect">
              <option value="checkin">Check-In</option>
              <option value="checkout">Check-Out</option>
            </select>
          </div>
          <button type="submit" class="btn-primary" style="width:100%;">Process</button>
        </form>
      </div>

      <!-- Today's Appointments -->
      <?php if (!empty($todayApts)): ?>
      <div class="card">
        <div class="card-header">
          <h3>📅 Today's Appointments (<?= count($todayApts) ?>)</h3>
        </div>
        <?php foreach ($todayApts as $a): ?>
        <div class="inside-row">
          <div class="avatar-placeholder sm"><?= strtoupper(substr($a['visitor_name'],0,1)) ?></div>
          <div style="flex:1;">
            <div class="row-name"><?= htmlspecialchars($a['visitor_name']) ?></div>
            <div class="row-sub"><?= date('H:i',strtotime($a['expected_time'])) ?> · <?= htmlspecialchars($a['host_name']) ?> · Flat <?= htmlspecialchars($a['flat_number']) ?></div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
            <span class="status-badge status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
            <?php if ($a['status']==='pending'): ?>
            <button class="btn-sm" onclick="useToken('<?= htmlspecialchars($a['qr_token']) ?>','checkin')">Check In</button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- RIGHT: Currently Inside -->
    <div class="card">
      <div class="card-header">
        <h3>🏠 Currently Inside (<?= count($inside) ?>)</h3>
        <a href="history.php?status=inside" class="link-more">View logs →</a>
      </div>
      <?php if (empty($inside)): ?>
      <p style="text-align:center;color:var(--muted);padding:40px;">No visitors inside</p>
      <?php else: foreach ($inside as $i):
        $dur = timeDiff($i['check_in']);
      ?>
      <div class="inside-row <?= $i['overstay_flagged']?'overstay-row':'' ?>">
        <?php if ($i['photo'] && file_exists(realpath(__DIR__.'/../../assets/uploads/'.$i['photo']))): ?>
        <img src="<?= rootPath().'assets/uploads/'.htmlspecialchars($i['photo']) ?>" class="avatar-sm">
        <?php else: ?>
        <div class="avatar-placeholder sm"><?= strtoupper(substr($i['full_name'],0,1)) ?></div>
        <?php endif; ?>
        <div style="flex:1;">
          <div class="row-name">
            <?= htmlspecialchars($i['full_name']) ?>
            <?php if ($i['overstay_flagged']): ?><span class="badge badge-danger ml-4">Overstay</span><?php endif; ?>
          </div>
          <div class="row-sub">
            Flat <?= htmlspecialchars($i['flat_number']) ?> ·
            <?= htmlspecialchars($i['host_name'] ?? '—') ?> ·
            <span style="color:<?= $i['overstay_flagged']?'var(--danger)':'var(--success)' ?>;"><?= $dur ?></span>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
          <button class="btn-sm" style="color:var(--success);"
            onclick="useToken('<?= htmlspecialchars($i['qr_token']) ?>','checkout')">✓ Out</button>
          <a href="<?= rootPath() ?>modules/badge/print_badge.php?visit=<?= $i['visit_id'] ?>" class="btn-sm">🪪</a>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</main>

<script>
let qrScanner = null;

function startScan(action) {
  document.getElementById('actionSelect').value = action;
  document.getElementById('scanStatus').textContent = 'Scanning (' + action + ')...';
  if (!qrScanner) qrScanner = new Html5Qrcode("qr-reader");
  qrScanner.start(
    { facingMode:"environment" },
    { fps:10, qrbox:{width:240,height:240} },
    decoded => {
      document.getElementById('scanStatus').textContent = '✅ Scanned!';
      stopScan();
      useToken(decoded, action);
    },
    () => {}
  ).catch(e => { document.getElementById('scanStatus').textContent = 'Camera error: ' + e; });
}

function stopScan() {
  if (qrScanner?.isScanning) qrScanner.stop().catch(()=>{});
  document.getElementById('scanStatus').textContent = 'Stopped';
}

function useToken(token, action) {
  document.getElementById('tokenInput').value = token;
  document.getElementById('actionSelect').value = action;
  document.getElementById('tokenInput').closest('form').submit();
}
</script>
</body>
</html>
