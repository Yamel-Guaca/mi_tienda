<?php
// admin/login.php
// Fondo: /mi_tienda/uploads/products/login.png

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

/*
  PARCHE DE SEGURIDAD AÑADIDO:
  - Forzar HTTPS en producción (redirige si no está en HTTPS) -- comentado para entornos locales
  - Cabeceras de seguridad (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)
  - Configuración segura de cookies de sesión y uso estricto de sesiones (solo si la sesión NO está ya activa)
  - CSRF token (generación y validación)
  - Regeneración de session id tras login exitoso
  - Logging de intentos de autenticación (sin registrar contraseñas)
  - Rate limiting simple por IP (archivo temporal)
  - Validaciones y mensajes genéricos
*/

/* ------------------ Ajustes de transporte y cabeceras ------------------ */
/* Forzar HTTPS (solo si no estás en entorno de desarrollo local, desactiva si trabajas en HTTP local) */
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    // Comentar la siguiente redirección si trabajas en entorno local sin HTTPS
    // $httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    // header('Location: ' . $httpsUrl, true, 301);
    // exit;
}

/* Cabeceras de seguridad */
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload"); // solo si sirves por HTTPS
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:;");

/* ------------------ Configuración segura de sesiones ------------------ */
/*
  IMPORTANTE:
  - Si alguna de las librerías incluidas ya llamó a session_start(), no es posible cambiar
    session.cookie_* ni session.use_strict_mode después. Por eso comprobamos el estado
    de la sesión y solo aplicamos los parámetros si la sesión NO está activa.
*/
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Ajustes que deben aplicarse antes de session_start()
    ini_set('session.use_strict_mode', 1);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '', // opcional: tu dominio
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
} else {
    // Si la sesión ya está activa (por ejemplo auth_functions.php la inició),
    // no intentamos cambiar parámetros que ya no pueden modificarse.
    // Nos aseguramos de que $_SESSION exista.
    if (!isset($_SESSION)) {
        session_start();
    }
}

/* ------------------ CSRF token (generación) ------------------ */
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Fallback si random_bytes falla
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

/* ------------------ Rate limiting simple por IP (archivo temporal) ------------------ */
function auth_rate_limit_check($maxAttempts = 6, $decaySeconds = 900) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = __DIR__ . '/../tmp_auth';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $file = $dir . '/auth_' . preg_replace('/[^a-z0-9_.-]/i', '_', $ip) . '.log';

    $now = time();
    $attempts = [];
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $ln) {
            $t = (int)trim($ln);
            if ($t > $now - $decaySeconds) $attempts[] = $t;
        }
    }

    if (count($attempts) >= $maxAttempts) {
        return false; // bloqueado temporalmente
    }

    // registrar intento actual
    $attempts[] = $now;
    file_put_contents($file, implode("\n", $attempts) . "\n", LOCK_EX);
    return true;
}

