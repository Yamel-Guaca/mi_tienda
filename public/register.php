<?php
// public/register.php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';         // session_start() seguro
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_functions.php';  // funciones login/isLoggedIn si las tienes
// require_once __DIR__ . '/../vendor/autoload.php';       // descomentar si usas PHPMailer

$pdo = DB::getConnection();
$errors = [];
$success = false;

// Generar CSRF token si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting simple por IP (ajusta según necesidad)
// Aquí se usa sesión; para producción usar Redis o tabla DB
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!isset($_SESSION['register_attempts'])) {
    $_SESSION['register_attempts'] = ['count' => 0, 'time' => time()];
}
if (time() - $_SESSION['register_attempts']['time'] > 3600) {
    $_SESSION['register_attempts'] = ['count' => 0, 'time' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $postedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedCsrf)) {
        $errors[] = 'Token inválido. Recarga la página e intenta de nuevo.';
    }

    // Verificar rate limit
    if ($_SESSION['register_attempts']['count'] >= 10) {
        $errors[] = 'Demasiados intentos. Intenta nuevamente más tarde.';
    }

    // Recoger y sanear entradas
    $name     = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150);
    $document = preg_replace('/\s+/', '', (string)($_POST['document'] ?? ''));
    $email    = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $phone    = preg_replace('/[^\d\+]/', '', (string)($_POST['phone'] ?? ''));
    $address  = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
    $city     = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 100);
    $country  = mb_substr(trim((string)($_POST['country'] ?? '')), 0, 100);
    $password = (string)($_POST['password'] ?? '');
    $password2= (string)($_POST['password2'] ?? '');

    // Validaciones
    if ($name === '') $errors[] = 'Nombre es obligatorio.';
    if (!preg_match('/^[\p{L}\s\.\-]{2,150}$/u', $name)) $errors[] = 'Nombre contiene caracteres inválidos.';
    if ($document === '' || !preg_match('/^[\dA-Za-z\-\_]{4,30}$/', $document)) $errors[] = 'Documento inválido.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email inválido.';
    if ($phone !== '' && !preg_match('/^\+?\d{7,15}$/', $phone)) $errors[] = 'Teléfono inválido.';
    if (mb_strlen($password) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'La contraseña debe incluir mayúscula, minúscula y número.';
    }
    if ($password !== $password2) $errors[] = 'Las contraseñas no coinciden.';

    // reCAPTCHA placeholder (implementa en frontend y verifica aquí)
    // $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
    // validar con la API de Google reCAPTCHA aquí

    // Verificar email único
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'Ya existe una cuenta con ese email.';
    }

    // Insertar usuario de forma segura
    if (empty($errors)) {
        try {
            $_SESSION['register_attempts']['count']++;

            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $verify_token = bin2hex(random_bytes(32));

            $sql = "INSERT INTO users
                (name, document, email, phone, address, city, country, password, role_id, verify_token, is_verified, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 3, ?, 0, NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $name, $document, $email, $phone, $address, $city, $country, $hash, $verify_token
            ]);

            $userId = (int)$pdo->lastInsertId();

            $pdo->commit();

            // Enviar email de verificación (placeholder)
            // Recomiendo usar PHPMailer y SMTP. Aquí un ejemplo mínimo con mail()
            $verifyUrl = sprintf(
                '%s/public/verify.php?token=%s',
                rtrim((isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'], '/'),
                urlencode($verify_token)
            );

            $subject = 'Verifica tu cuenta';
            $message = "Hola $name,\n\nGracias por registrarte. Haz clic en el enlace para verificar tu cuenta:\n\n$verifyUrl\n\nSi no solicitaste esto, ignora este mensaje.";
            $headers = 'From: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . "\r\n" .
                       'Reply-To: no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . "\r\n" .
                       'X-Mailer: PHP/' . phpversion();

            // En producción reemplazar mail() por PHPMailer con SMTP y manejo de errores
            @mail($email, $subject, $message, $headers);

            // Auto-login opcional: aquí NO auto-login para forzar verificación.
            // Si decides auto-login, regenerar session id:
            // session_regenerate_id(true);
            // $_SESSION['user'] = ['id'=>$userId,'name'=>$name,'email'=>$email,'role_id'=>3];

            $success = true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Registro error: ' . $e->getMessage());
            $errors[] = 'Error interno. Intenta nuevamente más tarde.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Registro</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <?php
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: no-referrer-when-downgrade");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://www.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline';");
  ?>
  <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
  <main>
    <h1>Crear cuenta</h1>

    <?php if ($success): ?>
      <div style="color:green;">
        Cuenta creada correctamente. Revisa tu correo para verificar la cuenta.
      </div>
    <?php else: ?>
      <?php if (!empty($errors)): ?>
        <ul style="color:red;">
          <?php foreach ($errors as $e) echo '<li>' . htmlspecialchars($e) . '</li>'; ?>
        </ul>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <label>Nombre completo
          <input type="text" name="name" required maxlength="150" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
        </label><br>

        <label>Documento
          <input type="text" name="document" required maxlength="30" value="<?= htmlspecialchars($_POST['document'] ?? '') ?>">
        </label><br>

        <label>Email
          <input type="email" name="email" required maxlength="255" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </label><br>

        <label>Teléfono
          <input type="text" name="phone" maxlength="20" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        </label><br>

        <label>Dirección
          <input type="text" name="address" maxlength="255" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
        </label><br>

        <label>Ciudad
          <input type="text" name="city" maxlength="100" value="<?= htmlspecialchars($_POST['city'] ?? '') ?>">
        </label><br>

        <label>País
          <input type="text" name="country" maxlength="100" value="<?= htmlspecialchars($_POST['country'] ?? '') ?>">
        </label><br>

        <label>Contraseña
          <input type="password" name="password" required>
        </label><br>

        <label>Repetir contraseña
          <input type="password" name="password2" required>
        </label><br>

        <!-- reCAPTCHA placeholder: añade el widget en frontend y envía g-recaptcha-response -->
        <!-- <div class="g-recaptcha" data-sitekey="TU_SITE_KEY"></div> -->

        <button type="submit">Registrarse</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
