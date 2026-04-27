<?php
require_once '../config/db.php';
requireLogin();
$user = currentUser();
$db   = getDB();

header('Content-Type: application/json');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'checkout_by_visit') {
        requireRole('admin','receptionist','guard');
        $vid = (int)($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);
        $v   = $db->prepare("SELECT v.*,vi.full_name FROM visits v JOIN visitors vi ON v.visitor_id=vi.id WHERE v.id=:id AND v.status='inside'");
        $v->execute([':id'=>$vid]);
        $visit = $v->fetch();
        if (!$visit) { echo json_encode(['success'=>false,'message'=>'Not found or already checked out']); exit(); }
        $db->prepare("UPDATE visits SET check_out=NOW(),status='checked_out',guard_checkout_id=:g WHERE id=:id")
           ->execute([':g'=>$user['id'],':id'=>$vid]);
        if ($visit['parking_slot_id']) {
            $db->prepare("UPDATE parking_slots SET is_occupied=FALSE,visit_id=NULL,assigned_at=NULL WHERE id=:p")
               ->execute([':p'=>$visit['parking_slot_id']]);
        }
        echo json_encode(['success'=>true,'message'=>$visit['full_name'].' checked out.']);

    } elseif ($action === 'live_stats') {
        checkOverstays();
        $stats = $db->query("
            SELECT
                COUNT(*) FILTER(WHERE status='inside') AS inside,
                COUNT(*) FILTER(WHERE check_in::date=CURRENT_DATE) AS today,
                COUNT(*) FILTER(WHERE status='inside' AND overstay_flagged=TRUE) AS overstay
            FROM visits
        ")->fetch();
        $parking = $db->query("SELECT COUNT(*) FROM parking_slots WHERE is_occupied=TRUE")->fetchColumn();
        echo json_encode(array_merge($stats, ['parking'=>(int)$parking, 'ts'=>time()]));

    } elseif ($action === 'search_visitor') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo json_encode([]); exit(); }
        $stmt = $db->prepare("SELECT id,full_name,phone,qr_token FROM visitors WHERE (full_name ILIKE :q OR phone ILIKE :q) AND is_blacklisted=FALSE LIMIT 8");
        $stmt->execute([':q'=>"%$q%"]);
        echo json_encode($stmt->fetchAll());

    } elseif ($action === 'cancel_appointment') {
        $aptId = (int)($_POST['apt_id'] ?? 0);
        $db->prepare("UPDATE appointments SET status='cancelled' WHERE id=:id")->execute([':id'=>$aptId]);
        echo json_encode(['success'=>true]);

    } elseif ($action === 'blacklist_check') {
        $q = trim($_GET['q'] ?? '');
        $stmt = $db->prepare("SELECT full_name,phone,blacklist_reason FROM visitors WHERE (full_name ILIKE :q OR phone ILIKE :q) AND is_blacklisted=TRUE LIMIT 5");
        $stmt->execute([':q'=>"%$q%"]);
        echo json_encode($stmt->fetchAll());

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: '.$action]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
