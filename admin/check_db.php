<?php
// admin/check_db.php
require_once __DIR__ . '/../includes/db.php';

$pdo = DB::getConnection();

$checks = [
    'orders' => ['cancelled_at', 'cancelled_by', 'cancel_reason', 'replacement_order', 'printed_invoice_html'],
    'gastos' => ['printed_invoice_html', 'anulado', 'branch_id'],
    'purchases' => ['printed_invoice_html', 'provider_name', 'invoice_number'],
    'inventory' => ['product_id', 'branch_id', 'quantity'],
    'inventory_movements' => ['id', 'product_id', 'branch_id', 'type', 'quantity', 'note'],
    'order_cancellations_log' => ['id', 'order_id', 'product_id', 'quantity']
];

echo "<h2> Diagnóstico de Estructura de Base de Datos</h2>";

foreach ($checks as $table =>$columns) {
    try {
        $stmt =$pdo->query("SHOW COLUMNS FROM `$table`");
        $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        echo "<strong>Tabla `$table`:</strong> ";
        
        $missing = [];
        foreach ($columns as$col) {
            if (!in_array($col,$existingColumns)) {
                $missing[] =$col;
            }
        }
        
        if (empty($missing)) {
            echo "<span style='color:green;'> Correcta</span><br>";
        } else {
            echo "<span style='color:red;'> Faltan columnas: " . implode(', ', $missing) . "</span><br>";
        }
    } catch (Exception $e) {
        echo "<strong style='color:red;'>Tabla `$table`: NO EXISTE</strong><br>";
    }
}