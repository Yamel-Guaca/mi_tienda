<?php
// admin/sucursales.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Solo Administrador
require_role([1, 2]);


$pdo = DB::getConnection();
$msg = "";
$action = $_GET['action'] ?? null;
$id     = $_GET['id'] ?? null;

// Crear sucursal
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);

    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO branches (name, active) VALUES (?, 1)");
        $stmt->execute([$name]);
        $msg = "Sucursal creada correctamente.";
    } else {
        $msg = "El nombre es obligatorio.";
    }
}

// Editar sucursal
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);

    if ($name) {
        $stmt = $pdo->prepare("UPDATE branches SET name=? WHERE id=?");
        $stmt->execute([$name, $id]);
        $msg = "Sucursal actualizada.";
    } else {
        $msg = "El nombre es obligatorio.";
    }
}

// Activar / desactivar
if ($action === 'toggle' && $id) {
    $stmt = $pdo->prepare("UPDATE branches SET active = IF(active=1,0,1) WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Estado actualizado.";
}

// Eliminar sucursal
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("DELETE FROM branches WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Sucursal eliminada.";
}

// Obtener sucursales
$branches = $pdo->query("SELECT * FROM branches ORDER BY id DESC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Sucursales - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; }
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
    input { padding:8px; width:100%; margin-bottom:10px; }
</style>
</head>
<body>

<header>
    <h2>Gestión de Sucursales</h2>    
      <!-- ✅ ENLACES RÁPIDOS COMPLETOS -->
    <p class="small" style="margin-top:12px;">Enlaces rápidos:  
      <a href="/mi_tienda/admin/dashboard.php">Salir</a>
    </p>
</header>

<div class="container">

<?php if ($msg): ?>
    <div class="msg"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Botón para crear sucursal -->
<a href="/mi_tienda/admin/sucursales.php?action=new" class="btn btn-create">+ Crear Sucursal</a>

<?php if ($action === 'new'): ?>
    <h3>Nueva Sucursal</h3>
    <form method="POST" action="/mi_tienda/admin/sucursales.php?action=create">
        <input type="text" name="name" placeholder="Nombre de la sucursal" required>
        <button class="btn btn-create">Guardar</button>
    </form>
<?php endif; ?>

<?php if ($action === 'edit' && $id): 
    $stmt = $pdo->prepare("SELECT * FROM branches WHERE id=?");
    $stmt->execute([$id]);
    $b = $stmt->fetch();
?>
    <h3>Editar Sucursal</h3>
    <form method="POST" action="/mi_tienda/admin/sucursales.php?action=edit&id=<?= $id ?>">
        <input type="text" name="name" value="<?= htmlspecialchars($b['name']) ?>" required>
        <button class="btn btn-create">Actualizar</button>
    </form>
<?php endif; ?>

<h3>Lista de Sucursales</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Activa</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($branches as $b): ?>
        <tr>
            <td><?= $b['id'] ?></td>
            <td><?= htmlspecialchars($b['name']) ?></td>
            <td><?= $b['active'] ? '✅' : '❌' ?></td>
            <td>
                <a class="btn btn-edit" href="/mi_tienda/admin/sucursales.php?action=edit&id=<?= $b['id'] ?>">Editar</a>
                <a class="btn btn-toggle" href="/mi_tienda/admin/sucursales.php?action=toggle&id=<?= $b['id'] ?>">Activar/Desactivar</a>
                <a class="btn btn-delete" href="/mi_tienda/admin/sucursales.php?action=delete&id=<?= $b['id'] ?>" onclick="return confirm('¿Eliminar sucursal?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>
