-- =========================================================
--  LUXARA — Migración v1 → v2
--  Solo si YA importaste el schema.sql anterior. Si vas a
--  empezar de cero, importa schema.sql y no necesitas esto.
-- =========================================================
SET NAMES utf8mb4;
ALTER TABLE producto_variantes ADD COLUMN costo DECIMAL(10,2) NULL AFTER precio_anterior;
ALTER TABLE producto_variantes ALTER stock_minimo SET DEFAULT 0;
ALTER TABLE pedido_items ADD COLUMN costo DECIMAL(10,2) NULL AFTER precio;
ALTER TABLE pedidos
  ADD COLUMN canal ENUM('web','manual') NOT NULL DEFAULT 'web' AFTER numero,
  MODIFY telefono VARCHAR(20) NOT NULL DEFAULT '',
  MODIFY departamento VARCHAR(40) NOT NULL DEFAULT '',
  MODIFY municipio VARCHAR(80) NOT NULL DEFAULT '',
  MODIFY direccion VARCHAR(255) NOT NULL DEFAULT '',
  ADD INDEX (canal);
UPDATE categorias SET nombre = 'Dama', orden = 1 WHERE slug = 'mujer';
UPDATE categorias SET nombre = 'Caballero', orden = 2 WHERE slug = 'hombre';
UPDATE categorias SET activo = 0 WHERE slug = 'sets';
UPDATE categorias SET nombre = 'Unisex', subtitulo = 'Para todos' WHERE slug = 'unisex';
