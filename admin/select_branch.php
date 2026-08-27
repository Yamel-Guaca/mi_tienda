<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

// Si no viene del login, no permitir acceso
if (!isset($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['temp_user_id'];

$pdo = DB::getConnection();

$stmt = $pdo->prepare("
    SELECT b.id, b.name
    FROM user_branches ub
    JOIN branches b ON b.id = ub.branch_id
    WHERE ub.user_id = ?
");
$stmt->execute([$user_id]);
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Seleccionar Sucursal</title>
</head>
<body>

<h2>Seleccione la sucursal donde va a trabajar</h2>

<?php foreach ($branches as $b): ?>
    <form method="POST" action="set_branch.php">
        <input type="hidden" name="branch_id" value="<?= $b['id'] ?>">
        <button type="submit"><?= htmlspecialchars($b['name']) ?></button>
    </form>
<?php endforeach; ?>

</body>
</html>
