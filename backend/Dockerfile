FROM php:8.3-apache

# Instalar extensión pdo_mysql para MariaDB y extensión redis mediante PECL
RUN docker-php-ext-install pdo pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis

# Habilitar mod_rewrite de Apache para rutas limpias de la API REST (/equipos, /health)
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copiar el código fuente del backend al directorio público del servidor Apache
COPY src/ /var/www/html/

# Ajustar permisos al usuario de Apache
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
