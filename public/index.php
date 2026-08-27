<?php
// public/index.php
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi Tienda - Bienvenidos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --color-bg: #10203C;   /* azul profundo */
      --color-accent: #0a6;  /* verde acento */
      --color-light: #f5f5f5;
      --color-text: #ffffff;
    }

    * { margin:0; padding:0; box-sizing:border-box; font-family: 'Segoe UI', sans-serif; }

    body {
      background: var(--color-bg);
      color: var(--color-text);
      display:flex;
      flex-direction:column;
      min-height:100vh;
    }

    header {
      padding:20px;
      text-align:center;
      background:rgba(255,255,255,0.05);
    }
    header h1 {
      font-size:2.2rem;
      letter-spacing:2px;
    }

    main {
      flex:1;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      text-align:center;
      padding:40px 20px;
    }
    main h2 {
      font-size:1.8rem;
      margin-bottom:20px;
      color: var(--color-accent);
    }
    main p {
      max-width:600px;
      line-height:1.6;
      margin-bottom:20px;
    }

    .btn {
      display:inline-block;
      padding:12px 24px;
      margin:10px;
      border-radius:6px;
      text-decoration:none;
      font-weight:bold;
      transition:0.3s;
    }
    .btn-primary {
      background: var(--color-accent);
      color:#fff;
    }
    .btn-primary:hover {
      background:#0c8;
    }
    .btn-secondary {
      background:transparent;
      border:1px solid var(--color-accent);
      color:var(--color-accent);
    }
    .btn-secondary:hover {
      background:var(--color-accent);
      color:#fff;
    }

    footer {
      text-align:center;
      padding:15px;
      font-size:0.9rem;
      background:rgba(255,255,255,0.05);
    }

    /* Responsive */
    @media (max-width:600px) {
      header h1 { font-size:1.6rem; }
      main h2 { font-size:1.4rem; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Bienvenidos a Mi Tienda</h1>
  </header>

  <main>
    <h2>Quiénes somos</h2>
    <p>
      Somos una empresa comprometida con ofrecer productos de calidad para tu hogar, aseo y alimentación.
      Nuestra misión es brindar confianza y cercanía a cada cliente, con raíces en la cultura campesina
      y experiencia urbana que nos impulsa a crecer.
    </p>

    <h2>Contáctanos</h2>
    <p>📧 Correo Temporal: yguaca@gmail.com </p>

    <div>
      <a href="catalogo2.php" class="btn btn-primary">Ver Catálogo</a>
      <a href="/mi_tienda/admin/login.php" class="btn btn-secondary">Administrativo</a>
    </div>
  </main>

  <footer>
    © <?= date('Y') ?> Mi Tienda. Todos los derechos reservados.
  </footer>
</body>
</html>
