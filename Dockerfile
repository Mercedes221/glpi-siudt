FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libbz2-dev \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        bz2 \
        curl \
        exif \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        zip \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN printf 'session.cookie_httponly=On\n' > /usr/local/etc/php/conf.d/glpi-security.ini

RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n    RewriteEngine On\n    RewriteRule ^apirest[.]php(/.*)?$ /index.php [END]\n    RewriteRule ^api[.]php(/.*)?$ /index.php [END]\n    FallbackResource /index.php\n</Directory>\n' \
        > /etc/apache2/conf-available/glpi.conf \
    && a2enconf glpi

WORKDIR /var/www/html
COPY . /var/www/html
COPY plugins/siudtfront/css/custom_theme.css /var/www/html/public/plugins/siudtfront/css/custom_theme.css

RUN mkdir -p /var/www/html/config /var/www/html/files \
    && chown -R www-data:www-data /var/www/html/config /var/www/html/files
