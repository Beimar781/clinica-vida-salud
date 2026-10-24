# Módulos del sistema

Cada carpeta es un paquete del documento y cada subcarpeta `CUxx_...` es un caso de uso.
Aquí (backend) van el controlador, las validaciones (Request) y el servicio con las reglas de negocio de cada CU.
Las pantallas (`.blade.php`) del mismo CU están en la carpeta hermana `frontend/<Paquete>/<CU>/`.

| Paquete | Contenido |
|---|---|
| `SeguridadUsuarios/` | P1: CU-01 Iniciar sesión (con el flujo alternativo «¿Olvidaste tu contraseña?») · CU-02 Cerrar sesión · CU-03 Gestionar usuarios y roles |
| `GestionMedica/` | P2: CU-04 Gestionar especialidades médicas · CU-05 Gestionar médicos · CU-06 Gestionar horarios y días sin atención |
| `GestionPacientes/` | P3: CU-07 Gestionar pacientes |
| `ReportesBitacora/` | P6: CU-19 Consultar bitácora (adelantado del Ciclo II; solo el administrador y solo lectura). Los reportes del paquete llegan con el Ciclo II |
| `GestionCitas/` | P4: CU-08 Consultar disponibilidad de horarios · CU-09 Registrar cita médica · CU-10 Reprogramar cita médica · CU-11 Reservar cita en línea (y `Compartido/` con el cálculo de disponibilidad que usan CU-09, CU-10 y CU-11). |

Dentro de cada CU hay, según corresponda: `...Controller.php` (el CC del documento; sus comentarios repiten el flujo de la ficha),
`...Request.php` (validación en el servidor) y `...Service.php` (reglas de negocio). Los modelos están en `app/Models`,
la bitácora en `app/Services/BitacoraService.php` y los middleware en `app/Http/Middleware`.

El archivo `routes.php` de cada paquete y sus vistas se cargan solos (ver `app/Providers/AppServiceProvider.php`, método `cargarModulos`).
Para crear un paquete o CU nuevo basta con agregar su carpeta aquí con su `routes.php` y la carpeta del mismo nombre en `frontend/`; no hay que registrar nada más.
Las vistas se llaman con el nombre del paquete en minúsculas, por ejemplo `view('seguridadusuarios::CU01_IniciarSesion.login')`.
