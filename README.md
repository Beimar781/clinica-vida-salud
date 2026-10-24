# Clínica Vida Salud · Gestión de citas médicas

Sistema web para la gestión de citas médicas de la Clínica Vida Salud. Proyecto de la materia Ingeniería de Requisitos (INF-219-SC), Grupo 13, UAGRM, desarrollado con el Proceso Unificado (PUDS) y UML 2.5.

Autores: Juan Simeón García Cervantes y Beimar Ríos Benavides.

## Tecnologías

- PHP 8.3 y Laravel 13 (versión instalada: 13.38)
- PostgreSQL 16 o superior (extensión `btree_gist`)
- Blade, HTML5, CSS3 y JavaScript

## Requisitos para ejecutarlo

- PHP 8.3 o superior con las extensiones `pdo_pgsql`, `pgsql`, `mbstring`, `zip` y `fileinfo` (`intl` es opcional)
- Composer
- PostgreSQL 16 o superior, con la extensión `btree_gist` disponible

## Instalación

El proyecto tiene dos carpetas en la raíz, dentro de un solo proyecto Laravel: `backend/` (código PHP, base de datos y pruebas) y `frontend/` (vistas Blade, CSS, JavaScript e imágenes). Los comandos se ejecutan en `backend/`.

```bash
git clone https://github.com/Beimar781/clinica-vida-salud.git
cd clinica-vida-salud/backend
composer install
copy .env.example .env        # en Linux o macOS: cp .env.example .env
php artisan key:generate
```

Crear la base de datos `clinica_vida_salud` en PostgreSQL, completar `DB_PASSWORD` y `ADMIN_PASSWORD` en `backend/.env` y ejecutar:

```bash
php artisan migrate --seed
php artisan serve
```

El sistema queda disponible en http://localhost:8000. No necesita Node ni conexión a internet: los estilos, íconos y scripts están en `frontend/assets` y los sirve el propio servidor de Laravel.

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

## Recuperar la contraseña

En el inicio de sesión, «¿Olvidaste tu contraseña?» pide el correo de la cuenta y envía un enlace de un solo uso que vence a los 60 minutos. En local `MAIL_MAILER=log` no envía correos de verdad: el enlace se lee en `backend/storage/logs/laravel.log`, en la línea que dice «ENLACE DE RECUPERACION». En producción (Railway) el correo sale por la API de Brevo con `MAIL_MAILER=brevo`, `BREVO_API_KEY` y `MAIL_FROM_ADDRESS` (el remitente verificado en Brevo).

También se cargan 5 especialidades, 5 médicos con horarios, 8 pacientes, 3 días sin atención y 12 citas en distintos estados. Para ingresar se puede usar el correo o el nombre de usuario.

Para reiniciar los datos de demostración (por ejemplo antes de una presentación), desde `backend/`:

```bash
php artisan migrate:fresh --seed
```

Borra todas las tablas y las vuelve a crear con los datos de demostración (tarda unos 2 segundos; los códigos CM-0001, PAC-0001 y MED-0001 vuelven a empezar). Si se prefiere deshacer paso a paso: `php artisan migrate:rollback` y luego `php artisan migrate --seed`. Ambos caminos funcionan aunque pgAdmin tenga la base abierta.

## Pruebas

Las pruebas usan la misma base de datos del sistema (revierten sus cambios al terminar, así que no borran los datos de demostración). Primero hay que haber ejecutado `php artisan migrate --seed`:

```bash
cd backend
php artisan test
```

## Despliegue en Railway

El repositorio trae un `Dockerfile` en la raíz (PHP 8.3 con `pdo_pgsql`, `pgsql`, `zip` e `intl`, y Composer `--no-dev`) y un `.dockerignore`. Railway construye una sola imagen con todo el repositorio (`backend/` lee la carpeta hermana `frontend/`, por eso el servicio no usa `backend/` como directorio raíz). Al arrancar, el contenedor crea las carpetas de `storage`, migra la base, carga los datos base y enciende el servidor en el puerto que asigna Railway. Los seeders son idempotentes: pueden correr en cada arranque sin duplicar nada.

**Pasos**

