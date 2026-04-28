<?php
require_once '../config/db.php';
bootSession();
if (!empty($_SESSION['user_id'])) { header('Location: ../dashboard.php'); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ../index.php'); exit(); }

$email    = trim($_POST['email']    ?? '');
$password = trim($_POST['password'] ?? '');
$role     = trim($_POST['role']     ?? '');

if (!$email || !$password || !$role) { header('Location: ../index.php?error=1'); exit(); }

try {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.email = :email
    ");
    $stmt->execute([':email' => strtolower($email)]);
    $user = $stmt->fetch();

    if (!$user) { header('Location: ../index.php?error=1'); exit(); }
    if (!$user['is_active']) { header('Location: ../index.php?error=inactive'); exit(); }
    if (!password_verify($password, $user['password'])) { header('Location: ../index.php?error=1'); exit(); }
    $role = strtolower(trim($_POST['role'] ?? ''));

    // Regenerate session ID for security
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['role']    = $user['role_name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['flat']    = $user['flat_number'];

    // Log login time
    $db->prepare("UPDATE users SET created_at=created_at WHERE id=:id")->execute([':id'=>$user['id']]);

    header('Location: ../dashboard.php');
    exit();
} catch (Throwable $e) {
    header('Location: ../index.php?error=db');
    exit();
}
