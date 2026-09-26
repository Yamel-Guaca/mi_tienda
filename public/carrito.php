<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Mi Tienda - Carrito de Compras</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --azul: #0B2545; --amarillo: #EEB902; --bg: #F4F6F9; }
    body { font-family: 'Inter', sans-serif; background: var(--bg); margin: 0; padding: 20px; }
    .container { max-width: 900px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    h1 { color: var(--azul); font-size: 1.5rem; border-bottom: 2px solid var(--bg); padding-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
    th { background: #f8fafc; color: #475569; }
    .qty-btn { background: #e2e8f0; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-weight: bold; }
    .btn-delete { background: #ef4444; color: #fff; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; }
    .summary { margin-top: 20px; text-align: right; font-size: 1.2rem; font-weight: bold; }
    .checkout-box { margin-top: 30px; background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .form-group { margin-bottom: 12px; }
    .form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 0.9rem; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
    .btn-pay { width: 100%; background: #22c55e; color: #fff; font-size: 1.1rem; font-weight: 700; border: none; padding: 14px; border-radius: 8px; cursor: pointer; margin-top: 10px; }
    .btn-wa { background: #25D366; margin-top: 10px; }
    .btn-wompi { background: #3B82F6; color: #fff; }
  </style>
</head>
<body>

<div class="container">
  <a href="catalogo.php" style="text-decoration:none; color:var(--azul); font-weight:bold;">&larr; Volver al Catálogo</a>
  <h1>Tu Carrito de Compras</h1>

  <table id="cart-table">
    <thead>
      <tr>
        <th>Producto</th>
        <th>Precio</th>
        <th>Cantidad</th>
        <th>Subtotal</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody id="cart-items">
      <!-- Inyección JS -->
    </tbody>
  </table>

  <div class="summary">
    Total a Pagar: $<span id="cart-total">0.00</span>
  </div>

  <!-- Formulario de Finalización -->
  <div class="checkout-box">
    <h3>Datos para la Entrega y Facturación</h3>
    <form id="checkout-form">
      <div class="form-group">
        <label>Nombre Completo</label>
        <input type="text" id="cust_name" required placeholder="Ej: Maria Lopez">
      </div>
      <div class="form-group">
        <label>Teléfono / WhatsApp</label>
        <input type="tel" id="cust_phone" required placeholder="Ej: 3001234567">
      </div>
      <div class="form-group">
        <label>Dirección de Envío</label>
        <input type="text" id="cust_address" required placeholder="Calle, Número, Ciudad">
      </div>
      
      <button type="button" class="btn-pay btn-wa" onclick="processWhatsAppOrder()">Completar Pedido por WhatsApp</button>
      <button type="submit" id="btn-submit-wompi" class="btn-pay btn-wompi">Pagar con Wompi (Monto Automático)</button>
    </form>
  </div>
</div>

<script>
function renderCart() {
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  const tbody = document.getElementById('cart-items');
  let total = 0;
  tbody.innerHTML = '';

  if(cart.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">El carrito está vacío</td></tr>';
    document.getElementById('cart-total').textContent = '0.00';
    return;
  }

  cart.forEach((item, index) => {
    const subtotal = item.price * item.qty;
    total += subtotal;
    tbody.innerHTML += `
      <tr>
        <td>${item.name}</td>
        <td>$${item.price.toFixed(2)}</td>
        <td>
          <button class="qty-btn" onclick="changeQty(${index}, -1)">-</button>
          <span style="margin: 0 8px;">${item.qty}</span>
          <button class="qty-btn" onclick="changeQty(${index}, 1)">+</button>
        </td>
        <td>$${subtotal.toFixed(2)}</td>
        <td><button class="btn-delete" onclick="deleteItem(${index})">Eliminar</button></td>
      </tr>
    `;
  });

  document.getElementById('cart-total').textContent = total.toFixed(2);
}

function changeQty(index, delta) {
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  if(cart[index]) {
    cart[index].qty += delta;
    if(cart[index].qty <= 0) cart.splice(index, 1);
    localStorage.setItem('cart_v1', JSON.stringify(cart));
    renderCart();
  }
}

function deleteItem(index) {
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  cart.splice(index, 1);
  localStorage.setItem('cart_v1', JSON.stringify(cart));
  renderCart();
}

// Opción 1: Enviar pedido por WhatsApp
function processWhatsAppOrder() {
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  if(cart.length === 0) return alert('El carrito está vacío');

  const name = document.getElementById('cust_name').value.trim();
  const phone = document.getElementById('cust_phone').value.trim();
  const address = document.getElementById('cust_address').value.trim();

  if(!name || !phone || !address) return alert('Por favor completa todos los datos de envío');

  let text = `*NUEVO PEDIDO - MI TIENDA*\n`;
  text += `*Cliente:* ${name}\n*Tel:* ${phone}\n*Dirección:* ${address}\n\n`;
  text += `*Productos:*\n`;

  let total = 0;
  cart.forEach(i => {
    const sub = i.price * i.qty;
    total += sub;
    text += `- ${i.name} x${i.qty} = $${sub.toFixed(2)}\n`;
  });

  text += `\n*TOTAL:* $${total.toFixed(2)}`;

  const myNumber = "573163766890"; 
  window.open(`https://wa.me/${myNumber}?text=${encodeURIComponent(text)}`, '_blank');
}

// Opción 2: Generar Link de Pago Dinámico con Wompi vía API Backend
document.getElementById('checkout-form').addEventListener('submit', function(e) {
  e.preventDefault();
  const cart = JSON.parse(localStorage.getItem('cart_v1') || '[]');
  if(cart.length === 0) return alert('El carrito está vacío');

  const btnPay = document.getElementById('btn-submit-wompi');
  btnPay.disabled = true;
  btnPay.textContent = 'Generando enlace de pago...';

  const payload = {
    customer: {
      name: document.getElementById('cust_name').value.trim(),
      phone: document.getElementById('cust_phone').value.trim(),
      address: document.getElementById('cust_address').value.trim()
    },
    items: cart
  };

  fetch('process_order.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(res => res.json())
  .then(data => {
    if(data.success && data.payment_url) {
      // Limpiar el carrito antes de la redirección
      localStorage.removeItem('cart_v1');
      // Redirigir al link dinámico con monto bloqueado
      window.location.href = data.payment_url;
    } else {
      alert('Error al generar la pasarela: ' + (data.error || 'Intenta de nuevo'));
      btnPay.disabled = false;
      btnPay.textContent = 'Pagar con Wompi (Monto Automático)';
    }
  })
  .catch(err => {
    console.error(err);
    alert('Ocurrió un error de conexión al procesar el pago.');
    btnPay.disabled = false;
    btnPay.textContent = 'Pagar con Wompi (Monto Automático)';
  });
});

document.addEventListener('DOMContentLoaded', renderCart);
</script>
</body>
</html>