<?php
require_once '../config/db.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    if ($action === 'mark_all_read') {
        $db->prepare("UPDATE notifications SET is_read=TRUE WHERE user_id=:uid")
           ->execute([':uid' => $user['id']]);
        // Redirect back if GET
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Location:/dashboard.php');
            exit();
        }
        echo json_encode(['success' => true]);

    } elseif ($action === 'mark_read') {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $db->prepare("UPDATE notifications SET is_read=TRUE WHERE id=:id AND user_id=:uid")
           ->execute([':id'=>$id,':uid'=>$user['id']]);
        echo json_encode(['success' => true]);

    } elseif ($action === 'unread_count') {
        $c = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=:uid AND is_read=FALSE");
        $c->execute([':uid'=>$user['id']]);
        echo json_encode(['count' => (int)$c->fetchColumn()]);

    } elseif ($action === 'list') {
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=:uid ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([':uid'=>$user['id']]);
        echo json_encode(['notifications' => $stmt->fetchAll()]);

    } elseif ($action === 'checkout_by_visit') {
        requireRole('admin','receptionist','guard');
        $vid = (int)($_GET['visit_id'] ?? 0);
        $v   = $db->prepare("SELECT * FROM visits WHERE id=:id AND status='inside'");
        $v->execute([':id'=>$vid]);
        $visit = $v->fetch();
        if (!$visit) { echo json_encode(['success'=>false,'message'=>'Visit not found or already checked out']); exit(); }
        $db->prepare("UPDATE visits SET check_out=NOW(),status='checked_out',guard_checkout_id=:g WHERE id=:id")
           ->execute([':g'=>$user['id'],':id'=>$vid]);
        if ($visit['parking_slot_id']) {
            $db->prepare("UPDATE parking_slots SET is_occupied=FALSE,visit_id=NULL,assigned_at=NULL WHERE id=:p")
               ->execute([':p'=>$visit['parking_slot_id']]);
        }
        echo json_encode(['success'=>true,'message'=>'Checked out successfully']);

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
