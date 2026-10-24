# Clínica Vida Salud · Gestión de citas médicas

Sistema web para gestionar las citas médicas de la Clínica Vida Salud: administración de usuarios, médicos, horarios y pacientes, y registro, reprogramación y reserva en línea de citas. Lo desarrollamos para la materia Ingeniería de Requisitos (INF-219-SC), Grupo 13, UAGRM, siguiendo el Proceso Unificado (PUDS) y UML 2.5.

**Sistema en línea:** https://clinica-vida-salud-production-aa9a.up.railway.app

Autores: Juan Simeón García Cervantes y Beimar Ríos Benavides.

## Estado del proyecto

| Ciclo | Casos de uso | Estado |
|---|---|---|
| Ciclo I | CU-01 a CU-11 (seguridad y usuarios, gestión médica, pacientes y citas) | Implementado, probado y desplegado |
| Ciclo II | CU-12 a CU-22 (atención clínica, reportes y bitácora) | Se entrega en la segunda presentación |

Además de los casos de uso del Ciclo I, el sistema incluye la recuperación de contraseña por correo electrónico y el bloqueo temporal de la cuenta tras 3 intentos fallidos de inicio de sesión.

## Qué hace el sistema (Ciclo I)

| Paquete | Casos de uso | Quién los usa |
|---|---|---|
| Seguridad y usuarios | CU-01 Iniciar sesión · CU-02 Cerrar sesión · CU-03 Gestionar usuarios y roles | Todos los usuarios; la gestión de usuarios, el administrador |
| Gestión médica | CU-04 Gestionar especialidades médicas · CU-05 Gestionar médicos · CU-06 Gestionar horarios y días sin atención | Administrador |
| Gestión de pacientes | CU-07 Gestionar pacientes | Administrador y recepcionista |
| Gestión de citas | CU-08 Consultar disponibilidad de horarios · CU-09 Registrar cita médica · CU-10 Reprogramar cita médica · CU-11 Reservar cita en línea | Recepcionista y administrador (consultar, registrar y reprogramar citas) y paciente (consultar y reservar en línea; la reserva la hace el propio paciente) |

Roles del sistema: Administrador, Recepcionista, Médico y Paciente. Una misma cuenta puede tener más de un rol. El Administrador hereda las funciones de recepción (pacientes, disponibilidad, registro y reprogramación de citas), por eso la cuenta `admin` tiene solo el rol Administrador; no hereda las del médico ni las del paciente (necesitan su propia ficha), así que no usa «Reservar cita».

**Reglas de negocio y seguridad que implementamos**

- Las contraseñas se guardan con hash bcrypt. Se puede ingresar con el correo o con el nombre de usuario.
- Después de 3 intentos fallidos en una cuenta, el inicio de sesión se bloquea 60 segundos. Los mensajes de error son genéricos para no revelar si la cuenta existe.
- Recuperación de contraseña por correo: enlace de un solo uso que vence a los 60 minutos (flujo alternativo A3 de CU-01).
- Una cita no puede cruzarse con otra del mismo médico ni caer fuera de su horario o en un día sin atención. Además de validarlo en la aplicación, la base de datos lo garantiza con una restricción `EXCLUDE USING gist` (extensión `btree_gist`), y el registro se hace dentro de una transacción con bloqueo del médico (`lockForUpdate`), de modo que dos personas no puedan reservar el mismo horario al mismo tiempo.
- Códigos correlativos para citas, pacientes y médicos (CM-0001, PAC-0001, MED-0001).
- Bitácora de operaciones inmutable: un trigger de PostgreSQL impide modificar o borrar sus registros.
- Acceso por rol (middleware `rol`), cuentas desactivadas sin acceso y páginas protegidas sin caché del navegador.

## Tecnologías

- PHP 8.3 y Laravel 13 (versión instalada: 13.38)
- PostgreSQL 16 o superior (extensión `btree_gist`)
- Blade, HTML5, CSS3 y JavaScript (sin Node ni CDN)
- Correo en producción con la API web de Brevo
- Despliegue en Railway con Docker

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

Crear la base de datos `clinica_vida_salud` en PostgreSQL, completar `DB_PASSWORD` en `backend/.env` (`ADMIN_PASSWORD` es opcional, ver «Usuarios de demostración») y ejecutar:

```bash
php artisan migrate --seed
php artisan serve
```

El sistema queda disponible en http://localhost:8000. No necesita Node ni conexión a internet: los estilos, íconos y scripts están en `frontend/assets` y los sirve el propio servidor de Laravel.

## Usuarios de demostración

`php artisan migrate --seed` crea un usuario por rol. Todas las cuentas de demostración, incluida la del administrador, usan la contraseña `Demo1234*`. Si se define `ADMIN_PASSWORD` (en `backend/.env` o en las variables del servicio), el administrador usa esa contraseña en lugar de `Demo1234*`.

| Usuario | Correo | Roles | Contraseña |
|---|---|---|---|
| admin | admin@vidasalud.test | Administrador | `Demo1234*` (o `ADMIN_PASSWORD` si está definida) |
| recepcion | recepcion@vidasalud.test | Recepcionista | `Demo1234*` |
| cmontano | carla.montano@vidasalud.test | Médico | `Demo1234*` |
| lsoliz | luis.soliz@vidasalud.test | Médico | `Demo1234*` |
| pvargas | patricia.vargas@vidasalud.test | Médico y Paciente | `Demo1234*` |
| mrojas | maria.rojas@correo.test | Paciente | `Demo1234*` |
| jperez | juan.perez@correo.test | Paciente | `Demo1234*` |

