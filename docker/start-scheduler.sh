#!/bin/sh
while true; do
    php /var/www/html/artisan schedule:run >> /var/www/html/storage/logs/scheduler.log 2>&1
    sleep 60
done
