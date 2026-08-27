<?php
// public/checkout.php
// Esta página toma el total guardado en localStorage mediante un pequeño script.
// Para seguridad real, el total debe calcularse en servidor a partir del carrito del usuario autenticado.
// Aquí implementamos el flujo solicitado: abrir link Wompi con el valor.

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Checkout - Mi Tienda</title>
  <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
  <main>
    <h1>Checkout</h1>
    <p id="total-display">Calculando total...</p>
    <button id="pay-button">Pagar con Wompi</button>
    <br><br>
    <a id="whatsapp-button" href="#" target="_blank">Enviar orden por WhatsApp</a>
  </main>

  <script>
    (function(){
      // Obtener total desde localStorage (establecido por cart.js)
      const total = localStorage.getItem('mi_tienda_total') || '0.00';
      document.getElementById('total-display').innerText = 'Total: $' + parseFloat(total).toFixed(2);

      // Link de Wompi proporcionado por el usuario
      const wompiBase = 'https://checkout.wompi.co/l/VPOS_LQP3KR';

      document.getElementById('pay-button').addEventListener('click', function(){
        // Si Wompi acepta query param con amount (depende de Wompi), intentamos pasarlo.
        // Si no, el usuario verá el monto en la pasarela manualmente.
        const amount = parseFloat(total).toFixed(2);
        // Muchas pasarelas esperan centavos o parámetros específicos; aquí abrimos el link.
        // Si Wompi soporta ?amount=..., se añadirá; si no, se abrirá el link base.
        const url = wompiBase + '?amount=' + encodeURIComponent(amount);
        window.open(url, '_blank');
      });

      // WhatsApp: enviar orden al número 3202477979 con resumen
      document.getElementById('whatsapp-button').addEventListener('click', function(e){
        e.preventDefault();
        const cart = JSON.parse(localStorage.getItem('mi_tienda_cart') || '[]');
        let text = 'Orden de compra%0A';
        cart.forEach(it => {
          text += `${encodeURIComponent(it.name)} x${it.qty} - $${it.price.toFixed(2)}%0A`;
        });
        text += 'Total: $' + parseFloat(total).toFixed(2) + '%0A';
        text += 'Enviar a: (poner dirección y datos del cliente)%0A';
        const wa = 'https://wa.me/573202477979?text=' + text;
        window.open(wa, '_blank');
      });
    })();
  </script>
</body>
</html>
