<?php
// admin/transfer_accept.php
// Acepta un traslado pendiente: actualiza stock en la sucursal destino y registra aceptación.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();
$user = $_SESSION['user'] ?? null;
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

$currentBranchId = intval($user['branch_id'] ?? 0);
$userId = intval($user['id'] ?? 0);

// Solo aceptar vía POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido. Usa POST.']);
    exit;
}

$transferId = intval($_POST['id'] ?? 0);
if ($transferId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID de traslado inválido.']);
    exit;
}

try {
    // Iniciar transacción antes de FOR UPDATE
    $pdo->beginTransaction();

    // Cargar traslado y bloquear fila
    $stmt = $pdo->prepare("SELECT * FROM transfers WHERE id = ? FOR UPDATE");
    $stmt->execute([$transferId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Traslado no encontrado.']);
        exit;
    }

    if (intval($transfer['to_branch_id']) !== $currentBranchId) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'No autorizado para aceptar este traslado.']);
        exit;
    }

    if (($transfer['status'] ?? '') !== 'pending') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'El traslado no está en estado pendiente.']);
        exit;
    }

    // Cargar items del traslado
    $stmtItems = $pdo->prepare("SELECT * FROM transfer_items WHERE transfer_id = ?");
    $stmtItems->execute([$transferId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay items, abortar
    if (!is_array($items) || count($items) === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'El traslado no contiene items.']);
        exit;
    }

    // Procesar cada item: asegurar fila stock destino y sumar cantidad
    foreach ($items as $it) {
        $productId = intval($it['product_id'] ?? 0);
        // Aceptar varias claves posibles para qty
        $qty = floatval($it['qty'] ?? $it['quantity'] ?? 0);

        if ($productId <= 0 || $qty <= 0) continue;

        // Asegurar existencia de fila stock destino (insertar si no existe)
        $stmtIns = $pdo->prepare("
            INSERT INTO stock (branch_id, product_id, quantity)
            SELECT ?, ?, 0
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM stock WHERE branch_id = ? AND product_id = ?)
        ");
        $stmtIns->execute([$currentBranchId, $productId, $currentBranchId, $productId]);

        // Bloquear fila destino
        $stmtLock = $pdo->prepare("SELECT quantity FROM stock WHERE branch_id = ? AND product_id = ? FOR UPDATE");
        $stmtLock->execute([$currentBranchId, $productId]);
        $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Si por alguna razón no existe, crearla y volver a bloquear
            $stmtCreate = $pdo->prepare("INSERT INTO stock (branch_id, product_id, quantity) VALUES (?, ?, 0)");
            $stmtCreate->execute([$currentBranchId, $productId]);
            $stmtLock->execute([$currentBranchId, $productId]);
            $row = $stmtLock->fetch(PDO::FETCH_ASSOC);
        }

        // Actualizar stock destino sumando qty
        $stmtUpd = $pdo->prepare("UPDATE stock SET quantity = quantity + ? WHERE branch_id = ? AND product_id = ?");
        $stmtUpd->execute([$qty, $currentBranchId, $productId]);
    }

    // Actualizar estado del traslado
    $stmtUpdTr = $pdo->prepare("UPDATE transfers SET status = 'accepted', accepted_by = ?, accepted_at = NOW() WHERE id = ?");
    $stmtUpdTr->execute([$userId, $transferId]);

    // Insertar log
    $stmtLog = $pdo->prepare("INSERT INTO transfer_logs (transfer_id, action, user_id, details) VALUES (?, 'accepted', ?, ?)");
    $details = "Aceptado por usuario ID {$userId} en sucursal ID {$currentBranchId}";
    $stmtLog->execute([$transferId, $userId, $details]);

    $pdo->commit();
    echo json_encode(['success' => true]);
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
