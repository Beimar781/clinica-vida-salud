# Clases de análisis (sección 5.3 del documento v30)

Nombres exactos de las clases de interfaz (CI), control (CC) y entidad (CE) de cada caso de uso, tal como aparecen en los diagramas de clases de análisis del documento v30. Se usan en los comentarios del código (`@CU`, `@CI`, `@CC`, `@CE`) y en la trazabilidad.

Correspondencia con Laravel: CI = vistas Blade del CU, CC = controlador + servicio del CU, CE = modelo Eloquent (`CEPaciente` → `Paciente`, `CEHorario` → `HorarioAtencion`, `CEAntecedente` → `AntecedenteExterno`, `CEBitacora` → `Bitacora`, etc.).

## Ciclo I

| CU | Actor | CI (boundary) | CC (control) | CE (entity) |
|---|---|---|---|---|
| CU-01 Iniciar sesión | Usuario | `CILogin`: mostrarFormulario, enviarCredenciales, mostrarPanel, mostrarError | `CCLogin`: autenticar, validarIntentos, verificarClave | `CEUsuario`: buscarPorCorreo, registrarAcceso |
| CU-02 Cerrar sesión | Usuario | `CILogout`: solicitarCierre, mostrarInicio | `CCLogout`: cerrar, invalidarSesion | `CEUsuario`: registrarAcceso |
| CU-03 Gestionar usuarios y roles | Administrador | `CIUsuarios`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCUsuarios`: crear, guardar, editar, actualizar, desactivar | `CEUsuario`: crear, actualizar, desactivar · `CERol`: listar (un usuario tiene 1..* roles) |
| CU-04 Gestionar especialidades y servicios | Administrador | `CIEspecialidad`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado (atributos nombre, descripcion) | `CCEspecialidad`: crear, guardar, editar, actualizar, desactivar | `CEEspecialidad` (atributos nombre, **tipo, precio**, descripcion, estado): existeNombre, crear, actualizar, desactivar |
| CU-05 Gestionar médicos | Administrador | `CIMedicos`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCMedicos`: crear, guardar, editar, actualizar, desactivar | `CEMedico`: obtener, crear, actualizar, desactivar · `CEEspecialidad`: listarActivas (un médico ejerce 1..* especialidades) |
| CU-06 Gestionar horarios y días sin atención | Administrador | `CIHorarios`: mostrarAgenda, mostrarFormulario, enviarDatos, mostrarResultado | `CCHorarios`: crear, guardar, validarHoras, actualizar, desactivar | `CEHorario`: haySuperposicion, crear, actualizar, desactivar · `CEMedico`: obtener (un médico atiende en 0..* horarios) |
| CU-07 Gestionar pacientes | Recepcionista / Administrador | `CIPacientes`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCPacientes`: crear, guardar, editar, actualizar, buscar | `CEPaciente`: existeCI, crear, actualizar, buscar · `CEHistoriaClinica`: crearParaPaciente · `CEAntecedente`: crear |
| CU-08 Consultar disponibilidad de horarios | Recepcionista / Paciente | `CIDisponibilidad`: elegirMedico, elegirFecha, mostrarHorarios, mostrarMensaje | `CCDisponibilidad`: calcularLibres, verificarDia | `CEMedico`: horariosLibres · `CECita`: citasDelDia |
| CU-09 Registrar cita médica | Recepcionista | `CIRegistrarCita` (atributos paciente, especialidad, medico, fecha, modalidad): buscarPaciente, mostrarHorarios, confirmarCita, mostrarComprobante | `CCRegistrarCita`: buscar, calcularLibres, validarReglas, registrar | `CEPaciente`: buscar · `CECita`: citasDelDia, crear, estaLibre · `CEMedico`: horariosLibres |
| CU-10 Reprogramar cita médica | Recepcionista | `CIReprogramarCita` (codigo_cita, fecha, horario, motivo): buscarCita, mostrarHorarios, confirmarCambio, mostrarComprobante | `CCReprogramarCita`: buscar, calcularLibres, reprogramar | `CECita`: obtener, citasDelDia, reprogramar · `CEMedico`: horariosLibres |
| CU-11 Reservar cita en línea | Paciente | `CIReservarCita` (especialidad, ubicacion, medico, fecha, modalidad): buscarMedicos, mostrarHorarios, confirmarReserva, mostrarComprobante | `CCReservarCita`: buscar, calcularLibres, reservar | `CECita`: citasDelDia, crear, estaLibre · `CEMedico`: buscar, horariosLibres |

## Ciclo II

| CU | Actor | CI (boundary) | CC (control) | CE (entity) |
|---|---|---|---|---|
| CU-12 Cancelar cita médica | Recepcionista / Paciente | `CICancelarCita` (codigo_cita, motivo): buscarCita, pedirMotivo, confirmarCancelacion, mostrarResultado | `CCCancelarCita`: buscar, verificarPermiso, cancelar | `CECita`: obtener, cancelar |
| CU-13 Controlar asistencia y estados de la cita | Recepcionista / Médico / Administrador | `CIEstadoCita` (fecha, cita, accion, justificacion): mostrarAgenda, elegirAccion, mostrarResultado | `CCEstadoCita`: listar, validarTransicion, cambiarEstado | `CECita`: citasDelDia, obtener, cambiarEstado |
| CU-14 Consultar agenda médica | Usuario | `CIAgenda` (medico, fecha, estado): elegirFiltros, mostrarAgenda | `CCAgenda`: consultar, filtrar | `CECita`: citasPorPeriodo · `CEMedico`: horariosLibres (un médico atiende 0..* citas) |
| CU-15 Consultar historial de citas del paciente | Usuario | `CIHistorial` (ci, estado, desde, hasta): buscarPaciente, aplicarFiltros, mostrarHistorial | `CCHistorial`: buscar, consultar, filtrar | `CEPaciente`: buscar · `CECita`: citasDelPaciente (un paciente solicita 0..* citas) |
| CU-16 Registrar consulta y tratamiento | Médico | `CIConsulta` (diagnostico, tratamiento, observaciones): mostrarCita, enviarConsulta, mostrarResultado | `CCConsulta`: abrir, registrar | `CECita`: obtener, cambiarEstado · `CEConsulta` (diagnostico, tratamiento, observaciones, fecha_registro): crear, obtenerPorCita · `CEHistoriaClinica`: obtenerPorPaciente (una cita origina 0..1 consulta; una historia contiene 0..* consultas) |
| CU-17 Consultar historia clínica | Médico | `CIHistoriaClinica` (ci): buscarPaciente, mostrarHistoria | `CCHistoriaClinica`: buscar, consultar | `CEHistoriaClinica`: obtenerPorPaciente · `CEConsulta`: consultasDeHistoria · `CEAntecedente`: antecedentesDeHistoria (una historia incluye 0..* antecedentes) |
| CU-18 Generar reportes con IA | Administrador / Recepcionista | `CIReportes` (tipo, desde, hasta, medico, estado): elegirFiltros, mostrarReporte, generarConIA, exportar | `CCReportes`: generar, calcularTotales, resumirConIA, exportar | `CECita`: citasPorPeriodo, totalesPorPeriodo · `CEPaciente`: listarRegistrados · `CEMedico`: listar |
| CU-19 Consultar bitácora | Administrador | `CIBitacora` (usuario, accion, desde, hasta): elegirFiltros, mostrarEventos | `CCBitacora`: consultar | `CEBitacora` (accion, entidad, detalle, fecha_hora): registrar, consultar |
| CU-20 Registrarse como paciente | Paciente | `CIRegistro` (ci, nombres, apellidos, fecha_nacimiento, correo, clave): mostrarFormulario, validarDatos, enviarRegistro, mostrarPanel | `CCRegistro`: registrar, verificarCI, crearCuenta | `CEUsuario`: crear · `CEPaciente`: existeCI, crear · `CEHistoriaClinica`: crearParaPaciente (un usuario accede como 0..1 paciente; un paciente tiene 1 historia) |
| CU-21 Gestionar mi perfil | Paciente | `CIPerfil` (telefono, tipo_sangre, alergias, notificaciones, clave): mostrarPerfil, validarDatos, enviarCambios, mostrarResultado | `CCPerfil`: consultar, actualizar, cambiarClave | `CEPaciente`: obtener, actualizarPerfil · `CEHistoriaClinica`: obtenerPorPaciente, actualizarDatosMedicos · `CEUsuario`: cambiarClave |
| CU-22 Enviar recordatorios de citas | Temporizador (tarea programada) | `CIRecordatorio` (asunto, mensaje): armarAviso, enviarCorreo | `CCRecordatorio`: enviar, verificarNotificaciones | `CEPaciente`: obtener · `CECita`: citasParaRecordar, marcarRecordatorio |

## Notas

- El modelo físico es el de `backend/database/schema.sql` (sección 6.3.3 del v30). En los diagramas el atributo del usuario se llama `password`; en la base de datos es `password_hash`. En la interfaz de horarios `duracion_min` se muestra como «duración». En `CEHistoriaClinica` el atributo del diagrama `antecedentes` es la columna `antecedentes_personales`.
- `CEMedico::horariosLibres` y `CCDisponibilidad::calcularLibres` se implementan una sola vez en `DisponibilidadService`, que reutilizan CU-09, CU-10, CU-11 y CU-14.
- Los diagramas de comunicación (5.2) y de secuencia (6.2) usan los mismos nombres de mensajes que los métodos de esta tabla.
- Métodos del diagrama que Laravel o un servicio compartido resuelven sin un método con ese nombre: `CEMedico::obtener` y `CECita::obtener` son `Medico::find()` y `Cita::find()`; `CEMedico::horariosLibres` es `DisponibilidadService::calcularLibres()`; `CECita::estaLibre` es la verificación dentro de la transacción de `AgendarCitaService` (con el bloqueo del médico) más las dos restricciones `EXCLUDE` de la base de datos; `CECita::cambiarEstado` es `TransicionesCita` (Compartido de P4).
- Los diagramas de CU-09, CU-10, CU-11 y CU-13 no muestran los atributos de pago (`monto`, `metodo_pago`, `estado_pago`, `referencia_pago`) ni la clase `CEEspecialidad` con su precio; el código sí los usa (anotado en `docs/INCONSISTENCIAS.md` para actualizar los diagramas).
- Controlador = nombre del CC sin el prefijo «CC» + `Controller` (`CCEstadoCita` → `EstadoCitaController`, `CCRegistro` → `RegistroController`, `CCHistorial` → `HistorialController`). Servicio = `<Nombre>Service` (los de CU-12 a CU-22 se nombran en `CLAUDE.md`, sección 4).
- Recuperar contraseña (flujo alternativo A3 de CU-01, no es un CU nuevo y no está en el documento): `OlvidoClaveController` y `RestablecerClaveController` con `RecuperarClaveService`, dentro de `CU01_IniciarSesion/`. Sus clases de análisis se agregan a `CILogin`/`CCLogin`/`CEUsuario` (mostrarOlvido, mostrarRestablecer, solicitarRecuperacion, restablecerClave).

## Correspondencia con el código (auditoría del Prompt 9, 07/11/2026)

Para cada caso de uso: las clases del análisis y el archivo que las implementa. Las vistas están en `frontend/<Paquete>/<CU>/` y el controlador, el servicio y la validación en `backend/app/Modules/<Paquete>/<CU>/`.

| CU | CI → pantallas (Blade) | CC → controlador · servicio | CE → modelo (`backend/app/Models`) |
|---|---|---|---|
| CU-01 | CILogin → `CU01_IniciarSesion/`: correo-recuperacion, correo-recuperacion-texto, login, olvido, panel, restablecer | CCLogin → LoginController, OlvidoClaveController, PanelController, RestablecerClaveController · LoginService, RecuperarClaveService | CEUsuario → Usuario |
| CU-02 | CILogout → `CU02_CerrarSesion/`: botón «Cerrar sesión» del layout | CCLogout → LogoutController · LogoutService | CEUsuario → Usuario |
| CU-03 | CIUsuarios → `CU03_GestionarUsuariosRoles/`: campos, crear, editar, index | CCUsuarios → UsuariosController · UsuarioService | CEUsuario, CERol → Usuario, Rol |
| CU-04 | CIEspecialidad → `CU04_GestionarEspecialidadesServicios/`: campos, crear, editar, index | CCEspecialidad → EspecialidadController · EspecialidadService | CEEspecialidad → Especialidad |
| CU-05 | CIMedicos → `CU05_GestionarMedicos/`: campos, crear, editar, index | CCMedicos → MedicosController · MedicoService | CEMedico, CEEspecialidad → Medico, Especialidad |
| CU-06 | CIHorarios → `CU06_GestionarHorariosDiasSinAtencion/`: dias-sin-atencion, horarios | CCHorarios → DiasSinAtencionController, HorariosController · DiaSinAtencionService, HorarioService | CEHorario, CEMedico → HorarioAtencion, Medico |
| CU-07 | CIPacientes → `CU07_GestionarPacientes/`: campos, crear, editar, index | CCPacientes → PacientesController · PacienteService | CEPaciente, CEHistoriaClinica, CEAntecedente → Paciente, HistoriaClinica, AntecedenteExterno |
| CU-08 | CIDisponibilidad → `CU08_ConsultarDisponibilidadHorarios/`: disponibilidad | CCDisponibilidad → DisponibilidadController · Compartido/DisponibilidadService | CEMedico, CECita → Medico, Cita |
| CU-09 | CIRegistrarCita → `CU09_RegistrarCitaMedica/`: comprobante, registrar | CCRegistrarCita → RegistrarCitaController · RegistrarCitaService, (Compartido: AgendarCitaService, TransicionesCita, PagoCitaService) | CEPaciente, CECita, CEMedico → Paciente, Cita, Medico |
| CU-10 | CIReprogramarCita → `CU10_ReprogramarCitaMedica/`: buscar, comprobante, reprogramar | CCReprogramarCita → ReprogramarCitaController · ReprogramarCitaService, (Compartido: AgendarCitaService, TransicionesCita) | CECita, CEMedico → Cita, Medico |
| CU-11 | CIReservarCita → `CU11_ReservarCitaEnLinea/`: buscar, comprobante, reservar | CCReservarCita → ReservarCitaController · ReservarCitaService, (Compartido: AgendarCitaService, TransicionesCita, PagoCitaService) | CECita, CEMedico → Cita, Medico |
| CU-12 | CICancelarCita → `CU12_CancelarCitaMedica/`: cancelar | CCCancelarCita → CancelarCitaController · CancelarCitaService, (Compartido: AgendarCitaService, TransicionesCita) | CECita → Cita |
| CU-13 | CIEstadoCita → `CU13_ControlarAsistenciaEstadosCita/`: control | CCEstadoCita → EstadoCitaController · EstadoCitaService, (Compartido: AgendarCitaService, TransicionesCita) | CECita → Cita |
| CU-14 | CIAgenda → `CU14_ConsultarAgendaMedica/`: agenda | CCAgenda → AgendaController · AgendaService | CECita, CEMedico → Cita, Medico |
| CU-15 | CIHistorial → `CU15_ConsultarHistorialCitasPaciente/`: historial | CCHistorial → HistorialController · HistorialCitasService | CEPaciente, CECita → Paciente, Cita |
| CU-16 | CIConsulta → `CU16_RegistrarConsultaTratamiento/`: registrar | CCConsulta → ConsultaController · ConsultaService | CECita, CEConsulta, CEHistoriaClinica → Cita, Consulta, HistoriaClinica |
| CU-17 | CIHistoriaClinica → `CU17_ConsultarHistoriaClinica/`: ver | CCHistoriaClinica → HistoriaClinicaController · HistoriaClinicaService | CEHistoriaClinica, CEConsulta, CEAntecedente → HistoriaClinica, Consulta, AntecedenteExterno |
| CU-18 | CIReportes → `CU18_GenerarReportesConIA/`: index, pdf | CCReportes → ReportesController · ReporteService, ResumenIAService | CECita, CEPaciente, CEMedico → Cita, Paciente, Medico |
| CU-19 | CIBitacora → `CU19_ConsultarBitacora/`: index | CCBitacora → BitacoraController · ConsultaBitacoraService | CEBitacora → Bitacora |
| CU-20 | CIRegistro → `CU20_RegistrarsePaciente/`: registro | CCRegistro → RegistroController · RegistroPacienteService | CEUsuario, CEPaciente, CEHistoriaClinica → Usuario, Paciente, HistoriaClinica |
| CU-21 | CIPerfil → `CU21_GestionarMiPerfil/`: perfil | CCPerfil → PerfilController · PerfilService | CEPaciente, CEHistoriaClinica, CEUsuario → Paciente, HistoriaClinica, Usuario |
| CU-22 | CIRecordatorio → `CU22_EnviarRecordatoriosCitas/`: correo-recordatorio (correo; no hay pantallas) | CCRecordatorio → EnviarRecordatoriosCommand · RecordatorioService | CEPaciente, CECita → Paciente, Cita |

### Observaciones de la auditoría

- Los 22 CU tienen su clase de control con el nombre del CC sin el prefijo «CC»; `CCRecordatorio` (CU-22) es un comando programado (`citas:enviar-recordatorios`) en vez de un controlador web, porque su actor es el Temporizador.
- `CEHorario` → `HorarioAtencion` y `CEAntecedente` → `AntecedenteExterno` (nombres de las tablas `horarios_atencion` y `antecedentes_externos` del modelo físico).
- CU-18 incluye además `ResumenIAService` (`CCReportes::resumirConIA`) y un asistente de voz en el navegador (`frontend/assets/js/modulos/reportes-voz.js`), que no está en los diagramas.
- CU-20 y CU-21 comparten el paquete P3 con CU-07 y CU-15; CU-21 no tiene `CEHistoriaClinica::obtenerPorPaciente` con ese nombre: se usa la relación `Paciente::historiaClinica`.
- Sugerencias para los diagramas 5.3 (detalladas en `docs/INCONSISTENCIAS.md`): atributos de pago y `CEEspecialidad` con su precio en CU-09, CU-10, CU-11 y CU-13; `especialidad` y modalidad de IA en `CIReportes`; dirección, emergencia y privacidad en `CIPerfil`; sexo y teléfono en `CIRegistro`; `paciente` y `cita` en `CIConsulta`.
