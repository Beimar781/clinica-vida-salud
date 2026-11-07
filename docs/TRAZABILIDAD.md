# Trazabilidad documento v30 ↔ código ↔ pruebas (archivo de trabajo local, no se sube al repositorio)

Claude Code mantiene este archivo: actualiza la fila de cada CU al terminarlo (Prompts 1 a 8) y completa las columnas de resultado en el Prompt 9. De aquí salen las tablas 8.1 y 8.2 del documento.

Estados: **Hecho (C-I)** = implementado en el Ciclo I y sin cambios en el v30 · **Por ajustar** = implementado, el v30 lo cambia · **Pendiente** = por construir · **Adelantado** = hecho antes de su ciclo.

## 1. Casos de uso

| CU | Nombre (v30) | Ciclo | RF | Prompt | Estado | Carpeta de código | Filas 8.1 | Pruebas (último resultado) |
|---|---|---|---|---|---|---|---|---|
| CU-01 | Iniciar sesión | I | RF01 | — | Hecho (C-I) | `SeguridadUsuarios/CU01_IniciarSesion` | 4 | 39 aprobadas (incluye recuperación) |
| CU-02 | Cerrar sesión | I | RF02 | — | Hecho (C-I) | `SeguridadUsuarios/CU02_CerrarSesion` | 2 | 6 aprobadas |
| CU-03 | Gestionar usuarios y roles | I | RF03 | 3 (aviso paciente sin ficha) | Hecho (C-I; aviso en el panel, 07/11) | `SeguridadUsuarios/CU03_GestionarUsuariosRoles` | 3 | 18 aprobadas |
| CU-04 | Gestionar especialidades y servicios | I | RF04 | 1 | Hecho (v30, 07/11) | `GestionMedica/CU04_GestionarEspecialidadesServicios` | 3 | 18 aprobadas (3 filas del 8.1 + 15 de reglas internas) |
| CU-05 | Gestionar médicos | I | RF05 | — | Hecho (C-I) | `GestionMedica/CU05_GestionarMedicos` | 4 | 16 aprobadas |
| CU-06 | Gestionar horarios y días sin atención | I | RF06 | 1 | Hecho (v30, 07/11) | `GestionMedica/CU06_GestionarHorariosDiasSinAtencion` | 5 | 25 aprobadas (5 filas del 8.1 + 20 de reglas internas) |
| CU-07 | Gestionar pacientes | I | RF07 | — | Hecho (C-I) | `GestionPacientes/CU07_GestionarPacientes` | 5 | 19 aprobadas |
| CU-08 | Consultar disponibilidad de horarios | I | RF08 | — | Hecho (C-I) | `GestionCitas/CU08_ConsultarDisponibilidadHorarios` | 5 | 14 aprobadas |
| CU-09 | Registrar cita médica | I | RF09 | 2 | Hecho (v30, 07/11) | `GestionCitas/CU09_RegistrarCitaMedica` | 8 | 41 aprobadas (8 filas del 8.1 + 32 de reglas internas y pago + 1 concurrencia) |
| CU-10 | Reprogramar cita médica | I | RF10 | 3 | Hecho (v30, 07/11) | `GestionCitas/CU10_ReprogramarCitaMedica` | 6 | 26 aprobadas (6 filas del 8.1 + 20 de reglas internas) |
| CU-11 | Reservar cita en línea | I | RF11 | 2, 3 | Hecho (v30, 07/11) | `GestionCitas/CU11_ReservarCitaEnLinea` | 7 | 36 aprobadas (7 filas del 8.1 + 29 de reglas internas, pago y cuenta sin ficha) |
| CU-12 | Cancelar cita médica | II | RF12 | 4 | Hecho (07/11) | `GestionCitas/CU12_CancelarCitaMedica` | 4 | 13 aprobadas (4 filas del 8.1 + 9 de reglas internas) |
| CU-13 | Controlar asistencia y estados de cita | II | RF13 | 4 | Pendiente | `GestionCitas/CU13_ControlarAsistenciaEstadosCita` | 6 | — |
| CU-14 | Consultar agenda médica | II | RF14 | 4 | Pendiente | `GestionCitas/CU14_ConsultarAgendaMedica` | 4 | — |
| CU-15 | Consultar historial de citas del paciente | II | RF15 | 4 | Pendiente | `GestionPacientes/CU15_ConsultarHistorialCitasPaciente` | 2 | — |
| CU-16 | Registrar consulta y tratamiento | II | RF16 | 5 | Pendiente | `AtencionClinica/CU16_RegistrarConsultaTratamiento` | 3 | — |
| CU-17 | Consultar historia clínica | II | RF17 | 5 | Pendiente | `AtencionClinica/CU17_ConsultarHistoriaClinica` | 3 | — |
| CU-18 | Generar reportes (con IA) | II | RF18 | 7 | Pendiente | `ReportesBitacora/CU18_GenerarReportesConIA` | 7 | — |
| CU-19 | Consultar bitácora | II | RF19 | 6 (ajuste) | Adelantado | `ReportesBitacora/CU19_ConsultarBitacora` | 2 | 23 aprobadas |
| CU-20 | Registrarse como paciente | II | RF20 | 6 | Pendiente | `GestionPacientes/CU20_RegistrarsePaciente` | 4 | — |
| CU-21 | Gestionar mi perfil | II | RF21 | 6 | Pendiente | `GestionPacientes/CU21_GestionarMiPerfil` | 4 | — |
| CU-22 | Enviar recordatorios de citas | II | RF22 | 8 | Pendiente | `GestionCitas/CU22_EnviarRecordatoriosCitas` | 2 | — |