/* ------------------ Logging de intentos (sin contraseñas) ------------------ */
function auth_log_attempt($email, $result) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $file = $dir . '/auth.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $line = sprintf("[%s] ip=%s email=%s result=%s ua=%s\n", date('c'), $ip, $email, $result, substr($ua,0,200));
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/* ------------------ Manejo del POST (validación CSRF, rate limit, login) ------------------ */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validar CSRF token
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        http_response_code(400);
        $error = "Solicitud inválida.";
        auth_log_attempt($_POST['email'] ?? 'unknown', 'csrf_failed');
    } else {
        // Rate limiting: si excede, rechazar
        if (!auth_rate_limit_check()) {
            http_response_code(429);
            $error = "Demasiados intentos. Intente nuevamente más tarde.";
            auth_log_attempt($_POST['email'] ?? 'unknown', 'rate_limited');
        } else {
            $email = $_POST['email'] ?? '';
            $pass  = $_POST['password'] ?? '';

            // ✅ Validar credenciales (login() debe usar password_verify internamente)
            if (login($email, $pass)) {

                // Regenerar session id tras login exitoso
                session_regenerate_id(true);

                $user_id = $_SESSION['user_id'];
                $role_id = $_SESSION['role_id'];

                $pdo = DB::getConnection();

                // ✅ Obtener datos completos del usuario y su rol
                $stmtUser = $pdo->prepare("
                    SELECT u.id, u.name, u.email, u.branch_id, r.id AS role_id, r.name AS role_name
                    FROM users u
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE u.id = ?
                ");
                $stmtUser->execute([$user_id]);
                $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

                if ($userRow) {
                    $_SESSION['user'] = [
                        'id'        => $userRow['id'],
                        'name'      => $userRow['name'],
                        'email'     => $userRow['email'],
                        'branch_id' => $userRow['branch_id'],
                        'role_id'   => $userRow['role_id'],
                        'role_name' => $userRow['role_name'] // Ej: "Administrador", "Supervisor"
                    ];
                }

                // ✅ Obtener sucursal directamente desde users.branch_id
                $branch_id = $userRow['branch_id'];

                if (!$branch_id) {
                    $error = "No tiene sucursales asignadas. Contacte al administrador.";
                    session_destroy();
                    auth_log_attempt($email, 'no_branch');
                }

                // ✅ SI ES ADMINISTRADOR → ENTRA DIRECTO
                elseif ($userRow['role_id'] == 1) {
                    $_SESSION['branch_id'] = $branch_id;

                    $stmt2 = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                    $stmt2->execute([$branch_id]);
                    $_SESSION['branch_name'] = $stmt2->fetchColumn();

                    auth_log_attempt($email, 'success_admin');
                    header('Location: /mi_tienda/admin/dashboard.php');
                    exit;
                }

                // ✅ Cualquier usuario que NO sea administrador → ir directo al POS
                else {
                    $_SESSION['branch_id'] = $branch_id;

                    $stmt2 = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
                    $stmt2->execute([$branch_id]);
                    $_SESSION['branch_name'] = $stmt2->fetchColumn();

                    auth_log_attempt($email, 'success_pos');
                    header('Location: /mi_tienda/admin/pos.php?mode=tactil');
                    exit;
                }

            } else {
                $error = "Credenciales inválidas";
                auth_log_attempt($email, 'invalid_credentials');
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Login Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Fuente moderna -->
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
  <!-- Bootstrap para responsividad (opcional, solo para utilidades) -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --accent:#0d6efd;
      --glass-bg: rgba(255,255,255,0.96);
      --muted:#6c757d;
      --card-radius:14px;
      --shadow: 0 12px 30px rgba(2,6,23,0.28);
    }

    html,body{
      height:100%;
      margin:0;
      font-family: 'Roboto', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
      -webkit-font-smoothing:antialiased;
      -moz-osx-font-smoothing:grayscale;
      background-color:#0b1220;
      color:#111827;
    }

    /* Fondo con la imagen login.png ubicada en /mi_tienda/uploads/products/login.png
       Ajuste: se aclara la imagen aplicando menor overlay y aumentando brillo sutilmente */
    body {
      background-color: #0b1220;
      background-image:
        linear-gradient(rgba(6,10,20,0.20), rgba(6,10,20,0.18)), /* menos oscuro que antes */
        url('/mi_tienda/uploads/products/login.png');
      background-position: center center;
      background-repeat: no-repeat;
      background-size: cover;
      background-attachment: fixed;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      /* aumentar brillo y contraste ligeramente para que la imagen se vea más clara */
      filter: brightness(1.12) contrast(1.02);
    }

    /* overlay sutil para mejorar contraste del formulario (más claro que antes) */
    .bg-overlay {
      position: fixed;
      inset: 0;
      background: rgba(3,7,18,0.12); /* reducido para aclarar fondo */
      z-index: 0;
      pointer-events: none;
      mix-blend-mode: normal;
    }

    .page-wrap{
      min-height:100%;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:32px;
      position:relative;
      z-index:1;
    }

    .login-box{
      width:100%;
      max-width:520px;
      background: var(--glass-bg);
      border-radius: var(--card-radius);
      box-shadow: var(--shadow);
      padding:28px;
      position:relative;
      overflow:hidden;
      border:1px solid rgba(255,255,255,0.08);
      backdrop-filter: blur(6px) saturate(1.05);
    }

    .brand {
      display:flex;
      gap:12px;
      align-items:center;
      justify-content:center;
      margin-bottom:18px;
    }
    .brand .logo {
      width:56px;
      height:56px;
      border-radius:12px;
      background: linear-gradient(135deg, var(--accent), #6f42c1);
      display:flex;
      align-items:center;
      justify-content:center;
      color:white;
      font-weight:700;
      box-shadow: 0 6px 18px rgba(13,110,253,0.18);
      flex-shrink:0;
      font-size:1.05rem;
    }
    .brand h1{
      margin:0;
      font-size:1.25rem;
      color:var(--accent);
      font-weight:700;
      letter-spacing:0.2px;
    }

    .login-box h2{
      margin:0 0 12px 0;
      font-size:1.05rem;
      color:#0b1220;
      text-align:center;
    }

    .form-label{
      font-size:0.9rem;
      color:var(--muted);
      margin-bottom:6px;
    }

    .form-control{
      border-radius:10px;
      border:1px solid rgba(15,23,42,0.06);
      padding:10px 12px;
      background:linear-gradient(180deg, #fff, #fbfbfb);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
    }

    .btn-primary{
      background: linear-gradient(90deg, var(--accent), #6f42c1);
      border: none;
      padding:10px 14px;
      border-radius:10px;
      font-weight:600;
      box-shadow: 0 8px 20px rgba(13,110,253,0.14);
    }

    .small-muted{
      font-size:0.85rem;
      color:var(--muted);
      text-align:center;
      margin-top:12px;
    }

    .error-msg{
      color:#b91c1c;
      background: rgba(185,28,28,0.06);
      border:1px solid rgba(185,28,28,0.08);
      padding:8px 10px;
      border-radius:8px;
      font-size:0.95rem;
      margin-bottom:12px;
      text-align:center;
    }

    /* responsive tweaks */
    @media (max-width:600px){
      .login-box{ padding:20px; border-radius:12px; }
      .brand .logo{ width:48px; height:48px; font-size:0.95rem; }
      /* reducir brillo en pantallas pequeñas para evitar sobreexposición */
      body { filter: brightness(1.06) contrast(1.01); }
    }
  </style>
</head>
<body>
  <div class="bg-overlay" aria-hidden="true"></div>

  <div class="page-wrap">
    <div class="login-box" role="main" aria-labelledby="loginTitle">
      <div class="brand" aria-hidden="true">
        <div class="logo">MT</div>
        <h1>Mi Tienda</h1>
      </div>

      <h2 id="loginTitle">Acceso Administrador</h2>

      <?php if(!empty($error)): ?>
        <div class="error-msg" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" novalidate autocomplete="on" aria-describedby="loginHelp">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <div class="mb-3">
          <label for="email" class="form-label">Correo electrónico</label>
          <input type="email" id="email" name="email" class="form-control" required autocomplete="username" inputmode="email" aria-required="true" />
        </div>

        <div class="mb-3">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" id="password" name="password" class="form-control" required autocomplete="current-password" aria-required="true" />
        </div>

        <div class="d-grid">
          <button type="submit" class="btn btn-primary">Entrar</button>
        </div>

        <div id="loginHelp" class="small-muted">
          Acceso seguro al panel administrativo — protege tus credenciales.
        </div>
      </form>
    </div>
  </div>
</body>
</html>
