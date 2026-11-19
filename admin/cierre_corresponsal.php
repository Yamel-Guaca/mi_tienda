<?php
// admin/cierre_corresponsal.php - Cierre Diario de Corresponsal + Arqueos Adicionales Independientes
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Roles permitidos según admin/caja.php: Administrador (1) y Cajero (3)
require_role([1, 3]);
$pdo = DB::getConnection();

// Datos de sesión
$currentBranchId   = (int)($_SESSION['branch_id'] ?? 0);
$currentBranchName = $_SESSION['branch_name'] ?? 'Principal';
$currentUserId     = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
$currentUserName   = $_SESSION['user']['name'] ?? $_SESSION['user_name'] ?? 'Cajero';
$currentUserRole   = (int)($_SESSION['user']['role_id'] ?? $_SESSION['role_id'] ?? 0);
$isAdmin           = ($currentUserRole === 1);

if ($currentBranchId <= 0) {
    die("No hay sucursal seleccionada. Vuelva al dashboard.");
}

// ==========================================
// ENDPOINT INTERNO PARA VER EFECTIVO (MODAL)
// Muestra el Arqueo Principal + Todos los Arqueos Adicionales de ese día
// ==========================================
if (isset($_GET['ajax_arqueo'])) {
    $id = (int)$_GET['ajax_arqueo'];
    if ($id <= 0) die('ID inválido');

    $stmt = $pdo->prepare("
        SELECT c.*, u.name AS cashier_name 
        FROM corresponsal_closures c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $closure = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$closure) die('Cierre no encontrado.');

    // 1. Conteo del Arqueo Principal
    $stmtCounts = $pdo->prepare("SELECT * FROM corresponsal_cash_counts WHERE closure_id = ? ORDER BY cash_type ASC, denomination ASC");
    $stmtCounts->execute([$id]);
    $rows = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

    $coins = array_filter($rows, fn($r) => $r['cash_type'] === 'coin');
    $bills = array_filter($rows, fn($r) => $r['cash_type'] === 'bill');

    // 2. Arqueos Adicionales registrados en esa misma fecha
    $stmtExtras = $pdo->prepare("
        SELECT e.*, u.name AS cashier_name, b.name AS branch_name
        FROM corresponsal_extra_arqueos e
        LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN branches b ON b.id = e.branch_id
        WHERE e.arqueo_date = ?
        ORDER BY e.id ASC
    ");
    $stmtExtras->execute([$closure['closure_date']]);
    $extras = $stmtExtras->fetchAll(PDO::FETCH_ASSOC);
    ?>

    <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-bottom:14px;">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
        <strong style="color:#0B2545; font-size:15px;">1. Arqueo Principal del Cierre</strong>
        <span style="font-size:12px; color:#475569;">Registrado por: <strong><?= htmlspecialchars($closure['cashier_name'] ?? 'Sistema') ?></strong></span>
      </div>

      <div class="modal-cash-grid">
        <table class="cash-table">
          <thead>
            <tr><th colspan="3" class="coin-hdr">MONEDAS CONTADAS</th></tr>
            <tr><th>Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
          </thead>
          <tbody>
            <?php foreach ($coins as $c): ?>
              <tr>
                <td style="text-align:center; font-weight:700;"><?= (int)$c['quantity'] ?></td>
                <td>$<?= number_format((int)$c['denomination'], 0, ',', '.') ?></td>
                <td>$<?= number_format((float)$c['subtotal'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="cash-total-coin">
              <td colspan="2">Total Moneda</td>
              <td>$<?= number_format((float)$closure['total_coins'], 0, ',', '.') ?></td>
            </tr>
          </tbody>
        </table>

        <table class="cash-table">
          <thead>
            <tr><th colspan="3" class="bill-hdr">BILLETES CONTADOS</th></tr>
            <tr><th>Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
          </thead>
          <tbody>
            <?php foreach ($bills as $b): ?>
              <tr>
                <td style="text-align:center; font-weight:700;"><?= (int)$b['quantity'] ?></td>
                <td>$<?= number_format((int)$b['denomination'], 0, ',', '.') ?></td>
                <td>$<?= number_format((float)$b['subtotal'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="cash-total-bill">
              <td colspan="2">Total Billete</td>
              <td>$<?= number_format((float)$closure['total_bills'], 0, ',', '.') ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div style="margin-top:10px; padding:10px 12px; background:#0B2545; color:#fff; border-radius:6px; display:flex; justify-content:space-between; align-items:center; font-weight:800; font-size:14px;">
        <span>TOTAL EFECTIVO PRINCIPAL:</span>
        <span>$<?= number_format((float)$closure['total_cash'], 0, ',', '.') ?></span>
      </div>

      <?php if (!empty($closure['notes'])): ?>
        <p style="margin-top:8px; font-size:12px; background:#fff; padding:8px; border-radius:6px; border:1px solid #e2e8f0; margin-bottom:0;">
          <strong>Observaciones:</strong> <?= htmlspecialchars($closure['notes']) ?>
        </p>
      <?php endif; ?>
    </div>

    <!-- ARQUEOS ADICIONALES DE ESA FECHA -->
    <?php if (!empty($extras)): ?>
      <h4 style="margin:16px 0 10px 0; color:#0B2545; border-top:2px dashed #cbd5e1; padding-top:12px;">
        ➕ Otros Arqueos de Efectivo Guardados el <?= date('d/m/Y', strtotime($closure['closure_date'])) ?> (<?= count($extras) ?>)
      </h4>

      <?php foreach ($extras as $idx => $ext): 
        $stmtExtC = $pdo->prepare("SELECT * FROM corresponsal_extra_arqueo_counts WHERE extra_arqueo_id = ? ORDER BY cash_type ASC, denomination ASC");
        $stmtExtC->execute([$ext['id']]);
        $extRows = $stmtExtC->fetchAll(PDO::FETCH_ASSOC);
        $extCoins = array_filter($extRows, fn($r) => $r['cash_type'] === 'coin');
        $extBills = array_filter($extRows, fn($r) => $r['cash_type'] === 'bill');
      ?>
        <div style="background:#fffbeb; border:1.5px solid #fcd34d; border-radius:8px; padding:12px; margin-bottom:12px;">
          <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px; margin-bottom:8px;">
            <strong style="color:#92400e; font-size:14px;">
              #<?= $idx + 1 ?> - <?= htmlspecialchars($ext['label']) ?>
              <?php if (!empty($ext['branch_name'])): ?>
                (<?= htmlspecialchars($ext['branch_name']) ?>)
              <?php endif; ?>
            </strong>
            <span style="font-size:12px; color:#78350f;">
              Cajero: <strong><?= htmlspecialchars($ext['cashier_name'] ?? 'Usuario #' . $ext['user_id']) ?></strong>
              • <?= date('H:i', strtotime($ext['created_at'])) ?>
            </span>
          </div>

          <div class="modal-cash-grid">
            <table class="cash-table" style="background:#fff;">
              <thead>
                <tr><th colspan="3" class="coin-hdr">MONEDAS</th></tr>
                <tr><th>Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
              </thead>
              <tbody>
                <?php foreach ($extCoins as $c): ?>
                  <tr>
                    <td style="text-align:center; font-weight:700;"><?= (int)$c['quantity'] ?></td>
                    <td>$<?= number_format((int)$c['denomination'], 0, ',', '.') ?></td>
                    <td>$<?= number_format((float)$c['subtotal'], 0, ',', '.') ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="cash-total-coin">
                  <td colspan="2">Total Moneda</td>
                  <td>$<?= number_format((float)$ext['total_coins'], 0, ',', '.') ?></td>
                </tr>
              </tbody>
            </table>

            <table class="cash-table" style="background:#fff;">
              <thead>
                <tr><th colspan="3" class="bill-hdr">BILLETES</th></tr>
                <tr><th>Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
              </thead>
              <tbody>
                <?php foreach ($extBills as $b): ?>
                  <tr>
                    <td style="text-align:center; font-weight:700;"><?= (int)$b['quantity'] ?></td>
                    <td>$<?= number_format((int)$b['denomination'], 0, ',', '.') ?></td>
                    <td>$<?= number_format((float)$b['subtotal'], 0, ',', '.') ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="cash-total-bill">
                  <td colspan="2">Total Billete</td>
                  <td>$<?= number_format((float)$ext['total_bills'], 0, ',', '.') ?></td>
                </tr>
              </tbody>
            </table>
          </div>

          <div style="margin-top:8px; padding:10px 12px; background:#b45309; color:#fff; border-radius:6px; display:flex; justify-content:space-between; align-items:center; font-weight:800; font-size:14px;">
            <span>TOTAL ESTE ARQUEO:</span>
            <span>$<?= number_format((float)$ext['total_cash'], 0, ',', '.') ?></span>
          </div>

          <?php if (!empty($ext['notes'])): ?>
            <p style="margin-top:6px; font-size:12px; background:#fff; padding:6px 8px; border-radius:6px; border:1px solid #fde68a; margin-bottom:0;">
              <strong>Nota:</strong> <?= htmlspecialchars($ext['notes']) ?>
            </p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <p style="font-size:12px; color:#64748b; text-align:center; margin:8px 0 0 0;">
        No hay arqueos adicionales registrados para esta fecha.
      </p>
    <?php endif;
    exit;
}

$msg = '';
$error = '';

// Denominaciones oficiales en Colombia
$COIN_DENOMS = [50, 100, 200, 500, 1000];
$BILL_DENOMS = [2000, 5000, 10000, 20000, 50000, 100000];

// ==========================================
// 1. ACCIONES DE ADMINISTRADOR (COLUMNAS Y BASE)
// ==========================================
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_base_config'])) {
        $newBase = floatval($_POST['base_amount'] ?? 0);
        $stmt = $pdo->prepare("UPDATE corresponsal_config SET base_amount = ? WHERE id = 1");
        $stmt->execute([$newBase]);
        $msg = 'Base del corresponsal actualizada correctamente.';
    }

    if (isset($_POST['save_column'])) {
        $colId = (int)($_POST['col_id'] ?? 0);
        $colName = strtoupper(trim($_POST['col_name'] ?? ''));
        $colOrder = (int)($_POST['col_order'] ?? 0);
        $colActive = isset($_POST['col_active']) ? 1 : 0;

        if ($colName !== '') {
            if ($colId > 0) {
                $stmt = $pdo->prepare("UPDATE corresponsal_columns SET name = ?, sort_order = ?, active = ? WHERE id = ?");
                $stmt->execute([$colName, $colOrder, $colActive, $colId]);
                $msg = 'Columna actualizada.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO corresponsal_columns (name, sort_order, active) VALUES (?, ?, ?)");
                $stmt->execute([$colName, $colOrder, $colActive]);
                $msg = 'Nueva columna agregada.';
            }
        } else {
            $error = 'El nombre de la columna no puede estar vacío.';
        }
    }
}

// ==========================================
// 1B. GUARDAR ARQUEO ADICIONAL INDEPENDIENTE (NO AFECTA EL CIERRE PRINCIPAL)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_extra_arqueo'])) {
    $extraDate  = trim($_POST['extra_date'] ?? date('Y-m-d'));
    $extraLabel = trim($_POST['extra_label'] ?? '');
    if ($extraLabel === '') {
        $extraLabel = 'Efectivo ' . $currentBranchName;
    }
    $extraNotes = trim($_POST['extra_notes'] ?? '');
    $exCoinsIn  = $_POST['ex_coins'] ?? [];
    $exBillsIn  = $_POST['ex_bills'] ?? [];

    // Validar regla de los 2 días para Cajeros
    $canSaveExtra = true;
    if (!$isAdmin) {
        $today = new DateTime(date('Y-m-d'));
        $targetDate = DateTime::createFromFormat('Y-m-d', $extraDate);
        if (!$targetDate) {
            $canSaveExtra = false;
            $error = 'Fecha inválida para el arqueo adicional.';
        } else {
            $diffDays = (int)$today->diff($targetDate)->format('%r%a');
            if ($diffDays < -2 || $diffDays > 0) {
                $canSaveExtra = false;
                $error = 'No tienes permiso para registrar arqueos con más de 2 días de antigüedad.';
            }
        }
    }

    if ($canSaveExtra && $error === '') {
        try {
            $pdo->beginTransaction();

            $exTotalCoins = 0;
            $exCoinRows = [];
            foreach ($COIN_DENOMS as $denom) {
                $qty = max(0, (int)($exCoinsIn[$denom] ?? 0));
                $sub = $qty * $denom;
                $exTotalCoins += $sub;
                $exCoinRows[] = ['denom' => $denom, 'type' => 'coin', 'qty' => $qty, 'sub' => $sub];
            }

            $exTotalBills = 0;
            $exBillRows = [];
            foreach ($BILL_DENOMS as $denom) {
                $qty = max(0, (int)($exBillsIn[$denom] ?? 0));
                $sub = $qty * $denom;
                $exTotalBills += $sub;
                $exBillRows[] = ['denom' => $denom, 'type' => 'bill', 'qty' => $qty, 'sub' => $sub];
            }

            $exTotalCash = $exTotalCoins + $exTotalBills;

            $stmtInsEx = $pdo->prepare("
                INSERT INTO corresponsal_extra_arqueos 
                (arqueo_date, branch_id, user_id, label, total_coins, total_bills, total_cash, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsEx->execute([
                $extraDate,
                $currentBranchId ?: null,
                $currentUserId ?: null,
                $extraLabel,
                $exTotalCoins,
                $exTotalBills,
                $exTotalCash,
                $extraNotes
            ]);
            $extraId = (int)$pdo->lastInsertId();

            $stmtInsExC = $pdo->prepare("
                INSERT INTO corresponsal_extra_arqueo_counts 
                (extra_arqueo_id, denomination, cash_type, quantity, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach (array_merge($exCoinRows, $exBillRows) as $r) {
                $stmtInsExC->execute([$extraId, $r['denom'], $r['type'], $r['qty'], $r['sub']]);
            }

            $pdo->commit();
            $fmtTotal = '$' . number_format($exTotalCash, 0, ',', '.');
            $msg = "Arqueo adicional guardado ($extraLabel: $fmtTotal). Ahora puedes digitar ese valor en la casilla que corresponda.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error al guardar el arqueo adicional: ' . $e->getMessage();
        }
    }
}

// ==========================================
// 2. GUARDAR O MODIFICAR CIERRE DIARIO PRINCIPAL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_closure'])) {
    $closureId = (int)($_POST['closure_id'] ?? 0);
    $closureDate = trim($_POST['closure_date'] ?? date('Y-m-d'));
    $totalSystem = floatval($_POST['total_system'] ?? 0);
    $transactionsCount = max(0, (int)($_POST['transactions_count'] ?? 0));
    $notes = trim($_POST['notes'] ?? '');
    $coinsInput = $_POST['coins'] ?? [];
    $billsInput = $_POST['bills'] ?? [];
    $colsInput = $_POST['cols'] ?? [];

    $canEdit = true;
    if (!$isAdmin) {
        $today = new DateTime(date('Y-m-d'));
        $targetDate = DateTime::createFromFormat('Y-m-d', $closureDate);
        if (!$targetDate) {
            $canEdit = false;
            $error = 'Fecha inválida.';
        } else {
            $diffDays = (int)$today->diff($targetDate)->format('%r%a');
            if ($diffDays < -2 || $diffDays > 0) {
                $canEdit = false;
                $error = 'No tienes permiso para crear o modificar cierres con más de 2 días de antigüedad.';
            }
        }
    }

    if ($canEdit && $error === '') {
        try {
            $pdo->beginTransaction();

            $baseAmount = (float)$pdo->query("SELECT base_amount FROM corresponsal_config WHERE id = 1")->fetchColumn();
            if ($closureId > 0) {
                $stmtOld = $pdo->prepare("SELECT base_snapshot FROM corresponsal_closures WHERE id = ?");
                $stmtOld->execute([$closureId]);
                $oldBase = $stmtOld->fetchColumn();
                if ($oldBase !== false) $baseAmount = (float)$oldBase;
            }

            $totalCoins = 0;
            $coinRows = [];
            foreach ($COIN_DENOMS as $denom) {
                $qty = max(0, (int)($coinsInput[$denom] ?? 0));
                $sub = $qty * $denom;
                $totalCoins += $sub;
                $coinRows[] = ['denom' => $denom, 'type' => 'coin', 'qty' => $qty, 'sub' => $sub];
            }

            $totalBills = 0;
            $billRows = [];
            foreach ($BILL_DENOMS as $denom) {
                $qty = max(0, (int)($billsInput[$denom] ?? 0));
                $sub = $qty * $denom;
                $totalBills += $sub;
                $billRows[] = ['denom' => $denom, 'type' => 'bill', 'qty' => $qty, 'sub' => $sub];
            }

            $totalCash = $totalCoins + $totalBills;

            $totalPlatforms = 0;
            $validCols = [];
            foreach ($colsInput as $colId => $val) {
                $cId = (int)$colId;
                $amount = floatval($val);
                if ($cId > 0) {
                    $totalPlatforms += $amount;
                    $validCols[$cId] = $amount;
                }
            }

            $subtotalCashPlatforms = $totalCash + $totalPlatforms;
            $grandTotal = $subtotalCashPlatforms + $totalSystem;
            $recaudo = $grandTotal - $baseAmount;

            if ($closureId > 0) {
                $stmtUpd = $pdo->prepare("
                    UPDATE corresponsal_closures 
                    SET total_coins=?, total_bills=?, total_cash=?, total_platforms=?, 
                        subtotal_cash_platforms=?, total_system=?, grand_total=?, 
                        recaudo=?, transactions_count=?, notes=?
                    WHERE id=?
                ");
                $stmtUpd->execute([
                    $totalCoins, $totalBills, $totalCash, $totalPlatforms,
                    $subtotalCashPlatforms, $totalSystem, $grandTotal,
                    $recaudo, $transactionsCount, $notes, $closureId
                ]);
            } else {
                $stmtCheck = $pdo->prepare("SELECT id FROM corresponsal_closures WHERE closure_date = ?");
                $stmtCheck->execute([$closureDate]);
                $existingId = $stmtCheck->fetchColumn();

                if ($existingId) {
                    throw new Exception("Ya existe un cierre registrado para la fecha $closureDate. Búscalo abajo y usa 'Modificar'.");
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO corresponsal_closures 
                    (closure_date, branch_id, user_id, total_coins, total_bills, total_cash, total_platforms, subtotal_cash_platforms, total_system, grand_total, base_snapshot, recaudo, transactions_count, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([
                    $closureDate, $currentBranchId ?: null, $currentUserId ?: null,
                    $totalCoins, $totalBills, $totalCash, $totalPlatforms,
                    $subtotalCashPlatforms, $totalSystem, $grandTotal,
                    $baseAmount, $recaudo, $transactionsCount, $notes
                ]);
                $closureId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("DELETE FROM corresponsal_closure_values WHERE closure_id = ?")->execute([$closureId]);
            $stmtColIns = $pdo->prepare("INSERT INTO corresponsal_closure_values (closure_id, column_id, amount) VALUES (?, ?, ?)");
            foreach ($validCols as $cId => $amt) {
                $stmtColIns->execute([$closureId, $cId, $amt]);
            }

            $pdo->prepare("DELETE FROM corresponsal_cash_counts WHERE closure_id = ?")->execute([$closureId]);
            $stmtCashIns = $pdo->prepare("INSERT INTO corresponsal_cash_counts (closure_id, denomination, cash_type, quantity, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach (array_merge($coinRows, $billRows) as $cr) {
                $stmtCashIns->execute([$closureId, $cr['denom'], $cr['type'], $cr['qty'], $cr['sub']]);
            }

            $pdo->commit();
            $msg = "Cierre del día $closureDate guardado correctamente.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ==========================================
// 3. CARGAR DATOS PARA LA INTERFAZ
// ==========================================
$baseAmount = (float)$pdo->query("SELECT base_amount FROM corresponsal_config WHERE id = 1")->fetchColumn();

$allColumns = $pdo->query("SELECT * FROM corresponsal_columns ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$activeColumns = array_filter($allColumns, fn($c) => (int)$c['active'] === 1);

$editClosure = null;
$editColValues = [];
$editCoinQtys = [];
$editBillQtys = [];

if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $stmt = $pdo->prepare("SELECT * FROM corresponsal_closures WHERE id = ?");
    $stmt->execute([$editId]);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($candidate) {
        $today = new DateTime(date('Y-m-d'));
        $cDate = new DateTime($candidate['closure_date']);
        $diffDays = (int)$today->diff($cDate)->format('%r%a');

        if ($isAdmin || ($diffDays >= -2 && $diffDays <= 0)) {
            $editClosure = $candidate;
            $baseAmount = (float)$editClosure['base_snapshot'];

            $stmtV = $pdo->prepare("SELECT column_id, amount FROM corresponsal_closure_values WHERE closure_id = ?");
            $stmtV->execute([$editId]);
            $editColValues = $stmtV->fetchAll(PDO::FETCH_KEY_PAIR);

            $stmtC = $pdo->prepare("SELECT cash_type, denomination, quantity FROM corresponsal_cash_counts WHERE closure_id = ?");
            $stmtC->execute([$editId]);
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['cash_type'] === 'coin') {
                    $editCoinQtys[(int)$row['denomination']] = (int)$row['quantity'];
                } else {
                    $editBillQtys[(int)$row['denomination']] = (int)$row['quantity'];
                }
            }
        } else {
            $error = 'Este cierre superó los 2 días permitidos y ya está bloqueado para edición.';
        }
    }
}

if ($isAdmin) {
    $closures = $pdo->query("SELECT * FROM corresponsal_closures ORDER BY closure_date DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmtHist = $pdo->prepare("
        SELECT * FROM corresponsal_closures 
        WHERE closure_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)
        ORDER BY closure_date DESC
    ");
    $stmtHist->execute();
    $closures = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
}

$closureValuesMap = [];
if (!empty($closures)) {
    $cIds = array_column($closures, 'id');
    $in = implode(',', array_fill(0, count($cIds), '?'));
    $stmtMap = $pdo->prepare("SELECT closure_id, column_id, amount FROM corresponsal_closure_values WHERE closure_id IN ($in)");
    $stmtMap->execute($cIds);
    foreach ($stmtMap->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $closureValuesMap[$r['closure_id']][$r['column_id']] = (float)$r['amount'];
    }
}

// Contar cuántos arqueos adicionales hay por cada fecha para mostrar insignia en el historial
$extraCountByDate = [];
if (!empty($closures)) {
    $dates = array_unique(array_column($closures, 'closure_date'));
    $inD = implode(',', array_fill(0, count($dates), '?'));
    $stmtExCnt = $pdo->prepare("SELECT arqueo_date, COUNT(*) AS cnt, SUM(total_cash) AS sum_cash FROM corresponsal_extra_arqueos WHERE arqueo_date IN ($inD) GROUP BY arqueo_date");
    $stmtExCnt->execute(array_values($dates));
    foreach ($stmtExCnt->fetchAll(PDO::FETCH_ASSOC) as $ec) {
        $extraCountByDate[$ec['arqueo_date']] = [
            'cnt' => (int)$ec['cnt'],
            'sum' => (float)$ec['sum_cash']
        ];
    }
}

// Arqueos adicionales de hoy (o de la fecha en edición) para mostrarlos como referencia rápida
$refDate = $editClosure['closure_date'] ?? date('Y-m-d');
$stmtTodayExtras = $pdo->prepare("
    SELECT e.*, u.name AS cashier_name 
    FROM corresponsal_extra_arqueos e
    LEFT JOIN users u ON u.id = e.user_id
    WHERE e.arqueo_date = ?
    ORDER BY e.id DESC
");
$stmtTodayExtras->execute([$refDate]);
$todayExtrasList = $stmtTodayExtras->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cierre Diario Corresponsal</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<style>
  :root {
    --primary: #0B2545;
    --accent: #EEB902;
    --success: #059669;
    --danger: #dc2626;
    --warning: #f59e0b;
    --info: #0284c7;
    --bg: #f1f5f9;
    --border: #cbd5e1;
  }

  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--bg);
    color: #1e293b;
    margin: 0;
    padding: 0 0 76px 0;
    line-height: 1.4;
  }

  header {
    background: var(--primary);
    padding: 14px 18px;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
  }
  header h2 { margin: 0; font-size: 18px; }
  header small { display: block; opacity: .85; font-size: 12px; margin-top: 2px; }
  .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
  .nav-links a {
    color: var(--accent);
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
    background: rgba(255,255,255,0.1);
    padding: 7px 12px;
    border-radius: 6px;
  }

  .container { max-width: 1350px; margin: 14px auto; padding: 0 12px; }

  .msg { background: #dcfce7; border: 1px solid #86efac; padding: 12px 14px; margin-bottom: 14px; border-radius: 8px; color: #166534; font-weight: 700; font-size: 14px; }
  .err { background: #fee2e2; border: 1px solid #fca5a5; padding: 12px 14px; margin-bottom: 14px; border-radius: 8px; color: #991b1b; font-weight: 700; font-size: 14px; }

  .card {
    background: #fff;
    padding: 16px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,.05);
    margin-bottom: 16px;
  }
  h3 { margin: 0 0 14px 0; color: var(--primary); border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-size: 17px; }
  h4 { margin: 0 0 10px 0; color: var(--primary); font-size: 15px; }

  .grid-main { display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 20px; }
  .grid-cash { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .grid-fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
  .modal-cash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  label {
    display: block;
    font-size: 11px;
    font-weight: 800;
    color: #475569;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }

  input[type="number"], input[type="date"], input[type="text"] {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 16px;
    min-height: 44px;
    background: #fff;
    color: #0f172a;
  }
  input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(11,37,69,.1); }
  input[readonly] { background: #f1f5f9; font-weight: 700; color: var(--primary); }

  .cash-table { width: 100%; border-collapse: collapse; }
  .cash-table th, .cash-table td { border: 1px solid var(--border); padding: 6px 8px; text-align: right; font-size: 14px; }
  .cash-table th.coin-hdr { background: #fef08a; color: #1e293b; text-align: center; font-weight: 800; padding: 8px; }
  .cash-table th.bill-hdr { background: #bbf7d0; color: #1e293b; text-align: center; font-weight: 800; padding: 8px; }
  .cash-table input {
    width: 82px;
    text-align: center;
    padding: 6px 4px;
    font-weight: 800;
    font-size: 16px;
    min-height: 40px;
  }
  .cash-total-coin { background: #fef08a; font-weight: 800; }
  .cash-total-bill { background: #bbf7d0; font-weight: 800; }

  .total-cash-banner {
    margin-top: 12px;
    padding: 12px 14px;
    background: var(--danger);
    color: #fff;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: 800;
    font-size: 15px;
  }

  /* Caja de arqueos adicionales rápidos */
  .extra-arqueo-box {
    margin-top: 12px;
    background: #fffbeb;
    border: 1.5px dashed #f59e0b;
    border-radius: 8px;
    padding: 12px;
  }
  .extra-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #fff;
    border: 1px solid #fcd34d;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    color: #92400e;
    margin: 4px 4px 0 0;
  }

  .summary-box {
    background: #f8fafc;
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 12px;
    margin-top: 18px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 10px;
  }
  .sum-item { background: #fff; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; }
  .sum-item span { display: block; font-size: 11px; color: #64748b; font-weight: 800; text-transform: uppercase; }
  .sum-item strong { display: block; font-size: 17px; color: var(--primary); margin-top: 3px; }
  .sum-item.recaudo-pos { background: #f0fdf4; border-color: #86efac; }
  .sum-item.recaudo-pos strong { color: var(--success); }
  .sum-item.recaudo-neg { background: #fef2f2; border-color: #fca5a5; }
  .sum-item.recaudo-neg strong { color: var(--danger); }

  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 8px;
    border: 0;
    cursor: pointer;
    font-weight: 700;
    text-decoration: none;
    font-size: 14px;
    min-height: 42px;
  }
  .btn-primary { background: var(--primary); color: #fff; }
  .btn-success { background: var(--success); color: #fff; }
  .btn-warning { background: var(--warning); color: #fff; padding: 8px 12px; font-size: 13px; min-height: 36px; }
  .btn-secondary { background: #64748b; color: #fff; }
  .btn-info { background: var(--info); color: #fff; padding: 8px 12px; font-size: 13px; min-height: 36px; }
  .btn-amber { background: #d97706; color: #fff; width: 100%; font-size: 14px; }
  .btn-submit-main { width: 100%; max-width: 340px; padding: 14px 20px; font-size: 16px; min-height: 50px; }

  .table-responsive { overflow-x: auto; }
  .excel-table { width: 100%; border-collapse: collapse; font-size: 13px; white-space: nowrap; }
  .excel-table th, .excel-table td { border: 1px solid var(--border); padding: 8px 10px; text-align: right; }
  .excel-table th { background: #dcfce7; color: #14532d; font-weight: 800; text-align: center; }
  .excel-table th.sys-col { background: #fef08a; color: #713f12; }
  .excel-table td:first-child { text-align: center; font-weight: 700; background: #f8fafc; }
  .excel-table tr:hover td { background: #f1f5f9; }
  .text-pos { color: var(--success); font-weight: 800; }
  .text-neg { color: var(--danger); font-weight: 800; }

  .badge-extra {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    margin-left: 4px;
  }

  .mobile-history-list { display: none; }
  .m-closure-card {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 12px;
    background: #fff;
  }
  .m-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 8px;
    margin-bottom: 10px;
  }
  .m-card-date { font-weight: 800; font-size: 15px; color: var(--primary); }
  .m-card-recaudo { font-weight: 800; font-size: 15px; padding: 3px 8px; border-radius: 6px; }
  .m-card-recaudo.pos { background: #dcfce7; color: #166534; }
  .m-card-recaudo.neg { background: #fee2e2; color: #991b1b; }
  .m-card-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; font-size: 13px; }
  .m-card-row { display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px dashed #f1f5f9; }
  .m-card-row span { color: #64748b; }
  .m-card-row strong { color: #0f172a; }
  .m-card-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
  .m-card-actions .btn { width: 100%; }

  .mobile-sticky-bar {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--primary);
    color: #fff;
    padding: 10px 14px;
    z-index: 200;
    box-shadow: 0 -3px 12px rgba(0,0,0,.2);
    justify-content: space-between;
    align-items: center;
    gap: 10px;
  }
  .mobile-sticky-bar small { display: block; font-size: 10px; opacity: .8; text-transform: uppercase; }
  .mobile-sticky-bar strong { font-size: 15px; }

  .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.65); z-index: 999; align-items: center; justify-content: center; padding: 12px; }
  .modal.open { display: flex; }
  .modal-content { background: #fff; padding: 16px; border-radius: 12px; max-width: 680px; width: 100%; max-height: 90vh; overflow-y: auto; }

  @media (max-width: 960px) {
    .grid-main { grid-template-columns: 1fr; }
  }

  @media (max-width: 680px) {
    header { padding: 12px 14px; }
    header h2 { font-size: 16px; }
    .container { padding: 0 10px; margin-top: 10px; }
    .card { padding: 14px 12px; }
    .grid-cash, .modal-cash-grid { grid-template-columns: 1fr; gap: 14px; }
    .cash-table input { width: 95px; }
    .grid-fields { grid-template-columns: 1fr 1fr; gap: 10px; }
    .summary-box { grid-template-columns: 1fr 1fr; gap: 8px; }
    .btn-submit-main { max-width: 100%; }
    .desktop-history-table { display: none; }
    .mobile-history-list { display: block; }
    .mobile-sticky-bar { display: flex; }
  }

  @media (max-width: 380px) {
    .grid-fields { grid-template-columns: 1fr; }
    .m-card-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<header>
  <div>
    <h2>Cierre Corresponsal</h2>
    <small>Sucursal: <?= htmlspecialchars($currentBranchName) ?> • <?= $isAdmin ? 'Admin' : 'Cajero: ' . htmlspecialchars($currentUserName) ?></small>
  </div>
  <div class="nav-links">
    <?php if ($isAdmin): ?>
      <a href="reporte_corresponsal.php">📊 Reportes</a>
      <a href="dashboard.php">← Dashboard</a>
    <?php else: ?>
      <a href="pos.php?mode=tactil">← Volver al POS</a>
    <?php endif; ?>
  </div>
</header>

<div class="container">
  <?php if ($msg): ?><div class="msg">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="err">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- PANEL EXCLUSIVO DE ADMINISTRADOR / SUPERVISOR -->
  <?php if ($isAdmin): ?>
    <div class="card" style="border-left: 4px solid #EEB902;">
      <details>
        <summary style="cursor:pointer; font-weight:800; color:#0B2545; font-size:14px; padding:4px 0;">
          ⚙️ Configuración de Supervisor (Base y Columnas)
        </summary>
        <div class="grid-main" style="margin-top: 14px;">
          <div>
            <h4>Base / Cupo del Corresponsal</h4>
            <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
              <input type="hidden" name="save_base_config" value="1">
              <div style="flex:1; min-width:160px;">
                <label>Valor Base ($):</label>
                <input type="number" inputmode="decimal" step="0.01" name="base_amount" value="<?= htmlspecialchars($baseAmount) ?>" required>
              </div>
              <button type="submit" class="btn btn-primary">Guardar Base</button>
            </form>
          </div>

          <div>
            <h4>Columnas de Plataformas / Cajas</h4>
            <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
              <input type="hidden" name="save_column" value="1">
              <input type="hidden" name="col_id" id="cfg_col_id" value="0">
              <div style="flex:2; min-width:140px;">
                <label>Nombre (Ej: NEQUI):</label>
                <input type="text" name="col_name" id="cfg_col_name" required placeholder="Nombre columna">
              </div>
              <div style="width:75px;">
                <label>Orden:</label>
                <input type="number" inputmode="numeric" name="col_order" id="cfg_col_order" value="0">
              </div>
              <div style="padding-bottom:10px;">
                <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-size:13px;">
                  <input type="checkbox" name="col_active" id="cfg_col_active" value="1" checked style="width:18px; height:18px;"> Activa
                </label>
              </div>
              <button type="submit" class="btn btn-success" id="cfg_col_btn">Agregar</button>
              <button type="button" class="btn btn-secondary" onclick="resetColForm()" style="display:none;" id="cfg_col_cancel">Cancelar</button>
            </form>

            <div style="max-height:180px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
              <table class="cash-table" style="margin:0;">
                <thead>
                  <tr>
                    <th style="text-align:left;">Columna</th>
                    <th style="text-align:center;">Orden</th>
                    <th style="text-align:center;">Estado</th>
                    <th style="text-align:center;">Acción</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($allColumns as $ac): ?>
                    <tr>
                      <td style="text-align:left; font-weight:700;"><?= htmlspecialchars($ac['name']) ?></td>
                      <td style="text-align:center;"><?= (int)$ac['sort_order'] ?></td>
                      <td style="text-align:center;"><?= $ac['active'] ? '✅' : '❌' ?></td>
                      <td style="text-align:center;">
                        <button type="button" class="btn btn-warning" onclick='editColumn(<?= json_encode($ac) ?>)'>Editar</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </details>
    </div>
  <?php endif; ?>

  <!-- FORMULARIO DE REGISTRO / MODIFICACIÓN DE CIERRE -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
      <h3 style="margin:0; border:0; padding:0;">
        <?= $editClosure ? '✏️ Editando: ' . date('d/m/Y', strtotime($editClosure['closure_date'])) : '📝 Registrar Cierre del Día' ?>
      </h3>
      <?php if ($editClosure): ?>
        <a href="cierre_corresponsal.php" class="btn btn-secondary">Cancelar Edición</a>
      <?php endif; ?>
    </div>

    <form method="POST" action="cierre_corresponsal.php">
      <input type="hidden" name="save_closure" value="1">
      <input type="hidden" name="closure_id" value="<?= $editClosure ? (int)$editClosure['id'] : 0 ?>">

      <div class="grid-main">
        <!-- IZQUIERDA: ARQUEO DE EFECTIVO -->
        <div>
          <h4>1. Arqueo de Efectivo (Conteo Físico)</h4>
          <div class="grid-cash">
            <!-- Tabla Monedas -->
            <table class="cash-table">
              <thead>
                <tr><th colspan="3" class="coin-hdr">MONEDAS</th></tr>
                <tr><th style="text-align:center;">Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
              </thead>
              <tbody>
                <?php foreach ($COIN_DENOMS as $d): 
                  $qVal = $editCoinQtys[$d] ?? 0;
                ?>
                  <tr>
                    <td style="text-align:center;">
                      <input type="number" inputmode="numeric" pattern="[0-9]*" min="0" name="coins[<?= $d ?>]" class="coin-input" data-denom="<?= $d ?>" value="<?= $qVal ?>" onfocus="this.select()">
                    </td>
                    <td>$<?= number_format($d, 0, ',', '.') ?></td>
                    <td class="coin-sub" id="coin_sub_<?= $d ?>">$<?= number_format($qVal * $d, 0, ',', '.') ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="cash-total-coin">
                  <td id="total_coin_qty" style="text-align:center;">0</td>
                  <td>Total Moneda</td>
                  <td id="total_coin_val">$0</td>
                </tr>
              </tbody>
            </table>

            <!-- Tabla Billetes -->
            <table class="cash-table">
              <thead>
                <tr><th colspan="3" class="bill-hdr">BILLETES</th></tr>
                <tr><th style="text-align:center;">Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
              </thead>
              <tbody>
                <?php foreach ($BILL_DENOMS as $d): 
                  $qVal = $editBillQtys[$d] ?? 0;
                ?>
                  <tr>
                    <td style="text-align:center;">
                      <input type="number" inputmode="numeric" pattern="[0-9]*" min="0" name="bills[<?= $d ?>]" class="bill-input" data-denom="<?= $d ?>" value="<?= $qVal ?>" onfocus="this.select()">
                    </td>
                    <td>$<?= number_format($d, 0, ',', '.') ?></td>
                    <td class="bill-sub" id="bill_sub_<?= $d ?>">$<?= number_format($qVal * $d, 0, ',', '.') ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="cash-total-bill">
                  <td id="total_bill_qty" style="text-align:center;">0</td>
                  <td>Total Billete</td>
                  <td id="total_bill_val">$0</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="total-cash-banner">
            <span>TOTAL EFECTIVO CONTADO:</span>
            <span id="display_total_cash" style="font-size:18px;">$0</span>
          </div>

          <!-- BOTÓN Y RESUMEN DE ARQUEO ADICIONAL INDEPENDIENTE -->
          <div class="extra-arqueo-box">
            <button type="button" class="btn btn-amber" onclick="abrirModalExtraArqueo()">
              ➕ Crear / Agregar Otro Arqueo de Efectivo (No suma al cierre)
            </button>
            <div style="font-size:12px; color:#78350f; margin-top:6px;">
              Úsalo para contar el efectivo de otra caja/sucursal. Se guarda en <strong>Ver Efectivo</strong> y luego digitas su valor manualmente en la casilla correspondiente.
            </div>

            <?php if (!empty($todayExtrasList)): ?>
              <div style="margin-top:8px; border-top:1px solid #fde68a; padding-top:6px;">
                <span style="font-size:11px; font-weight:800; color:#92400e; display:block;">
                  Arqueos adicionales registrados hoy (<?= date('d/m/Y', strtotime($refDate)) ?>):
                </span>
                <?php foreach ($todayExtrasList as $te): ?>
                  <span class="extra-pill">
                    💵 <?= htmlspecialchars($te['label']) ?>: <strong>$<?= number_format((float)$te['total_cash'], 0, ',', '.') ?></strong>
                    (<?= htmlspecialchars($te['cashier_name'] ?? 'Cajero') ?>)
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- DERECHA: PLATAFORMAS, SISTEMA Y TRANSACCIONES -->
        <div>
          <h4>2. Saldos en Plataformas y Sistema</h4>
          <div class="grid-fields">
            <div>
              <label>Fecha del Cierre:</label>
              <input type="date" name="closure_date" id="main_closure_date"
                     value="<?= htmlspecialchars($editClosure['closure_date'] ?? date('Y-m-d')) ?>"
                     <?= (!$isAdmin) ? 'min="'.date('Y-m-d', strtotime('-2 days')).'" max="'.date('Y-m-d').'"' : '' ?>
                     <?= $editClosure ? 'readonly' : 'required' ?>>
            </div>

            <?php foreach ($activeColumns as $col): 
              $cId = (int)$col['id'];
              $cVal = $editColValues[$cId] ?? 0;
            ?>
              <div>
                <label><?= htmlspecialchars($col['name']) ?> ($):</label>
                <input type="number" inputmode="decimal" step="0.01" name="cols[<?= $cId ?>]" class="platform-input" value="<?= htmlspecialchars($cVal) ?>" onfocus="this.select()">
              </div>
            <?php endforeach; ?>

            <div>
              <label style="background:#fef08a; padding:2px 6px; border-radius:4px; display:inline-block;">TOTAL SISTEMA ($):</label>
              <input type="number" inputmode="decimal" step="0.01" name="total_system" id="input_total_system" value="<?= htmlspecialchars($editClosure['total_system'] ?? 0) ?>" required onfocus="this.select()">
            </div>

            <div>
              <label>Transacciones:</label>
              <input type="number" inputmode="numeric" pattern="[0-9]*" min="0" name="transactions_count" value="<?= htmlspecialchars($editClosure['transactions_count'] ?? 0) ?>" onfocus="this.select()">
            </div>
          </div>

          <div style="margin-top:12px;">
            <label>Observaciones / Novedades (opcional):</label>
            <input type="text" name="notes" placeholder="Ej: Se consignó a Nequi, novedad en caja..." value="<?= htmlspecialchars($editClosure['notes'] ?? '') ?>">
          </div>
        </div>
      </div>

      <!-- BARRA DE RESULTADOS EN TIEMPO REAL -->
      <div class="summary-box">
        <div class="sum-item">
          <span>Total Efectivo</span>
          <strong id="sum_cash">$0</strong>
        </div>
        <div class="sum-item">
          <span>Total Plataformas</span>
          <strong id="sum_platforms">$0</strong>
        </div>
        <div class="sum-item">
          <span>Efectivo + Plat.</span>
          <strong id="sum_subtotal">$0</strong>
        </div>
        <div class="sum-item">
          <span>Total Sistema</span>
          <strong id="sum_system">$0</strong>
        </div>
        <div class="sum-item" style="background:#eef2ff;">
          <span>TOTAL GENERAL</span>
          <strong id="sum_grand_total">$0</strong>
        </div>
        <div class="sum-item" id="recaudo_box">
          <span>RECAUDO (Base: $<?= number_format($baseAmount, 0, ',', '.') ?>)</span>
          <strong id="sum_recaudo">$0</strong>
        </div>
      </div>

      <div style="margin-top:18px; text-align:right;">
        <button type="submit" class="btn btn-success btn-submit-main">
          <?= $editClosure ? '💾 Guardar Cambios' : '✅ Guardar Cierre del Día' ?>
        </button>
      </div>
    </form>
  </div>

  <!-- CIERRES REGISTRADOS -->
  <div class="card">
    <h3>📊 Cierres Registrados <?= !$isAdmin ? '(Últimos 2 días)' : '(Historial)' ?></h3>

    <?php if (empty($closures)): ?>
      <p style="text-align:center; padding:20px; color:#64748b; margin:0;">
        No hay cierres registrados en este rango de fechas.
      </p>
    <?php else: ?>

      <!-- VISTA ESCRITORIO -->
      <div class="table-responsive desktop-history-table">
        <table class="excel-table">
          <thead>
            <tr>
              <th>FECHA</th>
              <th>TOTAL EFECTIVO</th>
              <?php foreach ($activeColumns as $col): ?>
                <th><?= htmlspecialchars($col['name']) ?></th>
              <?php endforeach; ?>
              <th>TOTAL (EFEC + PLAT)</th>
              <th class="sys-col">TOTAL SISTEMA</th>
              <th>TOTAL GENERAL</th>
              <th>RECAUDO</th>
              <th>TRANS.</th>
              <th>ACCIONES</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($closures as $cl): 
              $clId = (int)$cl['id'];
              $rec = (float)$cl['recaudo'];
              $today = new DateTime(date('Y-m-d'));
              $cDate = new DateTime($cl['closure_date']);
              $diffDays = (int)$today->diff($cDate)->format('%r%a');
              $editable = $isAdmin || ($diffDays >= -2 && $diffDays <= 0);
              $exInfo = $extraCountByDate[$cl['closure_date']] ?? null;
            ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($cl['closure_date'])) ?></td>
                <td style="font-weight:700;">$<?= number_format((float)$cl['total_cash'], 0, ',', '.') ?></td>
                <?php foreach ($activeColumns as $col): 
                  $val = $closureValuesMap[$clId][$col['id']] ?? 0;
                ?>
                  <td>$<?= number_format($val, 0, ',', '.') ?></td>
                <?php endforeach; ?>
                <td style="font-weight:700;">$<?= number_format((float)$cl['subtotal_cash_platforms'], 0, ',', '.') ?></td>
                <td style="font-weight:700; background:#fefce8;">$<?= number_format((float)$cl['total_system'], 0, ',', '.') ?></td>
                <td style="font-weight:800; color:#0B2545;">$<?= number_format((float)$cl['grand_total'], 0, ',', '.') ?></td>
                <td class="<?= $rec >= 0 ? 'text-pos' : 'text-neg' ?>">
                  <?= $rec < 0 ? '$(' . number_format(abs($rec), 0, ',', '.') . ')' : '$' . number_format($rec, 0, ',', '.') ?>
                </td>
                <td style="text-align:center;"><?= (int)$cl['transactions_count'] ?></td>
                <td style="text-align:center;">
                  <button type="button" class="btn btn-info" onclick="verArqueo(<?= $clId ?>, '<?= date('d/m/Y', strtotime($cl['closure_date'])) ?>')">
                    👁️ Ver Efectivo
                    <?php if ($exInfo): ?>
                      <span class="badge-extra">+<?= $exInfo['cnt'] ?></span>
                    <?php endif; ?>
                  </button>
                  <?php if ($editable): ?>
                    <a href="cierre_corresponsal.php?edit_id=<?= $clId ?>" class="btn btn-warning">✏️ Modificar</a>
                  <?php else: ?>
                    <span style="font-size:11px; color:#94a3b8;">🔒 Bloqueado</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- VISTA CELULAR -->
      <div class="mobile-history-list">
        <?php foreach ($closures as $cl): 
          $clId = (int)$cl['id'];
          $rec = (float)$cl['recaudo'];
          $today = new DateTime(date('Y-m-d'));
          $cDate = new DateTime($cl['closure_date']);
          $diffDays = (int)$today->diff($cDate)->format('%r%a');
          $editable = $isAdmin || ($diffDays >= -2 && $diffDays <= 0);
          $recFormatted = $rec < 0 ? '$(' . number_format(abs($rec), 0, ',', '.') . ')' : '$' . number_format($rec, 0, ',', '.');
          $exInfo = $extraCountByDate[$cl['closure_date']] ?? null;
        ?>
          <div class="m-closure-card">
            <div class="m-card-header">
              <span class="m-card-date">📅 <?= date('d/m/Y', strtotime($cl['closure_date'])) ?></span>
              <span class="m-card-recaudo <?= $rec >= 0 ? 'pos' : 'neg' ?>">
                Recaudo: <?= $recFormatted ?>
              </span>
            </div>

            <div class="m-card-grid">
              <div class="m-card-row">
                <span>Efectivo:</span>
                <strong>$<?= number_format((float)$cl['total_cash'], 0, ',', '.') ?></strong>
              </div>
              <?php foreach ($activeColumns as $col): 
                $val = $closureValuesMap[$clId][$col['id']] ?? 0;
              ?>
                <div class="m-card-row">
                  <span><?= htmlspecialchars($col['name']) ?>:</span>
                  <strong>$<?= number_format($val, 0, ',', '.') ?></strong>
                </div>
              <?php endforeach; ?>
              <div class="m-card-row">
                <span>Sistema:</span>
                <strong>$<?= number_format((float)$cl['total_system'], 0, ',', '.') ?></strong>
              </div>
              <div class="m-card-row">
                <span>Total General:</span>
                <strong>$<?= number_format((float)$cl['grand_total'], 0, ',', '.') ?></strong>
              </div>
              <div class="m-card-row">
                <span>Transacciones:</span>
                <strong><?= (int)$cl['transactions_count'] ?></strong>
              </div>
            </div>

            <div class="m-card-actions">
              <button type="button" class="btn btn-info" onclick="verArqueo(<?= $clId ?>, '<?= date('d/m/Y', strtotime($cl['closure_date'])) ?>')">
                👁️ Ver Efectivo <?= $exInfo ? '(+' . $exInfo['cnt'] . ')' : '' ?>
              </button>
              <?php if ($editable): ?>
                <a href="cierre_corresponsal.php?edit_id=<?= $clId ?>" class="btn btn-warning">
                  ✏️ Modificar
                </a>
              <?php else: ?>
                <button type="button" class="btn btn-secondary" disabled style="opacity:.6;">
                  🔒 Bloqueado
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>
</div>

<!-- BARRA FLOTANTE INFERIOR EN MÓVIL -->
<div class="mobile-sticky-bar">
  <div>
    <small>Efectivo Principal</small>
    <strong id="sticky_cash">$0</strong>
  </div>
  <div style="text-align:right;">
    <small>Recaudo Estimado</small>
    <strong id="sticky_recaudo" style="color:#EEB902;">$0</strong>
  </div>
</div>

<!-- MODAL 1: VER EL DETALLE DE MONEDAS Y BILLETES GUARDADOS (PRINCIPAL + ADICIONALES) -->
<div id="modalArqueo" class="modal" onclick="if(event.target===this) cerrarModalArqueo()">
  <div class="modal-content">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:12px;">
      <h3 style="margin:0; border:0; padding:0; font-size:16px;">Efectivo Contado - <span id="modalFecha"></span></h3>
      <button type="button" class="btn btn-secondary" onclick="cerrarModalArqueo()" style="min-height:36px; padding:6px 12px;">✕</button>
    </div>
    <div id="modalBodyArqueo">Cargando...</div>
  </div>
</div>

<!-- MODAL 2: CREAR OTRO ARQUEO DE EFECTIVO (INDEPENDIENTE) -->
<div id="modalExtraArqueo" class="modal" onclick="if(event.target===this) cerrarModalExtraArqueo()">
  <div class="modal-content">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:12px;">
      <h3 style="margin:0; border:0; padding:0; font-size:16px;">➕ Nuevo Arqueo de Efectivo Adicional</h3>
      <button type="button" class="btn btn-secondary" onclick="cerrarModalExtraArqueo()" style="min-height:36px; padding:6px 12px;">✕</button>
    </div>

    <form method="POST" action="cierre_corresponsal.php">
      <input type="hidden" name="save_extra_arqueo" value="1">

      <div class="grid-fields" style="margin-bottom:12px;">
        <div>
          <label>Fecha del Arqueo:</label>
          <input type="date" name="extra_date" id="extra_date_input"
                 value="<?= htmlspecialchars($editClosure['closure_date'] ?? date('Y-m-d')) ?>"
                 <?= (!$isAdmin) ? 'min="'.date('Y-m-d', strtotime('-2 days')).'" max="'.date('Y-m-d').'"' : '' ?>
                 required>
        </div>
        <div>
          <label>Identificador / Sucursal:</label>
          <input type="text" name="extra_label" value="Efectivo <?= htmlspecialchars($currentBranchName) ?>" placeholder="Ej: Efectivo Sucursal 2" required>
        </div>
      </div>

      <div class="grid-cash">
        <!-- Monedas Extra -->
        <table class="cash-table">
          <thead>
            <tr><th colspan="3" class="coin-hdr">MONEDAS</th></tr>
            <tr><th style="text-align:center;">Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
          </thead>
          <tbody>
            <?php foreach ($COIN_DENOMS as $d): ?>
              <tr>
                <td style="text-align:center;">
                  <input type="number" inputmode="numeric" pattern="[0-9]*" min="0" name="ex_coins[<?= $d ?>]" class="ex-coin-input" data-denom="<?= $d ?>" value="0" onfocus="this.select()">
                </td>
                <td>$<?= number_format($d, 0, ',', '.') ?></td>
                <td id="ex_coin_sub_<?= $d ?>">$0</td>
              </tr>
            <?php endforeach; ?>
            <tr class="cash-total-coin">
              <td id="ex_total_coin_qty" style="text-align:center;">0</td>
              <td>Total Moneda</td>
              <td id="ex_total_coin_val">$0</td>
            </tr>
          </tbody>
        </table>

        <!-- Billetes Extra -->
        <table class="cash-table">
          <thead>
            <tr><th colspan="3" class="bill-hdr">BILLETES</th></tr>
            <tr><th style="text-align:center;">Cant.</th><th>Denom.</th><th>Subtotal</th></tr>
          </thead>
          <tbody>
            <?php foreach ($BILL_DENOMS as $d): ?>
              <tr>
                <td style="text-align:center;">
                  <input type="number" inputmode="numeric" pattern="[0-9]*" min="0" name="ex_bills[<?= $d ?>]" class="ex-bill-input" data-denom="<?= $d ?>" value="0" onfocus="this.select()">
                </td>
                <td>$<?= number_format($d, 0, ',', '.') ?></td>
                <td id="ex_bill_sub_<?= $d ?>">$0</td>
              </tr>
            <?php endforeach; ?>
            <tr class="cash-total-bill">
              <td id="ex_total_bill_qty" style="text-align:center;">0</td>
              <td>Total Billete</td>
              <td id="ex_total_bill_val">$0</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px; padding:12px 14px; background:#b45309; color:#fff; border-radius:8px; display:flex; justify-content:space-between; align-items:center; font-weight:800; font-size:16px;">
        <span>TOTAL ESTE ARQUEO:</span>
        <span id="ex_display_total_cash">$0</span>
      </div>

      <div style="margin-top:10px;">
        <label>Observación (opcional):</label>
        <input type="text" name="extra_notes" placeholder="Ej: Efectivo contado en caja 2 para digitar en columna...">
      </div>

      <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn btn-secondary" onclick="cerrarModalExtraArqueo()">Cancelar</button>
        <button type="submit" class="btn btn-success">💾 Guardar Arqueo Adicional</button>
      </div>
    </form>
  </div>
</div>

<script>
const BASE_CORRESPONSAL = <?= json_encode((float)$baseAmount) ?>;

function formatCOP(num) {
  const n = Math.round(Number(num) || 0);
  if (n < 0) return '$(' + Math.abs(n).toLocaleString('es-CO') + ')';
  return '$' + n.toLocaleString('es-CO');
}

function recalcularTodo() {
  let totalCoinQty = 0;
  let totalCoinVal = 0;
  document.querySelectorAll('.coin-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || 0, 10));
    const denom = parseInt(inp.dataset.denom, 10);
    const sub = qty * denom;
    totalCoinQty += qty;
    totalCoinVal += sub;
    const subEl = document.getElementById('coin_sub_' + denom);
    if (subEl) subEl.textContent = formatCOP(sub);
  });
  document.getElementById('total_coin_qty').textContent = totalCoinQty;
  document.getElementById('total_coin_val').textContent = formatCOP(totalCoinVal);

  let totalBillQty = 0;
  let totalBillVal = 0;
  document.querySelectorAll('.bill-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || 0, 10));
    const denom = parseInt(inp.dataset.denom, 10);
    const sub = qty * denom;
    totalBillQty += qty;
    totalBillVal += sub;
    const subEl = document.getElementById('bill_sub_' + denom);
    if (subEl) subEl.textContent = formatCOP(sub);
  });
  document.getElementById('total_bill_qty').textContent = totalBillQty;
  document.getElementById('total_bill_val').textContent = formatCOP(totalBillVal);

  const totalCash = totalCoinVal + totalBillVal;
  document.getElementById('display_total_cash').textContent = formatCOP(totalCash);

  let totalPlatforms = 0;
  document.querySelectorAll('.platform-input').forEach(inp => {
    totalPlatforms += parseFloat(inp.value || 0) || 0;
  });

  const subtotal = totalCash + totalPlatforms;
  const totalSystem = parseFloat(document.getElementById('input_total_system')?.value || 0) || 0;
  const grandTotal = subtotal + totalSystem;
  const recaudo = grandTotal - BASE_CORRESPONSAL;

  document.getElementById('sum_cash').textContent = formatCOP(totalCash);
  document.getElementById('sum_platforms').textContent = formatCOP(totalPlatforms);
  document.getElementById('sum_subtotal').textContent = formatCOP(subtotal);
  document.getElementById('sum_system').textContent = formatCOP(totalSystem);
  document.getElementById('sum_grand_total').textContent = formatCOP(grandTotal);
  document.getElementById('sum_recaudo').textContent = formatCOP(recaudo);

  const stickyCash = document.getElementById('sticky_cash');
  const stickyRec = document.getElementById('sticky_recaudo');
  if (stickyCash) stickyCash.textContent = formatCOP(totalCash);
  if (stickyRec) {
    stickyRec.textContent = formatCOP(recaudo);
    stickyRec.style.color = recaudo >= 0 ? '#4ade80' : '#f87171';
  }

  const recBox = document.getElementById('recaudo_box');
  recBox.classList.remove('recaudo-pos', 'recaudo-neg');
  recBox.classList.add(recaudo >= 0 ? 'recaudo-pos' : 'recaudo-neg');
}

// Cálculo independiente del Modal de Arqueo Adicional
function recalcularExtraArqueo() {
  let cQty = 0, cVal = 0;
  document.querySelectorAll('.ex-coin-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || 0, 10));
    const denom = parseInt(inp.dataset.denom, 10);
    const sub = qty * denom;
    cQty += qty;
    cVal += sub;
    const el = document.getElementById('ex_coin_sub_' + denom);
    if (el) el.textContent = formatCOP(sub);
  });
  document.getElementById('ex_total_coin_qty').textContent = cQty;
  document.getElementById('ex_total_coin_val').textContent = formatCOP(cVal);

  let bQty = 0, bVal = 0;
  document.querySelectorAll('.ex-bill-input').forEach(inp => {
    const qty = Math.max(0, parseInt(inp.value || 0, 10));
    const denom = parseInt(inp.dataset.denom, 10);
    const sub = qty * denom;
    bQty += qty;
    bVal += sub;
    const el = document.getElementById('ex_bill_sub_' + denom);
    if (el) el.textContent = formatCOP(sub);
  });
  document.getElementById('ex_total_bill_qty').textContent = bQty;
  document.getElementById('ex_total_bill_val').textContent = formatCOP(bVal);

  document.getElementById('ex_display_total_cash').textContent = formatCOP(cVal + bVal);
}

document.querySelectorAll('.coin-input, .bill-input, .platform-input, #input_total_system').forEach(el => {
  el.addEventListener('input', recalcularTodo);
});

document.querySelectorAll('.ex-coin-input, .ex-bill-input').forEach(el => {
  el.addEventListener('input', recalcularExtraArqueo);
});

document.addEventListener('DOMContentLoaded', () => {
  recalcularTodo();
  recalcularExtraArqueo();
});

function editColumn(col) {
  document.getElementById('cfg_col_id').value = col.id;
  document.getElementById('cfg_col_name').value = col.name;
  document.getElementById('cfg_col_order').value = col.sort_order;
  document.getElementById('cfg_col_active').checked = Number(col.active) === 1;
  document.getElementById('cfg_col_btn').textContent = 'Actualizar';
  document.getElementById('cfg_col_cancel').style.display = 'inline-flex';
}

function resetColForm() {
  document.getElementById('cfg_col_id').value = 0;
  document.getElementById('cfg_col_name').value = '';
  document.getElementById('cfg_col_order').value = 0;
  document.getElementById('cfg_col_active').checked = true;
  document.getElementById('cfg_col_btn').textContent = 'Agregar';
  document.getElementById('cfg_col_cancel').style.display = 'none';
}

function verArqueo(closureId, fechaStr) {
  document.getElementById('modalFecha').textContent = fechaStr;
  document.getElementById('modalBodyArqueo').innerHTML = '<p style="text-align:center; padding:20px;">Cargando detalle...</p>';
  document.getElementById('modalArqueo').classList.add('open');

  fetch('cierre_corresponsal.php?ajax_arqueo=' + closureId)
    .then(r => r.text())
    .then(html => {
      document.getElementById('modalBodyArqueo').innerHTML = html;
    })
    .catch(() => {
      document.getElementById('modalBodyArqueo').innerHTML = '<p style="color:red; text-align:center;">Error al cargar el detalle.</p>';
    });
}

function cerrarModalArqueo() {
  document.getElementById('modalArqueo').classList.remove('open');
}

function abrirModalExtraArqueo() {
  const mainDate = document.getElementById('main_closure_date');
  const extraDate = document.getElementById('extra_date_input');
  if (mainDate && extraDate && mainDate.value) {
    extraDate.value = mainDate.value;
  }
  recalcularExtraArqueo();
  document.getElementById('modalExtraArqueo').classList.add('open');
}

function cerrarModalExtraArqueo() {
  document.getElementById('modalExtraArqueo').classList.remove('open');
}
</script>
</body>
</html>