<?php
/**
 * =====================================================================================
 * MANUAL INTERACTIVO DE USUARIO Y SISTEMA (admin/manual.php)
 * =====================================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/db.php';

// Verificar autenticación
$user_id = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? $_SESSION['user']['id'] ?? 0;
if (!$user_id) {
    header('Location: /mi_tienda/login.php');
    exit;
}

$user_name = $_SESSION['user']['name'] ?? $_SESSION['user_name'] ?? 'Usuario';
$user_role = $_SESSION['user_role'] ?? $_SESSION['rol'] ?? $_SESSION['role'] ?? $_SESSION['user']['role_id'] ?? '';
$is_admin  = (strtolower((string)$user_role) === 'admin' || strtolower((string)$user_role) === 'administrador' || intval($user_role) === 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual de Usuario y Guía Operativa</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --dark: #1e293b;
            --light-bg: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background-color: #f1f5f9;
            color: #334155;
            margin: 0;
            padding: 0;
        }

        header {
            background: #0f172a;
            color: #fff;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        header h1 {
            margin: 0;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .back-btn:hover {
            background: var(--primary-hover);
        }

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .hero {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border-left: 5px solid var(--primary);
        }

        .hero h2 {
            margin-top: 0;
            color: var(--dark);
        }

        .hero p {
            margin-bottom: 0;
            color: #64748b;
            line-height: 1.6;
        }

        .search-bar {
            margin-bottom: 25px;
        }

        .search-bar input {
            width: 100%;
            padding: 14px 18px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: 15px;
            box-sizing: border-box;
            outline: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .search-bar input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        /* GRID DE MÓDULOS */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
        }

        .card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid var(--border);
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .card-icon {
            font-size: 28px;
            background: #eff6ff;
            padding: 10px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            margin: 0;
        }

        .card-desc {
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .btn-open {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.2s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-open:hover {
            background: var(--primary-hover);
        }

        /* MODAL / VENTANA EMERGENTE */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            padding: 20px;
            backdrop-filter: blur(2px);
        }

        .modal-content {
            background: #fff;
            border-radius: 12px;
            max-width: 800px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
            animation: fadeIn 0.2s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 10;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #94a3b8;
            padding: 0;
            line-height: 1;
        }

        .modal-close:hover {
            color: #334155;
        }

        .modal-body {
            padding: 25px;
            line-height: 1.7;
            color: #334155;
        }

        .step-list {
            padding-left: 20px;
            margin-bottom: 20px;
        }

        .step-list li {
            margin-bottom: 12px;
        }

        .step-list strong {
            color: var(--dark);
        }

        .tip-box {
            background: #eff6ff;
            border-left: 4px solid var(--primary);
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            margin: 15px 0;
            font-size: 13px;
            color: #1e40af;
        }

        .warn-box {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            padding: 12px 16px;
            border-radius: 0 6px 6px 0;
            margin: 15px 0;
            font-size: 13px;
            color: #991b1b;
        }

        .shortcut-badge {
            background: #e2e8f0;
            color: #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
    </style>
</head>
<body>

<header>
    <h1>📖 Centro de Ayuda & Manual de Uso</h1>
    <div>
        <a href="pos.php" class="back-btn">🛒 Volver al POS</a>
    </div>
</header>

<div class="container">
    <div class="hero">
        <h2>Bienvenido, <?= htmlspecialchars($user_name) ?> 👋</h2>
        <p>En este manual encontrarás la guía paso a paso para operar correctamente los módulos del sistema: Ventas, Clientes, Caja, Compras e Impresión de Comprobantes. Haz clic en cualquiera de los módulos para desplegar su guía detallada.</p>
    </div>

    <div class="search-bar">
        <input type="text" id="manualSearch" placeholder="🔍 Buscar función o proceso (ej: crear cliente, cedula, nit, apertura, proveedor, tirilla)..." onkeyup="filterModules()">
    </div>

    <div class="modules-grid">

        <!-- MÓDULO 1: POS / VENTAS -->
        <div class="card" data-keywords="pos ventas caja productos facturacion cobrar efectivo nequi daviplata tarjeta">
            <div>
                <div class="card-header">
                    <div class="card-icon">🛒</div>
                    <h3 class="card-title">POS / Punto de Venta</h3>
                </div>
                <p class="card-desc">Aprende a registrar ventas rápidamente, buscar productos por SKU o nombre, seleccionar diferentes métodos de pago e imprimir facturas.</p>
            </div>
            <button class="btn-open" onclick="openModal('modal-pos')">Ver Guía de Uso ➔</button>
        </div>

        <!-- MÓDULO NUEVO: CREACIÓN Y GESTIÓN DE CLIENTES EN POS -->
        <div class="card" data-keywords="clientes cliente crear registrar pos cedula nit consumidro final correo telefono direccion datos facturacion dian">
            <div>
                <div class="card-header">
                    <div class="card-icon">👤</div>
                    <h3 class="card-title">Creación de Clientes en POS</h3>
                </div>
                <p class="card-desc">Instrucciones para registrar clientes desde la pantalla de ventas, asignar datos tributarios (Cédula/NIT, Correo, Teléfono) y vinculación con la venta.</p>
            </div>
            <button class="btn-open" onclick="openModal('modal-clientes')">Ver Guía de Uso ➔</button>
        </div>

        <!-- MÓDULO 2: ARQUEO Y CIERRE DE CAJA -->
        <div class="card" data-keywords="caja arqueo apertura cierre efectivo denominaciones dinero reporte descuadre sobrante faltante">
            <div>
                <div class="card-header">
                    <div class="card-icon">💵</div>
                    <h3 class="card-title">Caja y Arqueo Diario</h3>
                </div>
                <p class="card-desc">Procedimiento para abrir turno, realizar el conteo físico de monedas y billetes, conciliar gastos y compras, y generar el reporte de cierre.</p>
            </div>
            <button class="btn-open" onclick="openModal('modal-caja')">Ver Guía de Uso ➔</button>
        </div>

        <!-- MÓDULO 3: COMPRAS Y PROVEEDORES -->
        <div class="card" data-keywords="compras proveedores facturas mercancia inventario embalaje cajas unidades stock sucursal">
            <div>
                <div class="card-header">
                    <div class="card-icon">📦</div>
                    <h3 class="card-title">Ingreso de Compras</h3>
                </div>
                <p class="card-desc">Cómo ingresar facturas de compra de proveedores, gestión de cajas/embalajes, asignación automática de sucursal y actualización del Kardex.</p>
            </div>
            <button class="btn-open" onclick="openModal('modal-compras')">Ver Guía de Uso ➔</button>
        </div>

        <!-- MÓDULO 4: IMPRESIÓN Y REIMPRESIONES -->
        <div class="card" data-keywords="impresion reimpresion tirilla ticket recibo factura soporte gasto comprobante 58mm 80mm">
            <div>
                <div class="card-header">
                    <div class="card-icon">🖨️</div>
                    <h3 class="card-title">Impresión de Ticket y Soportes</h3>
                </div>
                <p class="card-desc">Guía para la configuración de tirillas (58mm/80mm), reimpresión de comprobantes de ventas, compras a proveedores y egresos por gastos.</p>
            </div>
            <button class="btn-open" onclick="openModal('modal-impresion')">Ver Guía de Uso ➔</button>
        </div>

    </div>
</div>

<!-- ================= MODALES CON EL CONTENIDO DEL MANUAL ================= -->

<!-- MODAL POS -->
<div id="modal-pos" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🛒 Módulo: Punto de Venta (POS)</h3>
            <button class="modal-close" onclick="closeModal('modal-pos')">&times;</button>
        </div>
        <div class="modal-body">
            <h4>Paso a Paso para Registrar una Venta:</h4>
            <ol class="step-list">
                <li><strong>Apertura previa:</strong> Asegúrate de tener la caja abierta en el sistema antes de iniciar tus ventas.</li>
                <li><strong>Búsqueda de productos:</strong> Usa la barra de búsqueda para ingresar el nombre o código SKU del producto. Si usas un lector de código de barras, escanéalo directamente.</li>
                <li><strong>Agregar al carrito:</strong> Ajusta las cantidades solicitadas por el cliente.</li>
                <li><strong>Seleccionar Cliente:</strong> Por defecto el sistema asigna "Consumidor Final". Si el cliente requiere sus datos en la factura, selecciónalo o créalo con el botón <strong>"+ Nuevo Cliente"</strong>.</li>
                <li><strong>Seleccionar Método de Pago:</strong>
                    <ul>
                        <li><span class="shortcut-badge">Efectivo</span>: Ingresa el valor recibido para calcular automáticamente el vuelto/cambio.</li>
                        <li><span class="shortcut-badge">Nequi / DaviPlata / Tarjeta</span>: Ingresa el monto correspondiente enviado a la cuenta del negocio.</li>
                    </ul>
                </li>
                <li><strong>Finalizar Venta:</strong> Haz clic en <strong>"Procesar Venta"</strong>. El sistema guardará la transacción, descontará los artículos del inventario e iniciará la ventana de impresión.</li>
            </ol>

            <div class="tip-box">
                💡 <strong>Consejo:</strong> Puedes realizar ventas combinadas pagando una parte en efectivo y otra mediante transferencia electrónica (Nequi/DaviPlata).
            </div>
        </div>
    </div>
</div>

<!-- MODAL NUEVO: CLIENTES -->
<div id="modal-clientes" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>👤 Módulo: Creación y Gestión de Clientes en POS</h3>
            <button class="modal-close" onclick="closeModal('modal-clientes')">&times;</button>
        </div>
        <div class="modal-body">
            <h4>Pasos para Buscar o Crear un Cliente durante la Venta:</h4>
            
            <h5 style="margin-bottom:8px; color:var(--dark);">1. Buscar un Cliente Existente</h5>
            <ol class="step-list">
                <li>En la barra superior de selección de cliente del POS, escribe la Cédula/NIT o el Nombre del cliente.</li>
                <li>Selecciónalo del desplegable para que sus datos queden asociados a la venta actual.</li>
            </ol>

            <h5 style="margin-bottom:8px; color:var(--dark);">2. Crear un Cliente Nuevo</h5>
            <ol class="step-list">
                <li>Si el cliente no se encuentra registrado, presiona el botón <strong>"+ Nuevo Cliente"</strong> (o ícono de usuario 👤) ubicado al lado del campo de cliente.</li>
                <li>Se desplegará una ventana emergente donde debes diligenciar los datos principales:
                    <ul>
                        <li><strong>Tipo de Documento:</strong> Selecciona Cédula de Ciudadanía (CC), NIT, Cédula de Extranjería (CE) o Pasaporte.</li>
                        <li><strong>N° Documento / NIT:</strong> Ingresa el número de identificación único sin puntos ni guiones.</li>
                        <li><strong>Nombre Completo / Razón Social:</strong> Nombre completo de la persona o nombre fiscal de la empresa.</li>
                        <li><strong>Teléfono / Celular:</strong> Número de contacto para envío de comprobantes o llamadas de servicio.</li>
                        <li><strong>Correo Electrónico:</strong> Indispensable para el envío de factura digital.</li>
                        <li><strong>Dirección:</strong> Ubicación del cliente (opcional para facturación genérica).</li>
                    </ul>
                </li>
                <li>Haz clic en <strong>"Guardar Cliente"</strong>. El sistema guardará al usuario en la base de datos y lo dejará <strong>seleccionado automáticamente</strong> para la venta en curso.</li>
            </ol>

            <div class="tip-box">
                💡 <strong>Soporte Facturación / DIAN:</strong> Mantener registrados correctamente el documento y el correo del cliente permite que al imprimir el ticket o comprobante la información salga completa y lista para auditoría fiscal.
            </div>

            <div class="warn-box">
                ⚠️ <strong>Nota:</strong> Si el cliente no requiere sus datos en la factura, déjalo con la opción por defecto: <strong>Consumidor Final (Doc: 222222222222)</strong>.
            </div>
        </div>
    </div>
</div>

<!-- MODAL CAJA -->
<div id="modal-caja" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>💵 Módulo: Arqueo y Cierre de Caja</h3>
            <button class="modal-close" onclick="closeModal('modal-caja')">&times;</button>
        </div>
        <div class="modal-body">
            <h4>Flujo de Trabajo Diario en Caja:</h4>
            
            <h5 style="margin-bottom:8px; color:var(--dark);">1. Apertura de Caja</h5>
            <ol class="step-list">
                <li>Al iniciar la jornada, ingresa a <strong>Caja / Arqueo</strong>.</li>
                <li>Ingresa el dinero inicial en efectivo con el que empiezas en el cajón (Monto de Apertura) y presiona <strong>"Abrir Caja"</strong>.</li>
            </ol>

            <h5 style="margin-bottom:8px; color:var(--dark);">2. Realización del Arqueo / Conteo Físico</h5>
            <ol class="step-list">
                <li>En la tabla de denominaciones, ingresa la cantidad exacta de monedas y billetes presentes en el cajón (ej. 10 billetes de $50.000, 5 monedas de $500, etc.).</li>
                <li>El sistema sumará en tiempo real el valor total físico.</li>
            </ol>

            <h5 style="margin-bottom:8px; color:var(--dark);">3. Cierre de Caja y Descuadre</h5>
            <p>Al hacer clic en <strong>"Cerrar Caja"</strong>, el sistema concilia automáticamente todas las operaciones registradas durante la sesión mediante la siguiente ecuación:</p>

            <div class="tip-box" style="font-family:monospace; font-size:13px; text-align:center;">
                Diferencia = Efectivo a Retirar + Ventas Virtuales + Gastos + Compras - Ventas Totales
            </div>

            <ul>
                <li><strong>CUADRE ($0):</strong> El dinero físico coincide exactamente con las ventas netas.</li>
                <li><strong>SOBRANTE (+):</strong> Hay más dinero físico en caja del esperado.</li>
                <li><strong>FALTANTE (-):</strong> Falta dinero físico en el cajón.</li>
            </ul>

            <div class="warn-box">
                ⚠️ <strong>Atención:</strong> Las compras de mercancía realizadas en efectivo reducen el dinero en caja. Asegúrate de registrar todas las compras en el módulo correspondiente para que el sistema no lo detecte como faltante.
            </div>
        </div>
    </div>
</div>

<!-- MODAL COMPRAS -->
<div id="modal-compras" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📦 Módulo: Compras y Recepción de Mercancía</h3>
            <button class="modal-close" onclick="closeModal('modal-compras')">&times;</button>
        </div>
        <div class="modal-body">
            <h4>Pasos para Ingresar una Factura de Proveedor:</h4>
            <ol class="step-list">
                <li><strong>Sucursal de Destino:</strong>
                    <ul>
                        <li><strong>Cajeros/Operadores:</strong> El sistema bloquea y fija automáticamente la sucursal asignada a tu sesión activa para evitar ingresos por error en otra sede.</li>
                        <li><strong>Administradores:</strong> Tienen habilitado el selector para elegir la sucursal de destino deseada.</li>
                    </ul>
                </li>
                <li><strong>Datos de Proveedor:</strong> Ingresa el Nombre del Proveedor, el Número de Factura o comprobante recibido y la fecha de compra.</li>
                <li><strong>Agregar Productos:</strong>
                    <ul>
                        <li>Busca el producto por nombre o SKU.</li>
                        <li>Ingresa la <strong>Cantidad de Empaques</strong> y el <strong>Embalaje (Contenedores/Cajas)</strong>. El sistema desglosará automáticamente las unidades mínimas que ingresarán al inventario.</li>
                        <li>Ingresa el costo unitario y el porcentaje de IVA si aplica. Si el ítem es un obsequio o muestra comercial, marca la casilla <strong>"¿Es Obsequio?"</strong>.</li>
                        <li>Haz clic en <strong>"Agregar Ítem"</strong>.</li>
                    </ul>
                </li>
                <li><strong>Guardar y Procesar:</strong> Revisa el Total Pagado y presiona <strong>"Guardar e Imprimir Soporte"</strong>. Se actualizará inmediatamente el stock en el inventario y se descontará del egreso de caja de la sesión.</li>
            </ol>
        </div>
    </div>
</div>

<!-- MODAL IMPRESIÓN -->
<div id="modal-impresion" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>🖨️ Módulo: Impresión y Reimpresión de Documentos</h3>
            <button class="modal-close" onclick="closeModal('modal-impresion')">&times;</button>
        </div>
        <div class="modal-body">
            <h4>Tipos de Comprobantes Disponibles:</h4>
            <ul class="step-list">
                <li><strong>Facturas de Venta:</strong> Se generan automáticamente al completar una venta en el POS o se pueden reimprimir mediante el historial de ventas. Muestra el detalle de pago, datos del cliente e impuestos.</li>
                <li><strong>Reporte de Cierre de Caja:</strong> Se puede imprimir en formato de tirilla térmica (58mm u 80mm) con el desglose de ingresos, ventas virtuales, gastos, compras y diferencia.</li>
                <li><strong>Comprobante de Compras:</strong> Certificado de entrada a bodega que desglosa la mercadería recibida del proveedor.</li>
                <li><strong>Soporte de Gastos:</strong> Muestra la salida de caja por concepto de servicios, nómina o imprevistos.</li>
            </ul>

            <div class="warn-box">
                🚫 <strong>Documentos Anulados:</strong> Cuando se reimprime una factura o un gasto anulado, el sistema imprimirá automáticamente un marco rojo de auditoría con la leyenda <strong>"*** FACTURA ANULADA ***"</strong> junto con la fecha y el usuario que realizó la anulación.
            </div>
        </div>
    </div>
</div>

<script>
// Función para abrir modal
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

// Función para cerrar modal
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Cerrar modal al hacer clic fuera del contenedor
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal-overlay');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Filtro de búsqueda en tiempo real para las tarjetas del manual
function filterModules() {
    const input = document.getElementById('manualSearch').value.toLowerCase();
    const cards = document.querySelectorAll('.card');

    cards.forEach(card => {
        const keywords = card.getAttribute('data-keywords').toLowerCase();
        const title = card.querySelector('.card-title').textContent.toLowerCase();
        const desc = card.querySelector('.card-desc').textContent.toLowerCase();

        if (keywords.includes(input) || title.includes(input) || desc.includes(input)) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

</body>
</html>