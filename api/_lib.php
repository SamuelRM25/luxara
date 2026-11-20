<?php
/**
 * LUXARA — Núcleo compartido de la API
 * Conexión PDO, respuestas JSON, validación, sesiones y reglas de precios.
 */
declare(strict_types=1);

$CFG = require __DIR__ . '/../config/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'America/Guatemala');

ini_set('display_errors', $CFG['debug'] ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// CORS opcional (solo si la tienda vive en otro dominio)
if (!empty($CFG['cors_origins']) && isset($_SERVER['HTTP_ORIGIN'])
    && in_array($_SERVER['HTTP_ORIGIN'], $CFG['cors_origins'], true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

set_exception_handler(function (Throwable $e) use ($CFG) {
    error_log('[luxara] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $msg = $CFG['debug'] ? $e->getMessage() : 'Error interno del servidor';
    if ($e instanceof ApiError) { http_response_code($e->status); $msg = $e->getMessage(); }
    else http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
});

final class ApiError extends Exception {
    public int $status;
    public function __construct(string $msg, int $status = 400) { parent::__construct($msg); $this->status = $status; }
}

/* ---------------- Base de datos ---------------- */
function db(): PDO {
    static $pdo = null;
    global $CFG;
    if ($pdo) return $pdo;
    $d = $CFG['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }

/* ---------------- Entrada / salida ---------------- */
function ok($data = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => true] + (is_array($data) ? $data : ['data' => $data]), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function fail(string $msg, int $status = 400): never { throw new ApiError($msg, $status); }

function body(): array {
    static $b = null;
    if ($b !== null) return $b;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 200000) fail('Solicitud demasiado grande', 413);
        $b = json_decode($raw ?: '[]', true);
        if (!is_array($b)) fail('JSON inválido');
    } else $b = $_POST;
    return $b;
}
function method(string $m): void { if ($_SERVER['REQUEST_METHOD'] !== $m) fail('Método no permitido', 405); }

function str($v, int $max = 255): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }
function num($v): float { return round((float)str_replace(',', '', (string)($v ?? 0)), 2); }
function int_($v): int { return (int)($v ?? 0); }
function bool_($v): int { return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on') ? 1 : 0; }
function dt($v): ?string {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}
function slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim($s, '-') ?: 'producto';
}
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

/* ---------------- Ajustes ---------------- */
function ajustes(): array {
    static $a = null;
    if ($a !== null) return $a;
    $a = [];
    foreach (all('SELECT clave, valor FROM ajustes') as $r) $a[$r['clave']] = $r['valor'];
    return $a;
}
function ajuste(string $k, $def = null) { return ajustes()[$k] ?? $def; }

/* ---------------- Precios y promociones ---------------- */
function promociones_activas(): array {
    static $p = null;
    if ($p !== null) return $p;
    return $p = all("SELECT * FROM promociones
                     WHERE activo = 1 AND (inicia IS NULL OR inicia <= NOW()) AND (termina IS NULL OR termina >= NOW())");
}
/**
 * Devuelve el precio final de una variante aplicando la mejor promoción
 * vigente (producto > categoría > toda la tienda; gana la de mayor ahorro).
 * @return array [precio_final, precio_tachado|null, nombre_promo|null]
 */
function precio_final(float $precio, ?float $anterior, int $producto_id, ?int $categoria_id): array {
    $mejor = $precio; $promo = null;
    foreach (promociones_activas() as $pr) {
        $aplica = $pr['alcance'] === 'todo'
            || ($pr['alcance'] === 'categoria' && (int)$pr['categoria_id'] === (int)$categoria_id)
            || ($pr['alcance'] === 'producto'  && (int)$pr['producto_id']  === $producto_id);
        if (!$aplica) continue;
        $nuevo = $pr['tipo'] === 'porcentaje'
            ? $precio * (1 - min(100, (float)$pr['valor']) / 100)
            : $precio - (float)$pr['valor'];
        $nuevo = max(0, round($nuevo, 2));
        if ($nuevo < $mejor) { $mejor = $nuevo; $promo = $pr['nombre']; }
    }
    if ($promo) return [$mejor, $precio, $promo];
    return [$precio, ($anterior && $anterior > $precio) ? $anterior : null, null];
}

/**
 * Valida un cupón contra un subtotal. Lanza ApiError si no aplica.
 * @return array [cupon, descuento, envio_gratis]
 */
function evaluar_cupon(string $codigo, float $subtotal, bool $bloquear = false): array {
    $codigo = strtoupper(trim($codigo));
    $c = one('SELECT * FROM cupones WHERE codigo = ?' . ($bloquear ? ' FOR UPDATE' : ''), [$codigo]);
    if (!$c || !$c['activo']) fail('Cupón no válido');
    $now = time();
    if ($c['inicia'] && strtotime($c['inicia']) > $now) fail('Este cupón aún no está vigente');
    if ($c['vence'] && strtotime($c['vence']) < $now) fail('Este cupón ya venció');
    if ($c['usos_max'] !== null && (int)$c['usos'] >= (int)$c['usos_max']) fail('Este cupón ya alcanzó su límite de usos');
    if ($subtotal < (float)$c['minimo']) fail('Compra mínima para este cupón: Q' . number_format((float)$c['minimo'], 2));
    $desc = 0.0; $envio_gratis = false;
    if ($c['tipo'] === 'porcentaje') $desc = round($subtotal * min(100, (float)$c['valor']) / 100, 2);
    elseif ($c['tipo'] === 'monto')  $desc = min($subtotal, (float)$c['valor']);
    else $envio_gratis = true;
    return [$c, $desc, $envio_gratis];
}

function costo_envio(float $subtotal, bool $gratis): float {
    if ($gratis) return 0.0;
    $desde = (float)ajuste('envio_gratis_desde', 500);
    return ($desde > 0 && $subtotal >= $desde) ? 0.0 : (float)ajuste('costo_envio', 35);
}

/* ---------------- Sesión admin ---------------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('LUXADMIN');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    // expira tras 8 horas de inactividad
    if (isset($_SESSION['t']) && time() - $_SESSION['t'] > 8 * 3600) { $_SESSION = []; session_destroy(); session_start(); }
    $_SESSION['t'] = time();
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function require_admin(bool $soloAdmin = false): array {
    start_session();
    if (empty($_SESSION['uid'])) fail('Sesión expirada. Vuelve a iniciar sesión.', 401);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $t)) fail('Token de seguridad inválido. Recarga la página.', 419);
    }
    $u = one('SELECT id, nombre, email, rol FROM usuarios WHERE id = ? AND activo = 1', [$_SESSION['uid']]);
    if (!$u) { $_SESSION = []; fail('Usuario no autorizado', 401); }
    if ($soloAdmin && $u['rol'] !== 'admin') fail('Solo un administrador puede hacer esto', 403);
    return $u;
}