1. En Railway: **New Project → Deploy from GitHub repo** y elegir `Beimar781/clinica-vida-salud`. Railway detecta el `Dockerfile` de la raíz. No hace falta cambiar el comando de inicio ni el directorio raíz.
2. En el mismo proyecto: **+ New → Database → Add PostgreSQL**. El usuario de ese servicio puede ejecutar `CREATE EXTENSION btree_gist`, que usa la primera migración.
3. En el servicio web, pestaña **Settings → Networking → Generate Domain** (queda algo como `https://clinica-vida-salud-production.up.railway.app`).
4. En el servicio web, pestaña **Variables**, crear las de la tabla siguiente (las `DB_*` se enlazan al servicio de PostgreSQL con la sintaxis `${{Postgres.NOMBRE}}`; si el servicio se llama distinto, cambiar `Postgres` por ese nombre). `APP_URL` lleva el dominio del paso 3.
5. Opcional: en **Settings → Deploy**, poner `/up` como ruta de salud (responde 200 cuando la aplicación está viva).
6. Mantener **una sola réplica**: el bloqueo de 60 s tras 3 intentos fallidos usa la caché de archivos de esa instancia (si Railway reinicia el contenedor, el contador se reinicia y no pasa nada más).

| Variable | Valor (solo ejemplos, sin valores reales) |
|---|---|
| `APP_NAME` | `Clínica Vida Salud` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | resultado de `php artisan key:generate --show` (empieza con `base64:`) |
| `APP_URL` | `https://<dominio>.up.railway.app` (con `https://`) |
| `APP_LOCALE` | `es` |
| `ADMIN_PASSWORD` | contraseña del administrador (obligatoria: sin ella el arranque falla con un mensaje claro) |
| `ADMIN_CORREO` | opcional; correo real del administrador (por defecto `admin@vidasalud.test`) |
| `SEMBRAR_DEMO` | `true` para cargar los datos de demostración; `false` (o sin definir) para dejar solo roles, especialidades base y administrador |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `${{Postgres.PGHOST}}` |
| `DB_PORT` | `${{Postgres.PGPORT}}` |
| `DB_DATABASE` | `${{Postgres.PGDATABASE}}` |
| `DB_USERNAME` | `${{Postgres.PGUSER}}` |
| `DB_PASSWORD` | `${{Postgres.PGPASSWORD}}` |
| `SESSION_DRIVER` | `file` |
| `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `SESSION_SECURE_COOKIE` | `true` |
| `LOG_CHANNEL` | `stderr` (los errores salen en los logs de Railway) |
| `MAIL_MAILER` | `brevo` |
| `BREVO_API_KEY` | clave de API de Brevo (empieza con `xkeysib-`) |
| `MAIL_FROM_ADDRESS` | el remitente verificado en Brevo |
| `MAIL_FROM_NAME` | `Clínica Vida Salud` |

No hay que definir `PORT`: lo asigna Railway. Las claves y contraseñas viven solo en las variables de Railway; nunca se suben al repositorio.

**Qué revisar tras el primer despliegue**

- En **Deployments → View logs** deben verse las migraciones (`Running migrations`), el sembrado (`Seeding`) y la línea `Server running on [http://0.0.0.0:...]`. Si dice «Falta ADMIN_PASSWORD», falta esa variable.
- Abrir el dominio: inicio público, «Iniciar sesión» y los estilos cargados (todo con `https`).
- Iniciar sesión con `admin` y la contraseña de `ADMIN_PASSWORD`; con `SEMBRAR_DEMO=true` también con `recepcion`, `cmontano`, `mrojas`, etc. (`Demo1234*`).
- Hacer 3 intentos fallidos con una cuenta: el cuarto intento debe mostrar el bloqueo de 60 segundos.
- «¿Olvidaste tu contraseña?» con un correo real: el correo llega desde el remitente verificado (revisar spam) y el enlace abre con `https`.
- Registrar una cita y reprogramarla.

**Después de la presentación:** el repositorio es público y las contraseñas `Demo1234*` son conocidas. Poner `SEMBRAR_DEMO=false` no borra los usuarios ya creados: desactivarlos o cambiarles la contraseña desde «Usuarios y roles». El administrador se crea una sola vez; para cambiar su contraseña use «Usuarios y roles» (cambiar `ADMIN_PASSWORD` no la modifica).

## Cómo está organizado el código (para la defensa)

El repositorio es un solo proyecto Laravel dividido en **backend** (lógica y datos) y **frontend** (pantallas). Cada **paquete** del documento es una carpeta con el mismo nombre en las dos partes, y cada **caso de uso** es una subcarpeta (`CUxx_NombreDelCaso`):

```
clinica-vida-salud/
├── backend/                          ← proyecto Laravel (lógica, base de datos, rutas, pruebas)
│   ├── app/Modules/
│   │   ├── SeguridadUsuarios/        ← P1: Seguridad y Usuarios
│   │   │   ├── routes.php            ← URLs del paquete, agrupadas por CU
│   │   │   ├── CU01_IniciarSesion/   ← CU-01: LoginController, PanelController, OlvidoClaveController, RestablecerClaveController, LoginService, RecuperarClaveService
│   │   │   ├── CU02_CerrarSesion/    ← CU-02: LogoutController, LogoutService
│   │   │   └── CU03_GestionarUsuariosRoles/  ← CU-03: UsuariosController, UsuarioRequest, UsuarioService
│   │   ├── GestionMedica/            ← P2: CU04_..., CU05_..., CU06_...
│   │   ├── GestionPacientes/         ← P3: CU07_...
│   │   ├── GestionCitas/             ← P4: CU08_... a CU11_... y Compartido/ (disponibilidad)
│   │   └── README.md                 ← tabla de paquetes y casos de uso
│   ├── app/Models/                   ← una clase por tabla del modelo de datos (CE del análisis)
│   ├── app/Services/                 ← BitacoraService (registro de operaciones)
│   ├── app/Http/Middleware/          ← VerificarRol, VerificarCuentaActiva, SinCache
│   ├── app/Support/                  ← utilidades: Codigos (CM-0001), Texto, PanelPrincipal, Recursos
│   ├── database/                     ← migraciones, seeders y schema.sql (modelo físico)
│   └── tests/Feature/                ← una prueba por caso de uso (CUxx_NombreTest.php)
├── frontend/                         ← pantallas (Blade + CSS + JavaScript, sin Node ni CDN)
│   ├── SeguridadUsuarios/ · GestionMedica/ · GestionPacientes/ · GestionCitas/   ← pantalla de cada CU, misma ruta que en backend
│   ├── layouts/ · components/ · errors/  ← diseño general, componentes (ícono, logo, campo, estado de cita) y pantallas de error
│   └── assets/                       ← css/vidasalud.css (común) y css/modulos/, js/, img/
├── Dockerfile · .dockerignore        ← imagen para el despliegue en Railway (ver la sección anterior)
└── docs/                             ← documento (PDF), trazabilidad, clases de análisis y lista de capturas de la sección 4.4
```

Dentro de cada CU del backend hay, según corresponda:

| Archivo | Qué hace |
|---|---|
| `...Controller.php` | La clase de control (CC) del documento. Los comentarios del inicio repiten el flujo de la ficha y dicen qué método cumple cada paso |
| `...Request.php` | Validaciones del formulario en el servidor (campos obligatorios, formatos, duplicados) |
| `...Service.php` | Reglas de negocio del caso de uso (las que el controlador delgado delega) |

Los comentarios `@CU`, `@RF`, `@CI`, `@CC` y `@CE` en cada clase y pantalla enlazan el código con los nombres exactos de los diagramas de análisis (`docs/CLASES_ANALISIS.md`). La pantalla del mismo CU está en `frontend/<Paquete>/<CU>/*.blade.php`.

**Cómo explicar un caso de uso en la defensa** (ejemplo CU-03, registrar un usuario): el administrador abre `/usuarios/crear` → la ruta está en `backend/app/Modules/SeguridadUsuarios/routes.php` (bloque «CU-03») → llama a `UsuariosController::crear()`, que muestra `frontend/SeguridadUsuarios/CU03_GestionarUsuariosRoles/crear.blade.php` → al enviar el formulario, `UsuariosController::guardar()` valida con `UsuarioRequest` (correo y usuario únicos, contraseña de 8 caracteres o más, al menos un rol) → `UsuarioService::guardar()` guarda con los modelos `Usuario` y `Rol` (contraseña con hash) y deja registro en la bitácora con `BitacoraService` → vuelve al listado `index.blade.php` con el aviso verde de confirmación.
