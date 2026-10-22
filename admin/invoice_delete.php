<?php
// admin/invoice_delete.php
// Script para anular una factura con motivo, factura de cambio y soporte de impresión para contabilidad.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

// --- Verificar sesión y permisos ---
if (!isset($_SESSION['user'])) {
    die("Acceso denegado. Debes iniciar sesión.");
}

$userRoleName = $_SESSION['user']['role_name'] ?? '';
$userRoleId   = $_SESSION['user']['role_id'] ?? $_SESSION['role_id'] ?? 0;
$userId       = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0;

// Permitir Administrador (1) y Supervisor (2)
$allowedRoles = ['Administrador', 'Supervisor'];
$isAllowedRole = in_array($userRoleName, $allowedRoles) || in_array((int)$userRoleId, [1, 2]);

if (!$isAllowedRole) {
    die("No tienes permisos para anular facturas.");
}

// --- Asegurar columnas necesarias en la tabla 'orders' ---
try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS cancel_reason TEXT NULL");
    $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS replacement_order VARCHAR(100) NULL");
} catch (Exception $e) {
    // Si la versión de MySQL no soporta IF NOT EXISTS en ALTER TABLE, manejamos la excepción en un try silencioso
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason TEXT NULL"); } catch (Exception $ex) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN replacement_order VARCHAR(100) NULL"); } catch (Exception $ex) {}
}

// --- Obtener ID de la factura ---
$orderId = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
if ($orderId <= 0) {
    die("Factura inválida.");
}

// Consultar factura actual
$stmtCheck = $pdo->prepare("SELECT id, total, status, branch_id, created_at, COALESCE(customer_name, 'Cliente General') as customer_name FROM orders WHERE id = ? LIMIT 1");
$stmtCheck->execute([$orderId]);
$orderRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if (!$orderRow) {
    die("Factura #{$orderId} no encontrada.");
}

$message = '';
$error = '';
$isCancelledNow = false;

// --- PROCESAR LA ANULACIÓN (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_cancellation') {
    $cancelReason = trim($_POST['cancel_reason'] ?? '');
    $replacementOrder = trim($_POST['replacement_order'] ?? '');

    if (empty($cancelReason)) {
        $error = "Debe ingresar el motivo de la anulación para fines contables.";
    } elseif ($orderRow['status'] === 'cancelado') {
        $error = "La factura #{$orderId} ya se encuentra anulada.";
    } else {
        try {
            $pdo->beginTransaction();

            $branchId = (int)$orderRow['branch_id'];

            // 1. Marcar como cancelada guardando auditoría, motivo y factura de cambio
            $stmtUpdate = $pdo->prepare("
                UPDATE orders 
                SET status = 'cancelado', 
                    cancelled_at = NOW(), 
                    cancelled_by = ?,
                    cancel_reason = ?,
                    replacement_order = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$userId, $cancelReason, $replacementOrder ?: null, $orderId]);

            // 2. Devolver productos al inventario
            $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $item) {
                // Actualizar stock en INVENTORY
                $stmtUpdateStock = $pdo->prepare("
                    UPDATE inventory 
                    SET quantity = quantity + ? 
                    WHERE product_id = ? AND branch_id = ?
                ");
                $stmtUpdateStock->execute([$item['quantity'], $item['product_id'], $branchId]);

                // Registrar en log de auditoría si la tabla existe
                try {
                    $stmtLog = $pdo->prepare("
                        INSERT INTO order_cancellations_log (order_id, product_id, quantity, cancelled_by)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmtLog->execute([$orderId, $item['product_id'], $item['quantity'], $userId]);
                } catch (Exception $exLog) {}
            }

            $pdo->commit();
            $isCancelledNow = true;
            $message = "✅ La Factura #{$orderId} ha sido anulada exitosamente y sus productos reingresaron al inventario.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error al procesar la anulación: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Anular Factura #<?= $orderId ?></title>
<style>
body { font-family: Arial, sans-serif; background: #f4f6f8; margin: 20px; color: #333; }
.container { max-width: 600px; margin: 30px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
h2 { margin-top: 0; color: #d9534f; border-bottom: 2px solid #f2d2d0; padding-bottom: 10px; }
.info-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
.info-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 14px; }
.form-group { margin-bottom: 15px; }
label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 14px; }
input[type="text"], textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
textarea { resize: vertical; min-height: 80px; }
.btn { display: inline-block; padding: 10px 16px; border-radius: 6px; border: 0; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 14px; text-align: center; }
.btn-danger { background: #d9534f; color: #fff; }
.btn-danger:hover { background: #c9302c; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-secondary:hover { background: #5a6268; }
.btn-print { background: #0078d4; color: #fff; }
.btn-print:hover { background: #005a9e; }
.alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
.alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
.actions-flex { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; flex-wrap: wrap; }
</style>
</head>
<body>

<div class="container">
    <h2>⚠️ Anulación de Factura #<?= $orderId ?></h2>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($isCancelledNow || $orderRow['status'] === 'cancelado'): ?>
        <div class="alert-success">
            <h3>Factura Anulada</h3>
            <p><?= $message ?: "Esta factura se encuentra anulada en el sistema." ?></p>
        </div>

        <div style="text-align: center; margin: 25px 0;">
            <a href="invoice_print.php?order_id=<?= $orderId ?>" target="_blank" class="btn btn-print" style="font-size: 16px; padding: 12px 20px;">
                🖨️ Imprimir / Ver Factura Anulada (Contabilidad)
            </a>
        </div>

        <div class="actions-flex">
            <a href="invoices_list.php" class="btn btn-secondary">📋 Volver al Listado de Facturas</a>
            <a href="pos.php" class="btn btn-secondary">🛒 Ir al POS</a>
        </div>

    <?php else: ?>

        <div class="info-box">
            <div class="info-row"><span>Cliente:</span> <strong><?= htmlspecialchars($orderRow['customer_name']) ?></strong></div>
            <div class="info-row"><span>Fecha Venta:</span> <strong><?= htmlspecialchars($orderRow['created_at']) ?></strong></div>
            <div class="info-row"><span>Monto Total:</span> <strong>$<?= number_format((float)$orderRow['total'], 2, ',', '.') ?></strong></div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="confirm_cancellation">
            <input type="hidden" name="order_id" value="<?= $orderId ?>">

            <div class="form-group">
                <label for="cancel_reason">Motivo de Anulación (Requerido para Contabilidad) *</label>
                <textarea id="cancel_reason" name="cancel_reason" placeholder="Ej: Error en digitación de productos, devolución del cliente, duplicidad..." required></textarea>
            </div>

            <div class="form-group">
                <label for="replacement_order">Factura por la cual se cambió (Opcional)</label>
                <input type="text" id="replacement_order" name="replacement_order" placeholder="Ej: O12789 o N° de nueva factura">
            </div>

            <div class="actions-flex">
                <a href="invoices_list.php" class="btn btn-secondary">Cancelar y Volver</a>
                <button type="submit" class="btn btn-danger" onclick="return confirm('¿Confirmar la anulación de esta factura? Los productos volverán al inventario.');">
                    Confirmar Anulación
                </button>
            </div>
        </form>

    <?php endif; ?>
</div>

</body>
</html>