<?php
// admin/login.php
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Mostrar errores en Hostinger para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = $_POST['email'] ?? '';
    $pass  = $_POST['password'] ?? '';

    // ✅ Validar credenciales
    if (login($email, $pass)) {

        $user_id = $_SESSION['user_id'];
        $role_id = $_SESSION['role_id'];

        $pdo = DB::getConnection();

        // ✅ Obtener datos completos del usuario y su rol
        $stmtUser = $pdo->prepare("
            SELECT u.id, u.name, u.email, u.branch_id, r.id AS role_id, r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ");
        $stmtUser->execute([$user_id]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($userRow) {
            $_SESSION['user'] = [
                'id'        => $userRow['id'],
                'name'      => $userRow['name'],
                'email'     => $userRow['email'],
                'branch_id' => $userRow['branch_id'],
                'role_id'   => $userRow['role_id'],
                'role_name' => $userRow['role_name'] // Ej: "Administrador", "Supervisor"
            ];
        }

        // ✅ Obtener sucursal directamente desde users.branch_id
        $branch_id = $userRow['branch_id'];

        if (!$branch_id) {
            $error = "No tiene sucursales asignadas. Contacte al administrador.";
            session_destroy();
        }

        // ✅ SI ES ADMINISTRADOR → ENTRA DIRECTO
        elseif ($userRow['role_id'] == 1) {
            $_SESSION['branch_id'] = $branch_id;

            $stmt2 = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
            $stmt2->execute([$branch_id]);
            $_SESSION['branch_name'] = $stmt2->fetchColumn();

            header('Location: /mi_tienda/admin/dashboard.php');
            exit;
        }

        // ✅ Cualquier usuario que NO sea administrador → ir directo al POS
        else {
            $_SESSION['branch_id'] = $branch_id;

            $stmt2 = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
            $stmt2->execute([$branch_id]);
            $_SESSION['branch_name'] = $stmt2->fetchColumn();

            header('Location: /mi_tienda/admin/pos.php?mode=tactil');
            exit;
        }

    } else {
        $error = "Credenciales inválidas";
    }
}
?>
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Login Admin</title></head>
<body>
  <h1>Login Administrador</h1>
  <?php if(!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
  <form method="post">
    <label>Email <input type="email" name="email" required></label><br>
    <label>Password <input type="password" name="password" required></label><br>
    <button type="submit">Entrar</button>
  </form>
</body>
</html>
