# Trazabilidad requisito → código → prueba

Una fila por caso de uso. «Prueba» es `backend/tests/Feature/CUxx_NombreTest.php`; el estado se actualiza al correr `php artisan test --filter=CUxx`.
Los nombres de CU-12 a CU-22 y de sus controladores y servicios son propuestos hasta que el documento los fije.

## Base técnica

| Elemento | Archivo | Prueba | Estado |
|---|---|---|---|
| Modelo físico (13 tablas + `migrations`), EXCLUDE, secuencias, trigger de bitácora | `backend/database/migrations/*` (desde `backend/database/schema.sql`) | `backend/tests/Feature/BaseDatosTest.php` | Aprobado |
| Usuario con `password_hash`, middleware `rol`, inicio público | `backend/app/Models/Usuario.php`, `backend/app/Http/Middleware/VerificarRol.php` | `backend/tests/Feature/BaseSeguridadTest.php` | Aprobado |
| Normalización de texto | `backend/app/Support/Texto.php` | `backend/tests/Unit/TextoTest.php` | Aprobado |
| Assets del frontend por la ruta `assets` (sin symlinks, anti path traversal, caché) | `backend/app/Http/Controllers/RecursoController.php`, `backend/app/Support/Recursos.php` | `backend/tests/Feature/RecursosTest.php` | Aprobado |

## Ciclo I

Los nombres de clase (CI, CC, CE) son los de los diagramas (`docs/CLASES_ANALISIS.md`). Backend: `backend/app/Modules/<Paquete>/<CU>/`; pantallas: `frontend/<Paquete>/<CU>/`; pruebas: `backend/tests/Feature/`. Las rutas de cada paquete están en `backend/app/Modules/<Paquete>/routes.php`.

