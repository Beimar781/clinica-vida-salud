# Trazabilidad documento v30 ↔ código ↔ pruebas

Una fila por caso de uso con su requisito, paquete, pantallas, controlador y servicio, modelos, tablas, archivo de pruebas y estado. De aquí salen las tablas 8.1 y 8.2 del documento. Cada fila del plan 8.1 es un método `paso_N_...` del archivo `backend/tests/Feature/CUxx_*Test.php`, en el mismo orden del documento (comprobado con un recuento automático: 22 de 22 casos de uso coinciden, 93 filas); además hay pruebas de reglas internas (permisos, validaciones, bitácora).

Estado: **Hecho** = implementado, con todas sus pruebas aprobadas. Las vistas están en `frontend/<Paquete>/<CU>/` y el código en `backend/app/Modules/<Paquete>/<CU>/`.

## 1. Casos de uso

| CU | Nombre (v30) | Ciclo | RF | Paquete / carpeta | Vistas | Controlador · Servicio | Modelos (CE) | Tablas | Prueba | Filas 8.1 | Resultado | Estado |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| CU-01 | Iniciar sesión | I | RF01 | `SeguridadUsuarios/CU01_IniciarSesion` | correo-recuperacion, correo-recuperacion-texto, login, olvido, panel, restablecer | LoginController, OlvidoClaveController, PanelController, RestablecerClaveController · LoginService, RecuperarClaveService | Usuario, Rol | usuarios, rol_usuario, bitacora, password_reset_tokens | CU01_IniciarSesionTest.php | 4 | 39 pruebas, 206 aserciones: **Aprobado** | Hecho |
| CU-02 | Cerrar sesión | I | RF02 | `SeguridadUsuarios/CU02_CerrarSesion` | (botón del layout) | LogoutController · LogoutService | Usuario | usuarios | CU02_CerrarSesionTest.php | 2 | 6 pruebas, 21 aserciones: **Aprobado** | Hecho |
| CU-03 | Gestionar usuarios y roles | I | RF03 | `SeguridadUsuarios/CU03_GestionarUsuariosRoles` | campos, crear, editar, index | UsuariosController · UsuarioService | Usuario, Rol | usuarios, roles, rol_usuario | CU03_GestionarUsuariosRolesTest.php | 3 | 18 pruebas, 100 aserciones: **Aprobado** | Hecho |
| CU-04 | Gestionar especialidades y servicios | I | RF04 | `GestionMedica/CU04_GestionarEspecialidadesServicios` | campos, crear, editar, index | EspecialidadController · EspecialidadService | Especialidad | especialidades | CU04_GestionarEspecialidadesServiciosTest.php | 3 | 18 pruebas, 141 aserciones: **Aprobado** | Hecho |
| CU-05 | Gestionar médicos | I | RF05 | `GestionMedica/CU05_GestionarMedicos` | campos, crear, editar, index | MedicosController · MedicoService | Medico, Especialidad, Usuario | medicos, medico_especialidad, usuarios | CU05_GestionarMedicosTest.php | 4 | 16 pruebas, 94 aserciones: **Aprobado** | Hecho |
| CU-06 | Gestionar horarios y días sin atención | I | RF06 | `GestionMedica/CU06_GestionarHorariosDiasSinAtencion` | dias-sin-atencion, horarios | DiasSinAtencionController, HorariosController · DiaSinAtencionService, HorarioService | HorarioAtencion, BloqueoAgenda | horarios_atencion, bloqueos_agenda | CU06_GestionarHorariosDiasSinAtencionTest.php | 5 | 25 pruebas, 146 aserciones: **Aprobado** | Hecho |
| CU-07 | Gestionar pacientes | I | RF07 | `GestionPacientes/CU07_GestionarPacientes` | campos, crear, editar, index | PacientesController · PacienteService | Paciente, HistoriaClinica, AntecedenteExterno | pacientes, historias_clinicas, antecedentes_externos | CU07_GestionarPacientesTest.php | 5 | 19 pruebas, 127 aserciones: **Aprobado** | Hecho |
| CU-08 | Consultar disponibilidad de horarios | I | RF08 | `GestionCitas/CU08_ConsultarDisponibilidadHorarios` | disponibilidad | DisponibilidadController · (Compartido/DisponibilidadService) | HorarioAtencion, Cita, BloqueoAgenda | horarios_atencion, citas, bloqueos_agenda | CU08_ConsultarDisponibilidadHorariosTest.php | 5 | 14 pruebas, 61 aserciones: **Aprobado** | Hecho |
| CU-09 | Registrar cita médica | I | RF09 | `GestionCitas/CU09_RegistrarCitaMedica` | comprobante, registrar | RegistrarCitaController · RegistrarCitaService | Cita, Paciente, Medico, Especialidad | citas | CU09_RegistrarCitaMedicaConcurrenciaTest.php, CU09_RegistrarCitaMedicaTest.php | 8 | 41 pruebas, 297 aserciones: **Aprobado** | Hecho |
| CU-10 | Reprogramar cita médica | I | RF10 | `GestionCitas/CU10_ReprogramarCitaMedica` | buscar, comprobante, reprogramar | ReprogramarCitaController · ReprogramarCitaService | Cita | citas | CU10_ReprogramarCitaMedicaTest.php | 6 | 26 pruebas, 211 aserciones: **Aprobado** | Hecho |
| CU-11 | Reservar cita en línea | I | RF11 | `GestionCitas/CU11_ReservarCitaEnLinea` | buscar, comprobante, reservar | ReservarCitaController · ReservarCitaService | Cita, Paciente | citas | CU11_ReservarCitaEnLineaTest.php | 7 | 36 pruebas, 256 aserciones: **Aprobado** | Hecho |
| CU-12 | Cancelar cita médica | II | RF12 | `GestionCitas/CU12_CancelarCitaMedica` | cancelar | CancelarCitaController · CancelarCitaService | Cita | citas | CU12_CancelarCitaMedicaTest.php | 4 | 13 pruebas, 95 aserciones: **Aprobado** | Hecho |
| CU-13 | Controlar asistencia y estados de la cita | II | RF13 | `GestionCitas/CU13_ControlarAsistenciaEstadosCita` | control | EstadoCitaController · EstadoCitaService | Cita | citas, bitacora | CU13_ControlarAsistenciaEstadosCitaTest.php | 6 | 26 pruebas, 154 aserciones: **Aprobado** | Hecho |
| CU-14 | Consultar agenda médica | II | RF14 | `GestionCitas/CU14_ConsultarAgendaMedica` | agenda | AgendaController · AgendaService | Cita, HorarioAtencion | citas, horarios_atencion | CU14_ConsultarAgendaMedicaTest.php | 4 | 15 pruebas, 146 aserciones: **Aprobado** | Hecho |
| CU-15 | Consultar historial de citas del paciente | II | RF15 | `GestionPacientes/CU15_ConsultarHistorialCitasPaciente` | historial | HistorialController · HistorialCitasService | Cita, Paciente | citas, pacientes | CU15_ConsultarHistorialCitasPacienteTest.php | 2 | 14 pruebas, 142 aserciones: **Aprobado** | Hecho |
| CU-16 | Registrar consulta y tratamiento | II | RF16 | `AtencionClinica/CU16_RegistrarConsultaTratamiento` | registrar | ConsultaController · ConsultaService | Consulta, Cita, HistoriaClinica | consultas, citas, historias_clinicas | CU16_RegistrarConsultaTratamientoTest.php | 3 | 18 pruebas, 170 aserciones: **Aprobado** | Hecho |
| CU-17 | Consultar historia clínica | II | RF17 | `AtencionClinica/CU17_ConsultarHistoriaClinica` | ver | HistoriaClinicaController · HistoriaClinicaService | HistoriaClinica, Consulta, AntecedenteExterno | historias_clinicas, consultas, antecedentes_externos | CU17_ConsultarHistoriaClinicaTest.php | 3 | 12 pruebas, 106 aserciones: **Aprobado** | Hecho |
| CU-18 | Generar reportes con IA | II | RF18 | `ReportesBitacora/CU18_GenerarReportesConIA` | index, pdf | ReportesController · ReporteService, ResumenIAService | Cita, Paciente, Medico, Especialidad | citas, pacientes, historias_clinicas, medicos, especialidades | CU18_GenerarReportesConIATest.php | 7 | 35 pruebas, 395 aserciones: **Aprobado** | Hecho |
| CU-19 | Consultar bitácora | II (adelantado) | RF19 | `ReportesBitacora/CU19_ConsultarBitacora` | index | BitacoraController · ConsultaBitacoraService | Bitacora | bitacora | CU19_ConsultarBitacoraTest.php | 2 | 25 pruebas, 195 aserciones: **Aprobado** | Hecho |
| CU-20 | Registrarse como paciente | II | RF20 | `GestionPacientes/CU20_RegistrarsePaciente` | registro | RegistroController · RegistroPacienteService | Usuario, Paciente, HistoriaClinica | usuarios, rol_usuario, pacientes, historias_clinicas | CU20_RegistrarsePacienteTest.php | 4 | 24 pruebas, 149 aserciones: **Aprobado** | Hecho |
| CU-21 | Gestionar mi perfil | II | RF21 | `GestionPacientes/CU21_GestionarMiPerfil` | perfil | PerfilController · PerfilService | Paciente, HistoriaClinica, Usuario | pacientes, historias_clinicas, usuarios | CU21_GestionarMiPerfilTest.php | 4 | 23 pruebas, 189 aserciones: **Aprobado** | Hecho |
| CU-22 | Enviar recordatorios de citas | II | RF22 | `GestionCitas/CU22_EnviarRecordatoriosCitas` | correo-recordatorio, correo-recordatorio-texto, (correo) | EnviarRecordatoriosCommand · RecordatorioService, RecordatorioCitaMail | Cita, Paciente | citas, pacientes | CU22_EnviarRecordatoriosCitasTest.php | 2 | 16 pruebas, 117 aserciones: **Aprobado** | Hecho |

