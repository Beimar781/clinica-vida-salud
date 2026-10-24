FROM php:8.3-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Se copia todo el repositorio porque backend/ lee las vistas y los assets de la carpeta hermana frontend/.
COPY . .

WORKDIR /app/backend
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

ENV PHP_CLI_SERVER_WORKERS=4

# Al arrancar: crea las carpetas de trabajo, descarta cualquier configuración en caché, migra, carga los datos base (y los de demostración solo con
# SEMBRAR_DEMO=true; los seeders son idempotentes) y enciende el servidor en el puerto que asigna Railway.
CMD ["sh", "-c", "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && php artisan package:discover --ansi && php artisan config:clear && php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080} --no-reload"]
