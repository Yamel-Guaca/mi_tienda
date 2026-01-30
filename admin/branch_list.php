<?php
// admin/branch_list.php
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role([1]); // Solo administradores

$pdo = DB::getConnection();

// Cargar todas las sucursales
$stmt = $pdo->query("SELECT id, name, address, nit, phone FROM branches ORDER BY id ASC");
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Listado de sucursales</title>
    <link rel="stylesheet" href="../public/css/admin.css">
</head>
<body>
    <h2>Sucursales registradas</h2>

    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>NIT</th>
                <th>Dirección</th>
                <th>Teléfono</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($branches as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['id']) ?></td>
                    <td><?= htmlspecialchars($b['name']) ?></td>
                    <td><?= htmlspecialchars($b['nit']) ?></td>
                    <td><?= htmlspecialchars($b['address']) ?></td>
                    <td><?= htmlspecialchars($b['phone']) ?></td>
                    <td>
                        <a href="branch_edit.php?id=<?= $b['id'] ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
