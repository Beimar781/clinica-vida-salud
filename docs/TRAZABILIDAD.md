# Trazabilidad requisito → código → prueba

Una fila por caso de uso. «Prueba» es `backend/tests/Feature/CUxx_NombreTest.php`; el estado se actualiza al correr `php artisan test --filter=CUxx`.

## Base técnica

| Elemento | Archivo | Prueba | Estado |
|---|---|---|---|
| Modelo físico del Ciclo I (13 tablas + las técnicas `migrations` y `password_reset_tokens`), EXCLUDE, secuencias, trigger de bitácora | `backend/database/migrations/*` (desde `backend/database/schema.sql`) | `backend/tests/Feature/BaseDatosTest.php` | Aprobado |
| Usuario con `password_hash`, middleware `rol`, inicio público | `backend/app/Models/Usuario.php`, `backend/app/Http/Middleware/VerificarRol.php` | `backend/tests/Feature/BaseSeguridadTest.php` | Aprobado |
| Normalización de texto | `backend/app/Support/Texto.php` | `backend/tests/Unit/TextoTest.php` | Aprobado |
| Assets del frontend por la ruta `assets` (sin symlinks, anti path traversal, caché) | `backend/app/Http/Controllers/RecursoController.php`, `backend/app/Support/Recursos.php` | `backend/tests/Feature/RecursosTest.php` | Aprobado |
| Preparación del despliegue en Railway: seeders idempotentes (`SEMBRAR_DEMO`, `ADMIN_PASSWORD`), proxy con https, `Dockerfile` | `Dockerfile`, `.dockerignore`, `backend/database/seeders/*`, `backend/bootstrap/app.php`, `backend/app/Providers/AppServiceProvider.php` | `backend/tests/Feature/DespliegueTest.php` | Aprobado |

## Ciclo I

Los nombres de clase (CI, CC, CE) son los de los diagramas (`docs/CLASES_ANALISIS.md`). Backend: `backend/app/Modules/<Paquete>/<CU>/`; pantallas: `frontend/<Paquete>/<CU>/`; pruebas: `backend/tests/Feature/`. Las rutas de cada paquete están en `backend/app/Modules/<Paquete>/routes.php`.

