<?php
// includes/config.php

// ✅ Ajuste global de zona horaria (Colombia)
date_default_timezone_set("America/Bogota");

// Datos de conexión a la base de datos
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

// Crear conexión PDO
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$database", $username, $password, $options);

    // ✅ Ajuste de zona horaria también en MySQL
    $pdo->exec("SET time_zone = 'America/Bogota'");
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