Total de filas del plan 8.1: 93. Cada fila es un método `paso_N_...` del archivo `backend/tests/Feature/CUxx_*Test.php`; los CU con más pruebas que filas incluyen casos extra (bitácora, permisos, validaciones).

Referencia del Ciclo I (24/10): 250 pruebas, 1363 aserciones, todas aprobadas. Los CU «Por ajustar» se vuelven a ejecutar tras cada prompt y el conteo se actualiza en esta tabla.

## 2. Tareas técnicas

| Tarea | Prompt | Estado |
|---|---|---|
| Migraciones v30 (`tipo`, `precio`, `registrado_por`, datos de pago en `citas`) y `schema.sql` nuevo | 0 | Hecho (07/11) |
| Datos de demostración v30 (precios, laboratorio, enfermería, ficha sin cuenta, citas por estado y pago) | 0 | Hecho (07/11) |
| Pago con tarjeta (Stripe Checkout, captura manual) y efectivo | 2 | Hecho (07/11): probado también con Stripe real en modo de prueba |
| Proveedor de IA para el resumen de reportes | 7 | Pendiente |
| Programador y comando `citas:enviar-recordatorios`; servicio cron en Railway | 8, 10 | Pendiente |
| Auditoría documento ↔ código y prueba general | 9 | Pendiente |
| Redespliegue en Railway con variables de Stripe, IA y Brevo | 10 | Pendiente |

### Base técnica (Prompt 0, 07/11/2026)

| Elemento | Archivo | Tabla / uso | Prueba |
|---|---|---|---|
| Migración: `especialidades.tipo` y `precio` | `database/migrations/2026_11_06_000001_agregar_tipo_y_precio_a_especialidades.php` | especialidades (CU-04, CU-09, CU-11) | `BaseDatosTest` |
| Migración: `bloqueos_agenda.registrado_por` | `…_000002_agregar_registrado_por_a_bloqueos_agenda.php` | bloqueos_agenda (CU-06, CU-08) | `BaseDatosTest` |
| Migración: pago de `citas` | `…_000003_agregar_pago_a_citas.php` | citas: monto, metodo_pago, estado_pago, referencia_pago, fecha_pago (CU-09 a CU-13, CU-18) | `BaseDatosTest` |
| Migración: tabla `consultas` | `…_000004_crear_consultas.php` | consultas (CU-16, CU-17); no existía en el Ciclo I | `BaseDatosTest` |
| Modelo físico v30 | `backend/database/schema.sql` | copia de `.trabajo/schema.sql` | comparado con `migrate --seed` en base vacía |
| Modelos | `Especialidad` (tipo, precio), `BloqueoAgenda` (registradoPor), `Cita` (pago, scope `pagadas`) | CE | pruebas de CU-04 a CU-11 |
| Dinero y configuración | `App\Support\Dinero`, `config/vidasalud.php`, `config/services.php`, `.env.example` | formato `Bs 100,00`, conversión a Stripe | se prueba en el Prompt 2 |
| Seeders idempotentes | `EspecialidadSeeder`, `MedicoSeeder`, `PacienteSeeder`, `CitaSeeder` | datos base y demostración del v30 | `DespliegueTest` (17) |

Resultado: `BaseDatosTest` 17 aprobadas (64 aserciones), `DespliegueTest` 17 aprobadas (76); CU-04 a CU-11 siguen en verde (125 pruebas, 767 aserciones).

### Pago de citas (Prompt 2, 07/11/2026)

| Elemento | Archivo | Uso | Prueba |
|---|---|---|---|
| Cliente de la API de Stripe (Checkout alojado, captura manual) | `GestionCitas/Compartido/PagoStripeService.php`, `SesionPago.php`, `PagoException.php` | crear sesión, consultarla, capturar y cancelar la autorización | `PagosTest` (14) |
| Orquestación del pago | `Compartido/PagoCitaService.php`, `PagoCita.php` | validar, guardar la reserva en la sesión, verificar el retorno | CU-09 y CU-11 |
| Núcleo transaccional | `Compartido/AgendarCitaService.php` (`prevalidar`, `crear` con pago) | lock del médico + captura dentro de la transacción; cancela si algo falla | CU-09, CU-11 |
| Retorno de Stripe | `Compartido/RetornoPagoController.php`, rutas `GET /pagos/stripe/retorno` y `/pagos/stripe/cancelado` | agenda al volver del pago o devuelve al formulario | CU-09, CU-11 |
| Pantallas | `Compartido/metodo-pago.blade.php`, `Compartido/comprobante-datos.blade.php`, `components/estado-pago.blade.php` | método de pago, monto, comprobante y etiqueta Pendiente/Pagado | navegador |
| Bitácora | `CITA_REGISTRADA` / `CITA_RESERVADA` con método, estado y monto; `PAGO_TARJETA_FALLIDO` | auditoría del cobro | CU-09, CU-11, CU-19 |

Resultado: CU-09 41, CU-11 33, `PagosTest` 14, CU-10 18, CU-19 23 aprobadas. Conjunto CU-04 a CU-11 + base + despliegue + CU-19 + pagos: 255 pruebas, todas aprobadas.

## 3. Prueba general (la llena el Prompt 9)

| Fecha | Comando | Pruebas | Aserciones | Resultado |
|---|---|---|---|---|
| | `php artisan test` | | | |

## 4. Tabla 8.2 (resultado por CU para el documento)

Se llena tras la prueba general: por cada CU, `Aprobado` o `Fallido` y observaciones. Mientras tanto todas figuran como pendientes.
