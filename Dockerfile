# ===== LUXARA — imagen para Render (PHP 8.3 + Apache) =====
FROM php:8.3-apache

# Extensiones: PDO MySQL (base de datos) y GD con WEBP/JPEG (optimizar fotos)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
 && rm -rf /var/lib/apt/lists/*

# Apache: módulos que usa .htaccess y permitir .htaccess
RUN a2enmod rewrite headers expires \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
 && echo 'ServerTokens Prod\nServerSignature Off' >> /etc/apache2/conf-available/security.conf

# Render indica el puerto en la variable PORT (por defecto 10000)
ENV PORT=10000
RUN sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

# PHP en modo producción
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { echo 'upload_max_filesize=4M'; echo 'post_max_size=6M'; echo 'expose_php=Off'; echo 'date.timezone=America/Guatemala'; } > "$PHP_INI_DIR/conf.d/luxara.ini"

COPY . /var/www/html/
RUN rm -rf /var/www/html/database /var/www/html/instalar.php /var/www/html/.env \
 && chown -R www-data:www-data /var/www/html

EXPOSE 10000
