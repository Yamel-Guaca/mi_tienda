<?php
// scripts/create_admin.php
require_once __DIR__ . '/../includes/db.php'; // ajusta ruta si hace falta

$pdo = DB::getConnection();

$name = 'Administrador';
$email = 'admin@mitienda.local';
$password = 'Admin123!'; // Cambia esta contraseña antes de ejecutar
$role_id = 1; // Administrador

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role_id, active) VALUES (:name, :email, :hash, :role_id, 1)");
try {
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'hash' => $hash,
        'role_id' => $role_id
    ]);
    echo "Usuario admin creado: $email\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}





/* 
<?php
// includes/config.php

$servername = "localhost"; // o el host que te muestre Hostinger
$username   = "u755147454_mitienda";   // Usuario MySQL
$password   = "N/vPLnBU@A2";           // ⚠️ tu contraseña real
$database   = "u755147454_mitiendabd"; // Nombre de la base de datos

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];

*/