Pruebas que no son de un CU: `BaseDatosTest` (17: migraciones, `EXCLUDE`, secuencias, trigger de la bitácora, modelo del v30), `BaseSeguridadTest` (9), `DespliegueTest` (19: seeders idempotentes y arranque), `PagosTest` (14: API de Stripe simulada), `RecursosTest` (5) y `TextoTest` (1).

Notas: CU-04 pasó de «especialidades médicas» a «especialidades y servicios»; CU-09 tiene además `CU09_RegistrarCitaMedicaConcurrenciaTest` (crea y borra sus propios datos porque la concurrencia no se puede probar dentro de una transacción); CU-19 se implementó antes de su ciclo; CU-22 no tiene pantallas (comando `citas:enviar-recordatorios` y correo).

## 2. Tareas técnicas

| Tarea | Prompt | Estado |
|---|---|---|
| Migraciones v30 (`tipo`, `precio`, `registrado_por`, datos de pago en `citas`, tabla `consultas`) y `schema.sql` nuevo | 0 | Hecho (07/11) |
| Datos de demostración v30 (precios, laboratorio, enfermería, ficha sin cuenta, citas por estado y pago, consultas) | 0, 5 | Hecho (07/11) |
| Pago con tarjeta (Stripe Checkout, captura manual) y efectivo | 2 | Hecho (07/11): probado también con Stripe real en modo de prueba |
| Proveedor de IA para el resumen de reportes (Google Gemini, modelo `gemini-3.5-flash-lite`) | 7 | Hecho (07/11): probado también con Gemini real |
| PDF de reportes con dompdf (barryvdh/laravel-dompdf 3.1.2) | 7 | Hecho (07/11) |
| Asistente de voz de «Reportes» (extra, navegador) | 7 | Hecho (07/11) |
| Programador y comando `citas:enviar-recordatorios` | 8 | Hecho (07/11) |
| Servicio cron en Railway para los recordatorios | 10 | Documentado en el README (comando de inicio, horario `0 22 * * *` = 18:00 en La Paz, variables); lo crea el equipo en Railway |
| Auditoría documento ↔ código y prueba general | 9 | Hecho (07/11) |
| Redespliegue en Railway con variables de Stripe, IA y Brevo | 10 | Dockerfile revisado (memoria del PDF, comprobación de extensiones), arranque probado con base nueva y con datos del Ciclo I, README actualizado; verificado en línea con lecturas: **la IA no responde en producción** (revisar `GEMINI_API_KEY` e `IA_MODELO`) |

