# Clínica Vida Salud · Gestión de citas médicas

Sistema web para la gestión de citas médicas de la Clínica Vida Salud. Proyecto de la materia Ingeniería de Requisitos (INF-219-SC), Grupo 13, UAGRM, desarrollado con el Proceso Unificado (PUDS) y UML 2.5.

Autores: Juan Simeón García Cervantes y Beimar Ríos Benavides.

## Tecnologías

- PHP 8.3 y Laravel
- PostgreSQL 16 (extensión `btree_gist`)
- Blade, HTML5, CSS3 y JavaScript

## Requisitos para ejecutarlo

- PHP 8.3 o superior con las extensiones `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip` y `fileinfo`
- Composer
- PostgreSQL 16

## Instalación

```bash
git clone https://github.com/<usuario>/clinica-vida-salud.git
cd clinica-vida-salud
composer install
cp .env.example .env        # en Windows: copy .env.example .env
php artisan key:generate
```

Crear la base de datos `clinica_vida_salud` en PostgreSQL, completar `DB_PASSWORD` y `ADMIN_PASSWORD` en el archivo `.env` y ejecutar:

```bash
php artisan migrate --seed
php artisan serve
```

El sistema queda disponible en http://localhost:8000.

## Usuarios de demostración

Se completa cuando estén listos los seeders.

## Pruebas

Las pruebas usan la misma base de datos del sistema (revierten sus cambios al terminar, así que no borran los datos de demostración). Primero hay que haber ejecutado `php artisan migrate --seed`:

```bash
php artisan test
```

## Estructura

El código está organizado por paquetes del análisis, dentro de `app/Modules`:

| Paquete | Carpeta |
|---|---|
| Seguridad y Usuarios | `Seguridad` |
| Gestión Médica | `GestionMedica` |
| Gestión de Pacientes | `GestionPacientes` |
| Gestión de Citas | `GestionCitas` |

La documentación del proyecto está en `docs/` y el modelo físico de la base de datos en `database/schema.sql`.
