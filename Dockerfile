# CSL Marketplace API - Laravel 12.x with php:8.3-fpm
FROM php:8.3-fpm

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev libicu-dev libzip-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpq-dev zip unzip nginx \
    supervisor cron netcat-openbsd default-mysql-client nano procps \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath zip opcache

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) gd

RUN docker-php-ext-configure intl && docker-php-ext-install intl

RUN pecl install redis && docker-php-ext-enable redis

RUN apt-get update && apt-get install -y librdkafka-dev \
    && pecl install rdkafka && docker-php-ext-enable rdkafka \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
# Cache mount: resilient/faster dependency installs in CI builds
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist

RUN echo "[www]\nuser = www-data\ngroup = www-data\nlisten = 127.0.0.1:9000\nlisten.owner = www-data\nlisten.group = www-data\npm = dynamic\npm.max_children = 20\npm.start_servers = 4\npm.min_spare_servers = 2\npm.max_spare_servers = 6\npm.max_requests = 1000" > /usr/local/etc/php-fpm.d/www.conf

RUN echo "memory_limit = 256M\nupload_max_filesize = 50M\npost_max_size = 50M\nmax_execution_time = 120\ndate.timezone = UTC\nopcache.enable = 1\nopcache.memory_consumption = 128\nopcache.max_accelerated_files = 10000\nopcache.validate_timestamps = 0" > /usr/local/etc/php/conf.d/99-marketplace.ini

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/conf.d/app.conf /etc/nginx/sites-available/default
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/supervisor/*.conf /etc/supervisor/conf.d/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/start-scheduler.sh /usr/local/bin/start-scheduler.sh

RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/ \
    && chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/start-scheduler.sh

RUN mkdir -p /var/www/html/storage/framework/{sessions,views,cache} \
    /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && touch /var/www/html/storage/logs/laravel.log \
    /var/www/html/storage/logs/queue.log \
    /var/www/html/storage/logs/scheduler.log \
    /var/www/html/storage/logs/kafka-consumer.log

RUN echo "* * * * * www-data cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/laravel-scheduler \
    && chmod 0644 /etc/cron.d/laravel-scheduler

RUN mkdir -p /var/log/nginx /var/log/supervisor \
    && touch /var/log/nginx/access.log /var/log/nginx/error.log \
    && chown -R www-data:www-data /var/log/nginx

RUN mkdir -p /var/www/html/public/health \
    && echo '<?php echo json_encode(["status"=>"ok","timestamp"=>time()]);' > /var/www/html/public/health/index.php

COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data .env.staging /var/www/html/.env

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

RUN php artisan key:generate --no-interaction \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

RUN php artisan storage:link || true

RUN find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:80/health/index.php || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
