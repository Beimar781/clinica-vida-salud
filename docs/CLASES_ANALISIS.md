# Clases de análisis del Ciclo I (sección 5.3 del documento)

Nombres exactos de las clases de interfaz (CI), control (CC) y entidad (CE) de cada caso de uso, tal como aparecen en los diagramas de análisis de clase del documento. Se usan en los comentarios del código (`@CU`, `@CI`, `@CC`, `@CE`) y en la trazabilidad.

Correspondencia con Laravel: CI = vista Blade, CC = método del controlador + servicio del módulo, CE = modelo Eloquent.

| CU | Actor | CI (boundary) | CC (control) | CE (entity) |
|---|---|---|---|---|
| CU-01 Iniciar sesión | Usuario | `CILogin`: mostrarFormulario, enviarCredenciales, mostrarPanel, mostrarError | `CCLogin`: autenticar, validarIntentos, verificarClave | `CEUsuario`: buscarPorCorreo, registrarAcceso |
| CU-02 Cerrar sesión | Usuario | `CILogout`: solicitarCierre, mostrarInicio | `CCLogout`: cerrar, invalidarSesion | `CEUsuario`: registrarAcceso |
| CU-03 Gestionar usuarios y roles | Administrador | `CIUsuarios`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCUsuarios`: crear, guardar, editar, actualizar, desactivar | `CEUsuario`: crear, actualizar, desactivar · `CERol`: listar (un usuario tiene 1..* roles) |
| CU-04 Gestionar especialidades médicas | Administrador | `CIEspecialidad`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCEspecialidad`: crear, guardar, editar, actualizar, desactivar | `CEEspecialidad`: existeNombre, crear, actualizar, desactivar |
| CU-05 Gestionar médicos | Administrador | `CIMedicos`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCMedicos`: crear, guardar, editar, actualizar, desactivar | `CEMedico`: obtener, crear, actualizar, desactivar · `CEEspecialidad`: listarActivas (un médico ejerce 1..* especialidades) |
| CU-06 Gestionar horarios y días sin atención | Administrador | `CIHorarios`: mostrarAgenda, mostrarFormulario, enviarDatos, mostrarResultado | `CCHorarios`: crear, guardar, validarHoras, actualizar, desactivar | `CEHorario`: haySuperposicion, crear, actualizar, desactivar · `CEMedico`: obtener (un médico atiende en 0..* horarios) |
| CU-07 Gestionar pacientes | Recepcionista / Administrador | `CIPacientes`: mostrarListado, mostrarFormulario, enviarDatos, mostrarResultado | `CCPacientes`: crear, guardar, editar, actualizar, buscar | `CEPaciente`: existeCI, crear, actualizar, buscar · `CEHistoriaClinica`: crearParaPaciente · `CEAntecedente`: crear |
| CU-08 Consultar disponibilidad de horarios | Recepcionista / Paciente | `CIDisponibilidad`: elegirMedico, elegirFecha, mostrarHorarios, mostrarMensaje | `CCDisponibilidad`: calcularLibres, verificarDia | `CEMedico`: horariosLibres · `CECita`: citasDelDia |
| CU-09 Registrar cita médica | Recepcionista | `CIRegistrarCita`: buscarPaciente, mostrarHorarios, confirmarCita, mostrarComprobante | `CCRegistrarCita`: buscar, calcularLibres, validarReglas, registrar | `CEPaciente`: buscar · `CECita`: citasDelDia, crear, estaLibre · `CEMedico`: horariosLibres |
| CU-10 Reprogramar cita médica | Recepcionista | `CIReprogramarCita`: buscarCita, mostrarHorarios, confirmarCambio, mostrarComprobante | `CCReprogramarCita`: buscar, calcularLibres, reprogramar | `CECita`: obtener, citasDelDia, reprogramar · `CEMedico`: horariosLibres |
| CU-11 Reservar cita en línea | Paciente | `CIReservarCita`: buscarMedicos, mostrarHorarios, confirmarReserva, mostrarComprobante | `CCReservarCita`: buscar, calcularLibres, reservar | `CECita`: citasDelDia, crear, estaLibre · `CEMedico`: buscar, horariosLibres |

Notas:
- El modelo físico es el de `database/schema.sql`. En los diagramas el atributo se llama `password`; en la base de datos es `password_hash`. En `CEHorario` el atributo `duracion_min` se muestra como `duracion` en la interfaz.
- `CEMedico::horariosLibres` y `CCDisponibilidad::calcularLibres` se implementan una sola vez en `DisponibilidadService`, que reutilizan CU-09, CU-10 y CU-11.
- Los diagramas de comunicación y de secuencia del documento (5.2 y 6.2) usan los mismos nombres de mensajes que los métodos de esta tabla.
