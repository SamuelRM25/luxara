# Luxara — Tienda en línea + Panel de administración

Tienda de perfumes con carrito, checkout contra entrega, pedidos guardados en MySQL y panel para administrar pedidos, perfumes, inventario, cupones, promociones y ajustes.

**Stack:** PHP 8.1+ (sin frameworks ni Composer), MySQL 8, HTML/CSS/JS sin dependencias.


## En tu Mac (carpeta GitHub)
La copia de trabajo está en `Documentos/GitHub/luxara`, con el contenido de `public_html/` en la raíz y la carpeta `database/` al lado:

```
GitHub/luxara/
├── index.html, admin/, api/, assets/, config/, uploads/
├── instalar.php      ← crea las tablas y carga tus perfumes (solo funciona en localhost)
└── database/         ← los .sql que usa el instalador
```

1. Abre `http://localhost:PUERTO/luxara/instalar.php` en tu servidor PHP y pulsa los 3 pasos en orden.
2. Entra a `http://localhost:PUERTO/luxara/admin/` y crea tu usuario administrador.
3. La tienda queda en `http://localhost:PUERTO/luxara/`.

Para Hostinger: sube todo **menos** `instalar.php` y `database/` (la base ya queda lista desde tu Mac porque es la misma de Clever Cloud).

---

## Estructura

```
Luxara/
├── README.md
├── database/
│   ├── schema.sql            ← 1. tablas, ajustes y categorías (obligatorio)
│   ├── productos_luxara.sql  ← 2. tus 26 perfumes del Excel: precio, costo, stock e imagen
│   ├── historial_ventas.sql  ← 3. ventas de mayo–junio del Excel (opcional)
│   ├── migracion_v2.sql      ← solo si ya habías importado el schema anterior
│   └── demo.sql              ← productos ficticios (ya NO usar con tus productos reales)
└── public_html/          ← ESTO es lo que se sube a Hostinger
    ├── index.html        ← la tienda
    ├── .htaccess         ← HTTPS, seguridad, caché
    ├── assets/           ← logo
    ├── admin/index.html  ← panel  (tudominio.com/admin)
    ├── api/
    │   ├── _lib.php      ← conexión, precios, promociones, sesiones
    │   ├── tienda.php    ← API pública: catálogo, cupón, crear pedido
    │   └── admin.php     ← API del panel (requiere sesión + token CSRF)
    ├── config/
    │   ├── config.php    ← CREDENCIALES (bloqueado al público)
    │   └── config.example.php
    └── uploads/productos/← fotos que subes desde el panel
```

---

## Puesta en marcha

### 1. Crear las tablas
En phpMyAdmin (Hostinger o Clever Cloud), selecciona la base de datos → pestaña **Importar**:
1. `database/schema.sql` *(si ya lo habías importado antes, importa en su lugar `migracion_v2.sql`)*
2. `database/productos_luxara.sql` → carga tus 26 perfumes con precio, costo, existencias y foto.
3. *(opcional)* `database/historial_ventas.sql` → tus ventas de mayo y junio, para que aparezcan en reportes de ganancia.

O desde terminal:
```bash
mysql -h HOST -P 3306 -u USUARIO -p BASE < database/schema.sql
mysql -h HOST -P 3306 -u USUARIO -p BASE < database/productos_luxara.sql
mysql -h HOST -P 3306 -u USUARIO -p BASE < database/historial_ventas.sql
```

### 2. Subir el sitio
Hostinger → **Administrador de archivos** → entra a `public_html` del dominio y sube **el contenido** de la carpeta `public_html/` (no la carpeta en sí). Asegúrate de que también se suban los archivos ocultos `.htaccess`.

### 3. Activar SSL
Hostinger → Seguridad → SSL → instalar (gratis). El `.htaccess` ya redirige todo a HTTPS.

### 4. Crear tu usuario administrador
Entra a `https://tudominio.com/admin`. La primera vez el panel pide crear la cuenta del **administrador principal**. Después de eso esa pantalla desaparece y solo funciona el login.

### 5. Configurar la tienda
En el panel → **Ajustes**: número de WhatsApp, envío gratis desde, costo de envío, mensajes de la barra superior, métodos de pago y datos bancarios.

---

## Cómo funciona un pedido