| CU | RF | Paquete | CI (pantalla) | CC (controlador · servicio) | Rutas | CE (modelos) | Tablas | Prueba | Estado |
|---|---|---|---|---|---|---|---|---|---|
| CU-01 Iniciar sesión | RF01 | P1 SeguridadUsuarios | `CILogin` · `CU01_IniciarSesion/login`, `panel` | `CCLogin` · LoginController, PanelController · LoginService (LoginRequest) | `GET/POST /login`, `GET /panel` | `CEUsuario` (Usuario, Rol) | usuarios, rol_usuario | `CU01_IniciarSesionTest` | Aprobado |
| CU-02 Cerrar sesión | RF02 | P1 SeguridadUsuarios | `CILogout` · botón del menú (`layouts/app`) | `CCLogout` · LogoutController · LogoutService | `POST /logout` | `CEUsuario` (Usuario) | usuarios | `CU02_CerrarSesionTest` | Aprobado |
| CU-03 Gestionar usuarios y roles | RF03 | P1 SeguridadUsuarios | `CIUsuarios` · `CU03_GestionarUsuariosRoles/index`, `crear`, `editar`, `campos` | `CCUsuarios` · UsuariosController · UsuarioService (UsuarioRequest) | `/usuarios` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEUsuario` (Usuario), `CERol` (Rol) | usuarios, roles, rol_usuario | `CU03_GestionarUsuariosRolesTest` | Aprobado |
| CU-04 Gestionar especialidades médicas | RF04 | P2 GestionMedica | `CIEspecialidad` · `CU04_GestionarEspecialidadesMedicas/` | `CCEspecialidad` · EspecialidadController · EspecialidadService (EspecialidadRequest) | `/especialidades` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEEspecialidad` (Especialidad) | especialidades | `CU04_GestionarEspecialidadesMedicasTest` | Aprobado |
| CU-05 Gestionar médicos | RF05 | P2 GestionMedica | `CIMedicos` · `CU05_GestionarMedicos/` | `CCMedicos` · MedicosController · MedicoService (MedicoRequest) | `/medicos` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEMedico` (Medico), `CEEspecialidad` (Especialidad) | medicos, medico_especialidad | `CU05_GestionarMedicosTest` | Aprobado |
| CU-06 Gestionar horarios y días sin atención | RF06 | P2 GestionMedica | `CIHorarios` · `CU06_GestionarHorariosDiasSinAtencion/` | `CCHorarios` · HorariosController, DiasSinAtencionController · HorarioService, DiaSinAtencionService (HorarioRequest, DiaSinAtencionRequest) | `/horarios` (index, guardar, actualizar, desactivar, activar) y `/dias-sin-atencion` (index, guardar, desactivar, activar) | `CEHorario` (HorarioAtencion), `CEMedico` (Medico); BloqueoAgenda | horarios_atencion, bloqueos_agenda | `CU06_GestionarHorariosDiasSinAtencionTest` | Aprobado |
| CU-07 Gestionar pacientes | RF07 | P3 GestionPacientes | `CIPacientes` · `CU07_GestionarPacientes/` | `CCPacientes` · PacientesController · PacienteService (PacienteRequest) | `/pacientes` (index, crear, guardar, editar, actualizar, desactivar, activar) | `CEPaciente` (Paciente), `CEHistoriaClinica` (HistoriaClinica), `CEAntecedente` (AntecedenteExterno) | pacientes, historias_clinicas, antecedentes_externos | `CU07_GestionarPacientesTest` | Aprobado |
| CU-08 Consultar disponibilidad de horarios | RF08 | P4 GestionCitas | `CIDisponibilidad` · `CU08_ConsultarDisponibilidadHorarios/` | `CCDisponibilidad` · DisponibilidadController · DisponibilidadService (en `Compartido/`) | — | `CEMedico` (Medico), `CECita` (Cita) | horarios_atencion, citas, bloqueos_agenda | `CU08_ConsultarDisponibilidadHorariosTest` | Pendiente |
| CU-09 Registrar cita médica | RF09 | P4 GestionCitas | `CIRegistrarCita` · `CU09_RegistrarCitaMedica/` | `CCRegistrarCita` · RegistrarCitaController · RegistrarCitaService (usa DisponibilidadService) | — | `CEPaciente`, `CECita`, `CEMedico` | citas | `CU09_RegistrarCitaMedicaTest` | Pendiente |
| CU-10 Reprogramar cita médica | RF10 | P4 GestionCitas | `CIReprogramarCita` · `CU10_ReprogramarCitaMedica/` (y listado de citas) | `CCReprogramarCita` · ReprogramarCitaController · ReprogramarCitaService (usa DisponibilidadService) | — | `CECita`, `CEMedico` | citas | `CU10_ReprogramarCitaMedicaTest` | Pendiente |
| CU-11 Reservar cita en línea | RF11 | P4 GestionCitas | `CIReservarCita` · `CU11_ReservarCitaEnLinea/` | `CCReservarCita` · ReservarCitaController · ReservarCitaService (usa DisponibilidadService) | — | `CECita`, `CEMedico` | citas | `CU11_ReservarCitaEnLineaTest` | Pendiente |

## Ciclo II

| CU | RF | Paquete | Vista | Controlador | Tablas | Prueba | Estado |
|---|---|---|---|---|---|---|---|
| CU-12 Cancelar cita médica | RF12 | P4 Gestión de Citas | citas/index | CitaController | citas | GestionCitas/CU12Test | Pendiente |
| CU-13 Controlar asistencia y estados de la cita | RF13 | P4 Gestión de Citas | citas/index | CitaController | citas | GestionCitas/CU13Test | Pendiente |
| CU-14 Consultar agenda médica | RF14 | P4 Gestión de Citas | agenda/index | AgendaController | citas | GestionCitas/CU14Test | Pendiente |
| CU-15 Consultar historial de citas del paciente | RF15 | P3 Gestión de Pacientes | pacientes/historial | PacienteController | citas | GestionPacientes/CU15Test | Pendiente |
| CU-16 Registrar consulta y tratamiento | RF16 | P5 Atención Clínica | consultas/registrar | ConsultaController | consultas | AtencionClinica/CU16Test | Pendiente |
| CU-17 Consultar historia clínica | RF17 | P5 Atención Clínica | historias/ver | HistoriaClinicaController | historias_clinicas, consultas, antecedentes_externos | AtencionClinica/CU17Test | Pendiente |
| CU-18 Generar reportes | RF18 | P6 Reportes y Bitácora | reportes/index | ReporteController | citas, pacientes | ReportesBitacora/CU18Test | Pendiente |
| CU-19 Consultar bitácora | RF19 | P6 Reportes y Bitácora | bitacora/index | BitacoraController | bitacora | ReportesBitacora/CU19Test | Pendiente |
| CU-20 Registrarse como paciente | RF20 | P3 Gestión de Pacientes | auth/registro | RegistroPacienteController | usuarios, rol_usuario, pacientes, historias_clinicas | GestionPacientes/CU20Test | Pendiente |
| CU-21 Gestionar mi perfil | RF21 | P3 Gestión de Pacientes | perfil/index | PerfilController | pacientes, historias_clinicas, usuarios | GestionPacientes/CU21Test | Pendiente |
| CU-22 Enviar recordatorios de citas | RF22 | P4 Gestión de Citas | (comando programado) | `citas:enviar-recordatorios` | citas, pacientes | GestionCitas/CU22Test | Pendiente |
