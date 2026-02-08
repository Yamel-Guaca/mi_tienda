<?php
require_once __DIR__ . '/db.php';

echo "Hora PHP: " . date("Y-m-d H:i:s") . "<br>";

$pdo = DB::getConnection();
$stmt = $pdo->query("SELECT NOW()");
echo "Hora MySQL: " . $stmt->fetchColumn();
?>
