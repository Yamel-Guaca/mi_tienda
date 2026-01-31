<?php
// includes/config.php
$servername = "localhost"; // o el host que te muestre Hostinger
$username   = "u755147454_mitienda";   // Usuario MySQL
$password   = "N/vPLnBU@A2";           // ⚠️ tu contraseña real
$database   = "u755147454_mitiendabd"; // Nombre de la base de datos

$app_version = "v1.0.0"; // ✅ aquí defines tu versión

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];
