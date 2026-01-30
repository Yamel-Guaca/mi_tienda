// productos_edit_price.php (fragmento)
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$productId = (int)($_POST['product_id'] ?? 0);
$newPrice = isset($_POST['price']) ? number_format((float)$_POST['price'], 2, '.', null) : null;
$newMargin = isset($_POST['margin_percent']) ? number_format((float)$_POST['margin_percent'], 2, '.', null) : null;

if ($branchId <= 0 || $productId <= 0) {
    // manejar error
} else {
    // Insertar o actualizar solo para la sucursal actual
    $stmt = $pdo->prepare("
        INSERT INTO branch_prices (product_id, branch_id, price, margin_percent, stock, created_at)
        VALUES (:pid, :bid, :price, :margin, 0, NOW())
        ON DUPLICATE KEY UPDATE
            price = COALESCE(VALUES(price), branch_prices.price),
            margin_percent = COALESCE(VALUES(margin_percent), branch_prices.margin_percent)
    ");
    $stmt->execute([
        ':pid' => $productId,
        ':bid' => $branchId,
        ':price' => $newPrice,     // si quieres permitir NULL para borrar precio manual, pasa NULL
        ':margin' => $newMargin
    ]);

    // mensaje de éxito
}
