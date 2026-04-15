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
    sendmail \
    mailutils \
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
RUN mkdir -p /var/www/music_data && chown www-data:www-data /var/www/music_data && chmod -R 755 /var/www/music_data

# Déplacement des dépendances php
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

# Copie composer.json et lock
COPY ../composer.json composer.lock* ./

# Copie le code source
COPY ../src /var/www/html/src

# Droits d'accès
RUN chown -R www-data:www-data /var/www/html

COPY ../meilisearch_init/init.sh /init.sh
RUN chmod +x /init.sh

# --- ÉTAPE 2 : CONFIGURATION POUR LE DÉVELOPPEMENT ---
FROM base AS development
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php-dev.ini /usr/local/etc/php/conf.d/z-dev.ini
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Installation des dépendances
RUN composer install --optimize-autoloader --no-interaction

CMD ["/init.sh"]

# --- ÉTAPE 3 : CONFIGURATION POUR LA PRODUCTION ---
FROM base AS production
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Fichiers de configuration spécifiques
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/z-prod.ini
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Installer zip AVANT le security.ini
RUN apt-get update && apt-get install -y libzip-dev nodejs && docker-php-ext-install zip && apt-get clean

# Installation des dépendances sans les outils de dev
ENV COMPOSER_HOME=/tmp/composer
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Fichier de configuration spécifique
COPY docker/security.ini /usr/local/etc/php/conf.d/

USER www-data

CMD ["/init.sh"]