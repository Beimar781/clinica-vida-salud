# Trazabilidad requisito → código → prueba

Una fila por caso de uso. «Prueba» es `tests/Feature/<Módulo>/CUxxTest.php`; el estado se actualiza al correr `php artisan test --filter=CUxx`.
Los nombres de CU-12 a CU-22 y de sus controladores y servicios son propuestos hasta que el documento los fije.

## Base técnica

| Elemento | Archivo | Prueba | Estado |
|---|---|---|---|
| Modelo físico (13 tablas + `migrations`), EXCLUDE, secuencias, trigger de bitácora | `database/migrations/*` (desde `database/schema.sql`) | `tests/Feature/BaseDatosTest.php` | Aprobado |
| Usuario con `password_hash`, middleware `rol`, inicio público | `app/Modules/Seguridad/Models`, `app/Support/Middleware/VerificarRol.php` | `tests/Feature/BaseSeguridadTest.php` | Aprobado |
| Normalización de texto | `app/Support/Texto.php` | `tests/Unit/TextoTest.php` | Aprobado |

## Ciclo I

| CU | RF | Paquete | Vista | Controlador | Servicio | Modelos | Tablas | Prueba | Estado |
|---|---|---|---|---|---|---|---|---|---|
| CU-01 Iniciar sesión | RF01 | P1 Seguridad y Usuarios | auth/login | AutenticacionController | AutenticacionService | Usuario, Rol | usuarios, rol_usuario | Seguridad/CU01Test | Pendiente |
| CU-02 Cerrar sesión | RF02 | P1 Seguridad y Usuarios | (panel) | AutenticacionController | AutenticacionService | Usuario | usuarios | Seguridad/CU02Test | Pendiente |
| CU-03 Gestionar usuarios y roles | RF03 | P1 Seguridad y Usuarios | usuarios/index | UsuarioController | UsuarioService | Usuario, Rol | usuarios, roles, rol_usuario | Seguridad/CU03Test | Pendiente |
| CU-04 Gestionar especialidades médicas | RF04 | P2 Gestión Médica | especialidades/index | EspecialidadController | EspecialidadService | Especialidad | especialidades | GestionMedica/CU04Test | Pendiente |
| CU-05 Gestionar médicos | RF05 | P2 Gestión Médica | medicos/index | MedicoController | MedicoService | Medico, Especialidad, Usuario | medicos, medico_especialidad | GestionMedica/CU05Test | Pendiente |
| CU-06 Gestionar horarios y días sin atención | RF06 | P2 Gestión Médica | horarios/index, bloqueos/index | HorarioController | HorarioService | HorarioAtencion, BloqueoAgenda, Cita | horarios_atencion, bloqueos_agenda | GestionMedica/CU06Test | Pendiente |
| CU-07 Gestionar pacientes | RF07 | P3 Gestión de Pacientes | pacientes/index | PacienteController | PacienteService | Paciente, HistoriaClinica, AntecedenteExterno | pacientes, historias_clinicas, antecedentes_externos | GestionPacientes/CU07Test | Pendiente |
| CU-08 Consultar disponibilidad de horarios | RF08 | P4 Gestión de Citas | citas/disponibilidad | DisponibilidadController | DisponibilidadService | HorarioAtencion, BloqueoAgenda, Cita | horarios_atencion, citas, bloqueos_agenda | GestionCitas/CU08Test | Pendiente |
| CU-09 Registrar cita médica | RF09 | P4 Gestión de Citas | citas/registrar | CitaController | CitaService | Cita, Paciente, Medico | citas | GestionCitas/CU09Test | Pendiente |
| CU-10 Reprogramar cita médica | RF10 | P4 Gestión de Citas | citas/reprogramar | CitaController | CitaService | Cita | citas | GestionCitas/CU10Test | Pendiente |
| CU-11 Reservar cita en línea | RF11 | P4 Gestión de Citas | reservas/crear | ReservaController | ReservaService | Cita, Paciente, Medico | citas | GestionCitas/CU11Test | Pendiente |

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
