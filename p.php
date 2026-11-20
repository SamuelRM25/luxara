<?php
/**
 * LUXARA — Enlace directo a un perfume (para anuncios y redes sociales).
 *   https://tudominio.com/p.php?s=lattafa-yara
 * WhatsApp, Facebook e Instagram leen las etiquetas Open Graph de esta página
 * (foto, nombre y precio) y el visitante es llevado a la tienda con el perfume abierto.
 * Los parámetros extra (utm_source, utm_campaign…) se conservan.
 */
declare(strict_types=1);
$CFG = require __DIR__ . '/config/config.php';
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['s'] ?? '')));

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';

$extra = $_GET; unset($extra['s']);
$destino = $base . '?' . http_build_query(['p' => $slug] + $extra);

$p = null; $precio = null; $tienda = 'Luxara';
if ($slug !== '') {
    try {
        $d = $CFG['db'];
        $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 5]);
        $st = $pdo->prepare('SELECT id, slug, nombre, marca, descripcion, imagen FROM productos WHERE slug = ? AND activo = 1');
        $st->execute([$slug]);
        $p = $st->fetch() ?: null;
        if ($p) {
            $st = $pdo->prepare('SELECT MIN(precio) FROM producto_variantes WHERE producto_id = ? AND activo = 1');
            $st->execute([$p['id']]);
            $precio = $st->fetchColumn();
        }
        $tienda = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'tienda_nombre'")->fetchColumn() ?: 'Luxara';
    } catch (Throwable $e) { /* si la base no responde, igual redirige */ }
}
if (!$p) { header('Location: ' . $base, true, 302); exit; }

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$titulo = trim(($p['marca'] ? $p['marca'] . ' ' : '') . $p['nombre']);
$desc = ($precio ? 'Q' . number_format((float)$precio, 2) . ' · ' : '') . ($p['descripcion'] ?: 'Perfume original. Envío a toda Guatemala y pago contra entrega.');
$img = $p['imagen'] ? (preg_match('#^https?://#', $p['imagen']) ? $p['imagen'] : $base . $p['imagen']) : $base . 'assets/mono.png';
header('Cache-Control: public, max-age=300');
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<title><?= $h($titulo) ?> · <?= $h($tienda) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="<?= $h($desc) ?>">
<meta property="og:type" content="product">
<meta property="og:site_name" content="<?= $h($tienda) ?>">
<meta property="og:title" content="<?= $h($titulo) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:image" content="<?= $h($img) ?>">
<meta property="og:url" content="<?= $h($base . 'p.php?s=' . $slug) ?>">
<?php if ($precio): ?><meta property="product:price:amount" content="<?= $h(number_format((float)$precio, 2, '.', '')) ?>">
<meta property="product:price:currency" content="GTQ"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta http-equiv="refresh" content="0;url=<?= $h($destino) ?>">
<link rel="canonical" href="<?= $h($destino) ?>">
<style>body{background:#0a0706;color:#f5efe6;font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0}a{color:#f0d49a}</style>
</head><body>
<p>Abriendo <a href="<?= $h($destino) ?>"><?= $h($titulo) ?></a>…</p>
<script>location.replace(<?= json_encode($destino) ?>);</script>
</body></html>