1. La tienda pide el catálogo a `api/tienda.php?a=catalogo` (precios con promociones ya aplicadas).
2. El cliente llena el checkout → `api/tienda.php?a=pedido`.
3. El servidor **recalcula** precios, cupón y envío (nunca confía en el navegador), bloquea las filas de stock, guarda el pedido, descuenta inventario y registra el movimiento. Todo en una sola transacción: si algo falla, no se guarda nada.
4. El pedido aparece en el panel como **Pendiente**. El botón de WhatsApp sigue disponible para el cliente, pero ya no depende de él.
5. Desde el panel: Pendiente → Confirmado → Enviado → Entregado. **Cancelar** devuelve el stock y libera el uso del cupón.

## Qué tiene el panel

| Sección | Qué hace |
|---|---|
| Resumen | Ventas de hoy y del mes, gráfica de 14 días, stock bajo, más vendidos, departamentos |
| Resumen (ganancias) | Ganancia del mes (venta − costo), margen, ventas y ganancia de los últimos meses, tienda web vs venta directa |
| Pedidos | Filtros por estado y canal, búsqueda, fechas, detalle con ganancia, cambio de estado, notas internas, WhatsApp al cliente, exportar a Excel (CSV con costo y ganancia) |
| Venta directa | Botón “+ Venta directa” en Pedidos: registra ventas en persona/WhatsApp/Instagram, con precio editable, descuento, fecha y opción de descontar inventario |
| Perfumes | Crear/editar con varias presentaciones, precio, precio tachado, **costo**, stock, foto (se optimiza a WEBP) |
| Inventario | Inversión en bodega, valor de venta, ganancia potencial, entradas, ajustes, devoluciones e historial |
| Cupones | % / monto fijo / envío gratis, compra mínima, límite de usos, vigencia |
| Promociones | Descuento automático a toda la tienda, una categoría o un perfume, con fecha de inicio y fin |
| Categorías | Colecciones que se muestran en la tienda *(solo admin)* |
| Ajustes | WhatsApp, envíos, anuncios, métodos de pago *(solo admin)* |
| Usuarios | Administradores y vendedores *(solo admin)* |

## Seguridad incluida
- Consultas preparadas (PDO) en todo: sin inyección SQL.
- Contraseñas con `password_hash` (bcrypt) y bloqueo tras 8 intentos fallidos en 15 min.
- Sesión con cookie `HttpOnly` + `SameSite=Strict` y token CSRF en cada cambio.
- Carpeta `config/` y archivos `.sql/.md` bloqueados al público; `uploads/` no ejecuta PHP.
- Anti-spam en pedidos: campo trampa y máximo 5 pedidos por IP cada 10 minutos.

## Imágenes
Las 26 fotos de publicidad (carpetas dama, caballero y unisex) ya están asignadas a cada perfume y optimizadas en `public_html/uploads/productos/` con el nombre del producto (ej. `lattafa-yara.webp`). Para cambiar una foto, súbela desde el panel → Perfumes → Editar → Subir foto.

## Diseño de la tienda
- **Partículas doradas** animadas en el fondo (reaccionan al mouse; se desactivan si el usuario pidió reducir animaciones).
- **Glassmorphism**: encabezado flotante, tarjetas, filtros, colecciones, carrito, ventanas y checkout de vidrio esmerilado.
- **Gradient mesh**: fondo de degradados en movimiento lento; cada colección tiene su propio mesh de color (rosa, azul, ámbar).

## Notas importantes
- Tus fotos incluyen los frascos y logotipos de las marcas. Es normal en un revendedor, pero el pie de página aclara que las marcas pertenecen a sus dueños.
- Rose Petals tiene costo Q0 en el Excel: corrígelo en Perfumes si no fue regalo, para que la ganancia salga bien.
- El historial de ventas incluye perfumes que ya no están en inventario (Nitro Red, 9AM, Khamrah, World Cup…): aparecen en reportes pero no en la tienda.
- En **Ajustes** pon el número de WhatsApp de la tienda (viene uno de ejemplo).
- **Cambia la contraseña de la base de datos** en Clever Cloud (se compartió en un chat) y actualízala en `config/config.php`.
- El plan gratuito de Clever Cloud tiene límites bajos de conexiones simultáneas y de espacio. Hostinger incluye bases MySQL en sus planes; si migras, solo cambias los datos en `config/config.php` e importas `schema.sql`.
- Pon `'debug' => false` en producción (ya viene así).
- Si abres `index.html` sin servidor, la tienda entra en **modo demostración** con productos de ejemplo y no guarda pedidos.

## Probar en tu Mac
```bash
cd public_html
php -S localhost:8000
# tienda: http://localhost:8000   panel: http://localhost:8000/admin
```
