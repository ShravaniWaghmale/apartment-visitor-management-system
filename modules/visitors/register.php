<?php
require_once '../../config/db.php';
requireLogin();
requireRole('admin','receptionist','guard');
$user = currentUser();
$db   = getDB();
$s    = getSettings();

// ── Load dynamic dropdowns from DB settings ──────────────────
$purposes = array_filter(array_map('trim', explode(',', $s['allowed_purposes'] ?? '')));
$idTypes  = array_filter(array_map('trim', explode(',', $s['allowed_id_types']  ?? '')));
$hosts    = $db->query("SELECT id,name,flat_number FROM users WHERE role_id=4 AND is_active=TRUE ORDER BY flat_number")->fetchAll();
$freeSlots = $db->query("SELECT id,slot_number,slot_type FROM parking_slots WHERE is_occupied=FALSE ORDER BY slot_type,slot_number")->fetchAll();
$requirePhoto    = !empty($s['require_photo']);
$requireIdProof  = !empty($s['require_id_proof']);
$uploadDir = realpath(__DIR__ . '/../../assets/uploads') . '/';

// ── Handle form POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name    = trim($_POST['full_name']     ?? '');
        $phone   = trim($_POST['phone']         ?? '');
        $purpose = trim($_POST['purpose']       ?? '');
        $hostId  = (int)($_POST['host_id']      ?? 0) ?: null;
        $flat    = trim($_POST['flat_number']   ?? '');
        $idType  = trim($_POST['id_proof_type'] ?? '');
        $vehicle = trim($_POST['vehicle_number']?? '');
        $notes   = trim($_POST['notes']         ?? '');
        $parkSlot= (int)($_POST['parking_slot'] ?? 0) ?: null;

        if (!$name) throw new RuntimeException('Visitor name is required.');

        // Photo — webcam capture or file upload
        $photo = null;
        if (!empty($_POST['captured_photo'])) {
            $photo = saveBase64Photo($_POST['captured_photo'], $uploadDir);
        } elseif (!empty($_FILES['photo_upload']['name'])) {
            $photo = uploadFile('photo_upload', $uploadDir, ['jpg','jpeg','png','webp']);
        }
        if ($requirePhoto && !$photo) throw new RuntimeException('Visitor photo is required.');

        // ID proof upload
        $idFile = null;
        if (!empty($_FILES['id_proof']['name'])) {
            $idFile = uploadFile('id_proof', $uploadDir, ['jpg','jpeg','png','pdf']);
        }
        if ($requireIdProof && !$idFile) throw new RuntimeException('ID proof is required.');

        // QR token
        $qrToken = genToken();

        // Insert visitor
        $vStmt = $db->prepare("
            INSERT INTO visitors
              (full_name,phone,photo,id_proof_type,id_proof_file,purpose,host_id,flat_number,vehicle_number,qr_token)
            VALUES (:n,:p,:ph,:it,:if,:pu,:h,:f,:v,:q)
            RETURNING id
        ");
        $vStmt->execute([
            ':n'=>$name,  ':p'=>$phone,  ':ph'=>$photo,
            ':it'=>$idType, ':if'=>$idFile, ':pu'=>$purpose,
            ':h'=>$hostId, ':f'=>$flat, ':v'=>$vehicle, ':q'=>$qrToken,
        ]);
        $visitorId = (int)$vStmt->fetchColumn();

        // Insert visit (check-in)
        $viStmt = $db->prepare("
            INSERT INTO visits (visitor_id,host_id,flat_number,guard_checkin_id,parking_slot_id,notes)
            VALUES (:vi,:h,:f,:g,:ps,:no)
            RETURNING id
        ");
        $viStmt->execute([
            ':vi'=>$visitorId, ':h'=>$hostId, ':f'=>$flat,
            ':g'=>$user['id'], ':ps'=>$parkSlot, ':no'=>$notes,
        ]);
        $visitId = (int)$viStmt->fetchColumn();

        // Mark parking slot occupied
        if ($parkSlot) {
            $db->prepare("UPDATE parking_slots SET is_occupied=TRUE,visit_id=:vi,assigned_at=NOW() WHERE id=:id")
               ->execute([':vi'=>$visitId, ':id'=>$parkSlot]);
        }

        // Notify host
        if ($hostId) {
            notifyUser($hostId, 'visitor_arrived', "Visitor $name has arrived at flat $flat.", $visitId);
        }

        flash('success', "✅ Visitor \"$name\" registered & checked in. Visit #$visitId.");
        header("Location: /visitor-management/modules/badge/print_badge.php?visit=$visitId");
        exit();

    } catch (Throwable $e) {
        flash('danger', '❌ ' . $e->getMessage());
    }
}

