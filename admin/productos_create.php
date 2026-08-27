// productos_create.php (fragmento)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Datos del formulario
    $sku = trim($_POST['sku'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $cost = number_format((float)($_POST['cost'] ?? 0), 2, '.', '');
    $defaultPrice = isset($_POST['price']) ? number_format((float)$_POST['price'], 2, '.', '') : null;
    $defaultMargin = isset($_POST['margin_percent']) ? number_format((float)$_POST['margin_percent'], 2, '.', '') : null;
    $stock = (int)($_POST['stock'] ?? 0);

    if (!$name) {
        // manejar error
    } else {
        try {
            $pdo->beginTransaction();

            // 1) Insertar producto
            $stmt = $pdo->prepare("
                INSERT INTO products (sku, name, description, price, cost, tax_percent, active, created_at)
                VALUES (:sku, :name, :desc, :price, :cost, :tax, 1, NOW())
            ");
            $stmt->execute([
                ':sku' => $sku,
                ':name' => $name,
                ':desc' => $_POST['description'] ?? null,
                ':price' => $defaultPrice ?? 0.00,
                ':cost' => $cost,
                ':tax' => number_format((float)($_POST['tax_percent'] ?? 0), 2, '.', '')
            ]);
            $productId = (int)$pdo->lastInsertId();

            // 2) Insertar branch_price solo para la sucursal seleccionada
            if ($branchId > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO branch_prices (product_id, branch_id, price, margin_percent, stock, created_at)
                    VALUES (:pid, :bid, :price, :margin, :stock, NOW())
                    ON DUPLICATE KEY UPDATE price = VALUES(price), margin_percent = VALUES(margin_percent), stock = VALUES(stock)
                ");
                $stmt->execute([
                    ':pid' => $productId,
                    ':bid' => $branchId,
                    ':price' => $defaultPrice,           // puede ser NULL para forzar cálculo
                    ':margin' => $defaultMargin,         // override opcional
                    ':stock' => $stock
                ]);
            }

            $pdo->commit();
            // redirigir o mensaje de éxito
        } catch (Exception $e) {
            $pdo->rollBack();
            // manejar error
        }
    }
}
