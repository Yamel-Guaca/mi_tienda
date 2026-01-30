<?php
// includes/config.php
$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "mi_tienda";

$app_version = "v1.0.0"; // ✅ aquí defines tu versión

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];
