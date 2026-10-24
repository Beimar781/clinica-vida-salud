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

## Cómo está organizado el código (para la defensa)

El repositorio es un solo proyecto Laravel dividido en **backend** (lógica y datos) y **frontend** (pantallas). Cada **paquete** del documento es una carpeta con el mismo nombre en las dos partes, y cada **caso de uso** es una subcarpeta (`CUxx_NombreDelCaso`):

```
clinica-vida-salud/
├── backend/                          ← proyecto Laravel (lógica, base de datos, rutas, pruebas)
│   ├── app/Modules/
│   │   ├── SeguridadUsuarios/        ← P1: Seguridad y Usuarios
│   │   │   ├── routes.php            ← URLs del paquete, agrupadas por CU
│   │   │   ├── CU01_IniciarSesion/   ← CU-01: LoginController, PanelController, LoginRequest, LoginService
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
└── docs/                             ← documento, trazabilidad y clases de análisis
```

Dentro de cada CU del backend hay siempre:

| Archivo | Qué hace |
|---|---|
| `...Controller.php` | La clase de control (CC) del documento. Los comentarios del inicio repiten el flujo de la ficha y dicen qué método cumple cada paso |
| `...Request.php` | Validaciones del formulario en el servidor (campos obligatorios, formatos, duplicados) |
| `...Service.php` | Reglas de negocio del caso de uso (las que el controlador delgado delega) |

Los comentarios `@CU`, `@RF`, `@CI`, `@CC` y `@CE` en cada clase y pantalla enlazan el código con los nombres exactos de los diagramas de análisis (`docs/CLASES_ANALISIS.md`). La pantalla del mismo CU está en `frontend/<Paquete>/<CU>/*.blade.php`.

**Cómo explicar un caso de uso en la defensa** (ejemplo CU-03, registrar un usuario): el administrador abre `/usuarios/crear` → la ruta está en `backend/app/Modules/SeguridadUsuarios/routes.php` (bloque «CU-03») → llama a `UsuariosController::crear()`, que muestra `frontend/SeguridadUsuarios/CU03_GestionarUsuariosRoles/crear.blade.php` → al enviar el formulario, `UsuariosController::guardar()` valida con `UsuarioRequest` (correo y usuario únicos, contraseña de 8 caracteres o más, al menos un rol) → `UsuarioService::guardar()` guarda con los modelos `Usuario` y `Rol` (contraseña con hash) y deja registro en la bitácora con `BitacoraService` → vuelve al listado `index.blade.php` con el aviso verde de confirmación.
