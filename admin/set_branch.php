<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_POST['branch_id'])) {
    header("Location: login.php");
    exit;
}

$branch_id = (int)$_POST['branch_id'];

$pdo = DB::getConnection();

// Obtener nombre de la sucursal
$stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch_name = $stmt->fetchColumn();

// Guardar en sesión
$_SESSION['branch_id'] = $branch_id;
$_SESSION['branch_name'] = $branch_name;

// Ya no necesitamos temp_user_id
unset($_SESSION['temp_user_id']);

header("Location: dashboard.php");
exit;
