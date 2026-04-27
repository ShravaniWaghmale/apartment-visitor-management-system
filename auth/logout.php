<?php
require_once '../config/db.php';
bootSession();
session_destroy();
header('Location:/visitor-management/index.php');
exit();
