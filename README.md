# Clínica Vida Salud · Gestión de citas médicas

Sistema web para la gestión de citas médicas de la Clínica Vida Salud. Proyecto de la materia Ingeniería de Requisitos (INF-219-SC), Grupo 13, UAGRM, desarrollado con el Proceso Unificado (PUDS) y UML 2.5.

Autores: Juan Simeón García Cervantes y Beimar Ríos Benavides.

## Tecnologías

- PHP 8.3 y Laravel 13
- PostgreSQL 16 o superior (extensión `btree_gist`)
- Blade, HTML5, CSS3 y JavaScript

## Requisitos para ejecutarlo

- PHP 8.3 o superior con las extensiones `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip` y `fileinfo`
- Composer
- PostgreSQL 16 o superior, con la extensión `btree_gist` disponible

## Instalación

```bash
git clone https://github.com/Beimar781/clinica-vida-salud.git
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

`php artisan migrate --seed` crea un usuario por rol. El administrador usa la contraseña que se defina en `ADMIN_PASSWORD` (archivo `.env`); las demás cuentas usan `Demo1234*`.

| Usuario | Correo | Roles | Contraseña |
|---|---|---|---|
| admin | admin@vidasalud.test | Administrador y Recepcionista | `ADMIN_PASSWORD` |
| recepcion | recepcion@vidasalud.test | Recepcionista | `Demo1234*` |
| cmontano | carla.montano@vidasalud.test | Médico | `Demo1234*` |
| lsoliz | luis.soliz@vidasalud.test | Médico | `Demo1234*` |
| pvargas | patricia.vargas@vidasalud.test | Médico y Paciente | `Demo1234*` |
| mrojas | maria.rojas@correo.test | Paciente | `Demo1234*` |
| jperez | juan.perez@correo.test | Paciente | `Demo1234*` |

También se cargan 5 especialidades, 5 médicos con horarios, 8 pacientes, 3 días sin atención y 12 citas en distintos estados. Para ingresar se puede usar el correo o el nombre de usuario.

Para volver a empezar con datos limpios: `php artisan migrate:rollback` y luego `php artisan migrate --seed`.

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
