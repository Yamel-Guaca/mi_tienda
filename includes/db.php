<?php
// includes/db.php
require_once __DIR__ . '/config.php';

class DB {
    private static $pdo = null;

    public static function getConnection() {
        global $servername, $username, $password, $database, $options;
        if (self::$pdo === null) {
            $dsn = "mysql:host={$servername};dbname={$database};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $username, $password, $options);

            // ✅ Ajuste de zona horaria en MySQL usando offset UTC-5
            self::$pdo->exec("SET time_zone = '-05:00'");
        }
        return self::$pdo;
    }
}
