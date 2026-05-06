# =========================
# 1. Frontend build stage
# =========================
FROM node:20 AS frontend

WORKDIR /app

COPY package*.json ./

ENV NPM_CONFIG_IGNORE_SCRIPTS=true

RUN npm install --legacy-peer-deps

COPY js ./js
COPY webpack* ./
COPY .vue.webpack.config.js ./
COPY public ./public
COPY tools ./tools

RUN npm run build:vue


# =========================
# 2. PHP backend stage
# =========================
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev \
    zip unzip git \
    && docker-php-ext-install pdo pdo_mysql gd

WORKDIR /var/www/html

# -------------------------
# copy GLPI source
# -------------------------
COPY . .

# -------------------------
# Apache config FIX (SAFE WAY)
# -------------------------
RUN a2enmod rewrite && \
    sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html/public|g' \
    /etc/apache2/sites-available/000-default.conf

# ใช้ conf-available (ไม่ใช้ echo เข้า apache2.conf แล้ว)
RUN printf "<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n" > /etc/apache2/conf-available/glpi-public.conf && \
    a2enconf glpi-public

# -------------------------
# DB CONFIG (DEV ONLY)
# -------------------------
RUN mkdir -p files/_config && \
    cat <<EOF > files/_config/config_db.php
<?php
class DB extends DBmysql {
   public \$dbhost = '172.25.13.84';
   public \$dbuser = 'glpiuser';
   public \$dbpassword = 'password';
   public \$dbdefault = 'glpi_dev';
}
EOF

# -------------------------
# frontend build output
# -------------------------
COPY --from=frontend /app/public /var/www/html/public

# permission fix
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80