<?php
/**
 * LUXARA — Sirve las fotos guardadas en la base de datos (IMAGES_STORAGE=db).
 *   api/img.php?id=12
 */
declare(strict_types=1);
$CFG = require __DIR__ . '/../config/config.php';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit; }

$etag = '"lx-img-' . $id . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

try {
    $d = $CFG['db'];
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $st = $pdo->prepare('SELECT mime, datos FROM imagenes WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { http_response_code(500); exit; }

if (!$row) { http_response_code(404); exit; }
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . strlen($row['datos']));
header('Cache-Control: public, max-age=31536000, immutable');   // las imágenes nunca cambian: una nueva = otro id
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
echo $row['datos'];
