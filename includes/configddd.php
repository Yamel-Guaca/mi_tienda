<?php
// includes/config.php

// ✅ Ajuste global de zona horaria (Colombia) en PHP
date_default_timezone_set("America/Bogota");

// Datos de conexión a la base de datos
$servername = "localhost"; // o el host que te muestre Hostinger
<<<<<<< HEAD
$username   = "root";   // Usuario MySQL
$password   = "";           // ⚠️ tu contraseña real
=======
$username   = "u755147454_mitienda";   // Usuario MySQL
$password   = "N/vPLnBU@A2";           // ⚠️ tu contraseña real
>>>>>>> 1956c45c64ed310ed3aebaa10b6ead872a8da3a5
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

    // ✅ Ajuste de zona horaria en MySQL usando offset UTC-5
    $pdo->exec("SET time_zone = '-05:00'");
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
