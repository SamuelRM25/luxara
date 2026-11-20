<?php
/**
 * LUXARA — Instalador de la base de datos
 * Ábrelo en tu navegador desde tu servidor local:  http://localhost:PUERTO/luxara/instalar.php
 * Solo funciona desde tu propia computadora (localhost). NO lo subas a Hostinger.
 */
declare(strict_types=1);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('El instalador solo funciona desde tu computadora (localhost).'); }

$CFG = require __DIR__ . '/config/config.php';
$DIR = is_dir(__DIR__ . '/database') ? __DIR__ . '/database' : __DIR__ . '/../database';
$log = []; $error = null; $pdo = null;

try {
    $d = $CFG['db'];
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);
} catch (Throwable $e) { $error = 'No se pudo conectar a la base de datos: ' . $e->getMessage(); }

function tabla(PDO $pdo, string $t): bool { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn(); }
function columna(PDO $pdo, string $t, string $c): bool { return (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetchColumn(); }
function ejecutar(PDO $pdo, string $file, array &$log): void {
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // quita comentarios
    $parts = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)), 'strlen');
    $n = 0;
    foreach ($parts as $stmt) { $pdo->exec($stmt); $n++; }
    $log[] = '✔ ' . basename($file) . " — $n instrucciones ejecutadas";
}

if ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $acc = $_POST['accion'] ?? '';
        if ($acc === 'schema') {
            if (tabla($pdo, 'productos')) {
                if (!columna($pdo, 'pedidos', 'canal')) { ejecutar($pdo, "$DIR/migracion_v2.sql", $log); }
                else $log[] = 'Las tablas ya existen y están actualizadas. No se cambió nada.';
            } else ejecutar($pdo, "$DIR/schema.sql", $log);
        } elseif ($acc === 'productos') {
            ejecutar($pdo, "$DIR/productos_luxara.sql", $log);
        } elseif ($acc === 'historial') {
            ejecutar($pdo, "$DIR/historial_ventas.sql", $log);
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$st = ['tablas' => false, 'v2' => false, 'productos' => 0, 'stock' => 0, 'historial' => 0, 'usuarios' => 0, 'pedidos' => 0];
if ($pdo) {
    try {
        $st['tablas'] = tabla($pdo, 'productos');
        if ($st['tablas']) {
            $st['v2'] = columna($pdo, 'pedidos', 'canal');
            $st['productos'] = (int)$pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn();
            $st['stock'] = (int)$pdo->query('SELECT COALESCE(SUM(stock),0) FROM producto_variantes')->fetchColumn();
            $st['pedidos'] = (int)$pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
            $st['usuarios'] = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
            if ($st['v2']) $st['historial'] = (int)$pdo->query("SELECT COUNT(*) FROM pedidos WHERE numero LIKE 'LX-H-%'")->fetchColumn();
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$okTablas = $st['tablas'] && $st['v2'];
?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Instalador · Luxara</title>
<style>
body{margin:0;min-height:100vh;background:#0c0908;color:#f3ece2;font:15px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;display:flex;justify-content:center;padding:40px 16px}
.box{width:min(640px,100%)}
h1{font-size:30px;margin:0 0 4px}p.s{color:#a3988a;margin:0 0 26px}
.step{display:flex;gap:16px;align-items:center;justify-content:space-between;padding:18px 20px;border:1px solid #2c241c;border-radius:16px;background:#15110e;margin-bottom:12px}
.step b{display:block;font-size:16px}.step small{color:#a3988a}
.ok{color:#8fd19b}.no{color:#e0b45a}
button{font:inherit;font-weight:600;border:0;border-radius:11px;padding:11px 18px;cursor:pointer;background:linear-gradient(120deg,#e9c27c,#f6dfae 50%,#d29e52);color:#1d1307;white-space:nowrap}
button.sec{background:#2a221b;color:#f3ece2}
button[disabled]{opacity:.35;cursor:not-allowed}
.log{background:#0f2416;border:1px solid #275c38;color:#bfe9c8;padding:14px 18px;border-radius:12px;margin-bottom:18px;white-space:pre-line}
.err{background:#2a1210;border:1px solid #6e2c24;color:#f3b5aa;padding:14px 18px;border-radius:12px;margin-bottom:18px;word-break:break-word}
.fin{margin-top:22px;padding:18px 20px;border-radius:16px;border:1px solid #3a3127;color:#d9cfc1}
a{color:#f0d49a}
</style></head><body><div class="box">
<h1>Instalador de Luxara</h1>
<p class="s">Base de datos: <b><?= $h($CFG['db']['name']) ?></b> en <?= $h($CFG['db']['host']) ?></p>

<?php if ($log): ?><div class="log"><?= $h(implode("\n", $log)) ?></div><?php endif; ?>
<?php if ($error): ?><div class="err"><b>Error:</b> <?= $h($error) ?></div><?php endif; ?>

<?php if ($pdo): ?>
<form method="post" class="step"><div><b>1. Crear las tablas</b>
  <small class="<?= $okTablas ? 'ok' : 'no' ?>"><?= $okTablas ? '✔ Tablas creadas' : ($st['tablas'] ? 'Tablas antiguas: se actualizarán a la versión 2' : 'Pendiente') ?></small></div>
  <button name="accion" value="schema" <?= $okTablas ? 'class="sec"' : '' ?>><?= $okTablas ? 'Verificar' : 'Crear tablas' ?></button></form>

<form method="post" class="step"><div><b>2. Cargar tus 26 perfumes</b>
  <small class="<?= $st['productos'] ? 'ok' : 'no' ?>"><?= $st['productos'] ? "✔ {$st['productos']} perfumes · {$st['stock']} unidades en stock" : 'Pendiente (desde productos_luxara.sql)' ?></small></div>
  <button name="accion" value="productos" <?= !$okTablas ? 'disabled' : ($st['productos'] ? 'class="sec"' : '') ?>><?= $st['productos'] ? 'Volver a cargar' : 'Cargar perfumes' ?></button></form>

<form method="post" class="step"><div><b>3. Historial de ventas (opcional)</b>
  <small class="<?= $st['historial'] ? 'ok' : 'no' ?>"><?= $st['historial'] ? "✔ {$st['historial']} ventas de mayo–junio importadas" : 'Ventas de la hoja VENTAS del Excel' ?></small></div>
  <button name="accion" value="historial" <?= !$okTablas ? 'disabled' : ($st['historial'] ? 'class="sec"' : '') ?>><?= $st['historial'] ? 'Ya importado' : 'Importar historial' ?></button></form>

<div class="fin">
  <?php if ($okTablas && $st['productos']): ?>
    <b class="ok">¡Listo!</b> Ahora abre <a href="admin/">el panel de administración</a><?= $st['usuarios'] ? '' : ' y crea tu cuenta de administrador' ?>, o <a href="./">la tienda</a>.<br>
    <small>Cuando subas el sitio a Hostinger no subas este archivo (instalar.php).</small>
  <?php else: ?>
    Sigue los pasos en orden. Puedes volver a pulsar un paso sin duplicar datos.
  <?php endif; ?>
</div>
<?php endif; ?>
</div></body></html>