| CU | RF | Paquete | CI (pantalla) | CC (controlador · servicio) | Rutas | CE (modelos) | Tablas | Prueba | Estado |
|---|---|---|---|---|---|---|---|---|---|
| CU-01 Iniciar sesión | RF01 | P1 SeguridadUsuarios | `CILogin` · `CU01_IniciarSesion/login`, `panel`, `olvido`, `restablecer` (A3) | `CCLogin` · LoginController, PanelController · LoginService (LoginRequest). Flujo alternativo A3 «Recuperar contraseña»: OlvidoClaveController, RestablecerClaveController · RecuperarClaveService (OlvidoClaveRequest, RestablecerClaveRequest); correo `BrevoTransport` | `GET/POST /login`, `GET /panel`; A3: `GET/POST /olvido-clave`, `GET /restablecer-clave/{token}`, `POST /restablecer-clave` | `CEUsuario` (Usuario, Rol) | usuarios, rol_usuario, bitacora; técnica: password_reset_tokens | `CU01_IniciarSesionTest` (36) | Aprobado |
| CU-02 Cerrar sesión | RF02 | P1 SeguridadUsuarios | `CILogout` · botón del menú (`layouts/app`) | `CCLogout` · LogoutController · LogoutService | `POST /logout` | `CEUsuario` (Usuario) | usuarios | `CU02_CerrarSesionTest` (5) | Aprobado |
| CU-03 Gestionar usuarios y roles | RF03 | P1 SeguridadUsuarios | `CIUsuarios` · `CU03_GestionarUsuariosRoles/index`, `crear`, `editar`, `campos` | `CCUsuarios` · UsuariosController · UsuarioService (UsuarioRequest) | `/usuarios` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEUsuario` (Usuario), `CERol` (Rol) | usuarios, roles, rol_usuario | `CU03_GestionarUsuariosRolesTest` (16) | Aprobado |
| CU-04 Gestionar especialidades médicas | RF04 | P2 GestionMedica | `CIEspecialidad` · `CU04_GestionarEspecialidadesMedicas/` | `CCEspecialidad` · EspecialidadController · EspecialidadService (EspecialidadRequest) | `/especialidades` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEEspecialidad` (Especialidad) | especialidades | `CU04_GestionarEspecialidadesMedicasTest` (8) | Aprobado |
| CU-05 Gestionar médicos | RF05 | P2 GestionMedica | `CIMedicos` · `CU05_GestionarMedicos/` | `CCMedicos` · MedicosController · MedicoService (MedicoRequest) | `/medicos` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEMedico` (Medico), `CEEspecialidad` (Especialidad) | medicos, medico_especialidad | `CU05_GestionarMedicosTest` (16) | Aprobado |
| CU-06 Gestionar horarios y días sin atención | RF06 | P2 GestionMedica | `CIHorarios` · `CU06_GestionarHorariosDiasSinAtencion/` | `CCHorarios` · HorariosController, DiasSinAtencionController · HorarioService, DiaSinAtencionService (HorarioRequest, DiaSinAtencionRequest) | `/horarios` (index, guardar, actualizar, desactivar, activar) y `/dias-sin-atencion` (index, guardar, desactivar, activar) | `CEHorario` (HorarioAtencion), `CEMedico` (Medico); BloqueoAgenda | horarios_atencion, bloqueos_agenda | `CU06_GestionarHorariosDiasSinAtencionTest` (17) | Aprobado |
| CU-07 Gestionar pacientes | RF07 | P3 GestionPacientes | `CIPacientes` · `CU07_GestionarPacientes/` | `CCPacientes` · PacientesController · PacienteService (PacienteRequest) | `/pacientes` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEPaciente` (Paciente), `CEHistoriaClinica` (HistoriaClinica), `CEAntecedente` (AntecedenteExterno) | pacientes, historias_clinicas, antecedentes_externos | `CU07_GestionarPacientesTest` (19) | Aprobado |
| CU-08 Consultar disponibilidad de horarios | RF08 | P4 GestionCitas | `CIDisponibilidad` · `CU08_ConsultarDisponibilidadHorarios/` | `CCDisponibilidad` · DisponibilidadController · DisponibilidadService y CatalogoMedicosService (en `Compartido/`), DisponibilidadRequest | `GET /disponibilidad` | `CEMedico` (Medico), `CECita` (Cita) | horarios_atencion, citas, bloqueos_agenda | `CU08_ConsultarDisponibilidadHorariosTest` (14) | Aprobado |
| CU-09 Registrar cita médica | RF09 | P4 GestionCitas | `CIRegistrarCita` · `CU09_RegistrarCitaMedica/` | `CCRegistrarCita` · RegistrarCitaController · RegistrarCitaService (RegistrarCitaRequest), AgendarCitaService (en `Compartido/`: transacción, bloqueo del médico y 23P01) | `GET /citas/registrar`, `POST /citas`, `GET /citas/{cita}/comprobante` | `CEPaciente`, `CECita`, `CEMedico` | citas | `CU09_RegistrarCitaMedicaTest` (17) y `CU09_RegistrarCitaMedicaConcurrenciaTest` (1, dos procesos reales) | Aprobado |
| CU-10 Reprogramar cita médica | RF10 | P4 GestionCitas | `CIReprogramarCita` · `CU10_ReprogramarCitaMedica/` (y listado de citas) | `CCReprogramarCita` · ReprogramarCitaController · ReprogramarCitaService (ReprogramarCitaRequest), reutiliza AgendarCitaService y DisponibilidadService (`Compartido/`) | `GET /citas`, `GET|PUT /citas/{cita}/reprogramar`, `GET /citas/{cita}/reprogramada` | `CECita`, `CEMedico` | citas | `CU10_ReprogramarCitaMedicaTest` (17) | Aprobado |
| CU-11 Reservar cita en línea | RF11 | P4 GestionCitas | `CIReservarCita` · `CU11_ReservarCitaEnLinea/` | `CCReservarCita` · ReservarCitaController · ReservarCitaService (ReservarCitaRequest), reutiliza AgendarCitaService, DisponibilidadService y CatalogoMedicosService (`Compartido/`) | `GET /reservas/crear`, `GET /reservas/reservar`, `POST /reservas`, `GET /reservas/{cita}/comprobante` | `CECita`, `CEMedico` | citas | `CU11_ReservarCitaEnLineaTest` (16) | Aprobado |

