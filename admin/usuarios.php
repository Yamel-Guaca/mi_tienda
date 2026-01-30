<?php
// admin/usuarios.php

// Mostrar errores en desarrollo (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ✅ NO usar session_start() aquí (ya está en auth_functions.php)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (role_id = 1)
require_role([1]);

$pdo = DB::getConnection();

// ✅ Sucursal desde sesión
$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

// ✅ Validar sucursal seleccionada
if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// ✅ Evitar error de htmlspecialchars(null)
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}


// ============================================================
// 1. CARGAR SUCURSALES PARA SELECT
// ============================================================

$branches = $pdo->query("SELECT id, name FROM branches ORDER BY name")->fetchAll();


// ============================================================
// 2. ACCIONES CRUD DE USUARIOS
// ============================================================

$action = $_GET['action'] ?? null;
$id     = $_GET['id'] ?? null;
$msg    = "";

// Crear usuario
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']);
    $email     = trim($_POST['email']);
    $role      = intval($_POST['role_id']);
    $pass      = $_POST['password'];
    $branch_id = intval($_POST['branch_id']);

    if ($name && $email && $role && $pass && $branch_id) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password_hash, role_id, branch_id, active)
            VALUES (?,?,?,?,?,1)
        ");
        $stmt->execute([$name, $email, $hash, $role, $branch_id]);
        $msg = "Usuario creado correctamente.";
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

// Editar usuario
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']);
    $email     = trim($_POST['email']);
    $role      = intval($_POST['role_id']);
    $pass      = $_POST['password'];
    $branch_id = intval($_POST['branch_id']);

    if ($name && $email && $role && $branch_id) {
        if ($pass) {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users SET name=?, email=?, role_id=?, branch_id=?, password_hash=? WHERE id=?
            ");
            $stmt->execute([$name, $email, $role, $branch_id, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users SET name=?, email=?, role_id=?, branch_id=? WHERE id=?
            ");
            $stmt->execute([$name, $email, $role, $branch_id, $id]);
        }
        $msg = "Usuario actualizado.";
    } else {
        $msg = "Nombre, email, rol y sucursal son obligatorios.";
    }
}

// Activar / desactivar
if ($action === 'toggle' && $id) {
    $stmt = $pdo->prepare("UPDATE users SET active = IF(active=1,0,1) WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Estado actualizado.";
}

// Eliminar usuario
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Usuario eliminado.";
}

// Obtener roles
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY id")->fetchAll();

// ✅ Obtener usuarios con sucursal
$users = $pdo->query("
    SELECT 
        u.*, 
        r.name AS role_name,
        b.name AS branch_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN branches b ON b.id = u.branch_id
    ORDER BY u.id DESC
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Usuarios - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:1100px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { padding:10px; border-bottom:1px solid #ddd; }
    th { background:#eee; }
    .btn { padding:6px 10px; border-radius:4px; text-decoration:none; color:#fff; }
    .btn-edit { background:#007bff; }
    .btn-delete { background:#d9534f; }
    .btn-toggle { background:#5bc0de; }
    .btn-create { background:#5cb85c; margin-bottom:15px; display:inline-block; }
    .msg { background:#dff0d8; padding:10px; border-radius:6px; margin-bottom:10px; }
    form { margin-top:20px; }
    input, select { padding:8px; width:100%; margin-bottom:10px; }
</style>
</head>
<body>

<header>
    <h2>Gestión de Usuarios</h2>
    <div class="branch">Sucursal actual: <strong><?= htmlspecialchars($currentBranchName) ?></strong></div>
      <!-- ✅ ENLACES RÁPIDOS COMPLETOS -->
    <p class="small" style="margin-top:12px;">Enlaces rápidos:  
      <a href="/mi_tienda/admin/dashboard.php">Salir</a>
    </p>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Botón para crear usuario -->
<a href="/mi_tienda/admin/usuarios.php?action=new" class="btn btn-create">+ Crear Usuario</a>

<?php if ($action === 'new'): ?>
    <h3>Nuevo Usuario</h3>
    <form method="POST" action="/mi_tienda/admin/usuarios.php?action=create">
        <input type="text" name="name" placeholder="Nombre completo" required>
        <input type="email" name="email" placeholder="Correo" required>

        <select name="role_id" required>
            <option value="">Seleccione rol</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>"><?= $r['name'] ?></option>
            <?php endforeach; ?>
        </select>

        <!-- ✅ Selección de sucursal -->
        <select name="branch_id" required>
            <option value="">Seleccione sucursal</option>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="password" name="password" placeholder="Contraseña" required>
        <button class="btn btn-create">Guardar</button>
    </form>
<?php endif; ?>

<?php if ($action === 'edit' && $id): 
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
?>
    <h3>Editar Usuario</h3>
    <form method="POST" action="/mi_tienda/admin/usuarios.php?action=edit&id=<?= $id ?>">
        <input type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>" required>
        <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required>

        <select name="role_id" required>
            <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $r['id']==$u['role_id']?'selected':'' ?>>
                    <?= $r['name'] ?>
                </option>
            <?php endforeach; ?>
        </select>

        <!-- ✅ Selección de sucursal -->
        <select name="branch_id" required>
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $b['id']==$u['branch_id']?'selected':'' ?>>
                    <?= htmlspecialchars($b['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="password" name="password" placeholder="Nueva contraseña (opcional)">
        <button class="btn btn-create">Actualizar</button>
    </form>
<?php endif; ?>

<h3>Lista de Usuarios</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Sucursal</th>
            <th>Activo</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role_name']) ?></td>

            <!-- ✅ Mostrar sucursal -->
            <td><?= htmlspecialchars($u['branch_name'] ?? 'Sin sucursal') ?></td>

            <td><?= $u['active'] ? '✅' : '❌' ?></td>
            <td>
                <a class="btn btn-edit" href="/mi_tienda/admin/usuarios.php?action=edit&id=<?= $u['id'] ?>">Editar</a>
                <a class="btn btn-toggle" href="/mi_tienda/admin/usuarios.php?action=toggle&id=<?= $u['id'] ?>">Activar/Desactivar</a>
                <a class="btn btn-delete" href="/mi_tienda/admin/usuarios.php?action=delete&id=<?= $u['id'] ?>" onclick="return confirm('¿Eliminar usuario?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>