### Base técnica (Prompt 0, 07/11/2026)

| Elemento | Archivo | Tabla / uso | Prueba |
|---|---|---|---|
| Migración: `especialidades.tipo` y `precio` | `database/migrations/2026_11_06_000001_agregar_tipo_y_precio_a_especialidades.php` | especialidades (CU-04, CU-09, CU-11) | `BaseDatosTest` |
| Migración: `bloqueos_agenda.registrado_por` | `…_000002_agregar_registrado_por_a_bloqueos_agenda.php` | bloqueos_agenda (CU-06, CU-08) | `BaseDatosTest` |
| Migración: pago de `citas` | `…_000003_agregar_pago_a_citas.php` | citas: monto, metodo_pago, estado_pago, referencia_pago, fecha_pago (CU-09 a CU-13, CU-18) | `BaseDatosTest` |
| Migración: tabla `consultas` | `…_000004_crear_consultas.php` | consultas (CU-16, CU-17); no existía en el Ciclo I | `BaseDatosTest` |
| Modelo físico v30 | `backend/database/schema.sql` | copia del modelo físico 6.3.3 | comparado con `migrate --seed` en base vacía |
| Modelos | `Especialidad` (tipo, precio), `BloqueoAgenda` (registradoPor), `Cita` (pago, scope `pagadas`), `Consulta` | CE | pruebas de CU-04 a CU-17 |
| Dinero y configuración | `App\Support\Dinero`, `config/vidasalud.php`, `config/services.php`, `.env.example` | formato `Bs 100,00`, conversión a Stripe | `PagosTest`, CU-09, CU-11 |
| Seeders idempotentes | `EspecialidadSeeder`, `MedicoSeeder`, `PacienteSeeder`, `CitaSeeder`, `ConsultaSeeder` | datos base y demostración del v30 | `DespliegueTest` |

