<?php
require_once __DIR__ . '/db.php';

// Hora en PHP
echo "Hora PHP: " . date("Y-m-d H:i:s") . "<br>";

// Hora en MySQL
$pdo = DB::getConnection();
$stmt = $pdo->query("SELECT NOW()");
echo "Hora MySQL: " . $stmt->fetchColumn();
?>
