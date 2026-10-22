// public/js/cart.js
(function(){
  function getCart() {
    return JSON.parse(localStorage.getItem('mi_tienda_cart') || '[]');
  }
  function saveCart(cart) {
    localStorage.setItem('mi_tienda_cart', JSON.stringify(cart));
  }
  function addToCart(item) {
    const cart = getCart();
    const idx = cart.findIndex(i => i.id == item.id);
    if (idx >= 0) {
      cart[idx].qty += item.qty || 1;
    } else {
      cart.push({ id: item.id, name: item.name, price: parseFloat(item.price), qty: item.qty || 1 });
    }
    saveCart(cart);
    alert('Producto agregado al carrito');
  }
  function renderCart() {
    const container = document.getElementById('cart-container');
    if (!container) return;
    const cart = getCart();
    if (cart.length === 0) {
      container.innerHTML = '<p>Tu carrito está vacío.</p>';
      return;
    }
    let html = '<table><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr>';
    let total = 0;
    cart.forEach(item => {
      const subtotal = item.price * item.qty;
      total += subtotal;
      html += `<tr><td>${item.name}</td><td>${item.qty}</td><td>$${item.price.toFixed(2)}</td><td>$${subtotal.toFixed(2)}</td></tr>`;
    });
    html += `</table><p>Total: $${total.toFixed(2)}</p>`;
    container.innerHTML = html;
    const checkoutBtn = document.getElementById('go-to-checkout');
    if (checkoutBtn) {
      checkoutBtn.onclick = function() {
        // Guardar total en localStorage y redirigir a checkout
        localStorage.setItem('mi_tienda_total', total.toFixed(2));
        window.location.href = '/checkout.php';
      };
    }
  }

  // botones "Agregar al carrito" en catálogo
  document.querySelectorAll('.add-to-cart').forEach(btn => {
    btn.addEventListener('click', function(){
      const id = this.dataset.id;
      const name = this.dataset.name;
      const price = this.dataset.price;
      addToCart({ id, name, price, qty: 1 });
    });
  });

  // renderizar carrito si estamos en la página
  document.addEventListener('DOMContentLoaded', renderCart);
})();