### Pago de citas (Prompt 2)

| Elemento | Archivo | Uso | Prueba |
|---|---|---|---|
| Cliente de la API de Stripe (Checkout alojado, captura manual) | `GestionCitas/Compartido/PagoStripeService.php`, `SesionPago.php`, `PagoException.php` | crear sesión, consultarla, capturar y cancelar la autorización | `PagosTest` |
| Orquestación del pago | `Compartido/PagoCitaService.php`, `PagoCita.php` | validar, guardar la reserva en la sesión, verificar el retorno | CU-09 y CU-11 |
| Núcleo transaccional | `Compartido/AgendarCitaService.php` | lock del médico + captura dentro de la transacción; cancela si algo falla | CU-09, CU-10, CU-11 |
| Retorno de Stripe | `Compartido/RetornoPagoController.php`, rutas `GET /pagos/stripe/retorno` y `/pagos/stripe/cancelado` | agenda al volver del pago o devuelve al formulario | CU-09, CU-11 |
| Reglas de estado | `Compartido/TransicionesCita.php` | diagrama 6.2.3 para CU-12, CU-13 y CU-16 | CU-12, CU-13, CU-16 |

### Ciclo II (Prompts 4 a 8)

| Elemento | Archivo | Uso | Prueba |
|---|---|---|---|
| Registro y perfil del paciente | `GestionPacientes/CU20_.../RegistroPacienteService.php`, `CU21_.../PerfilService.php` | cuenta + ficha + historia; vinculación con la ficha de recepción; perfil y contraseña | CU-20, CU-21 |
| Resumen con IA | `ReportesBitacora/CU18_.../ResumenIAService.php` | Gemini por HTTP, solo totales, 20 s, sin resumen si falla | CU-18 (`Http::fake`) |
| Reportes, CSV y PDF | `CU18_.../ReporteService.php`, `ReportesController.php`, `frontend/.../pdf.blade.php` | ocho reportes, exportación y vista de impresión | CU-18 |
| Asistente de voz | `frontend/assets/js/modulos/reportes-voz.js` | frases a filtros y lectura de totales (navegador) | CU-18 (marcado y sin recursos externos) |
| Recordatorios | `GestionCitas/CU22_.../EnviarRecordatoriosCommand.php`, `RecordatorioService.php`, `RecordatorioCitaMail.php`; `routes/console.php` | comando diario a las 18:00 (La Paz) | CU-22 |

