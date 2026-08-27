<?php
// admin/subcategorias.php

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

// ============================================================
// 1. SUCURSAL SELECCIONADA
// ============================================================

$currentBranchId   = $_SESSION['branch_id']   ?? null;
$currentBranchName = $_SESSION['branch_name'] ?? null;

if (!$currentBranchId) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

if (!$currentBranchName) {
    $currentBranchName = "No seleccionada";
}

// ============================================================
// 2. CARGAR CATEGORÍAS (para asignar subcategorías)
// ============================================================

$categories = $pdo->query("SELECT id, name FROM categories WHERE active = 1 ORDER BY name")
                  ->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// 3. ACCIONES CRUD
// ============================================================

$action = $_GET['action'] ?? null;
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$msg    = "";

// Crear subcategoría
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $cat  = (int)$_POST['category_id'];

    if ($name && $cat > 0) {
        $stmt = $pdo->prepare("INSERT INTO subcategories (name, category_id, active) VALUES (?, ?, 1)");
        $stmt->execute([$name, $cat]);
        $msg = "Subcategoría creada correctamente.";
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

// Editar subcategoría
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $name = trim($_POST['name']);
    $cat  = (int)$_POST['category_id'];

    if ($name && $cat > 0) {
        $stmt = $pdo->prepare("UPDATE subcategories SET name=?, category_id=? WHERE id=?");
        $stmt->execute([$name, $cat, $id]);
        $msg = "Subcategoría actualizada.";
    } else {
        $msg = "Todos los campos son obligatorios.";
    }
}

// Activar / desactivar
if ($action === 'toggle' && $id) {
    $stmt = $pdo->prepare("UPDATE subcategories SET active = IF(active=1,0,1) WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Estado actualizado.";
}

// Eliminar subcategoría
if ($action === 'delete' && $id) {
    $stmt = $pdo->prepare("DELETE FROM subcategories WHERE id=?");
    $stmt->execute([$id]);
    $msg = "Subcategoría eliminada.";
}

// ============================================================
// 4. OBTENER LISTA DE SUBCATEGORÍAS
// ============================================================

$subcategories = $pdo->query("
    SELECT s.*, c.name AS category_name
    FROM subcategories s
    JOIN categories c ON s.category_id = c.id
    ORDER BY s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Si estamos editando, obtener datos
$edit_subcategory = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM subcategories WHERE id=?");
    $stmt->execute([$id]);
    $edit_subcategory = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$edit_subcategory) {
        $msg = "Subcategoría no encontrada.";
        $action = null;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gestión de Subcategorías - Mi Tienda</title>
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
    input, select { padding:8px; width:100%; margin-bottom:10px; }
</style>
</head>
<body>

<header>
    <h2>Gestión de Subcategorías</h2>
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

<a href="/mi_tienda/admin/subcategorias.php?action=new" class="btn btn-create">+ Crear Subcategoría</a>

<?php if ($action === 'new'): ?>
    <h3>Nueva Subcategoría</h3>
    <form method="POST" action="/mi_tienda/admin/subcategorias.php?action=create">
        <input type="text" name="name" placeholder="Nombre de la subcategoría" required>

        <label>Categoría:</label>
        <select name="category_id" required>
            <option value="">Seleccione categoría</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-create">Guardar</button>
    </form>
<?php endif; ?>

<?php if ($action === 'edit' && $edit_subcategory): ?>
    <h3>Editar Subcategoría</h3>
    <form method="POST" action="/mi_tienda/admin/subcategorias.php?action=edit&id=<?= $edit_subcategory['id'] ?>">
        <input type="text" name="name" value="<?= htmlspecialchars($edit_subcategory['name']) ?>" required>

        <label>Categoría:</label>
        <select name="category_id" required>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= ($edit_subcategory['category_id'] == $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-create">Actualizar</button>
    </form>
<?php endif; ?>

<h3>Lista de Subcategorías</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Subcategoría</th>
            <th>Categoría</th>
            <th>Activo</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($subcategories as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['category_name']) ?></td>
            <td><?= $s['active'] ? '✅' : '❌' ?></td>
            <td>
                <a class="btn btn-edit" href="/mi_tienda/admin/subcategorias.php?action=edit&id=<?= $s['id'] ?>">Editar</a>
                <a class="btn btn-toggle" href="/mi_tienda/admin/subcategorias.php?action=toggle&id=<?= $s['id'] ?>">Activar/Desactivar</a>
                <a class="btn btn-delete" href="/mi_tienda/admin/subcategorias.php?action=delete&id=<?= $s['id'] ?>" onclick="return confirm('¿Eliminar subcategoría?')">Eliminar</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

</body>
</html>
