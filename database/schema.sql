-- =========================================================
--  LUXARA — Esquema de base de datos (MySQL 8 / MariaDB 10.5+)
--  Ejecutar una sola vez sobre la base de datos vacía.
-- =========================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS usuarios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120) NOT NULL,
  usuario         VARCHAR(50) NOT NULL UNIQUE,
  email           VARCHAR(160) NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  rol             ENUM('admin','vendedor') NOT NULL DEFAULT 'admin',
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  ultimo_acceso   DATETIME NULL,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sesiones (
  id           VARCHAR(128) PRIMARY KEY,
  data         MEDIUMTEXT NOT NULL,
  actualizado  INT UNSIGNED NOT NULL,
  INDEX (actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intentos_login (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip      VARCHAR(45) NOT NULL,
  email   VARCHAR(160) NULL,
  creado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug       VARCHAR(60) NOT NULL UNIQUE,
  nombre     VARCHAR(80) NOT NULL,
  subtitulo  VARCHAR(120) NULL,
  orden      INT NOT NULL DEFAULT 0,
  activo     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS productos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(140) NOT NULL UNIQUE,
  nombre         VARCHAR(140) NOT NULL,
  marca          VARCHAR(100) NULL,
  categoria_id   INT UNSIGNED NULL,
  descripcion    TEXT NULL,
  notas_salida   VARCHAR(200) NULL,
  notas_corazon  VARCHAR(200) NULL,
  notas_fondo    VARCHAR(200) NULL,
  imagen         VARCHAR(255) NULL,
  forma          VARCHAR(20) NOT NULL DEFAULT 'square',   -- ilustración si no hay imagen
  color_liquido  VARCHAR(9)  NOT NULL DEFAULT '#7a4b12',
  color_tapa     VARCHAR(9)  NOT NULL DEFAULT '#d4ac62',
  destacado      TINYINT(1) NOT NULL DEFAULT 0,
  nuevo          TINYINT(1) NOT NULL DEFAULT 0,
  activo         TINYINT(1) NOT NULL DEFAULT 1,
  ventas         INT UNSIGNED NOT NULL DEFAULT 0,
  orden          INT NOT NULL DEFAULT 0,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (activo), INDEX (categoria_id),
  CONSTRAINT fk_prod_cat FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada tamaño/presentación es una variante con su precio y su stock
CREATE TABLE IF NOT EXISTS producto_variantes (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producto_id      INT UNSIGNED NOT NULL,
  nombre           VARCHAR(60) NOT NULL,              -- "100 ml"
  sku              VARCHAR(60) NULL,
  precio           DECIMAL(10,2) NOT NULL,
  precio_anterior  DECIMAL(10,2) NULL,                -- precio tachado manual
  costo            DECIMAL(10,2) NULL,                -- inversión por unidad (solo admin)
  stock            INT NOT NULL DEFAULT 0,
  stock_minimo     INT NOT NULL DEFAULT 0,            -- alerta de pocas unidades
  orden            INT NOT NULL DEFAULT 0,
  activo           TINYINT(1) NOT NULL DEFAULT 1,
  INDEX (producto_id),
  CONSTRAINT fk_var_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movimientos_inventario (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variante_id  INT UNSIGNED NOT NULL,
  cambio       INT NOT NULL,                          -- +entrada / -salida
  stock_final  INT NOT NULL,
  motivo       ENUM('venta','cancelacion','ingreso','ajuste','devolucion') NOT NULL,
  referencia   VARCHAR(120) NULL,
  usuario_id   INT UNSIGNED NULL,
  creado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (variante_id), INDEX (creado),
  CONSTRAINT fk_mov_var FOREIGN KEY (variante_id) REFERENCES producto_variantes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Promociones automáticas (sin código): bajan el precio mostrado
CREATE TABLE IF NOT EXISTS promociones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  tipo          ENUM('porcentaje','monto') NOT NULL DEFAULT 'porcentaje',
  valor         DECIMAL(10,2) NOT NULL,
  alcance       ENUM('todo','categoria','producto') NOT NULL DEFAULT 'todo',
  categoria_id  INT UNSIGNED NULL,
  producto_id   INT UNSIGNED NULL,
  inicia        DATETIME NULL,
  termina       DATETIME NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_promo_cat  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_prod FOREIGN KEY (producto_id)  REFERENCES productos(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de descuento que el cliente escribe en el checkout
CREATE TABLE IF NOT EXISTS cupones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo      VARCHAR(40) NOT NULL UNIQUE,
  descripcion VARCHAR(160) NULL,
  tipo        ENUM('porcentaje','monto','envio') NOT NULL DEFAULT 'porcentaje',
  valor       DECIMAL(10,2) NOT NULL DEFAULT 0,
  minimo      DECIMAL(10,2) NOT NULL DEFAULT 0,      -- compra mínima
  usos_max    INT UNSIGNED NULL,                      -- NULL = ilimitado
  usos        INT UNSIGNED NOT NULL DEFAULT 0,
  inicia      DATETIME NULL,
  vence       DATETIME NULL,
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(20) NOT NULL UNIQUE,
  canal          ENUM('web','manual') NOT NULL DEFAULT 'web',   -- tienda en línea o venta directa
  nombre         VARCHAR(120) NOT NULL,
  telefono       VARCHAR(20) NOT NULL DEFAULT '',
  email          VARCHAR(160) NULL,
  departamento   VARCHAR(40) NOT NULL DEFAULT '',
  municipio      VARCHAR(80) NOT NULL DEFAULT '',
  direccion      VARCHAR(255) NOT NULL DEFAULT '',
  referencias    VARCHAR(255) NULL,
  nit            VARCHAR(20) NOT NULL DEFAULT 'CF',
  metodo_pago    VARCHAR(60) NOT NULL,
  nota           VARCHAR(500) NULL,
  subtotal       DECIMAL(10,2) NOT NULL,
  descuento      DECIMAL(10,2) NOT NULL DEFAULT 0,
  envio          DECIMAL(10,2) NOT NULL DEFAULT 0,
  total          DECIMAL(10,2) NOT NULL,
  cupon_codigo   VARCHAR(40) NULL,
  estado         ENUM('pendiente','confirmado','enviado','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  ip             VARCHAR(45) NULL,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (estado), INDEX (creado), INDEX (telefono), INDEX (canal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedido_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id    INT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NULL,
  variante_id  INT UNSIGNED NULL,
  nombre       VARCHAR(160) NOT NULL,
  variante     VARCHAR(60) NOT NULL,
  precio       DECIMAL(10,2) NOT NULL,
  costo        DECIMAL(10,2) NULL,
  cantidad     INT UNSIGNED NOT NULL,
  total        DECIMAL(10,2) NOT NULL,
  INDEX (pedido_id),
  CONSTRAINT fk_item_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  CONSTRAINT fk_item_prod FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE SET NULL,
  CONSTRAINT fk_item_var FOREIGN KEY (variante_id) REFERENCES producto_variantes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedido_historial (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pedido_id   INT UNSIGNED NOT NULL,
  estado      VARCHAR(20) NOT NULL,
  nota        VARCHAR(300) NULL,
  usuario_id  INT UNSIGNED NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (pedido_id),
  CONSTRAINT fk_hist_ped FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fotos subidas desde el panel cuando IMAGES_STORAGE=db (Render)
CREATE TABLE IF NOT EXISTS imagenes (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mime    VARCHAR(40) NOT NULL,
  datos   MEDIUMBLOB NOT NULL,
  bytes   INT UNSIGNED NOT NULL,
  creado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ajustes (
  clave  VARCHAR(60) PRIMARY KEY,
  valor  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- Ajustes por defecto ----------
INSERT IGNORE INTO ajustes (clave, valor) VALUES
 ('tienda_nombre', 'Luxara'),
 ('whatsapp', '50200000000'),
 ('envio_gratis_desde', '500'),
 ('costo_envio', '35'),
 ('anuncios', '["Envío gratis en compras desde Q500 a toda Guatemala","Compra hoy y paga al recibir tu pedido","Fragancias 100% originales, selladas de fábrica"]'),
 ('metodos_pago', '["Efectivo contra entrega","Tarjeta contra entrega (POS)","Transferencia / depósito"]'),
 ('datos_bancarios', '');

-- ---------- Categorías ----------
INSERT IGNORE INTO categorias (slug, nombre, subtitulo, orden) VALUES
 ('mujer',  'Dama',  'Para ella', 1),
 ('hombre', 'Caballero', 'Para él', 2),
 ('unisex', 'Unisex', 'Para todos', 3),
 ('sets',   'Sets de regalo', 'Para regalar', 4);
UPDATE categorias SET activo = 0 WHERE slug = 'sets';
