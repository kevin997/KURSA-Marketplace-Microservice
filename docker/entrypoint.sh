#!/bin/sh
set +e

# Create log directories
mkdir -p /var/log/nginx /var/log/supervisor
touch /var/log/nginx/access.log /var/log/nginx/error.log
touch /var/log/supervisor/supervisord.log

# Create Laravel storage directories
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# Ensure log files exist
touch /var/www/html/storage/logs/laravel.log
touch /var/www/html/storage/logs/queue.log
touch /var/www/html/storage/logs/scheduler.log
touch /var/www/html/storage/logs/kafka-consumer.log

# Set permissions
chown -R www-data:www-data /var/www/html/storage
chmod -R 777 /var/www/html/storage
chown -R www-data:www-data /var/www/html/bootstrap/cache
chmod -R 777 /var/www/html/bootstrap/cache
chmod 777 /tmp

# Wait for MySQL on app/queue/scheduler roles
if [ "$CONTAINER_ROLE" = "app" ] || [ "$CONTAINER_ROLE" = "queue" ] || [ "$CONTAINER_ROLE" = "scheduler" ] || [ "$CONTAINER_ROLE" = "kafka-consumer" ] || [ "$CONTAINER_ROLE" = "outbox-processor" ]; then
    echo "Waiting for MySQL ($DB_HOST:3306)..."
    RETRY_COUNT=0
    MAX_RETRIES=15
    until nc -z -w10 $DB_HOST 3306 || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do
        echo "  attempt $((RETRY_COUNT+1))/$MAX_RETRIES..."
        sleep 3
        RETRY_COUNT=$((RETRY_COUNT+1))
    done
    if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
        echo "Warning: Could not connect to MySQL after $MAX_RETRIES attempts."
    else
        echo "MySQL connection established."
    fi
fi

# Run migrations on app role only
if [ "$CONTAINER_ROLE" = "app" ]; then
    echo "Running database migrations..."
    php artisan migrate --force && echo "Migrations done." || echo "Warning: migrations failed."
fi

# Clear and rebuild caches
echo "Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan optimize:clear && php artisan optimize || true

# Ensure health endpoint exists
mkdir -p /var/www/html/public/health
echo '<?php echo json_encode(["status"=>"ok","timestamp"=>time()]);' > /var/www/html/public/health/index.php

# Create storage symlink
php artisan storage:link || true

mkdir -p /var/log/supervisor

# Start appropriate role
if [ "$CONTAINER_ROLE" = "queue" ]; then
    echo "Starting queue worker..."
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
elif [ "$CONTAINER_ROLE" = "scheduler" ]; then
    echo "Starting scheduler..."
    # Fix permissions for any log files created by root during artisan commands
    chown -R www-data:www-data /var/www/html/storage/logs
    chmod -R 777 /var/www/html/storage/logs
    service cron start
    cp /var/www/html/docker/supervisor/scheduler.conf /etc/supervisor/conf.d/
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
elif [ "$CONTAINER_ROLE" = "kafka-consumer" ]; then
    echo "Starting Kafka consumer (template.publish_to_marketplace)..."
    # Fix permissions for any log files created by root during artisan commands
    chown -R www-data:www-data /var/www/html/storage/logs
    chmod -R 777 /var/www/html/storage/logs
    # Ensure required Kafka topics exist (wait for Kafka to be available)
    if [ -n "$KAFKA_BROKERS" ]; then
      echo "Waiting for Kafka topics to be available..."
      RETRY_COUNT=0
      MAX_RETRIES=30
      until php artisan kafka:ensure-topics 2>/dev/null || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do
        echo "  attempt $((RETRY_COUNT+1))/$MAX_RETRIES: waiting for Kafka..."
        sleep 2
        RETRY_COUNT=$((RETRY_COUNT+1))
      done
      if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
        echo "Warning: Could not ensure Kafka topics exist. Consumer may retry on missing topics."
      else
        echo "Kafka topics verified."
      fi
    fi
    cp /var/www/html/docker/supervisor/kafka-consumer.conf /etc/supervisor/conf.d/
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
elif [ "$CONTAINER_ROLE" = "outbox-processor" ]; then
    echo "Starting Kafka outbox processor..."
    # Fix permissions for any log files created by root during artisan commands
    chown -R www-data:www-data /var/www/html/storage/logs
    chmod -R 777 /var/www/html/storage/logs
    cp /var/www/html/docker/supervisor/outbox-processor.conf /etc/supervisor/conf.d/
    exec supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    echo "Starting Nginx and PHP-FPM..."
    php-fpm -D
    exec nginx -g "daemon off;"
fi