pageHead('Register Visitor');
renderSidebar('register');
?>
<main class="main-content">
  <header class="page-header">
    <div>
      <h1 class="page-title">Register Visitor</h1>
      <p class="page-sub">Fill in details and check visitor in</p>
    </div>
    <a href="/visitor-management/dashboard.php" class="btn-secondary">← Dashboard</a>
  </header>

  <?= renderFlash() ?>

  <form method="POST" enctype="multipart/form-data">
    <div class="two-col" style="align-items:start;margin-bottom:20px;">

      <!-- LEFT: Visitor Details -->
      <div class="card">
        <div class="card-header"><h3>Visitor Information</h3></div>
        <div class="form-grid">

          <div class="form-group">
            <label>Full Name *</label>
            <input type="text" name="full_name" required placeholder="e.g. Ravi Kumar"
                   value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" placeholder="+91 9876543210"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>

          <!-- Purpose — from DB settings -->
          <div class="form-group">
            <label>Purpose of Visit *</label>
            <select name="purpose" required>
              <option value="">— Select Purpose —</option>
              <?php foreach ($purposes as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>"
                <?= ($_POST['purpose'] ?? '') === $p ? 'selected' : '' ?>>
                <?= htmlspecialchars($p) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Vehicle Number</label>
            <input type="text" name="vehicle_number" placeholder="MH12AB1234"
                   value="<?= htmlspecialchars($_POST['vehicle_number'] ?? '') ?>">
          </div>

          <!-- ID Type — from DB settings -->
          <div class="form-group">
            <label>ID Proof Type<?= $requireIdProof?' *':'' ?></label>
            <select name="id_proof_type" <?= $requireIdProof?'required':'' ?>>
              <option value="">— Select ID Type —</option>
              <?php foreach ($idTypes as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Upload ID Proof<?= $requireIdProof?' *':'' ?></label>
            <input type="file" name="id_proof" accept="image/*,.pdf" <?= $requireIdProof?'required':'' ?>>
          </div>

          <!-- Host — from DB users -->
          <div class="form-group">
            <label>Visiting Flat / Host</label>
            <select name="host_id" id="hostSelect"
                    onchange="document.getElementById('flatField').value=this.options[this.selectedIndex].dataset.flat||''">
              <option value="">— Select Resident —</option>
              <?php foreach ($hosts as $h): ?>
              <option value="<?= $h['id'] ?>" data-flat="<?= htmlspecialchars($h['flat_number']) ?>"
                <?= ($_POST['host_id'] ?? '') == $h['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($h['name']) ?> — Flat <?= htmlspecialchars($h['flat_number']) ?>
              </option>
              <?php endforeach; ?>
              <?php if (empty($hosts)): ?>
              <option disabled>No residents registered yet</option>
              <?php endif; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Flat Number</label>
            <input type="text" name="flat_number" id="flatField" placeholder="e.g. A-201"
                   value="<?= htmlspecialchars($_POST['flat_number'] ?? '') ?>">
          </div>

          <!-- Parking — from DB -->
          <div class="form-group form-full">
            <label>Assign Parking Slot (optional)</label>
            <select name="parking_slot">
              <option value="">— No Parking Required —</option>
              <?php
              $lastType = '';
              foreach ($freeSlots as $ps):
                  if ($ps['slot_type'] !== $lastType):
                      if ($lastType) echo '</optgroup>';
                      echo '<optgroup label="' . htmlspecialchars($ps['slot_type']) . '">';
                      $lastType = $ps['slot_type'];
                  endif;
              ?>
              <option value="<?= $ps['id'] ?>"><?= htmlspecialchars($ps['slot_number']) ?></option>
              <?php endforeach;
              if ($lastType) echo '</optgroup>';
              if (empty($freeSlots)):
              ?><option disabled>All slots occupied</option><?php endif; ?>
            </select>
          </div>

          <div class="form-group form-full">
            <label>Notes (optional)</label>
            <textarea name="notes" rows="2" placeholder="Any special instructions..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
          </div>
        </div>
      </div>

      <!-- RIGHT: Photo Capture -->
      <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
          <div class="card-header">
            <h3>Visitor Photo<?= $requirePhoto?' <span style="color:var(--danger)">*</span>':'' ?></h3>
          </div>
          <div id="photoPlaceholder" class="webcam-box" onclick="startWebcam()" style="flex-direction:column;gap:8px;">
            <div style="font-size:40px;">📷</div>
            <p>Click to start webcam</p>
          </div>
          <div id="webcamContainer" style="display:none;">
            <video id="webcam" autoplay playsinline style="width:100%;border-radius:8px;background:#000;"></video>
            <div style="display:flex;gap:8px;margin-top:10px;">
              <button type="button" class="btn-primary" style="flex:1;" onclick="capturePhoto()">📸 Capture</button>
              <button type="button" class="btn-secondary" onclick="stopWebcam()">✕ Stop</button>
            </div>
          </div>
          <canvas id="photoCanvas" style="display:none;"></canvas>
          <div id="photoPreview" style="display:none;margin-top:10px;">
            <img id="previewImg" style="width:100%;border-radius:8px;max-height:200px;object-fit:cover;">
            <button type="button" class="btn-secondary" style="width:100%;margin-top:8px;" onclick="clearPhoto()">✕ Retake</button>
          </div>
          <input type="hidden" name="captured_photo" id="capturedPhoto">
          <div style="margin-top:10px;">
            <label class="btn-secondary" style="display:block;text-align:center;cursor:pointer;">
              📁 Upload Photo Instead
              <input type="file" name="photo_upload" accept="image/*" style="display:none;" onchange="previewUpload(this)">
            </label>
          </div>
        </div>

        <!-- Live visitor count pill -->
        <div class="card" style="padding:16px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
            <span style="font-size:.82rem;color:var(--muted);">Currently inside</span>
            <span style="font-size:1.2rem;font-weight:700;" id="insideCount">
              <?= $db->query("SELECT COUNT(*) FROM visits WHERE status='inside'")->fetchColumn() ?>
            </span>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span style="font-size:.82rem;color:var(--muted);">Free parking slots</span>
            <span style="font-size:1.2rem;font-weight:700;"><?= count($freeSlots) ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn-primary" style="padding:13px 36px;">✅ Register & Check In</button>
      <a href="/visitor-management/dashboard.php" class="btn-secondary">Cancel</a>
    </div>
  </form>
</main>

<script>
let stream = null;

function startWebcam() {
  document.getElementById('photoPlaceholder').style.display = 'none';
  document.getElementById('webcamContainer').style.display = 'block';
  navigator.mediaDevices.getUserMedia({ video: { facingMode:'user' } })
    .then(s => { stream = s; document.getElementById('webcam').srcObject = s; })
    .catch(e => { alert('Webcam unavailable: ' + e.message); document.getElementById('photoPlaceholder').style.display='flex'; });
}

function capturePhoto() {
  const video = document.getElementById('webcam');
  const canvas = document.getElementById('photoCanvas');
  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0);
  const data = canvas.toDataURL('image/png');
  document.getElementById('capturedPhoto').value = data;
  document.getElementById('previewImg').src = data;
  document.getElementById('photoPreview').style.display = 'block';
  document.getElementById('webcamContainer').style.display = 'none';
  stopWebcam();
}

function stopWebcam() {
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
}

function previewUpload(input) {
  if (!input.files?.length) return;
  const reader = new FileReader();
  reader.onload = e => {
    document.getElementById('previewImg').src = e.target.result;
    document.getElementById('photoPreview').style.display = 'block';
    document.getElementById('photoPlaceholder').style.display = 'none';
    document.getElementById('webcamContainer').style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
}

function clearPhoto() {
  document.getElementById('capturedPhoto').value = '';
  document.getElementById('photoPreview').style.display = 'none';
  document.getElementById('photoPlaceholder').style.display = 'flex';
}
</script>
</body>
</html>
