<?php
// public/catalogo2.php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi Tienda - Catálogo en construcción</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --color-bg: #10203C;
      --color-accent: #0a6;
      --color-light: #f5f5f5;
      --color-text: #ffffff;
    }

    * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', sans-serif; }

    body {
      background: var(--color-bg);
      color: var(--color-text);
      display:flex;
      flex-direction:column;
      min-height:100vh;
      justify-content:center;
      align-items:center;
      text-align:center;
      padding:20px;
    }

    h1 {
      font-size:2.2rem;
      margin-bottom:20px;
      color: var(--color-accent);
    }

    p {
      font-size:1.2rem;
      max-width:600px;
      line-height:1.6;
      margin-bottom:30px;
    }

    .loader {
      border:6px solid rgba(255,255,255,0.2);
      border-top:6px solid var(--color-accent);
      border-radius:50%;
      width:60px;
      height:60px;
      animation: spin 1s linear infinite;
      margin:20px auto;
    }

    @keyframes spin {
      0% { transform:rotate(0deg); }
      100% { transform:rotate(360deg); }
    }

    a {
      display:inline-block;
      margin-top:20px;
      padding:12px 24px;
      border-radius:6px;
      background: var(--color-accent);
      color:#fff;
      text-decoration:none;
      font-weight:bold;
      transition:0.3s;
    }
    a:hover {
      background:#0c8;
    }

    footer {
      position:absolute;
      bottom:10px;
      width:100%;
      text-align:center;
      font-size:0.9rem;
      color:rgba(255,255,255,0.6);
    }

    @media (max-width:600px) {
      h1 { font-size:1.6rem; }
      p { font-size:1rem; }
    }
  </style>
</head>
<body>
  <h1>Catálogo en construcción</h1>
  <p>
    Estamos trabajando para ofrecerte la mejor experiencia de compra en línea.  
    Muy pronto podrás explorar nuestro catálogo completo de productos.
  </p>
  <div class="loader"></div>
  <a href="index.php">Volver al inicio</a>

  <footer>
    © <?= date('Y') ?> Mi Tienda
  </footer>
</body>
</html>
