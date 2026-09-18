<?php
// admin/crear_pedido.php
// Gestión de solicitudes de pedido: creación, listado, recepción parcial/total, buscador Select2, filtros y paginación.
// Corrección: LIMIT y OFFSET se inyectan como enteros en la consulta para evitar error de sintaxis en MariaDB.

// AJUSTE SEGURIDAD: Desactivar despliegue de errores en pantalla para producción y mandar a log
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Acceso: Administrador (1), Cajero (3) y Auxiliar (4)
require_role([1, 3, 4]);

$pdo = DB::getConnection();

/* ------------------ Asegurar tablas ------------------ */
$pdo->exec("
CREATE TABLE IF NOT EXISTS purchase_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    status ENUM('pending','received','cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_at TIMESTAMP NULL DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS purchase_request_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ------------------ CSRF token ------------------ */
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/* ------------------ Helpers ------------------ */
function get_request_items($pdo, $request_id) {
    $stmt = $pdo->prepare("
        SELECT pri.id, pri.product_id, pri.quantity, p.name, p.sku
        FROM purchase_request_items pri
        LEFT JOIN products p ON pri.product_id = p.id
        WHERE pri.request_id = ?
    ");
    $stmt->execute([(int)$request_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ------------------ Manejo de POST: crear solicitud ------------------ */
$error = '';
$success = '';

// AJUSTE SEGURIDAD: Obtener datos de la sesión validados en el servidor
$current_branch_id = (int)($_SESSION['branch_id'] ?? 0);
$current_user_id   = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['branch_id']) && isset($_POST['qty']) && !isset($_POST['action'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Solicitud inválida (CSRF).";
    } else {
        // AJUSTE SEGURIDAD: Usar branch_id de la sesión para evitar suplantación de la sucursal enviada desde el cliente
        $branch_id = $current_branch_id;
        $qtys = is_array($_POST['qty']) ? $_POST['qty'] : []; // array product_id => qty

        $items_to_insert = [];
        foreach ($qtys as $product_id => $q) {
            $product_id = (int)$product_id;
            $q = (int)$q;
            if ($product_id > 0 && $q > 0) {
                $items_to_insert[$product_id] = $q;
            }
        }

        if (empty($items_to_insert)) {
            $error = "No se indicó ninguna cantidad válida para solicitar.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmtReq = $pdo->prepare("INSERT INTO purchase_requests (branch_id, user_id, status) VALUES (?, ?, 'pending')");
                $stmtReq->execute([$branch_id, $current_user_id]);
                $request_id = $pdo->lastInsertId();

                $stmtItem = $pdo->prepare("INSERT INTO purchase_request_items (request_id, product_id, quantity) VALUES (?, ?, ?)");
                foreach ($items_to_insert as $pid => $q) {
                    $stmtItem->execute([$request_id, $pid, $q]);
                }

                $pdo->commit();
                session_regenerate_id(true);
                $success = "Solicitud creada correctamente. ID: " . (int)$request_id;

                // Ajuste solicitado: marcar en sesión que ya se envió una solicitud
                // Esto hará que inventario_bajo.php deje de mostrar el listado hasta que la solicitud sea recibida o la bandera sea removida.
                $_SESSION['solicitud_enviada'] = true;

            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error al crear la solicitud: " . $e->getMessage());
                $error = "Error al crear la solicitud.";
            }
        }
    }
}

/* ------------------ Manejo de POST: marcar solicitud completa como recibida ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive' && isset($_POST['request_id'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Solicitud inválida (CSRF).";
    } else {
        $request_id = (int)$_POST['request_id'];

        // AJUSTE SEGURIDAD: Verificar ownership de la sucursal antes de proceder
        $stmtCheck = $pdo->prepare("SELECT branch_id FROM purchase_requests WHERE id = ?");
        $stmtCheck->execute([$request_id]);
        $reqData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$reqData || (int)$reqData['branch_id'] !== $current_branch_id) {
            $error = "Acceso denegado: La solicitud no pertenece a su sucursal.";
        } else {
            $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM purchase_request_items WHERE request_id = ?");
            $stmtItems->execute([$request_id]);
            $reqItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($reqItems)) {
                $error = "Solicitud no encontrada o sin ítems.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmtSelectInv = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND branch_id = ? FOR UPDATE");
                    $stmtUpdateInv = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
                    $stmtInsertInv = $pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, ?)");

                    foreach ($reqItems as $ri) {
                        $pid = (int)$ri['product_id'];
                        $q = (int)$ri['quantity'];

                        $stmtSelectInv->execute([$pid, $current_branch_id]);
                        $invRow = $stmtSelectInv->fetch(PDO::FETCH_ASSOC);

                        if ($invRow) {
                            $stmtUpdateInv->execute([$q, $invRow['id']]);
                        } else {
                            $stmtInsertInv->execute([$pid, $current_branch_id, $q]);
                        }
                    }

                    $stmtUpdReq = $pdo->prepare("UPDATE purchase_requests SET status = 'received', received_at = NOW() WHERE id = ?");
                    $stmtUpdReq->execute([$request_id]);

                    $pdo->commit();
                    $success = "Solicitud marcada como recibida y stock actualizado.";

                    // Si se recibe la solicitud, quitamos la bandera para que inventario_bajo vuelva a mostrar productos si aplica
                    if (isset($_SESSION['solicitud_enviada'])) {
                        unset($_SESSION['solicitud_enviada']);
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Error al procesar la recepción: " . $e->getMessage());
                    $error = "Error al procesar la recepción.";
                }
            }
        }
    }
}

/* ------------------ Manejo de POST: recepción parcial por ítems ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_items' && isset($_POST['request_id'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Solicitud inválida (CSRF).";
    } else {
        $request_id = (int)$_POST['request_id'];
        $received = is_array($_POST['received_qty'] ?? null) ? $_POST['received_qty'] : []; // array product_id => qty received

        // AJUSTE SEGURIDAD: Verificar ownership de la sucursal antes de proceder
        $stmtCheck = $pdo->prepare("SELECT branch_id FROM purchase_requests WHERE id = ?");
        $stmtCheck->execute([$request_id]);
        $reqData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$reqData || (int)$reqData['branch_id'] !== $current_branch_id) {
            $error = "Acceso denegado: La solicitud no pertenece a su sucursal.";
        } else {
            $stmtItems = $pdo->prepare("SELECT id, product_id, quantity FROM purchase_request_items WHERE request_id = ?");
            $stmtItems->execute([$request_id]);
            $reqItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            if (empty($reqItems)) {
                $error = "Solicitud no encontrada o sin ítems.";
            } else {
                $itemsMap = [];
                foreach ($reqItems as $it) {
                    $itemsMap[(int)$it['product_id']] = $it;
                }

                $toProcess = [];
                foreach ($received as $pid => $rqty) {
                    $pid = (int)$pid;
                    $rqty = (int)$rqty;
                    if ($pid > 0 && $rqty > 0 && isset($itemsMap[$pid])) {
                        $toProcess[$pid] = min($rqty, (int)$itemsMap[$pid]['quantity']);
                    }
                }

                if (empty($toProcess)) {
                    $error = "No se indicó ninguna cantidad recibida válida.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $stmtSelectInv = $pdo->prepare("SELECT id, quantity FROM inventory WHERE product_id = ? AND branch_id = ? FOR UPDATE");
                        $stmtUpdateInv = $pdo->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
                        $stmtInsertInv = $pdo->prepare("INSERT INTO inventory (product_id, branch_id, quantity) VALUES (?, ?, ?)");
                        $stmtDeleteItem = $pdo->prepare("DELETE FROM purchase_request_items WHERE id = ?");
                        $stmtUpdateItemQty = $pdo->prepare("UPDATE purchase_request_items SET quantity = ? WHERE id = ?");

                        foreach ($toProcess as $pid => $rqty) {
                            $itemRow = $itemsMap[$pid];
                            $requestedQty = (int)$itemRow['quantity'];
                            $itemId = (int)$itemRow['id'];

                            $stmtSelectInv->execute([$pid, $current_branch_id]);
                            $invRow = $stmtSelectInv->fetch(PDO::FETCH_ASSOC);
                            if ($invRow) {
                                $stmtUpdateInv->execute([$rqty, $invRow['id']]);
                            } else {
                                $stmtInsertInv->execute([$pid, $current_branch_id, $rqty]);
                            }

                            if ($rqty >= $requestedQty) {
                                $stmtDeleteItem->execute([$itemId]);
                            } else {
                                $newQty = $requestedQty - $rqty;
                                $stmtUpdateItemQty->execute([$newQty, $itemId]);
                            }
                        }

                        $stmtRemain = $pdo->prepare("SELECT COUNT(*) FROM purchase_request_items WHERE request_id = ?");
                        $stmtRemain->execute([$request_id]);
                        $remaining = (int)$stmtRemain->fetchColumn();

                        if ($remaining === 0) {
                            $stmtUpdReq = $pdo->prepare("UPDATE purchase_requests SET status = 'received', received_at = NOW(), notes = CONCAT(IFNULL(notes,''), ' | Recepción parcial completada: todos los ítems recibidos') WHERE id = ?");
                            $stmtUpdReq->execute([$request_id]);
                            $success = "Recepción registrada. Todos los ítems de la solicitud han sido recibidos.";

                            // Si la recepción parcial completó la solicitud, quitar la bandera
                            if (isset($_SESSION['solicitud_enviada'])) {
                                unset($_SESSION['solicitud_enviada']);
                            }
                        } else {
                            $stmtUpdReq = $pdo->prepare("UPDATE purchase_requests SET status = 'pending', notes = CONCAT(IFNULL(notes,''), ' | Recepción parcial: se recibieron ítems (ver historial)') WHERE id = ?");
                            $stmtUpdReq->execute([$request_id]);
                            $success = "Recepción parcial registrada. Quedan $remaining ítem(s) pendientes en la solicitud.";
                        }

                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        error_log("Error al procesar la recepción parcial: " . $e->getMessage());
                        $error = "Error al procesar la recepción parcial.";
                    }
                }
            }
        }
    }
}

/* ------------------ Listado con filtros y paginación (CORRECCIÓN LIMIT/OFFSET) ------------------ */
$branchFilter = $current_branch_id;
$statusFilter = in_array($_GET['status'] ?? '', ['pending','received','cancelled'], true) ? $_GET['status'] : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

$params = [];
$sqlWhere = "WHERE pr.branch_id = ?";
$params[] = $branchFilter;

/*
  Ajuste: por defecto ocultamos las solicitudes con estado 'received' para no saturar la página.
  Si el usuario selecciona explícitamente 'received' en el filtro, se mostrarán.
*/
if ($statusFilter === 'all') {
    $sqlWhere .= " AND pr.status != 'received'";
} else {
    $sqlWhere .= " AND pr.status = ?";
    $params[] = $statusFilter;
}

/* total para paginación */
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM purchase_requests pr $sqlWhere");
$stmtCount->execute($params);
$totalRequests = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRequests / $perPage));

/*
  CORRECCIÓN IMPORTANTE:
  MariaDB/MySQL no siempre permite pasar LIMIT y OFFSET como parámetros que PDO quoteará.
  Para evitar el error de sintaxis, inyectamos los valores ya validados como enteros directamente en la consulta.
*/
$limitInt = (int)$perPage;
$offsetInt = (int)$offset;

$sql = "
    SELECT pr.id, pr.branch_id, pr.user_id, pr.status, pr.created_at, pr.received_at, pr.notes, u.name AS requester
    FROM purchase_requests pr
    LEFT JOIN users u ON pr.user_id = u.id
    $sqlWhere
    ORDER BY pr.created_at DESC
    LIMIT $limitInt OFFSET $offsetInt
";
$stmtPending = $pdo->prepare($sql);
$stmtPending->execute($params);
$requests = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

/* ------------------ Productos para Select2 (local data) ------------------ */
$stmtProducts = $pdo->prepare("SELECT id, name, sku FROM products WHERE active = 1 ORDER BY name");
$stmtProducts->execute();
$allProducts = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);

$productsForJs = [];
foreach ($allProducts as $p) {
    $productsForJs[] = [
        'id' => (int)$p['id'],
        'text' => $p['name'] . ' — ' . $p['sku'],
        'name' => $p['name'],
        'sku' => $p['sku']
    ];
}
$productsJson = json_encode($productsForJs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Crear / Ver solicitudes de pedido</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    body{font-family:Arial,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;margin:0;background:#f6f7fb;color:#222}
    .wrap{max-width:1100px;margin:20px auto;padding:18px;background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(2,6,23,0.06)}
    header{display:flex;align-items:center;gap:12px;justify-content:space-between;margin-bottom:12px}
    h1{margin:0 0 12px 0;color:#0d6efd}
    .msg{padding:10px;border-radius:8px;margin-bottom:12px}
    .err{background:#fff0f0;border:1px solid #f5c2c2;color:#8b1d1d}
    .ok{background:#f0fff6;border:1px solid #b7f0d0;color:#0b6b3a}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:8px;border-bottom:1px solid #eee;text-align:left}
    th{background:#fafafa}
    .btn{display:inline-block;padding:8px 12px;border-radius:8px;text-decoration:none;color:#fff;border:0;cursor:pointer}
    .btn-receive{background:#198754}
    .btn-back{background:#28a745}
    .btn-create{background:#6f42c1}
    .small{font-size:0.9rem;color:#666}
    .items{margin-top:8px;padding:10px;background:#fbfbff;border-radius:8px}
    .meta{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
    form.inline{display:inline}
    .form-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
    .form-row select, .form-row input[type="number"]{padding:8px;border-radius:6px;border:1px solid #ddd}
    .add-row{background:#0d6efd;color:#fff;border:none;padding:8px 10px;border-radius:6px;cursor:pointer}
    .remove-row{background:#dc3545;color:#fff;border:none;padding:6px 8px;border-radius:6px;cursor:pointer}
    .note{font-size:0.9rem;color:#555;margin-top:8px}
    .selected-preview{font-size:0.9rem;color:#333;margin-left:6px}
    .filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
    .pagination{display:flex;gap:6px;align-items:center;margin-top:12px}
    .page-link{padding:6px 10px;border-radius:6px;background:#f1f3f5;color:#333;text-decoration:none}
    .page-link.active{background:#0d6efd;color:#fff}
    .page-link.disabled{opacity:0.5;pointer-events:none}
    .select2-container--default .select2-selection--single { height:40px; border-radius:6px; }
    .receive-form { margin-top:10px; }
    .receive-input { width:90px; padding:6px; border-radius:6px; border:1px solid #ddd; }
</style>
</head>
<body>
<div class="wrap">
    <header>
        <div>
            <h1>Solicitudes de pedido</h1>
            <div class="small">Sucursal: <?= htmlspecialchars($_SESSION['branch_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div>
            <a class="btn btn-back" href="/mi_tienda/admin/pos.php" style="text-decoration:none;color:#fff;padding:8px 12px;border-radius:8px;display:inline-flex;align-items:center;gap:8px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="white" viewBox="0 0 16 16" aria-hidden="true">
                  <path fill-rule="evenodd" d="M15 8a.5.5 0 0 1-.5.5H3.707l3.147 3.146a.5.5 0 0 1-.708.708l-4-4a.5.5 0 0 1 0-.708l4-4a.5.5 0 1 1 .708.708L3.707 7.5H14.5A.5.5 0 0 1 15 8z"/>
                </svg>
                Volver al POS
            </a>
        </div>
    </header>

    <?php if ($error): ?>
        <div class="msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="msg ok"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Crear solicitud manual -->
    <section>
        <h2 style="font-size:1rem;margin-bottom:6px">Crear solicitud manual</h2>
        <p class="small">Busca por nombre o SKU, selecciona y añade cantidad. Puedes agregar varias filas.</p>

        <form id="manualRequestForm" method="post" action="/mi_tienda/admin/crear_pedido.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="branch_id" value="<?= (int)($current_branch_id) ?>">

            <div id="rowsContainer">
                <div class="form-row" data-row-index="0">
                    <select name="product_select[]" class="product-select" style="min-width:320px" required></select>
                    <input type="number" min="1" class="qty-input" placeholder="Cantidad" required>
                    <button type="button" class="remove-row" onclick="removeRow(this)" title="Eliminar fila">Eliminar</button>
                    <div class="selected-preview" aria-hidden="true"></div>
                </div>
            </div>

            <div style="margin-top:8px;">
                <button type="button" class="add-row" onclick="addRow()">+ Agregar producto</button>
            </div>

            <div style="margin-top:12px;">
                <button type="submit" class="btn btn-create">Crear solicitud</button>
            </div>
        </form>

        <div class="note">El buscador usa Select2 para autocompletar y búsqueda por nombre/SKU. Si no seleccionas una opción válida, la fila será ignorada.</div>
    </section>

    <!-- Filtros -->
    <div class="filters" style="margin-top:18px;">
        <form method="get" style="display:flex;gap:8px;align-items:center;">
            <label for="status">Filtrar estado:</label>
            <select id="status" name="status" onchange="this.form.submit()">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Todos (oculta recibidos)</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="received" <?= $statusFilter === 'received' ? 'selected' : '' ?>>Received</option>
                <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </form>
        <div style="margin-left:auto;" class="small">Total solicitudes: <?= $totalRequests ?></div>
    </div>

    <!-- Listado de solicitudes con recepción parcial -->
    <section style="margin-top:12px">
        <h2 style="font-size:1rem;margin-bottom:6px">Solicitudes</h2>

        <?php if (empty($requests)): ?>
            <p class="small">No hay solicitudes registradas para esta sucursal con el filtro seleccionado.</p>
        <?php else: ?>
            <?php foreach ($requests as $r): ?>
                <div style="margin-bottom:14px;padding:12px;border:1px solid #eee;border-radius:8px">
                    <div class="meta">
                        <div><strong>ID:</strong> <?= (int)$r['id'] ?></div>
                        <div><strong>Estado:</strong> <?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div><strong>Creada:</strong> <?= htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div><strong>Solicitante:</strong> <?= htmlspecialchars($r['requester'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($r['status'] === 'received'): ?>
                            <div><strong>Recibida:</strong> <?= htmlspecialchars($r['received_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="items">
                        <strong>Ítems:</strong>
                        <table>
                            <thead><tr><th>Producto</th><th>SKU</th><th>Cantidad solicitada</th><th>Recibir ahora</th></tr></thead>
                            <tbody>
                                <?php
                                    $reqItems = get_request_items($pdo, $r['id']);
                                    foreach ($reqItems as $it):
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['name'] ?? 'N/D', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($it['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= (int)$it['quantity'] ?></td>
                                    <td>
                                        <input class="receive-input" type="number" min="0" max="<?= (int)$it['quantity'] ?>" name="received_qty[<?= (int)$it['product_id'] ?>]" form="receiveForm_<?= (int)$r['id'] ?>" value="0" />
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <form id="receiveForm_<?= (int)$r['id'] ?>" method="post" class="receive-form" onsubmit="return confirm('Registrar recepción parcial para la solicitud <?= (int)$r['id'] ?>? Solo se aceptarán las cantidades indicadas.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="receive_items">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-receive">Registrar recepción parcial</button>

                            <!--<button type="submit" formaction="/mi_tienda/admin/crear_pedido.php" formmethod="post" name="action" value="receive" class="btn" style="background:#0d6efd;margin-left:8px;">
                                Marcar todo como recibido
                            </button>-->
                        </form>
                    </div>

                    <?php if (!empty($r['notes'])): ?>
                        <!-- AJUSTE SEGURIDAD: Escape de notas contra vulnerabilidades XSS -->
                        <div style="margin-top:8px;font-size:0.9rem;color:#555;"><strong>Notas:</strong> <?= htmlspecialchars($r['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Paginación -->
            <div class="pagination" role="navigation" aria-label="Paginación">
                <?php
                    $baseUrl = strtok($_SERVER["REQUEST_URI"], '?');
                    $statusParam = $statusFilter !== 'all' ? '&status=' . urlencode($statusFilter) : '';
                    for ($p = 1; $p <= $totalPages; $p++):
                        $class = $p === $page ? 'page-link active' : 'page-link';
                        $href = $baseUrl . '?page=' . $p . $statusParam;
                ?>
                    <a class="<?= $class ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Select2 JS (CDN) -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const products = <?= $productsJson ?>;

function initSelect2($select) {
    $select.select2({
        data: products,
        placeholder: '-- Buscar producto por nombre o SKU --',
        width: 'resolve',
        templateResult: function(item) {
            if (!item.id) return item.text;
            return '<div><strong>' + escapeHtml(item.name) + '</strong><div style="font-size:0.85rem;color:#666">SKU: ' + escapeHtml(item.sku) + '</div></div>';
        },
        templateSelection: function(item) {
            if (!item.id) return item.text;
            return escapeHtml(item.text);
        },
        escapeMarkup: function(m) { return m; }
    });

    $select.on('select2:select', function(e) {
        const data = e.params.data;
        const preview = $select.closest('.form-row').querySelector('.selected-preview');
        if (preview) preview.textContent = data.name + ' (SKU: ' + data.sku + ')';
    });

    $select.on('select2:unselect', function() {
        const preview = $select.closest('.form-row').querySelector('.selected-preview');
        if (preview) preview.textContent = '';
    });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"'\/]/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;'}[c];
    });
}

function addRow() {
    const container = document.getElementById('rowsContainer');
    const index = container.children.length;
    const row = document.createElement('div');
    row.className = 'form-row';
    row.setAttribute('data-row-index', index);

    const select = document.createElement('select');
    select.name = 'product_select[]';
    select.className = 'product-select';
    select.style.minWidth = '320px';

    row.appendChild(select);

    const qty = document.createElement('input');
    qty.type = 'number';
    qty.min = '1';
    qty.className = 'qty-input';
    qty.placeholder = 'Cantidad';
    qty.required = true;
    row.appendChild(qty);

    const btnRemove = document.createElement('button');
    btnRemove.type = 'button';
    btnRemove.className = 'remove-row';
    btnRemove.textContent = 'Eliminar';
    btnRemove.onclick = function() { removeRow(btnRemove); };
    row.appendChild(btnRemove);

    const preview = document.createElement('div');
    preview.className = 'selected-preview';
    row.appendChild(preview);

    container.appendChild(row);

    $(select).select2({
        data: products,
        placeholder: '-- Buscar producto por nombre o SKU --',
        width: 'resolve',
        templateResult: function(item) {
            if (!item.id) return item.text;
            return '<div><strong>' + escapeHtml(item.name) + '</strong><div style="font-size:0.85rem;color:#666">SKU: ' + escapeHtml(item.sku) + '</div></div>';
        },
        templateSelection: function(item) {
            if (!item.id) return item.text;
            return escapeHtml(item.text);
        },
        escapeMarkup: function(m) { return m; }
    });

    $(select).on('select2:select', function(e) {
        const data = e.params.data;
        const preview = select.closest('.form-row').querySelector('.selected-preview');
        if (preview) preview.textContent = data.name + ' (SKU: ' + data.sku + ')';
    });
}

function removeRow(btn) {
    const row = btn.closest('.form-row');
    if (!row) return;
    const container = document.getElementById('rowsContainer');
    if (container.children.length <= 1) {
        const sel = row.querySelector('.product-select');
        const q = row.querySelector('.qty-input');
        if (sel) $(sel).val(null).trigger('change');
        if (q) q.value = '';
        const preview = row.querySelector('.selected-preview');
        if (preview) preview.textContent = '';
        return;
    }
    const sel = row.querySelector('.product-select');
    if (sel) $(sel).select2('destroy');
    row.remove();
}

document.getElementById('manualRequestForm').addEventListener('submit', function(e) {
    const existingHidden = document.querySelectorAll('input[name^="qty["]');
    existingHidden.forEach(function(n){ n.remove(); });

    const rows = document.querySelectorAll('.form-row');
    let hasValid = false;

    rows.forEach(function(row) {
        const sel = row.querySelector('.product-select');
        const q = row.querySelector('.qty-input');
        if (!sel || !q) return;
        const pid = parseInt($(sel).val() || 0, 10);
        const qty = parseInt(q.value || 0, 10);
        if (pid > 0 && qty > 0) {
            hasValid = true;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'qty[' + pid + ']';
            hidden.value = qty;
            document.getElementById('manualRequestForm').appendChild(hidden);
        }
    });

    if (!hasValid) {
        e.preventDefault();
        alert('Debes indicar al menos un producto válido y una cantidad mayor a 0.');
        return false;
    }
    return true;
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.product-select').forEach(function(sel) {
        initSelect2($(sel));
    });
});
</script>
</body>
</html>