También se cargan 5 especialidades, 5 médicos con horarios, 8 pacientes, 3 días sin atención y 12 citas en distintos estados.

Para reiniciar los datos de demostración, desde `backend/`:

```bash
php artisan migrate:fresh --seed
```

Borra todas las tablas y las vuelve a crear con los datos de demostración (tarda unos 2 segundos; los códigos CM-0001, PAC-0001 y MED-0001 vuelven a empezar). Si se prefiere deshacer paso a paso: `php artisan migrate:rollback` y luego `php artisan migrate --seed`. Ambos caminos funcionan aunque pgAdmin tenga la base abierta.

## Recuperar la contraseña

En el inicio de sesión, «¿Olvidaste tu contraseña?» pide el correo de la cuenta y envía un enlace de un solo uso que vence a los 60 minutos. El sistema responde lo mismo exista o no el correo, y limita las solicitudes repetidas.

- **En local** `MAIL_MAILER=log` no envía correos de verdad: el enlace se lee en `backend/storage/logs/laravel.log`, en la línea que dice «ENLACE DE RECUPERACION».
- **En producción (Railway)** el correo sale por la API de Brevo (`App\Support\BrevoTransport`) con `MAIL_MAILER=brevo`, `BREVO_API_KEY` y `MAIL_FROM_ADDRESS` (el remitente verificado en Brevo). Usamos la API web y no SMTP porque Railway bloquea los puertos de correo SMTP.

## Pruebas

Las pruebas usan la misma base de datos del sistema (revierten sus cambios al terminar, así que no borran los datos de demostración). Primero hay que haber ejecutado `php artisan migrate --seed`:

```bash
cd backend
php artisan test
```

La última corrida completa (24/10/2026) dio **214 pruebas y 1139 aserciones, todas aprobadas**. Hay una prueba por caso de uso (`CUxx_NombreTest.php`) y pruebas de la base de datos, la seguridad, los recursos del frontend y el despliegue. La relación entre cada requisito, su código y su prueba está en `docs/TRAZABILIDAD.md`.

## Despliegue en Railway

El repositorio trae un `Dockerfile` en la raíz (PHP 8.3 con `pdo_pgsql`, `pgsql`, `zip` e `intl`, y Composer `--no-dev`) y un `.dockerignore`. Railway construye una sola imagen con todo el repositorio (`backend/` lee la carpeta hermana `frontend/`, por eso el servicio no usa `backend/` como directorio raíz). Al arrancar, el contenedor crea las carpetas de `storage`, migra la base, carga los datos base y enciende el servidor en el puerto que asigna Railway. Los seeders son idempotentes: pueden correr en cada arranque sin duplicar nada.

**Cómo lo desplegamos**

1. En Railway: **New Project → Deploy from GitHub repo** y elegir `Beimar781/clinica-vida-salud`. Railway detecta el `Dockerfile` de la raíz. No hace falta cambiar el comando de inicio ni el directorio raíz.
2. En el mismo proyecto: **+ New → Database → Add PostgreSQL**. El usuario de ese servicio puede ejecutar `CREATE EXTENSION btree_gist`, que usa la primera migración.
3. En el servicio web, pestaña **Settings → Networking → Generate Domain**.
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
| `ADMIN_PASSWORD` | contraseña del administrador (opcional: si falta, el administrador usa `Demo1234*`) |
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

**Verificación tras el despliegue**

- En **Deployments → View logs** se ven las migraciones (`Running migrations`), el sembrado (`Seeding`) y la línea `Server running on [http://0.0.0.0:...]`.
- El dominio abre el inicio público y «Iniciar sesión», con los estilos cargados y todo por `https`.
- Se puede iniciar sesión con `admin`; con `SEMBRAR_DEMO=true` también con `recepcion`, `cmontano`, `mrojas`, etc.
- Tras 3 intentos fallidos con una cuenta, el siguiente intento muestra el bloqueo de 60 segundos.
- «¿Olvidaste tu contraseña?» envía el correo desde el remitente verificado (puede llegar a spam) y el enlace abre con `https`.
- Se puede registrar una cita y reprogramarla.

**Nota sobre los datos de demostración:** como el repositorio es público, las contraseñas `Demo1234*` son conocidas. Para usar el sistema con datos reales hay que poner `SEMBRAR_DEMO=false`, definir un `ADMIN_PASSWORD` propio y desactivar o cambiar la contraseña de las cuentas de demostración desde «Usuarios y roles». El administrador se crea una sola vez: cambiar `ADMIN_PASSWORD` después no modifica su contraseña, eso se hace desde «Usuarios y roles».

## Cómo está organizado el código

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
│   ├── app/Support/                  ← utilidades: Codigos (CM-0001), Texto, PanelPrincipal, Recursos, BrevoTransport
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

**Recorrido de un caso de uso** (ejemplo CU-03, registrar un usuario): el administrador abre `/usuarios/crear` → la ruta está en `backend/app/Modules/SeguridadUsuarios/routes.php` (bloque «CU-03») → llama a `UsuariosController::crear()`, que muestra `frontend/SeguridadUsuarios/CU03_GestionarUsuariosRoles/crear.blade.php` → al enviar el formulario, `UsuariosController::guardar()` valida con `UsuarioRequest` (correo y usuario únicos, contraseña de 8 caracteres o más, al menos un rol) → `UsuarioService::guardar()` guarda con los modelos `Usuario` y `Rol` (contraseña con hash) y deja registro en la bitácora con `BitacoraService` → vuelve al listado `index.blade.php` con el aviso verde de confirmación.
