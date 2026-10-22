/* ============================================
   NAVEGACIÓN ENTRE CATEGORÍAS (MODO TÁCTIL)
   Estas funciones cambian la URL para navegar
============================================ */
function goToCategories() {
    window.location.href = "pos.php?mode=tactil";
}

function goToCategory(catId) {
    window.location.href = "pos.php?mode=tactil&cat=" + catId;
}

function goToSubcategory(catId, subId) {
    window.location.href = "pos.php?mode=tactil&cat=" + catId + "&sub=" + subId;
}

/* ============================================
   CARRITO UNIFICADO (CLÁSICO + TÁCTIL)
============================================ */
let cart = [];

/* Agrega un producto al carrito */
function addToCart(id, name, price, image, quantity = 1) {
    const index = cart.findIndex(item => item.product_id === id);

    if (index >= 0) {
        cart[index].quantity += quantity;
    } else {
        cart.push({
            product_id: id,
            name: name,
            price: parseFloat(price),
            quantity: quantity,
            image: image
        });
    }

    renderCart();
    playBeep();
}

/* Renderiza el carrito en pantalla */
function renderCart() {
    const tbody = document.getElementById("cart-body");
    const totalSpan = document.getElementById("total");
    const itemsInput = document.getElementById("items-input");

    if (!tbody || !totalSpan || !itemsInput) return;

    let html = "";
    let total = 0;

    cart.forEach((item, i) => {
        const subtotal = item.price * item.quantity;
        total += subtotal;

        html += `
            <tr>
                <td>${item.name}</td>
                <td>${item.quantity}</td>
                <td>$${subtotal.toFixed(2)}</td>
                <td><button class="btn btn-danger" type="button" onclick="removeItem(${i})">X</button></td>
            </tr>
        `;
    });

    tbody.innerHTML = html;
    totalSpan.innerText = total.toFixed(2);
    itemsInput.value = JSON.stringify(cart);
}

/* Elimina un producto del carrito */
function removeItem(i) {
    cart.splice(i, 1);
    renderCart();
}

/* Limpia el carrito */
function clearCart() {
    if (!cart.length) return;
    if (!confirm("¿Vaciar carrito?")) return;
    cart = [];
    renderCart();
}

/* ============================================
   MODAL DE CANTIDAD (CLÁSICO)
============================================ */
let currentProduct = null;

/* Abre el modal */
function openQtyModal(id, name, price, image) {
    currentProduct = { id, name, price: parseFloat(price), image };
    const overlay = document.getElementById("modal-overlay");
    const qtyInput = document.getElementById("modal-qty");
    const title = document.getElementById("modal-product-name");

    if (!overlay || !qtyInput || !title) return;

    title.innerText = name;
    qtyInput.value = 1;
    overlay.style.display = "flex";
    qtyInput.focus();
}

/* Cierra el modal */
function closeQtyModal() {
    const overlay = document.getElementById("modal-overlay");
    if (overlay) overlay.style.display = "none";
    currentProduct = null;
}

/* Confirma la cantidad */
function confirmQty() {
    const qtyInput = document.getElementById("modal-qty");
    if (!qtyInput || !currentProduct) return;

    const qty = parseInt(qtyInput.value, 10);
    if (!qty || qty <= 0) return;

    addToCart(
        currentProduct.id,
        currentProduct.name,
        currentProduct.price,
        currentProduct.image,
        qty
    );

    closeQtyModal();
}

/* ============================================
   SONIDO
============================================ */
function playBeep() {
    const beep = document.getElementById("beep-sound");
    if (beep) {
        beep.currentTime = 0;
        beep.play().catch(() => {});
    }
}

/* ============================================
   MODO OSCURO
============================================ */
const btnToggleDark = document.getElementById("btn-toggle-dark");
if (btnToggleDark) {
    btnToggleDark.addEventListener("click", () => {
        document.body.classList.toggle("dark");
        btnToggleDark.textContent = document.body.classList.contains("dark")
            ? "Modo claro"
            : "Modo oscuro";
    });
}

/* ============================================
   ATAJOS DE TECLADO
============================================ */
document.addEventListener("keydown", function(e) {
    const overlay = document.getElementById("modal-overlay");
    const modalVisible = overlay && overlay.style.display === "flex";

    if (e.key === "Escape" && modalVisible) {
        closeQtyModal();
        return;
    }

    if (modalVisible && e.key === "Enter") {
        e.preventDefault();
        confirmQty();
        return;
    }

    if (!modalVisible) {
        if (e.key === "F1") {
            e.preventDefault();
            const input = document.getElementById("search-input");
            if (input) input.focus();
        }

        if (e.key === "F2") {
            e.preventDefault();
            const form = document.getElementById("sell-form");
            if (form) form.submit();
        }

        if (e.key === "F3") {
            e.preventDefault();
            clearCart();
        }
    }
});
