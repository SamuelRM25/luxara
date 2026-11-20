<?php
/**
 * LUXARA — Configuración.
 * No contiene credenciales: todo se lee de variables de entorno.
 *  - En tu Mac: crea un archivo .env en la raíz del proyecto (ver .env.example).
 *  - En Render: agrega las variables en Environment del servicio.
 * Acepta los nombres que da Clever Cloud (MYSQL_ADDON_*) o los genéricos (DB_*).
 */

// Carga .env si existe (solo desarrollo local; en Render no hay archivo .env)
$__envFile = dirname(__DIR__) . '/.env';
if (is_readable($__envFile)) {
    foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
        $__line = trim($__line);
        if ($__line === '' || $__line[0] === '#' || !str_contains($__line, '=')) continue;
        [$__k, $__v] = array_map('trim', explode('=', $__line, 2));
        $__v = trim($__v, "\"'");
        if (getenv($__k) === false) { putenv("$__k=$__v"); $_ENV[$__k] = $__v; }
    }
}

$env = static function (string $k, $default = null) {
    $v = getenv($k);
    if ($v === false || $v === '') $v = $_ENV[$k] ?? $_SERVER[$k] ?? null;
    return ($v === null || $v === '') ? $default : $v;
};

return [
    'db' => [
        'host'    => $env('DB_HOST', $env('MYSQL_ADDON_HOST', '127.0.0.1')),
        'port'    => (int)$env('DB_PORT', $env('MYSQL_ADDON_PORT', 3306)),
        'name'    => $env('DB_NAME', $env('MYSQL_ADDON_DB', 'luxara')),
        'user'    => $env('DB_USER', $env('MYSQL_ADDON_USER', 'root')),
        'pass'    => $env('DB_PASSWORD', $env('MYSQL_ADDON_PASSWORD', '')),
        'charset' => 'utf8mb4',
    ],
    'timezone'     => $env('APP_TIMEZONE', 'America/Guatemala'),
    'debug'        => $env('APP_DEBUG', '0') === '1',
    // 1 cuando el sitio está detrás de un proxy (Render, Cloudflare): usa X-Forwarded-For para la IP real
    'trust_proxy'  => $env('TRUST_PROXY', '0') === '1',
    // disk = guarda las fotos en uploads/ · db = las guarda en MySQL (usar en Render: su disco se borra)
    'images'       => $env('IMAGES_STORAGE', 'disk') === 'db' ? 'db' : 'disk',
    'cors_origins' => array_filter(array_map('trim', explode(',', (string)$env('CORS_ORIGINS', '')))),
    // Horas sin actividad antes de cerrar la sesión del panel
    'session_hours' => (int)$env('SESSION_HOURS', 8),
    'max_upload'   => 3 * 1024 * 1024,
];
