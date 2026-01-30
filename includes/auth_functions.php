<?php
// includes/auth_functions.php

require_once __DIR__ . '/db.php';

// Iniciar sesión solo si no está activa
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function login($email, $password) {
    $pdo = DB::getConnection();

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {

        // Variables necesarias para multisucursal / sesión
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name']    = $user['name'];
        $_SESSION['role_id'] = $user['role_id'];

        // Estructura unificada para require_role()
        $_SESSION['user'] = [
            'id'      => $user['id'],
            'name'    => $user['name'],
            'role_id' => $user['role_id']   // usar role_id consistente
        ];

        return true;
    }

    return false;
}

function require_role($roles = []) {

    // Verificar que el usuario esté logueado
    if (!isset($_SESSION['user'])) {
        header('Location: /mi_tienda/admin/login.php');
        exit;
    }

    // Verificar rol por role_id (número)
    $userRole = $_SESSION['user']['role_id'] ?? null;
    if (!in_array($userRole, $roles)) {
        http_response_code(403);
        echo "Acceso denegado";
        exit;
    }
}

function current_user() {
    return $_SESSION['user'] ?? null;
}
