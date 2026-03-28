# --- ÉTAPE 1 : BASE COMMUNE ---
FROM php:8.2-apache AS base

# Installation des dépendances système
RUN apt-get update && apt-get install -y \
    python3 \
    ffmpeg \
    libmariadb-dev \
    curl \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Installation de yt-dlp
RUN curl -fL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp \
    && chmod +x /usr/local/bin/yt-dlp

# Configuration Apache
RUN a2enmod rewrite headers

# Dossier de stockage
RUN mkdir -p /var/www/music_data && chown www-data:www-data /var/www/music_data

WORKDIR /var/www/html

# --- ÉTAPE 2 : CONFIGURATION POUR LE DÉVELOPPEMENT ---
FROM base AS development
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY ./docker/php-dev.ini /usr/local/etc/php/conf.d/z-dev.ini

# --- ÉTAPE 3 : CONFIGURATION POUR LA PRODUCTION ---
FROM base AS production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Fichiers de configuration spécifiques
COPY ./docker/php-prod.ini /usr/local/etc/php/conf.d/z-prod.ini
COPY ./docker/security.ini /usr/local/etc/php/conf.d/
COPY ./docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Copie du code
COPY ./src /var/www/html

# Installation des dépendances sans les outils de dev
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Droits d'accès stricts
RUN chown -R www-data:www-data /var/www/html /var/www/music_data

USER www-data