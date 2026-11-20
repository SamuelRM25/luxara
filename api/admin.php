<?php
/**
 * LUXARA — API del panel de administración
 * Todas las rutas (excepto estado/login/setup) requieren sesión y token CSRF.
 *   api/admin.php?r=<ruta>
 */
require __DIR__ . '/_lib.php';
start_session();

$r = $_GET['r'] ?? '';
$m = $_SERVER['REQUEST_METHOD'];

const ESTADOS = ['pendiente', 'confirmado', 'enviado', 'entregado', 'cancelado'];

switch ($r) {
    /* ---------- Acceso ---------- */
    case 'estado': {
        $needs = (int)val('SELECT COUNT(*) FROM usuarios') === 0;
        $u = !empty($_SESSION['uid']) ? one('SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND activo = 1', [$_SESSION['uid']]) : null;
        ok(['needs_setup' => $needs, 'user' => $u, 'csrf' => csrf_token()]);
    }
    case 'setup': {
        method('POST');
        if ((int)val('SELECT COUNT(*) FROM usuarios') > 0) fail('El administrador inicial ya fue creado', 403);
        $b = body();
        $nombre = str($b['nombre'] ?? '', 120); $email = strtolower(str($b['email'] ?? '', 160)); $pass = (string)($b['password'] ?? '');
        if (mb_strlen($nombre) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nombre o correo no válido');
        if (strlen($pass) < 8) fail('La contraseña debe tener al menos 8 caracteres');
        q("INSERT INTO usuarios (nombre, email, password_hash, rol) VALUES (?,?,?,'admin')", [$nombre, $email, password_hash($pass, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)db()->lastInsertId();
        ok(['csrf' => csrf_token()]);
    }
    case 'login': {
        method('POST');
        $b = body();
        $fallos = (int)val('SELECT COUNT(*) FROM intentos_login WHERE ip = ? AND creado > NOW() - INTERVAL 15 MINUTE', [client_ip()]);
        if ($fallos >= 8) fail('Demasiados intentos. Espera 15 minutos.', 429);
        $email = strtolower(str($b['email'] ?? '', 160));
        $u = one('SELECT * FROM usuarios WHERE email = ? AND activo = 1', [$email]);
        if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) {
            q('INSERT INTO intentos_login (ip, email) VALUES (?,?)', [client_ip(), $email]);
            usleep(400000);
            fail('Correo o contraseña incorrectos', 401);
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT))
            q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
        q('DELETE FROM intentos_login WHERE ip = ?', [client_ip()]);
        q('UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?', [$u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        unset($_SESSION['csrf']);
        ok(['user' => ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol']], 'csrf' => csrf_token()]);
    }
    case 'logout': {
        $_SESSION = []; session_destroy(); ok();
    }
}

/* ===== A partir de aquí todo requiere sesión ===== */
$U = require_admin();

switch ($r) {

/* ---------- Dashboard ---------- */
case 'dashboard': {
    $hoy = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM pedidos WHERE DATE(creado) = CURDATE() AND estado <> 'cancelado'");
    $mes = one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM pedidos WHERE creado >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND estado <> 'cancelado'");
    $mesAnt = (float)val("SELECT COALESCE(SUM(total),0) FROM pedidos WHERE creado >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH,'%Y-%m-01')
                          AND creado < DATE_FORMAT(CURDATE(),'%Y-%m-01') AND estado <> 'cancelado'");
    $pend = (int)val("SELECT COUNT(*) FROM pedidos WHERE estado = 'pendiente'");
    // Ganancia del mes = (subtotal − descuento) − costo de lo vendido
    $gan = one("SELECT COALESCE(SUM(p.subtotal - p.descuento),0) venta,
                       COALESCE(SUM((SELECT SUM(COALESCE(i.costo,0) * i.cantidad) FROM pedido_items i WHERE i.pedido_id = p.id)),0) costo
                FROM pedidos p WHERE p.creado >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND p.estado <> 'cancelado'");
    $ganancia = round((float)$gan['venta'] - (float)$gan['costo'], 2);
    $canales = all("SELECT canal, COUNT(*) n, COALESCE(SUM(total),0) t FROM pedidos WHERE estado <> 'cancelado' AND creado >= DATE_FORMAT(CURDATE(),'%Y-%m-01') GROUP BY canal");
    $mesesGan = all("SELECT DATE_FORMAT(p.creado,'%Y-%m') m, SUM(p.subtotal - p.descuento) venta,
                       SUM((SELECT SUM(COALESCE(i.costo,0) * i.cantidad) FROM pedido_items i WHERE i.pedido_id = p.id)) costo, COUNT(*) n
                     FROM pedidos p WHERE p.estado <> 'cancelado' AND p.creado >= DATE_FORMAT(CURDATE() - INTERVAL 6 MONTH,'%Y-%m-01')
                     GROUP BY m ORDER BY m");
    $porEstado = all("SELECT estado, COUNT(*) n FROM pedidos GROUP BY estado");
    $dias = all("SELECT DATE(creado) d, COUNT(*) n, SUM(total) t FROM pedidos
                 WHERE creado >= CURDATE() - INTERVAL 13 DAY AND estado <> 'cancelado' GROUP BY DATE(creado)");
    $serie = []; $map = array_column($dias, null, 'd');
    for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $serie[] = ['d' => $d, 'n' => (int)($map[$d]['n'] ?? 0), 't' => (float)($map[$d]['t'] ?? 0)]; }
    $top = all("SELECT i.nombre, SUM(i.cantidad) cant, SUM(i.total) t FROM pedido_items i JOIN pedidos p ON p.id = i.pedido_id
                WHERE p.estado <> 'cancelado' AND p.creado >= CURDATE() - INTERVAL 30 DAY GROUP BY i.nombre ORDER BY cant DESC LIMIT 5");
    $deptos = all("SELECT departamento, COUNT(*) n FROM pedidos WHERE estado <> 'cancelado' AND departamento <> '' GROUP BY departamento ORDER BY n DESC LIMIT 6");
    $bajo = all("SELECT v.id, p.nombre, v.nombre variante, v.stock, v.stock_minimo FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
                 WHERE v.activo = 1 AND p.activo = 1 AND v.stock <= v.stock_minimo ORDER BY v.stock LIMIT 8");
    $ultimos = all("SELECT id, numero, canal, nombre, departamento, total, estado, creado FROM pedidos ORDER BY creado DESC, id DESC LIMIT 6");
    ok(compact('hoy', 'mes', 'mesAnt', 'pend', 'porEstado', 'serie', 'top', 'deptos', 'bajo', 'ultimos', 'ganancia', 'canales', 'mesesGan'));
}

/* ---------- Pedidos ---------- */
case 'pedidos': {
    [$where, $p] = filtros_pedidos();
    $page = max(1, int_($_GET['page'] ?? 1)); $per = 25;
    $total = (int)val("SELECT COUNT(*) FROM pedidos $where", $p);
    $rows = all("SELECT id, numero, canal, nombre, telefono, departamento, municipio, total, metodo_pago, estado, creado,
                        (SELECT SUM(cantidad) FROM pedido_items WHERE pedido_id = pedidos.id) items
                 FROM pedidos $where ORDER BY creado DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
    $conteo = array_column(all('SELECT estado, COUNT(*) n FROM pedidos GROUP BY estado'), 'n', 'estado');
    ok(['pedidos' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'conteo' => $conteo]);
}
case 'pedido': {
    $id = int_($_GET['id'] ?? 0);
    $ped = one('SELECT * FROM pedidos WHERE id = ?', [$id]) ?? fail('Pedido no encontrado', 404);
    $ped['items'] = all('SELECT * FROM pedido_items WHERE pedido_id = ?', [$id]);
    $ped['historial'] = all('SELECT h.*, u.nombre usuario FROM pedido_historial h LEFT JOIN usuarios u ON u.id = h.usuario_id WHERE pedido_id = ? ORDER BY h.id', [$id]);
    $ped['otros'] = $ped['telefono'] !== '' ? (int)val('SELECT COUNT(*) FROM pedidos WHERE telefono = ? AND id <> ?', [$ped['telefono'], $id]) : 0;
    $ped['costo_total'] = (float)val('SELECT COALESCE(SUM(COALESCE(costo,0) * cantidad),0) FROM pedido_items WHERE pedido_id = ?', [$id]);
    $ped['sin_costo'] = (int)val('SELECT COUNT(*) FROM pedido_items WHERE pedido_id = ? AND costo IS NULL', [$id]);
    ok(['pedido' => $ped, 'whatsapp_tienda' => ajuste('whatsapp')]);
}
case 'pedido_estado': {
    method('POST');
    $b = body(); $id = int_($b['id'] ?? 0); $nuevo = str($b['estado'] ?? '', 20); $nota = str($b['nota'] ?? '', 300);
    if (!in_array($nuevo, ESTADOS, true)) fail('Estado no válido');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ped = one('SELECT * FROM pedidos WHERE id = ? FOR UPDATE', [$id]) ?? fail('Pedido no encontrado', 404);
        if ($ped['estado'] === 'cancelado') fail('Un pedido cancelado no se puede reactivar. Crea uno nuevo.');
        if ($ped['estado'] === $nuevo) fail('El pedido ya está en ese estado');
        if ($nuevo === 'cancelado') {   // devolver inventario
            foreach (all('SELECT * FROM pedido_items WHERE pedido_id = ?', [$id]) as $it) {
                if (!$it['variante_id']) continue;
                // solo devuelve lo que realmente se descontó (ventas manuales pueden no descontar stock)
                $desc = (int)val("SELECT COALESCE(-SUM(cambio),0) FROM movimientos_inventario WHERE variante_id = ? AND referencia = ? AND motivo = 'venta'", [$it['variante_id'], $ped['numero']]);
                $it['cantidad'] = min((int)$it['cantidad'], max(0, $desc));
                if ($it['cantidad'] <= 0) continue;
                q('UPDATE producto_variantes SET stock = stock + ? WHERE id = ?', [$it['cantidad'], $it['variante_id']]);
                $sf = (int)val('SELECT stock FROM producto_variantes WHERE id = ?', [$it['variante_id']]);
                q("INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia, usuario_id) VALUES (?,?,?,'cancelacion',?,?)",
                  [$it['variante_id'], $it['cantidad'], $sf, $ped['numero'], $U['id']]);
                if ($it['producto_id']) q('UPDATE productos SET ventas = GREATEST(0, CAST(ventas AS SIGNED) - ?) WHERE id = ?', [$it['cantidad'], $it['producto_id']]);
            }
            if ($ped['cupon_codigo']) q('UPDATE cupones SET usos = GREATEST(0, CAST(usos AS SIGNED) - 1) WHERE codigo = ?', [$ped['cupon_codigo']]);
        }
        q('UPDATE pedidos SET estado = ? WHERE id = ?', [$nuevo, $id]);
        q('INSERT INTO pedido_historial (pedido_id, estado, nota, usuario_id) VALUES (?,?,?,?)', [$id, $nuevo, $nota ?: null, $U['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok();
}
case 'pedido_nota': {
    method('POST');
    $b = body(); $id = int_($b['id'] ?? 0); $nota = str($b['nota'] ?? '', 300);
    if ($nota === '') fail('Escribe una nota');
    $est = val('SELECT estado FROM pedidos WHERE id = ?', [$id]) ?? fail('Pedido no encontrado', 404);
    q('INSERT INTO pedido_historial (pedido_id, estado, nota, usuario_id) VALUES (?,?,?,?)', [$id, $est, $nota, $U['id']]);
    ok();
}
case 'exportar_pedidos': {
    [$where, $p] = filtros_pedidos();
    $rows = all("SELECT p.numero, p.creado, p.canal, p.estado, p.nombre, p.telefono, p.email, p.departamento, p.municipio, p.direccion, p.referencias, p.nit,
                        p.metodo_pago, p.subtotal, p.descuento, p.envio, p.total, p.cupon_codigo, p.nota,
                        (SELECT SUM(COALESCE(i.costo,0) * i.cantidad) FROM pedido_items i WHERE i.pedido_id = p.id) costo,
                        (SELECT GROUP_CONCAT(CONCAT(i.cantidad,' x ',i.nombre,' (',i.variante,')') SEPARATOR ' | ') FROM pedido_items i WHERE i.pedido_id = p.id) productos
                 FROM pedidos p $where ORDER BY p.id DESC", $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pedidos-luxara-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel lea acentos
    fputcsv($out, ['Número','Fecha','Canal','Estado','Cliente','Teléfono','Correo','Departamento','Municipio','Dirección','Referencias','NIT','Pago','Subtotal','Descuento','Envío','Total','Cupón','Nota','Costo','Ganancia','Productos']);
    foreach ($rows as $row) {
        $prods = $row['productos']; unset($row['productos']);
        $row['ganancia'] = number_format((float)$row['subtotal'] - (float)$row['descuento'] - (float)$row['costo'], 2, '.', '');
        $row['productos'] = $prods;
        fputcsv($out, array_values($row));
    }
    exit;
}

case 'venta_manual': {
    method('POST');
    $b = body();
    $nombre = str($b['nombre'] ?? '', 120) ?: 'Cliente de mostrador';
    $tel = preg_replace('/\D/', '', (string)($b['telefono'] ?? ''));
    if ($tel !== '' && strlen($tel) !== 8) fail('El teléfono debe tener 8 dígitos o quedar vacío');
    $metodo = str($b['metodo_pago'] ?? 'Efectivo', 60) ?: 'Efectivo';
    $fecha = dt($b['fecha'] ?? '') ?? date('Y-m-d H:i:s');
    if (strtotime($fecha) > time() + 3600) fail('La fecha no puede ser futura');
    $estado = in_array($b['estado'] ?? '', ['pendiente', 'confirmado', 'enviado', 'entregado'], true) ? $b['estado'] : 'entregado';
    $descontar = bool_($b['descontar'] ?? 1);
    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
    if (!$items) fail('Agrega al menos un perfume');
    $descuento = max(0, num($b['descuento'] ?? 0));
    $pdo = db(); $pdo->beginTransaction();
    try {
        $lineas = []; $subtotal = 0.0;
        foreach ($items as $it) {
            $vid = int_($it['variante_id'] ?? 0); $c = max(1, int_($it['cantidad'] ?? 1));
            $v = one('SELECT v.*, p.nombre producto FROM producto_variantes v JOIN productos p ON p.id = v.producto_id WHERE v.id = ? FOR UPDATE', [$vid])
                 ?? fail('Presentación no encontrada');
            if ($descontar && (int)$v['stock'] < $c) fail("Solo hay {$v['stock']} unidad(es) de {$v['producto']} ({$v['nombre']})");
            $precio = ($it['precio'] ?? '') === '' ? (float)$v['precio'] : max(0, num($it['precio']));
            $lineas[] = [$v, $c, $precio];
            $subtotal += $precio * $c;
        }
        $subtotal = round($subtotal, 2); $descuento = min($descuento, $subtotal); $total = round($subtotal - $descuento, 2);
        do { $numero = 'LX-' . date('ymd', strtotime($fecha)) . '-M' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3)); }
        while (val('SELECT 1 FROM pedidos WHERE numero = ?', [$numero]));
        q("INSERT INTO pedidos (numero, canal, nombre, telefono, departamento, municipio, direccion, metodo_pago, nota, subtotal, descuento, envio, total, estado, creado)
           VALUES (?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)",
          [$numero, $nombre, $tel, str($b['departamento'] ?? '', 40), str($b['municipio'] ?? '', 80), str($b['direccion'] ?? '', 255),
           $metodo, str($b['nota'] ?? '', 500) ?: null, $subtotal, $descuento, $total, $estado, $fecha]);
        $pid = (int)$pdo->lastInsertId();
        foreach ($lineas as [$v, $c, $precio]) {
            q('INSERT INTO pedido_items (pedido_id, producto_id, variante_id, nombre, variante, precio, costo, cantidad, total) VALUES (?,?,?,?,?,?,?,?,?)',
              [$pid, $v['producto_id'], $v['id'], $v['producto'], $v['nombre'], $precio, $v['costo'], $c, round($precio * $c, 2)]);
            if ($descontar) {
                q('UPDATE producto_variantes SET stock = stock - ? WHERE id = ?', [$c, $v['id']]);
                q("INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia, usuario_id) VALUES (?,?,?,'venta',?,?)",
                  [$v['id'], -$c, (int)$v['stock'] - $c, $numero, $U['id']]);
            }
            q('UPDATE productos SET ventas = ventas + ? WHERE id = ?', [$c, $v['producto_id']]);
        }
        q('INSERT INTO pedido_historial (pedido_id, estado, nota, usuario_id) VALUES (?,?,?,?)',
          [$pid, $estado, 'Venta manual registrada' . ($descontar ? '' : ' (sin descontar stock)'), $U['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok(['id' => $pid, 'numero' => $numero]);
}

/* ---------- Productos ---------- */
case 'productos': {
    $w = []; $p = [];
    if (!empty($_GET['q'])) { $w[] = '(p.nombre LIKE ? OR p.marca LIKE ?)'; $p[] = '%' . $_GET['q'] . '%'; $p[] = '%' . $_GET['q'] . '%'; }
    if (!empty($_GET['categoria'])) { $w[] = 'p.categoria_id = ?'; $p[] = int_($_GET['categoria']); }
    if (isset($_GET['activo']) && $_GET['activo'] !== '') { $w[] = 'p.activo = ?'; $p[] = bool_($_GET['activo']); }
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $prods = all("SELECT p.*, c.nombre categoria FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id $where ORDER BY p.orden, p.id DESC", $p);
    $vars = all('SELECT * FROM producto_variantes ORDER BY orden, precio');
    $by = []; foreach ($vars as $v) $by[$v['producto_id']][] = $v;
    foreach ($prods as &$pr) $pr['variantes'] = $by[$pr['id']] ?? [];
    ok(['productos' => $prods, 'categorias' => all('SELECT * FROM categorias ORDER BY orden, nombre')]);
}
case 'producto_guardar': {
    method('POST');
    $b = body(); $id = int_($b['id'] ?? 0);
    $f = [
        'nombre' => str($b['nombre'] ?? '', 140), 'marca' => str($b['marca'] ?? '', 100) ?: null,
        'categoria_id' => int_($b['categoria_id'] ?? 0) ?: null, 'descripcion' => str($b['descripcion'] ?? '', 3000) ?: null,
        'notas_salida' => str($b['notas_salida'] ?? '', 200) ?: null, 'notas_corazon' => str($b['notas_corazon'] ?? '', 200) ?: null,
        'notas_fondo' => str($b['notas_fondo'] ?? '', 200) ?: null, 'imagen' => str($b['imagen'] ?? '', 255) ?: null,
        'forma' => in_array($b['forma'] ?? '', ['square','round','tall','flask','facet','box'], true) ? $b['forma'] : 'square',
        'color_liquido' => preg_match('/^#[0-9a-f]{6}$/i', $b['color_liquido'] ?? '') ? $b['color_liquido'] : '#7a4b12',
        'color_tapa' => preg_match('/^#[0-9a-f]{6}$/i', $b['color_tapa'] ?? '') ? $b['color_tapa'] : '#d4ac62',
        'destacado' => bool_($b['destacado'] ?? 0), 'nuevo' => bool_($b['nuevo'] ?? 0), 'activo' => bool_($b['activo'] ?? 1),
        'orden' => int_($b['orden'] ?? 0),
    ];
    if (mb_strlen($f['nombre']) < 2) fail('El nombre es obligatorio');
    if ($f['imagen'] && !preg_match('#^(uploads/productos/[\w.-]+|https://[^\s"\'<>]+)$#', $f['imagen'])) fail('Ruta de imagen no válida');
    $variantes = $b['variantes'] ?? [];
    if (!is_array($variantes) || !$variantes) fail('Agrega al menos una presentación (tamaño y precio)');
    foreach ($variantes as $v) {
        if (str($v['nombre'] ?? '', 60) === '') fail('Cada presentación necesita un nombre, por ejemplo "100 ml"');
        if (num($v['precio'] ?? 0) <= 0) fail('Cada presentación necesita un precio mayor a 0');
    }

    $pdo = db(); $pdo->beginTransaction();
    try {
        if ($id) {
            one('SELECT id FROM productos WHERE id = ?', [$id]) ?? fail('Producto no encontrado', 404);
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            q("UPDATE productos SET $sets WHERE id = ?", [...array_values($f), $id]);
        } else {
            $base = slugify(($f['marca'] ? $f['marca'] . ' ' : '') . $f['nombre']); $slug = $base; $n = 2;
            while (val('SELECT 1 FROM productos WHERE slug = ?', [$slug])) $slug = $base . '-' . $n++;
            $f['slug'] = $slug;
            q('INSERT INTO productos (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')', array_values($f));
            $id = (int)$pdo->lastInsertId();
        }
        $keep = [];
        foreach (array_values($variantes) as $i => $v) {
            $vid = int_($v['id'] ?? 0);
            $costo = (($v['costo'] ?? '') === '' || $v['costo'] === null) ? null : max(0, num($v['costo']));
            $row = [str($v['nombre'], 60), str($v['sku'] ?? '', 60) ?: null, num($v['precio']),
                    num($v['precio_anterior'] ?? 0) ?: null, $costo, max(0, int_($v['stock_minimo'] ?? 0)), $i, bool_($v['activo'] ?? 1)];
            $stock = max(0, int_($v['stock'] ?? 0));
            if ($vid && ($old = one('SELECT stock FROM producto_variantes WHERE id = ? AND producto_id = ? FOR UPDATE', [$vid, $id]))) {
                q('UPDATE producto_variantes SET nombre=?, sku=?, precio=?, precio_anterior=?, costo=?, stock_minimo=?, orden=?, activo=?, stock=? WHERE id=?', [...$row, $stock, $vid]);
                if ($stock !== (int)$old['stock'])
                    q("INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia, usuario_id) VALUES (?,?,?,'ajuste','Edición de producto',?)",
                      [$vid, $stock - (int)$old['stock'], $stock, $U['id']]);
            } else {
                q('INSERT INTO producto_variantes (nombre, sku, precio, precio_anterior, costo, stock_minimo, orden, activo, stock, producto_id) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$row, $stock, $id]);
                $vid = (int)$pdo->lastInsertId();
                if ($stock > 0) q("INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia, usuario_id) VALUES (?,?,?,'ingreso','Stock inicial',?)", [$vid, $stock, $stock, $U['id']]);
            }
            $keep[] = $vid;
        }
        // Presentaciones quitadas: se borran si nunca se vendieron, si no se desactivan
        $in = implode(',', array_fill(0, count($keep), '?'));
        foreach (all("SELECT id FROM producto_variantes WHERE producto_id = ? AND id NOT IN ($in)", [$id, ...$keep]) as $gone) {
            if (val('SELECT 1 FROM pedido_items WHERE variante_id = ? LIMIT 1', [$gone['id']])) q('UPDATE producto_variantes SET activo = 0 WHERE id = ?', [$gone['id']]);
            else q('DELETE FROM producto_variantes WHERE id = ?', [$gone['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok(['id' => $id]);
}
case 'producto_eliminar': {
    method('POST');
    $id = int_(body()['id'] ?? 0);
    if (val('SELECT 1 FROM pedido_items WHERE producto_id = ? LIMIT 1', [$id])) {
        q('UPDATE productos SET activo = 0 WHERE id = ?', [$id]);
        ok(['mensaje' => 'El producto tiene ventas registradas: se ocultó de la tienda en lugar de borrarse.']);
    }
    $img = val('SELECT imagen FROM productos WHERE id = ?', [$id]);
    q('DELETE FROM productos WHERE id = ?', [$id]);
    if ($img && str_starts_with($img, 'uploads/productos/')) @unlink(__DIR__ . '/../' . $img);
    ok(['mensaje' => 'Producto eliminado']);
}
case 'subir_imagen': {
    method('POST');
    global $CFG;
    $f = $_FILES['imagen'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('No se recibió la imagen');
    if ($f['size'] > $CFG['max_upload']) fail('La imagen supera ' . round($CFG['max_upload'] / 1048576) . ' MB');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) fail('Formato no permitido. Usa JPG, PNG o WEBP.');
    $dir = __DIR__ . '/../uploads/productos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6));
    // Reescala a máx. 1200 px y guarda en WEBP si GD lo permite (más liviano)
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $src = imagecreatefromstring(file_get_contents($f['tmp_name']));
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)]; $s = min(1, 1200 / max($w, $h));
            $dst = imagecreatetruecolor((int)($w * $s), (int)($h * $s));
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, (int)($w * $s), (int)($h * $s), $w, $h);
            imagewebp($dst, $dir . $name . '.webp', 85);
            ok(['ruta' => 'uploads/productos/' . $name . '.webp']);
        }
    }
    move_uploaded_file($f['tmp_name'], $dir . $name . '.' . $ext) || fail('No se pudo guardar la imagen', 500);
    ok(['ruta' => 'uploads/productos/' . $name . '.' . $ext]);
}

/* ---------- Inventario ---------- */
case 'inventario': {
    $w = ['1=1']; $p = [];
    if (!empty($_GET['q'])) { $w[] = '(p.nombre LIKE ? OR p.marca LIKE ? OR v.sku LIKE ?)'; array_push($p, ...array_fill(0, 3, '%' . $_GET['q'] . '%')); }
    if (($_GET['filtro'] ?? '') === 'bajo') $w[] = 'v.stock <= v.stock_minimo AND v.stock > 0';
    if (($_GET['filtro'] ?? '') === 'agotado') $w[] = 'v.stock <= 0';
    $rows = all('SELECT v.*, p.nombre producto, p.marca, p.activo p_activo FROM producto_variantes v JOIN productos p ON p.id = v.producto_id
                 WHERE ' . implode(' AND ', $w) . ' ORDER BY p.nombre, v.orden', $p);
    $res = one('SELECT COUNT(*) variantes, COALESCE(SUM(stock),0) unidades, COALESCE(SUM(stock*precio),0) valor, COALESCE(SUM(stock*COALESCE(costo,0)),0) valor_costo,
                SUM(stock <= 0) agotados, SUM(stock > 0 AND stock <= stock_minimo) bajos FROM producto_variantes WHERE activo = 1');
    ok(['items' => $rows, 'resumen' => $res]);
}
case 'inventario_ajustar': {
    method('POST');
    $b = body(); $vid = int_($b['variante_id'] ?? 0); $cambio = int_($b['cambio'] ?? 0);
    $motivo = in_array($b['motivo'] ?? '', ['ingreso', 'ajuste', 'devolucion'], true) ? $b['motivo'] : 'ajuste';
    if ($cambio === 0) fail('Indica una cantidad distinta de 0');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $v = one('SELECT stock FROM producto_variantes WHERE id = ? FOR UPDATE', [$vid]) ?? fail('Presentación no encontrada', 404);
        $nuevo = (int)$v['stock'] + $cambio;
        if ($nuevo < 0) fail('El stock no puede quedar negativo (hay ' . $v['stock'] . ')');
        q('UPDATE producto_variantes SET stock = ? WHERE id = ?', [$nuevo, $vid]);
        q('INSERT INTO movimientos_inventario (variante_id, cambio, stock_final, motivo, referencia, usuario_id) VALUES (?,?,?,?,?,?)',
          [$vid, $cambio, $nuevo, $motivo, str($b['referencia'] ?? '', 120) ?: null, $U['id']]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok(['stock' => $nuevo]);
}
case 'movimientos': {
    $vid = int_($_GET['variante_id'] ?? 0);
    $rows = all('SELECT m.*, p.nombre producto, v.nombre variante, u.nombre usuario FROM movimientos_inventario m
                 JOIN producto_variantes v ON v.id = m.variante_id JOIN productos p ON p.id = v.producto_id
                 LEFT JOIN usuarios u ON u.id = m.usuario_id ' . ($vid ? 'WHERE m.variante_id = ?' : '') . ' ORDER BY m.id DESC LIMIT 200', $vid ? [$vid] : []);
    ok(['movimientos' => $rows]);
}

/* ---------- Categorías ---------- */
case 'categorias': ok(['categorias' => all('SELECT c.*, (SELECT COUNT(*) FROM productos p WHERE p.categoria_id = c.id) productos FROM categorias c ORDER BY orden, nombre')]);
case 'categoria_guardar': {
    method('POST'); require_admin(true);
    $b = body(); $id = int_($b['id'] ?? 0);
    $nombre = str($b['nombre'] ?? '', 80); if ($nombre === '') fail('Nombre obligatorio');
    $slug = slugify(str($b['slug'] ?? '', 60) ?: $nombre);
    if (val('SELECT 1 FROM categorias WHERE slug = ? AND id <> ?', [$slug, $id])) fail('Ya existe una categoría con ese identificador');
    $vals = [$slug, $nombre, str($b['subtitulo'] ?? '', 120) ?: null, int_($b['orden'] ?? 0), bool_($b['activo'] ?? 1)];
    if ($id) q('UPDATE categorias SET slug=?, nombre=?, subtitulo=?, orden=?, activo=? WHERE id=?', [...$vals, $id]);
    else q('INSERT INTO categorias (slug, nombre, subtitulo, orden, activo) VALUES (?,?,?,?,?)', $vals);
    ok();
}
case 'categoria_eliminar': {
    method('POST'); require_admin(true);
    q('DELETE FROM categorias WHERE id = ?', [int_(body()['id'] ?? 0)]);
    ok();
}

/* ---------- Cupones ---------- */
case 'cupones': ok(['cupones' => all('SELECT * FROM cupones ORDER BY activo DESC, id DESC')]);
case 'cupon_guardar': {
    method('POST');
    $b = body(); $id = int_($b['id'] ?? 0);
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['codigo'] ?? '')));
    if (strlen($codigo) < 3) fail('El código debe tener al menos 3 caracteres (letras y números)');
    if (val('SELECT 1 FROM cupones WHERE codigo = ? AND id <> ?', [$codigo, $id])) fail('Ya existe un cupón con ese código');
    $tipo = in_array($b['tipo'] ?? '', ['porcentaje', 'monto', 'envio'], true) ? $b['tipo'] : 'porcentaje';
    $valor = num($b['valor'] ?? 0);
    if ($tipo !== 'envio' && $valor <= 0) fail('Indica el valor del descuento');
    if ($tipo === 'porcentaje' && $valor > 100) fail('El porcentaje no puede pasar de 100');
    $usosMax = ($b['usos_max'] ?? '') === '' ? null : max(1, int_($b['usos_max']));
    $vals = [$codigo, str($b['descripcion'] ?? '', 160) ?: null, $tipo, $valor, num($b['minimo'] ?? 0), $usosMax, dt($b['inicia'] ?? ''), dt($b['vence'] ?? ''), bool_($b['activo'] ?? 1)];
    if ($id) q('UPDATE cupones SET codigo=?, descripcion=?, tipo=?, valor=?, minimo=?, usos_max=?, inicia=?, vence=?, activo=? WHERE id=?', [...$vals, $id]);
    else q('INSERT INTO cupones (codigo, descripcion, tipo, valor, minimo, usos_max, inicia, vence, activo) VALUES (?,?,?,?,?,?,?,?,?)', $vals);
    ok();
}
case 'cupon_eliminar': { method('POST'); q('DELETE FROM cupones WHERE id = ?', [int_(body()['id'] ?? 0)]); ok(); }

/* ---------- Promociones ---------- */
case 'promociones': ok(['promociones' => all('SELECT pr.*, c.nombre categoria, p.nombre producto FROM promociones pr
                         LEFT JOIN categorias c ON c.id = pr.categoria_id LEFT JOIN productos p ON p.id = pr.producto_id ORDER BY pr.activo DESC, pr.id DESC')]);
case 'promocion_guardar': {
    method('POST');
    $b = body(); $id = int_($b['id'] ?? 0);
    $nombre = str($b['nombre'] ?? '', 120); if ($nombre === '') fail('Ponle un nombre a la promoción');
    $tipo = ($b['tipo'] ?? '') === 'monto' ? 'monto' : 'porcentaje';
    $valor = num($b['valor'] ?? 0); if ($valor <= 0) fail('Indica el valor del descuento');
    if ($tipo === 'porcentaje' && $valor > 90) fail('El porcentaje máximo es 90%');
    $alc = in_array($b['alcance'] ?? '', ['todo', 'categoria', 'producto'], true) ? $b['alcance'] : 'todo';
    $cat = $alc === 'categoria' ? (int_($b['categoria_id'] ?? 0) ?: fail('Elige la categoría')) : null;
    $prod = $alc === 'producto' ? (int_($b['producto_id'] ?? 0) ?: fail('Elige el producto')) : null;
    $vals = [$nombre, $tipo, $valor, $alc, $cat, $prod, dt($b['inicia'] ?? ''), dt($b['termina'] ?? ''), bool_($b['activo'] ?? 1)];
    if ($id) q('UPDATE promociones SET nombre=?, tipo=?, valor=?, alcance=?, categoria_id=?, producto_id=?, inicia=?, termina=?, activo=? WHERE id=?', [...$vals, $id]);
    else q('INSERT INTO promociones (nombre, tipo, valor, alcance, categoria_id, producto_id, inicia, termina, activo) VALUES (?,?,?,?,?,?,?,?,?)', $vals);
    ok();
}
case 'promocion_eliminar': { method('POST'); q('DELETE FROM promociones WHERE id = ?', [int_(body()['id'] ?? 0)]); ok(); }

/* ---------- Ajustes ---------- */
case 'ajustes': ok(['ajustes' => ajustes()]);
case 'ajustes_guardar': {
    method('POST'); require_admin(true);
    $b = body();
    $wa = preg_replace('/\D/', '', (string)($b['whatsapp'] ?? ''));
    if ($wa !== '' && strlen($wa) < 8) fail('Número de WhatsApp no válido');
    if (strlen($wa) === 8) $wa = '502' . $wa;
    $limpiar = fn($arr) => array_values(array_filter(array_map(fn($x) => str($x, 160), is_array($arr) ? $arr : []), 'strlen'));
    $metodos = $limpiar($b['metodos_pago'] ?? []);
    if (!$metodos) fail('Debe haber al menos un método de pago');
    $set = [
        'tienda_nombre' => str($b['tienda_nombre'] ?? 'Luxara', 80) ?: 'Luxara', 'whatsapp' => $wa,
        'envio_gratis_desde' => (string)max(0, num($b['envio_gratis_desde'] ?? 0)), 'costo_envio' => (string)max(0, num($b['costo_envio'] ?? 0)),
        'anuncios' => json_encode($limpiar($b['anuncios'] ?? []), JSON_UNESCAPED_UNICODE),
        'metodos_pago' => json_encode($metodos, JSON_UNESCAPED_UNICODE),
        'datos_bancarios' => str($b['datos_bancarios'] ?? '', 1000),
    ];
    $st = db()->prepare('INSERT INTO ajustes (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    foreach ($set as $k => $v) $st->execute([$k, $v]);
    ok();
}

/* ---------- Usuarios ---------- */
case 'usuarios': require_admin(true); ok(['usuarios' => all('SELECT id, nombre, email, rol, activo, ultimo_acceso, creado FROM usuarios ORDER BY id')]);
case 'usuario_guardar': {
    method('POST'); require_admin(true);
    $b = body(); $id = int_($b['id'] ?? 0);
    $nombre = str($b['nombre'] ?? '', 120); $email = strtolower(str($b['email'] ?? '', 160)); $pass = (string)($b['password'] ?? '');
    $rol = ($b['rol'] ?? '') === 'vendedor' ? 'vendedor' : 'admin'; $activo = bool_($b['activo'] ?? 1);
    if (mb_strlen($nombre) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nombre o correo no válido');
    if (val('SELECT 1 FROM usuarios WHERE email = ? AND id <> ?', [$email, $id])) fail('Ese correo ya está registrado');
    if ($id === (int)$U['id'] && (!$activo || $rol !== 'admin')) fail('No puedes desactivarte ni quitarte el rol de administrador');
    if ($id) {
        q('UPDATE usuarios SET nombre=?, email=?, rol=?, activo=? WHERE id=?', [$nombre, $email, $rol, $activo, $id]);
        if ($pass !== '') { if (strlen($pass) < 8) fail('La contraseña debe tener al menos 8 caracteres'); q('UPDATE usuarios SET password_hash=? WHERE id=?', [password_hash($pass, PASSWORD_DEFAULT), $id]); }
    } else {
        if (strlen($pass) < 8) fail('La contraseña debe tener al menos 8 caracteres');
        q('INSERT INTO usuarios (nombre, email, password_hash, rol, activo) VALUES (?,?,?,?,?)', [$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $rol, $activo]);
    }
    ok();
}
case 'mi_password': {
    method('POST');
    $b = body();
    $hash = val('SELECT password_hash FROM usuarios WHERE id = ?', [$U['id']]);
    if (!password_verify((string)($b['actual'] ?? ''), $hash)) fail('La contraseña actual no es correcta');
    if (strlen((string)($b['nueva'] ?? '')) < 8) fail('La nueva contraseña debe tener al menos 8 caracteres');
    q('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash((string)$b['nueva'], PASSWORD_DEFAULT), $U['id']]);
    ok();
}

default: fail('Ruta no encontrada', 404);
}

/* ---------- utilidades ---------- */
function filtros_pedidos(): array {
    $w = []; $p = [];
    if (!empty($_GET['estado']) && in_array($_GET['estado'], ESTADOS, true)) { $w[] = 'estado = ?'; $p[] = $_GET['estado']; }
    if (in_array($_GET['canal'] ?? '', ['web', 'manual'], true)) { $w[] = 'canal = ?'; $p[] = $_GET['canal']; }
    if (!empty($_GET['q'])) { $w[] = '(numero LIKE ? OR nombre LIKE ? OR telefono LIKE ?)'; array_push($p, ...array_fill(0, 3, '%' . trim($_GET['q']) . '%')); }
    if ($d = dt($_GET['desde'] ?? '')) { $w[] = 'creado >= ?'; $p[] = substr($d, 0, 10) . ' 00:00:00'; }
    if ($d = dt($_GET['hasta'] ?? '')) { $w[] = 'creado <= ?'; $p[] = substr($d, 0, 10) . ' 23:59:59'; }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}
