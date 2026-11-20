<?php
/**
 * LUXARA — API pública de la tienda
 *   GET  api/tienda.php?a=catalogo   → ajustes públicos, categorías y productos
 *   POST api/tienda.php?a=cupon      → valida un cupón contra el carrito
 *   POST api/tienda.php?a=pedido     → crea un pedido (ver crear_pedido)
 *   GET  api/tienda.php?a=pedido&numero=LX-…&tel=55551234 → estado de un pedido
 */
require __DIR__ . '/_lib.php';

$a = $_GET['a'] ?? '';

switch ($a) {
    case 'catalogo': method('GET');  catalogo();
    case 'cupon':    method('POST'); validar_cupon();
    case 'pedido':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') estado_pedido();
        method('POST'); crear_pedido();
    default: fail('Acción no válida', 404);
}

function catalogo(): never {
    $aj = ajustes();
    $cats = all('SELECT id, slug, nombre, subtitulo FROM categorias WHERE activo = 1 ORDER BY orden, nombre');
    $catSlug = array_column($cats, 'slug', 'id');

    $prods = all("SELECT p.* FROM productos p WHERE p.activo = 1 ORDER BY p.orden, p.destacado DESC, p.id DESC");
    $vars = all("SELECT v.* FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
                 WHERE v.activo = 1 AND p.activo = 1 ORDER BY v.orden, v.precio");
    $byProd = [];
    foreach ($vars as $v) $byProd[$v['producto_id']][] = $v;

    $out = [];
    foreach ($prods as $p) {
        if (empty($byProd[$p['id']])) continue;
        $variantes = [];
        foreach ($byProd[$p['id']] as $v) {
            [$final, $tachado, $promo] = precio_final((float)$v['precio'], $v['precio_anterior'] !== null ? (float)$v['precio_anterior'] : null, (int)$p['id'], $p['categoria_id'] !== null ? (int)$p['categoria_id'] : null);
            $variantes[] = [
                'id' => (int)$v['id'], 'nombre' => $v['nombre'], 'precio' => $final,
                'anterior' => $tachado, 'promo' => $promo, 'stock' => max(0, (int)$v['stock']),
            ];
        }
        $out[] = [
            'id' => (int)$p['id'], 'slug' => $p['slug'], 'nombre' => $p['nombre'], 'marca' => $p['marca'],
            'categoria' => $catSlug[$p['categoria_id']] ?? null, 'descripcion' => $p['descripcion'],
            'notas' => [$p['notas_salida'], $p['notas_corazon'], $p['notas_fondo']],
            'imagen' => $p['imagen'], 'forma' => $p['forma'], 'color_liquido' => $p['color_liquido'], 'color_tapa' => $p['color_tapa'],
            'nuevo' => (bool)$p['nuevo'], 'destacado' => (bool)$p['destacado'], 'ventas' => (int)$p['ventas'],
            'creado' => $p['creado'], 'variantes' => $variantes,
        ];
    }
    header('Cache-Control: public, max-age=30');
    ok([
        'tienda' => [
            'nombre' => $aj['tienda_nombre'] ?? 'Luxara',
            'whatsapp' => $aj['whatsapp'] ?? '',
            'envio_gratis_desde' => (float)($aj['envio_gratis_desde'] ?? 500),
            'costo_envio' => (float)($aj['costo_envio'] ?? 35),
            'anuncios' => json_decode($aj['anuncios'] ?? '[]', true) ?: [],
            'metodos_pago' => json_decode($aj['metodos_pago'] ?? '[]', true) ?: [],
        ],
        'categorias' => array_map(fn($c) => ['slug' => $c['slug'], 'nombre' => $c['nombre'], 'subtitulo' => $c['subtitulo']], $cats),
        'productos' => $out,
    ]);
}

/**
 * Recalcula el carrito en el servidor (nunca se confía en precios del navegador).
 * @param array $items [{variante_id, cantidad}]
 * @param bool  $bloquear usa SELECT … FOR UPDATE (dentro de transacción)
 */
function calcular_carrito(array $items, bool $bloquear = false): array {
    $cant = [];
    foreach ($items as $it) {
        $vid = int_($it['variante_id'] ?? 0); $c = int_($it['cantidad'] ?? 0);
        if ($vid <= 0 || $c <= 0) continue;
        if ($c > 50) fail('Cantidad no permitida');
        $cant[$vid] = ($cant[$vid] ?? 0) + $c;
    }
    if (!$cant) fail('El carrito está vacío');
    if (count($cant) > 40) fail('Demasiados productos en un pedido');

    $in = implode(',', array_fill(0, count($cant), '?'));
    $rows = all("SELECT v.id, v.producto_id, v.nombre AS variante, v.precio, v.precio_anterior, v.costo, v.stock, v.activo AS v_activo,
                        p.nombre, p.categoria_id, p.activo AS p_activo
                 FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
                 WHERE v.id IN ($in)" . ($bloquear ? ' FOR UPDATE' : ''), array_keys($cant));
    $byId = array_column($rows, null, 'id');

    $lineas = []; $subtotal = 0.0;
    foreach ($cant as $vid => $c) {
        $r = $byId[$vid] ?? null;
        if (!$r || !$r['v_activo'] || !$r['p_activo']) fail('Un producto de tu carrito ya no está disponible. Actualiza la página.', 409);
        if ((int)$r['stock'] < $c) fail("Solo quedan {$r['stock']} unidades de {$r['nombre']} ({$r['variante']})", 409);
        [$precio] = precio_final((float)$r['precio'], $r['precio_anterior'] !== null ? (float)$r['precio_anterior'] : null, (int)$r['producto_id'], $r['categoria_id'] !== null ? (int)$r['categoria_id'] : null);
        $total = round($precio * $c, 2);
        $subtotal += $total;
        $lineas[] = ['variante_id' => $vid, 'producto_id' => (int)$r['producto_id'], 'nombre' => $r['nombre'],
                     'variante' => $r['variante'], 'precio' => $precio, 'cantidad' => $c, 'total' => $total, 'stock' => (int)$r['stock'],
                     'costo' => $r['costo'] !== null ? (float)$r['costo'] : null];
    }
    return [$lineas, round($subtotal, 2)];
}

function validar_cupon(): never {
    $b = body();
    $codigo = str($b['codigo'] ?? '', 40);
    if ($codigo === '') fail('Escribe un cupón');
    [, $subtotal] = calcular_carrito($b['items'] ?? []);
    [$c, $desc, $envioGratis] = evaluar_cupon($codigo, $subtotal);
    $envio = costo_envio($subtotal, $envioGratis);
    ok(['cupon' => ['codigo' => $c['codigo'], 'tipo' => $c['tipo'], 'descripcion' => $c['descripcion']],
        'subtotal' => $subtotal, 'descuento' => $desc, 'envio' => $envio, 'total' => round($subtotal - $desc + $envio, 2)]);
}

function crear_pedido(): never {
    $b = body();
    // Anti-spam: campo trampa y límite por IP
    if (!empty($b['website'])) fail('Solicitud rechazada', 422);
    $recientes = (int)val('SELECT COUNT(*) FROM pedidos WHERE ip = ? AND creado > NOW() - INTERVAL 10 MINUTE', [client_ip()]);
    if ($recientes >= 5) fail('Demasiados pedidos seguidos. Intenta en unos minutos o escríbenos por WhatsApp.', 429);

    $c = $b['cliente'] ?? [];
    $d = [
        'nombre' => str($c['nombre'] ?? '', 120), 'telefono' => preg_replace('/\D/', '', (string)($c['telefono'] ?? '')),
        'email' => str($c['email'] ?? '', 160), 'departamento' => str($c['departamento'] ?? '', 40),
        'municipio' => str($c['municipio'] ?? '', 80), 'direccion' => str($c['direccion'] ?? '', 255),
        'referencias' => str($c['referencias'] ?? '', 255), 'nit' => strtoupper(str($c['nit'] ?? 'CF', 20)) ?: 'CF',
        'metodo_pago' => str($c['metodo_pago'] ?? '', 60), 'nota' => str($b['nota'] ?? '', 500),
    ];
    $deptos = ['Alta Verapaz','Baja Verapaz','Chimaltenango','Chiquimula','El Progreso','Escuintla','Guatemala','Huehuetenango','Izabal','Jalapa','Jutiapa','Petén','Quetzaltenango','Quiché','Retalhuleu','Sacatepéquez','San Marcos','Santa Rosa','Sololá','Suchitepéquez','Totonicapán','Zacapa'];
    $metodos = json_decode((string)ajuste('metodos_pago', '[]'), true) ?: [];
    if (mb_strlen($d['nombre']) < 3) fail('Ingresa tu nombre completo');
    if (strlen($d['telefono']) !== 8) fail('El teléfono debe tener 8 dígitos');
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) fail('Correo no válido');
    if (!in_array($d['departamento'], $deptos, true)) fail('Selecciona un departamento válido');
    if ($d['municipio'] === '') fail('Ingresa el municipio');
    if (mb_strlen($d['direccion']) < 5) fail('Ingresa tu dirección completa');
    if (!in_array($d['metodo_pago'], $metodos, true)) fail('Selecciona un método de pago');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$lineas, $subtotal] = calcular_carrito($b['items'] ?? [], true);
        $desc = 0.0; $envioGratis = false; $cuponCodigo = null;
        if (!empty($b['cupon'])) {
            [$cup, $desc, $envioGratis] = evaluar_cupon(str($b['cupon'], 40), $subtotal, true);
            $cuponCodigo = $cup['codigo'];
            q('UPDATE cupones SET usos = usos + 1 WHERE id = ?', [$cup['id']]);
        }
        $envio = costo_envio($subtotal, $envioGratis);
        $total = round($subtotal - $desc + $envio, 2);

        do { $numero = 'LX-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4)); }
        while (val('SELECT 1 FROM pedidos WHERE numero = ?', [$numero]));

        q('INSERT INTO pedidos (numero, nombre, telefono, email, departamento, municipio, direccion, referencias, nit, metodo_pago, nota,
                               subtotal, descuento, envio, total, cupon_codigo, ip)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$numero, $d['nombre'], $d['telefono'], $d['email'] ?: null, $d['departamento'], $d['municipio'], $d['direccion'],
           $d['referencias'] ?: null, $d['nit'], $d['metodo_pago'], $d['nota'] ?: null, $subtotal, $desc, $envio, $total, $cuponCodigo, client_ip()]);
        $pid = (int)$pdo->lastInsertId();

        $insItem = $pdo->prepare('INSERT INTO pedido_items (pedido_id, producto_id, variante_id, nombre, variante, precio, costo, cantidad, total) VALUES (?,?,?,?,?,?,?,?,?)');
        $updStock = $pdo->prepare('UPDATE producto_variantes SET stock = stock - ? WHERE id = ?');
        $mov = $pdo->prepare("INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia) VALUES (?,?,?,'venta',?)");
        $ventas = $pdo->prepare('UPDATE productos SET ventas = ventas + ? WHERE id = ?');
        foreach ($lineas as $l) {
            $insItem->execute([$pid, $l['producto_id'], $l['variante_id'], $l['nombre'], $l['variante'], $l['precio'], $l['costo'], $l['cantidad'], $l['total']]);
            $updStock->execute([$l['cantidad'], $l['variante_id']]);
            $mov->execute([$l['variante_id'], -$l['cantidad'], $l['stock'] - $l['cantidad'], $numero]);
            $ventas->execute([$l['cantidad'], $l['producto_id']]);
        }
        q("INSERT INTO pedido_historial (pedido_id, estado, nota) VALUES (?, 'pendiente', 'Pedido creado desde la tienda')", [$pid]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

    ok(['pedido' => [
        'numero' => $numero, 'subtotal' => $subtotal, 'descuento' => $desc, 'envio' => $envio, 'total' => $total,
        'cupon' => $cuponCodigo, 'items' => array_map(fn($l) => ['nombre' => $l['nombre'], 'variante' => $l['variante'], 'precio' => $l['precio'], 'cantidad' => $l['cantidad']], $lineas),
        'cliente' => ['nombre' => $d['nombre'], 'telefono' => $d['telefono'], 'departamento' => $d['departamento'], 'municipio' => $d['municipio'],
                      'direccion' => $d['direccion'], 'referencias' => $d['referencias'], 'nit' => $d['nit'], 'metodo_pago' => $d['metodo_pago']],
        'nota' => $d['nota'], 'creado' => date('c'), 'estado' => 'pendiente',
    ]], 201);
}

function estado_pedido(): never {
    $numero = strtoupper(str($_GET['numero'] ?? '', 20));
    $tel = preg_replace('/\D/', '', (string)($_GET['tel'] ?? ''));
    $p = one('SELECT numero, estado, total, creado, actualizado FROM pedidos WHERE numero = ? AND telefono = ?', [$numero, $tel]);
    if (!$p) fail('No encontramos ese pedido', 404);
    ok(['pedido' => $p]);
}
