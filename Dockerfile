# =========================
# 1. Frontend build stage
# =========================
FROM node:20 AS frontend

WORKDIR /app

# copy dependency ก่อน
COPY package*.json ./

# กัน lifecycle scripts พัง (php not found / postinstall)
ENV NPM_CONFIG_IGNORE_SCRIPTS=true

# install deps แบบปลอดภัย
RUN npm install --legacy-peer-deps

# copy source ทั้งหมดทีเดียว (กัน tools หาย + ลด error COPY)
COPY . .

# build vue
RUN npm run build:vue


# =========================
# 2. PHP backend stage
# =========================
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev zip unzip git \
    && docker-php-ext-install pdo pdo_mysql gd

WORKDIR /var/www/html

# copy backend ทั้งหมด (ใช้ .dockerignore คุมแทน)
COPY . .

# copy frontend build
COPY --from=frontend /app/public/build /var/www/html/public/build

# permission fix
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80