## 3. Prueba general (Prompt 9)

| Fecha | Comando | Pruebas | Aserciones | Resultado |
|---|---|---|---|---|
| 07/11/2026 | `php artisan test` en el proyecto de trabajo (base migrada y sembrada) | 541 | 3778 | Todas aprobadas |
| 07/11/2026 (cierre del despliegue) | `php artisan test` en el proyecto de trabajo, tras el tope del PDF, el reintento del modelo de IA y el Dockerfile | 544 | 3794 | Todas aprobadas |
| 07/11/2026 | `php artisan test` en un clon limpio del repositorio, con una base nueva y siguiendo solo el README (`composer install`, `.env`, `key:generate`, `migrate --seed`) | 541 | 3778 | Todas aprobadas; el servidor del clon responde (inicio, login, assets, panel, reportes con 16 citas) |

Distribución al cierre (544 pruebas y 3794 aserciones): Ciclo I (CU-01 a CU-11) 258 pruebas y 1660 aserciones; Ciclo II (CU-12 a CU-22) 221 pruebas y 1858 aserciones (CU-18 pasó de 32 a 35 pruebas y de 379 a 395 aserciones); pruebas de base, seguridad, pagos, despliegue y utilidades 65 pruebas y 276 aserciones.

## 4. Tabla 8.2 (resultado por CU para el documento)

Todas las pruebas se ejecutaron el 07/11/2026 sobre PostgreSQL con la base migrada y sembrada (el correo, Stripe y la IA siempre simulados). El resultado «Aprobado» incluye las filas del plan 8.1 de ese CU y sus pruebas de reglas internas.

### Ciclo I

| ID | Caso de uso | Descripción de la funcionalidad evaluada | Tipo de prueba | Componentes evaluados | Filas 8.1 | Resultado |
|---|---|---|---|---|---|---|
| CP-01 | CU-01 Iniciar sesión | Inicio de sesión con credenciales, error genérico, bloqueo de 60 s por intentos fallidos, acceso sin sesión y recuperación de contraseña por correo. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (39 pruebas, 206 aserciones) |
| CP-02 | CU-02 Cerrar sesión | Cierre de sesión (invalida la sesión y el token) y acceso a páginas internas sin sesión. | Caja negra (PHPUnit) | Frontend / Backend / BD | 2 | **Aprobado** (6 pruebas, 21 aserciones) |
| CP-03 | CU-03 Gestionar usuarios y roles | Alta, edición y desactivación de usuarios; correo y usuario únicos; roles múltiples y permisos como unión de roles. | Caja negra (PHPUnit) | Frontend / Backend / BD | 3 | **Aprobado** (18 pruebas, 100 aserciones) |
| CP-04 | CU-04 Gestionar especialidades y servicios | Alta y edición de especialidades y servicios (consulta, laboratorio, enfermería) con tipo y precio; duplicados normalizados; desactivación. | Caja negra (PHPUnit) | Frontend / Backend / BD | 3 | **Aprobado** (18 pruebas, 141 aserciones) |
| CP-05 | CU-05 Gestionar médicos | Alta de médicos con especialidades, registro profesional único, cuenta de usuario opcional, ubicación y permiso de atender; desactivación con historial. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (16 pruebas, 94 aserciones) |
| CP-06 | CU-06 Gestionar horarios y días sin atención | Horarios por día con duración exacta, superposiciones rechazadas y días sin atención con las citas afectadas. | Caja negra (PHPUnit) | Frontend / Backend / BD | 5 | **Aprobado** (25 pruebas, 146 aserciones) |
| CP-07 | CU-07 Gestionar pacientes | Alta de pacientes con CI único, historia clínica en la misma transacción y antecedentes de otros hospitales; búsqueda y desactivación. | Caja negra (PHPUnit) | Frontend / Backend / BD | 5 | **Aprobado** (19 pruebas, 127 aserciones) |
| CP-08 | CU-08 Consultar disponibilidad de horarios | Cálculo de horarios libres del médico (días sin atención, fechas pasadas, citas ocupadas y canceladas). | Caja negra (PHPUnit) | Frontend / Backend / BD | 5 | **Aprobado** (14 pruebas, 61 aserciones) |
| CP-09 | CU-09 Registrar cita médica | Registro de citas con verificación de disponibilidad, restricción de la base de datos, concurrencia, pago en efectivo y con tarjeta (Stripe). | Caja negra (PHPUnit) | Frontend / Backend / BD | 8 | **Aprobado** (41 pruebas, 297 aserciones) |
| CP-10 | CU-10 Reprogramar cita médica | Reprogramación de citas (incluida la inasistencia ya pagada) conservando código y pago, con motivo del cambio. | Caja negra (PHPUnit) | Frontend / Backend / BD | 6 | **Aprobado** (26 pruebas, 211 aserciones) |
| CP-11 | CU-11 Reservar cita en línea | Reserva en línea del paciente con búsqueda de médicos y pago con tarjeta o efectivo. | Caja negra (PHPUnit) | Frontend / Backend / BD | 7 | **Aprobado** (36 pruebas, 256 aserciones) |

