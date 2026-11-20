<?php
/**
 * LUXARA — Configuración del servidor.
 * Este archivo contiene credenciales: NO lo subas a GitHub ni lo compartas.
 * La carpeta /config está bloqueada al público con .htaccess.
 */
return [
    'db' => [
        'host'    => 'TU_HOST',
        'port'    => 3306,
        'name'    => 'TU_BASE_DE_DATOS',
        'user'    => 'TU_USUARIO',
        'pass'    => 'TU_CONTRASEÑA',
        'charset' => 'utf8mb4',
    ],
    // Zona horaria de la tienda
    'timezone' => 'America/Guatemala',
    // Dominios desde los que la tienda puede llamar a la API (vacío = mismo dominio)
    'cors_origins' => [],
    // Muestra errores detallados. Pon false en producción.
    'debug' => false,
    // Tamaño máximo de imagen de producto (bytes)
    'max_upload' => 3 * 1024 * 1024,
];
