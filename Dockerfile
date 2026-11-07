FROM php:8.3-cli-bookworm

# Extensiones del sistema: pdo_pgsql y pgsql (PostgreSQL), zip (Composer) e intl. El PDF de los reportes (dompdf) solo necesita dom y mbstring,
# que ya trae la imagen oficial de PHP: no hace falta gd porque el PDF no lleva imágenes.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl \
 && rm -rf /var/lib/apt/lists/*

# Si falta alguna extensión que el sistema necesita, la construcción de la imagen falla aquí y no en producción.
RUN php -r 'foreach (["pdo_pgsql", "pgsql", "zip", "intl", "dom", "mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Falta la extension PHP: $e\n"); exit(1); } }'

# El PDF de los reportes (dompdf) usa unos 56 MB más 0,3 MB por fila (tope de 300 filas): 256 MB sobra y 128 MB (el valor por defecto) no alcanza.
RUN printf 'memory_limit=256M\n' > /usr/local/etc/php/conf.d/vidasalud.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Se copia todo el repositorio porque backend/ lee las vistas y los assets de la carpeta hermana frontend/.
COPY . .

WORKDIR /app/backend
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

ENV PHP_CLI_SERVER_WORKERS=4

# Al arrancar el servicio web: crea las carpetas de trabajo, descarta cualquier configuración en caché, migra (las migraciones nuevas se aplican sobre
# la base existente sin perder datos), carga los datos base (y los de demostración solo con SEMBRAR_DEMO=true; los seeders son idempotentes),
# guarda la configuración en caché y enciende el servidor en el puerto que asigna Railway.
# El servicio cron de los recordatorios (CU-22) usa este mismo Dockerfile con otro comando de inicio (ver el README, sección «Despliegue en Railway»).
CMD ["sh", "-c", "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts bootstrap/cache && php artisan package:discover --ansi && php artisan config:clear && php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080} --no-reload"]