### Ciclo II

| ID | Caso de uso | Descripción de la funcionalidad evaluada | Tipo de prueba | Componentes evaluados | Filas 8.1 | Resultado |
|---|---|---|---|---|---|---|
| CP-12 | CU-12 Cancelar cita médica | Cancelación con motivo, aviso de que el pago no se devuelve y plazo de 2 horas para el paciente. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (13 pruebas, 95 aserciones) |
| CP-13 | CU-13 Controlar asistencia y estados de la cita | Confirmar, cobrar en efectivo, marcar atendida, registrar inasistencia y corregir estados con justificación del administrador. | Caja negra (PHPUnit) | Frontend / Backend / BD | 6 | **Aprobado** (26 pruebas, 154 aserciones) |
| CP-14 | CU-14 Consultar agenda médica | Agenda del médico por fecha o rango con horarios libres de un día y privacidad del contacto del paciente. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (15 pruebas, 146 aserciones) |
| CP-15 | CU-15 Consultar historial de citas del paciente | Historial de citas del paciente («Mis citas») con filtros y búsqueda del personal por CI o nombre. | Caja negra (PHPUnit) | Frontend / Backend / BD | 2 | **Aprobado** (14 pruebas, 142 aserciones) |
| CP-16 | CU-16 Registrar consulta y tratamiento | Registro de la consulta (diagnóstico y tratamiento) por el médico de la cita; la cita pasa a Atendida. | Caja negra (PHPUnit) | Frontend / Backend / BD | 3 | **Aprobado** (18 pruebas, 170 aserciones) |
| CP-17 | CU-17 Consultar historia clínica | Consulta de solo lectura de la historia clínica (datos generales, consultas y antecedentes externos) por el médico. | Caja negra (PHPUnit) | Frontend / Backend / BD | 3 | **Aprobado** (12 pruebas, 106 aserciones) |
| CP-18 | CU-18 Generar reportes con IA | Ocho reportes con totales, resumen opcional con IA (solo totales), impresión, CSV y PDF, y asistente de voz. | Caja negra (PHPUnit) | Frontend / Backend / BD | 7 | **Aprobado** (35 pruebas, 395 aserciones) |
| CP-19 | CU-19 Consultar bitácora | Consulta de la bitácora inmutable con filtros por usuario, acción, entidad y fechas. | Caja negra (PHPUnit) | Frontend / Backend / BD | 2 | **Aprobado** (25 pruebas, 195 aserciones) |
| CP-20 | CU-20 Registrarse como paciente | Registro del paciente desde la web (cuenta, ficha e historia clínica) y vinculación con la ficha creada en recepción. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (24 pruebas, 149 aserciones) |
| CP-21 | CU-21 Gestionar mi perfil | Edición del perfil del paciente (datos, información médica, emergencia, preferencias) y cambio de contraseña. | Caja negra (PHPUnit) | Frontend / Backend / BD | 4 | **Aprobado** (23 pruebas, 189 aserciones) |
| CP-22 | CU-22 Enviar recordatorios de citas | Recordatorios por correo de las citas del día siguiente a pacientes con notificaciones activadas (tarea programada). | Caja negra (PHPUnit) | Frontend / Backend / BD | 2 | **Aprobado** (16 pruebas, 117 aserciones) |
