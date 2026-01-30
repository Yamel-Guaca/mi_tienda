<?php
// admin/categorias.php

// Mostrar errores en desarrollo (quitar en producción)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ❌ session_start();  ← ELIMINADO

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// ✅ Solo Administrador (role_id = 1)
require_role([1]);

$pdo = DB::getConnection();

// ============================================================
// 1. SUCURSAL SELECCIONADA
// ============================================================

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// ✅ Evitar deprecated de htmlspecialchars(null)
if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ============================================================
// 2. ACCIONES CRUD
// ============================================================

$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$msg    = "";

// Crear categoría
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);

    if ($name) {
        $stmt = $pdo->prepare("INSERT INTO categories (name, active) VALUES (?, 1)");
        $stmt->execute([$name]);
        $msg = "Categoría creada correctamente.";
    } else {
        $msg = "El nombre es obligatorio.";
    }
}

// Editar categoría
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $name = trim($_POST['name']);

    if ($name) {
        $stmt = $pdo->prepare("UPDATE categories SET name=? WHERE id=?");
        $stmt->execute([$name, $id]);
        $msg = "Categoría actualizada.";
    } else {
        $msg = "El nombre es obligatorio.";
    }
}

// Activar / desactivar
if ($action === 'toggle' && $id) {
    $stmt = $pdo->prepare("UPDATE categories SET active = IF(active=1,0,1) WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Estado actualizado.";
}

// Eliminar categoría
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Categoría eliminada.";
}

// ============================================================
// 3. OBTENER LISTA DE CATEGORÍAS
// ============================================================

$categories = $pdo->query("
    SELECT * FROM categories ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Si estamos editando, obtener datos
$edit_category = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $stmt->execute([$id]);
    $edit_category = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$edit_category) {
        $msg = "Categoría no encontrada.";
        $action = null;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Categorías - Mi Tienda</title>
<style>
    body { font-family: Arial; background:#f4f4f4; margin:0; padding:0; }
    header { background:#0a6; color:#fff; padding:12px 20px; display:flex; justify-content:space-between; align-items:center; }
    .branch { font-size:14px; opacity:0.9; }
    .container { max-width:900px; margin:20px auto; background:#fff; padding:20px; border-radius:8px; }
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
    <h2>Gestión de Categorías</h2>
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

<a href="/mi_tienda/admin/categorias.php?action=new" class="btn btn-create">+ Crear Categoría</a>

<?php if ($action === 'new'): ?>
    <h3>Nueva Categoría</h3>
    <form method="POST" action="/mi_tienda/admin/categorias.php?action=create">
        <input type="text" name="name" placeholder="Nombre de la categoría" required>
        <button class="btn btn-create">Guardar</button>
    </form>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_category): ?>
    <h3>Editar Categoría</h3>
    <form method="POST" action="/mi_tienda/admin/categorias.php?action=edit&id=<?= $edit_category['id'] ?>">
        <input type="text" name="name" value="<?= htmlspecialchars($edit_category['name']) ?>" required>
        <button class="btn btn-create">Actualizar</button>
    </form>
<?php endif; ?>

<h3>Lista de Categorías</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Activo</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($categories as $c): ?>
        <?php 
            // ✅ Evitar warning: Undefined array key "active"
            $estado = isset($c['active']) && $c['active'] == 1 ? '✅' : '❌';
        ?>
        <tr>
            <td><?= $c['id'] ?></td>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= $estado ?></td>
            <td>
                <a class="btn btn-edit" href="/mi_tienda/admin/categorias.php?action=edit&id=<?= $c['id'] ?>">Editar</a>
                <a class="btn btn-toggle" href="/mi_tienda/admin/categorias.php?action=toggle&id=<?= $c['id'] ?>">Activar/Desactivar</a>
                <a class="btn btn-delete" href="/mi_tienda/admin/categorias.php?action=delete&id=<?= $c['id'] ?>" onclick="return confirm('¿Eliminar categoría?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>