## Reporte de prueba del Ciclo I (insumo de la sección 8.2)

Corrida del 24/10/2026 con `php artisan test` sobre la base ya migrada y sembrada (`php artisan migrate --seed`): **214 pruebas, 1139 aserciones, todas aprobadas**.

| CU | Archivo de prueba | Pruebas | Filas del plan 8.1 | Resultado |
|---|---|---|---|---|
| CU-01 | `CU01_IniciarSesionTest` | 36 | 4 (más recuperación de contraseña, bloqueo de 60 s y `BrevoTransport`) | Aprobado |
| CU-02 | `CU02_CerrarSesionTest` | 5 | 2 | Aprobado |
| CU-03 | `CU03_GestionarUsuariosRolesTest` | 16 | 3 | Aprobado |
| CU-04 | `CU04_GestionarEspecialidadesMedicasTest` | 8 | 2 | Aprobado |
| CU-05 | `CU05_GestionarMedicosTest` | 16 | 4 | Aprobado |
| CU-06 | `CU06_GestionarHorariosDiasSinAtencionTest` | 17 | 5 | Aprobado |
| CU-07 | `CU07_GestionarPacientesTest` | 19 | 5 | Aprobado |
| CU-08 | `CU08_ConsultarDisponibilidadHorariosTest` | 14 | 5 | Aprobado |
| CU-09 | `CU09_RegistrarCitaMedicaTest` y `CU09_RegistrarCitaMedicaConcurrenciaTest` | 17 + 1 | 7 | Aprobado |
| CU-10 | `CU10_ReprogramarCitaMedicaTest` | 17 | 4 | Aprobado |
| CU-11 | `CU11_ReservarCitaEnLineaTest` | 16 | 4 | Aprobado |

Las otras 32 pruebas (base de datos, seguridad, assets, normalización de texto y preparación del despliegue) están en la tabla «Base técnica».

## Flujo alternativo A3 de CU-01 · Recuperar contraseña (texto sugerido para la ficha del documento)

No es un caso de uso nuevo: se agrega a la ficha de CU-01 (sección 4.3) y a su plan de pruebas (8.1). Controladores `OlvidoClaveController` y `RestablecerClaveController`, servicio `RecuperarClaveService`, tabla técnica `password_reset_tokens`.

**Flujo alternativo A3.** En el paso 2, el usuario elige «¿Olvidaste tu contraseña?». (A3.1) El sistema solicita el correo de la cuenta. (A3.2) El usuario lo ingresa. (A3.3) Si la cuenta existe y está activa, el sistema envía a ese correo un enlace con un token de un solo uso que vence a los 60 minutos; en todos los casos responde «Si el correo está registrado, te enviamos un enlace de recuperación». (A3.4) El usuario abre el enlace. (A3.5) El sistema solicita la contraseña nueva (mínimo 8 caracteres) y su confirmación. (A3.6) El usuario las ingresa. (A3.7) El sistema guarda la contraseña con hash bcrypt, invalida el token, desbloquea la cuenta si estaba bloqueada por intentos fallidos, registra la operación en la bitácora y muestra el inicio de sesión con «Contraseña actualizada. Ya puedes iniciar sesión.».

**Excepciones de A3.** Correo no registrado o cuenta inactiva: el sistema responde igual y no envía nada. Enlace vencido, ya usado o alterado: «El enlace de recuperación venció o ya fue usado. Solicita uno nuevo.» y se ofrece pedir otro. Contraseña de menos de 8 caracteres o confirmación distinta: el sistema indica el error junto al campo. Falla del servicio de correo: «No pudimos enviar el correo en este momento. Inténtalo de nuevo en unos minutos.». Más de 5 solicitudes por minuto desde un mismo equipo: el sistema las rechaza.

**Casos de prueba nuevos (8.1).** Solicitud con cuenta activa (se envía el enlace); con correo inexistente (misma respuesta, no se envía); con cuenta inactiva (no se envía); restablecer con token válido (entra con la clave nueva, no con la anterior); token inválido, vencido o reutilizado; contraseña corta o sin confirmación; el restablecimiento limpia el bloqueo.

