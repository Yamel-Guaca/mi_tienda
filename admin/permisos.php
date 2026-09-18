<?php
// admin/permisos.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

/* ============================================================
   1. VALIDACIÓN DE ACCESO DE ADMINISTRADOR
============================================================ */
$roleSession = $_SESSION['user_role'] ?? $_SESSION['role_id'] ?? $_SESSION['role'] ?? null;

$isAdmin = false;
if ($roleSession !== null) {
    if (intval($roleSession) === 1 || strtolower((string)$roleSession) === 'admin') {
        $isAdmin = true;
    }
}

if (!$isAdmin) {
    die("Acceso restringido únicamente para el Administrador General.");
}

/* ============================================================
   2. MÓDULOS DE PERMISOS ASIGNABLES
============================================================ */
$modules = [
    'compras'    => 'Ingreso de Compras / Facturas',
    'productos'  => 'Gestión de Productos',
    'inventario' => 'Ajustes de Inventario',
    'ventas'     => 'Punto de Venta / Facturación',
    'gastos'     => 'Gestión de Gastos',
    'reportes'   => 'Reportes y Estadísticas'
];

/* ============================================================
   3. PROCESAR CAMBIO DE PERMISO VÍA AJAX (POST)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_permission'])) {
    header('Content-Type: application/json');
    $u_id     = intval($_POST['user_id'] ?? 0);
    $perm_key = trim($_POST['permission_key'] ?? '');
    $status   = intval($_POST['status'] ?? 0); // 1 = conceder, 0 = revocar

    if ($u_id > 0 && !empty($perm_key)) {
        try {
            if ($status === 1) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO user_permissions (user_id, permission_key) VALUES (?, ?)");
                $stmt->execute([$u_id, $perm_key]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ? AND permission_key = ?");
                $stmt->execute([$u_id, $perm_key]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    }
    exit;
}

/* ============================================================
   4. CONSULTAR USUARIOS (Filtrando por role_id != 1)
============================================================ */
$stmtUsers = $pdo->query("
    SELECT u.id, u.name, u.email, COALESCE(r.name, 'Sin Rol') AS role_name, u.role_id 
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.role_id != 1
    ORDER BY u.name ASC
");
$users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   5. CONSULTAR MATRIZ DE PERMISOS ACTUALES
============================================================ */
$rawPerms = $pdo->query("SELECT user_id, permission_key FROM user_permissions")->fetchAll(PDO::FETCH_ASSOC);
$userPermissions = [];
foreach ($rawPerms as $rp) {
    $userPermissions[$rp['user_id']][$rp['permission_key']] = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel de Control de Permisos</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 20px; background: #f4f6f8; }
        .container { max-width: 1100px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        h2 { margin-top: 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; }
        th { background: #f8fafc; font-size: 14px; }
        .switch { position: relative; display: inline-block; width: 44px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 22px; }
        .slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
        input:checked + .slider { background-color: #16a34a; }
        input:checked + .slider:before { transform: translateX(22px); }
        .user-badge { background: #e2e8f0; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; color: #334155; display: inline-block; margin-top: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Control de Permisos de Usuarios</h2>
    <p>Concede o revoca el acceso a los diferentes módulos del sistema con un clic.</p>

    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <?php foreach ($modules as $key => $name): ?>
                    <th style="text-align: center;"><?= htmlspecialchars($name) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr>
                    <td colspan="<?= count($modules) + 1 ?>" style="text-align: center; color: #64748b;">
                        No hay usuarios registrados para asignar permisos.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($u['name']) ?></strong><br>
                            <small style="color: #64748b;"><?= htmlspecialchars($u['email']) ?></small><br>
                            <span class="user-badge"><?= htmlspecialchars($u['role_name']) ?></span>
                        </td>
                        <?php foreach ($modules as $key => $name): 
                            $hasPerm = !empty($userPermissions[$u['id']][$key]);
                        ?>
                            <td style="text-align: center;">
                                <label class="switch">
                                    <input type="checkbox" <?= $hasPerm ? 'checked' : '' ?> onchange="togglePermission(<?= $u['id'] ?>, '<?= $key ?>', this)">
                                    <span class="slider"></span>
                                </label>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function togglePermission(userId, permKey, checkbox) {
    const status = checkbox.checked ? 1 : 0;
    const formData = new FormData();
    formData.append('toggle_permission', '1');
    formData.append('user_id', userId);
    formData.append('permission_key', permKey);
    formData.append('status', status);

    fetch('permisos.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            alert('Error actualizando permiso: ' + (data.error || 'Desconocido'));
            checkbox.checked = !checkbox.checked;
        }
    })
    .catch(err => {
        alert('Error de conexión con el servidor');
        checkbox.checked = !checkbox.checked;
    });
}
</script>

</body>
</html>