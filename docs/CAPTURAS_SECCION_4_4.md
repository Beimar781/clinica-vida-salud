# Capturas para la sección 4.4 «Prototipar interfaz de usuario» (v30: CU-01 a CU-22)

Una captura por pantalla importante de cada caso de uso, en el orden del documento (CU-01 a CU-22; el Ciclo II va al final desde la figura 72). Para que todas salgan con los mismos datos:

1. Desde `backend/`: `php artisan migrate:fresh --seed` y `php artisan serve` (http://localhost:8000). Las fechas de las citas de demostración se calculan desde el día en que se siembra, así que conviene sembrar el mismo día de las capturas y no mezclar días.
2. Navegador a 1280 px de ancho (PC), zoom 100 %. Una captura final a 768 px (tablet, RNF04) y, si se quiere, una a 390 px (celular) con el menú plegado.
3. Usuarios (todos con la clave `Demo1234*`; en el panel el nombre aparece con mayúscula inicial, por ejemplo «Admin»; deje `ADMIN_PASSWORD` sin definir en `.env`): `admin` (Administrador; hereda las funciones de recepción), `recepcion` (Recepcionista), `cmontano` (Médico), `pvargas` (Médico y Paciente), `mrojas` (Paciente).
4. Nombre sugerido del archivo: `fig-4.4-NN-CUxx-descripcion.png`. Mostrar la barra de direcciones o poner la URL como pie de figura.
5. Datos de la clínica (siembra limpia con `php artisan migrate:fresh --seed`): médicos MED-0001 Carla Montaño Rojas, MED-0002 Luis Fernando Soliz Peña, MED-0003 Patricia Vargas Egüez, MED-0004 Marcelo Antelo Paz, MED-0005 Jorge Rivero Salvatierra, MED-0006 Rosario Vaca Heredia (Laboratorio) y MED-0007 Daniel Cuéllar Roca (Enfermería); pacientes con CI 6543210 (María Elena Rojas Suárez), 7123456 (Juan Carlos Pérez Mamani), 9012345 (Sofía Camacho Justiniano), 10234567 (Lucía Fernández Roca), 5987654 (Carlos Alberto Mamani Quispe); una ficha de paciente sin cuenta, CI 8123456 (Ana Lucía Gutiérrez Parada, nacida el 15/06/1990), para CU-20; 16 citas: CM-0001 (atendida, efectivo pagado), CM-0003 (inasistencia pagada con tarjeta), CM-0004 (cancelada pagada), CM-0005 (confirmada pagada con tarjeta), CM-0006 y CM-0007 (programadas, efectivo pendiente), CM-0013 (inasistencia sin pago) y CM-0015 (programada pagada con tarjeta); días sin atención 25/12/2026, 01/01/2027 y 02/11/2027 (las fechas de las citas y los feriados se calculan desde el día en que se siembra; con otra fecha de siembra cambian las fechas, no los códigos).

Las URL son relativas a `http://localhost:8000`.

## CU-01 Iniciar sesión (y recuperar contraseña)

| N.º | Pantalla | URL | Usuario y datos | Qué debe verse |
|---|---|---|---|---|
| 01 | Inicio público | `/` | Sin sesión | Logo, nombre de la clínica y botón «Iniciar sesión» |
| 02 | Formulario de ingreso | `/login` | Sin sesión | Campos «Correo o nombre de usuario» y «Contraseña», enlace «¿Olvidaste tu contraseña?» |
| 03 | Error genérico | `/login` | `recepcion` con contraseña `incorrecta1` | Aviso rojo «Credenciales incorrectas.» |
| 04 | Bloqueo temporal | `/login` | `recepcion`: 3 intentos con contraseña errónea y un 4.º con la correcta | «Demasiados intentos fallidos. Espere N segundos e intente nuevamente.» (esperar 60 s antes de seguir) |
| 05 | Cuenta inactiva | `/login` | Antes, con `admin`, desactivar a `jperez` en CU-03; ingresar con `jperez` / `Demo1234*`; volver a activarlo | «El acceso no está habilitado.» |
| 06 | Panel del administrador | `/panel` | `admin` | Cinco tarjetas (Seguridad y Usuarios, Gestión Médica, Gestión de Pacientes, Gestión de Citas, Reportes y bitácora) con sus funciones y etiquetas CU; rol «Administrador» (incluye las funciones de recepción: Pacientes, Historial de citas, Consultar disponibilidad, Registrar cita, Citas y reprogramación, Control de citas y Agenda médica; no «Reservar cita»); botón «Cerrar sesión» en la barra |
| 07 | Panel de la recepcionista | `/panel` | `recepcion` | Tres tarjetas: Gestión de Pacientes (Pacientes, Historial de citas), Gestión de Citas y Reportes y bitácora (solo «Reportes»; sin «Bitácora») |
| 08 | Panel del paciente | `/panel` | `mrojas` | Gestión de Pacientes («Mis citas» y «Mi perfil») y Gestión de Citas («Consultar disponibilidad» y «Reservar cita») |
| 09 | Panel del médico | `/panel` | `cmontano` | Rol «Médico»; Gestión de Citas («Control de citas» y «Agenda médica») y Atención clínica («Registrar consulta» desde la agenda e «Historia clínica») |
| 10 | Panel con dos roles | `/panel` | `pvargas` | Roles «Médico» y «Paciente»; ve las opciones de ambos roles (unión de roles): Mis citas, Mi perfil, Consultar disponibilidad, Reservar cita, Control de citas, Agenda médica y Atención clínica |
| 11 | Acceso denegado | `/usuarios` | `recepcion` | Pantalla «Acceso denegado» |
| 12 | Pedir el enlace | `/olvido-clave` | Sin sesión; correo `recepcion@vidasalud.test` | Aviso verde «Si el correo está registrado, te enviamos un enlace de recuperación.» |
| 13 | Contraseña nueva | `/restablecer-clave/{token}?correo=recepcion@vidasalud.test` | Abrir el enlace de la línea «ENLACE DE RECUPERACION» de `backend/storage/logs/laravel.log` | Formulario con dos campos; error «La confirmación no coincide con la contraseña.» si se escriben distintas |
| 14 | Contraseña actualizada | `/login` | Guardar `NuevaClave2026`; luego volver a dejar `Demo1234*` (o resembrar) | Aviso verde «Contraseña actualizada. Ya puedes iniciar sesión.» |

## CU-02 Cerrar sesión

| N.º | Pantalla | URL | Usuario y datos | Qué debe verse |
|---|---|---|---|---|
| 15 | Botón «Cerrar sesión» | cualquier pantalla interna | `recepcion` | Barra superior con el nombre de usuario y el botón (puede ser la figura 07 recortada) |
| 16 | Sesión cerrada | `/login` | Pulsar «Cerrar sesión» y luego abrir `/panel` | Vuelve al formulario de ingreso |

## CU-03 Gestionar usuarios y roles (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 17 | Listado | `/usuarios` | Datos de demostración | 7 usuarios con roles y estado, campo de búsqueda |
| 18 | Búsqueda | `/usuarios?q=perez` | — | Solo `jperez` |
| 19 | Validación | `/usuarios/crear` | Enviar el formulario vacío | Errores junto a cada campo y «Seleccione al menos un rol.» |
| 20 | Correo repetido | `/usuarios/crear` | Usuario `demo.uno`, correo `recepcion@vidasalud.test`, contraseña `Clave12345`, rol Recepcionista | Error en el campo correo |
| 21 | Alta con dos roles | `/usuarios/crear` → `/usuarios` | Usuario `demo.doble`, correo `demo.doble@vidasalud.test`, contraseña `Clave12345`, roles Médico y Paciente | Listado con aviso verde y el usuario nuevo con los dos roles |
| 22 | Edición | `/usuarios/{id}/editar` | Editar `demo.doble` | Datos y roles cargados; contraseña opcional |
| 23 | Usuario desactivado | `/usuarios` | Desactivar `demo.doble` | Fila con estado «Inactivo» (el registro se conserva) |

## CU-04 Gestionar especialidades y servicios (`admin`) · v30: con tipo y precio

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 24 | Listado | `/especialidades` | Demostración | Especialidades y servicios (consulta, laboratorio, enfermería) con tipo, precio en Bs y estado |
| 25 | Duplicado normalizado | `/especialidades/crear` | Nombre `cardiologia ` (minúsculas, sin tilde, con espacio al final) | Rechazo por nombre repetido |
| 26 | Alta | `/especialidades/crear` → `/especialidades` | Nombre `Dermatología`, tipo Consulta, precio `150`, descripción «Piel y cabello» | Aviso verde y la especialidad activa en el listado |
| 27 | Desactivada | `/especialidades` | Desactivar `Dermatología` | Estado «Inactiva» |

## CU-05 Gestionar médicos (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 28 | Listado | `/medicos` | Demostración | Médicos con código MED-, especialidades y ubicación |
| 29 | Validación | `/medicos/crear` | Enviar vacío | Errores junto a cada campo |
| 30 | Registro repetido | `/medicos/crear` | Cualquier dato y el número de registro de un médico del listado | Rechazo por registro profesional repetido |
| 31 | Alta con cuenta | `/medicos/crear` → `/medicos` | Nombres `Ana`, apellidos `Prueba Soria`, registro `MP-9001`, teléfono `70000001`, correo `ana.prueba@vidasalud.test`, ubicación `Sede Central · Consultorio 301`, dos especialidades, «puede marcar atendidas» y cuenta con usuario `aprueba` | Aviso verde y código MED-0006 |
| 32 | Edición | `/medicos/{id}/editar` | Médico creado | Especialidades marcadas y cuenta existente |
| 33 | Médico desactivado | `/medicos` | Desactivar al médico creado en la figura 31 (MED-0008) | Estado «Inactivo» |

## CU-06 Gestionar horarios y días sin atención (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 34 | Agenda semanal | `/horarios?medico={id de MED-0001}` | Carla Montaño | Bloques por día de la semana |
| 35 | Horario válido | `/horarios?medico={id del médico creado en la figura 31}` | Lunes, 08:00, 12:00, 30 min | Aviso verde y «8 citas» |
| 36 | Minutos sobrantes | mismo formulario | Lunes, 14:00, 18:00, 25 min | Advertencia de cuántos minutos sobran |
| 37 | Superposición | `/horarios?medico={id de MED-0001}` | Lunes, 11:00, 14:00 | Rechazo por bloque superpuesto |
| 38 | Hora final inválida | mismo formulario | Lunes, 10:00, 09:00 | Rechazo: la hora final debe ser posterior |
| 39 | Días sin atención | `/dias-sin-atencion` | Demostración | 3 feriados |
| 40 | Día con citas afectadas | `/dias-sin-atencion` | Fecha de una cita programada (por ejemplo la de CM-0008) y motivo «Paro de transporte» | Aviso verde y lista de citas afectadas para reprogramar |
| 41 | Fecha repetida | `/dias-sin-atencion` | La misma fecha otra vez | Rechazo por fecha repetida |
| 42 | Día desactivado | `/dias-sin-atencion` | Desactivar el día | Mensaje de que la fecha vuelve a estar disponible |

## CU-07 Gestionar pacientes (`recepcion`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 43 | Listado | `/pacientes` | Demostración | 9 pacientes con código PAC-, estado y cantidad de citas |
| 44 | Búsqueda | `/pacientes?q=6543` | CI parcial | María Elena Rojas Suárez |
| 45 | Validación | `/pacientes/crear` | Enviar vacío | Errores junto a cada campo |
| 46 | CI duplicado | `/pacientes/crear` | CI `6543210` | Rechazo y tarjeta con el paciente existente |
| 47 | Alta con antecedentes | `/pacientes/crear` → `/pacientes` | CI `T-1234567`, nombres `Rosa`, apellidos `Demo Gómez`, nacimiento `1990-05-12`, sexo F, teléfono `71111111`, con «viene de otro hospital»: Hospital Japonés, diagnóstico «Gastritis», tratamiento «Omeprazol» | Aviso verde y código PAC-0009 |
| 48 | Edición con historia clínica | `/pacientes/{id}/editar` | Paciente creado | Historia clínica y antecedente externo listado |
| 49 | Paciente desactivado | `/pacientes` | Desactivar a la paciente creada en la figura 47 (PAC-0010) | Estado «Inactivo» y el historial se conserva |

## CU-08 Consultar disponibilidad de horarios (`recepcion` o `mrojas`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 50 | Formulario | `/disponibilidad` | — | Lista de médicos y campo de fecha |
| 51 | Horarios libres | `/disponibilidad?medico={id MED-0001}&fecha={lunes de CM-0005}` | Carla Montaño, el lunes de las citas CM-0005 y CM-0006 | Bloques libres sin 08:00 ni 08:30 |
| 52 | Feriado | `/disponibilidad?medico={id MED-0001}&fecha=2026-12-25` | Primer feriado del listado de la figura 39 (Navidad con la siembra del 07/11/2026; use la fecha del primer feriado de su siembra) | Aviso de feriado o día sin atención y ninguna hora |
| 53 | Médico que no atiende | misma URL con un domingo | Carla Montaño | «El médico no atiende en esa fecha» |
| 54 | Fecha pasada | misma URL con una fecha anterior a hoy | — | Aviso de fecha pasada y ninguna hora |

## CU-09 Registrar cita médica (`recepcion`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 55 | Buscar al paciente | `/citas/registrar?q=6543` | CI parcial | María Elena Rojas Suárez con el botón «Seleccionar» |
| 56 | Horarios libres | `/citas/registrar` (tras «Seleccionar») | Especialidad Medicina general, médico Carla Montaño, una fecha con atención (miércoles) | Horarios libres y formulario con modalidad y motivo |
| 57 | Comprobante (efectivo) | `/citas/{id}/comprobante` | Un horario libre, modalidad Virtual, motivo «Control de presión», pago en efectivo | Código CM-0017 en adelante, paciente, especialidad, médico, fecha, horario, modalidad, monto, pago «Pendiente», estado «Programada» y botón de imprimir |
| 58 | Horario ocupado | `/citas/registrar` | Con dos pestañas abiertas en el mismo horario, confirmar primero en una y luego en la otra | «El horario fue ocupado por otro usuario. Elija otro de los horarios libres que se muestran.» con la lista actualizada |
| 59 | Paciente inactivo | `/citas/registrar?q=` + nombre | Paciente desactivado (figura 49) | Paciente marcado como no seleccionable |

| 59 bis | Pago con tarjeta | Stripe Checkout y `/pagos/stripe/retorno` | Tarjeta de prueba de Stripe 4242 4242 4242 4242 | Comprobante con pago «Pagado» y referencia; con la tarjeta 4000 0000 0000 0002, aviso de pago rechazado y sin cita |

## CU-10 Reprogramar cita médica (`recepcion`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 60 | Listado de citas | `/citas` | Demostración | Citas vigentes con botón «Reprogramar» |
| 61 | Búsqueda por código | `/citas?q=CM-0006` | — | La cita de Juan Carlos Pérez Mamani |
| 62 | Horarios y motivo | `/citas/{id}/reprogramar` | CM-0006, una fecha nueva de Carla Montaño | Horarios libres del mismo médico y campo «Motivo del cambio» |
| 63 | Comprobante actualizado | `/citas/{id}/reprogramada` | Motivo «El paciente viaja el lunes» | Mismo código CM-0006, nuevo horario, horario anterior y estado «Programada» |
| 64 | Cita no reprogramable | `/citas/{id}/reprogramar` | CM-0001 (atendida) o CM-0004 (cancelada) | Mensaje que sugiere registrar una cita nueva |

## CU-11 Reservar cita en línea (`mrojas`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 65 | Buscar médicos | `/reservas/crear` | Especialidad Pediatría | Luis Fernando Soliz Peña con su ubicación y su próximo horario libre |
| 66 | Sin coincidencias | `/reservas/crear` | Una combinación de especialidad y ubicación sin médicos | Aviso para cambiar la búsqueda |
| 67 | Horarios y reserva | `/reservas/reservar?medico={id}&fecha=…` | Dr. Soliz, un día con atención | Horarios libres, modalidad y motivo «Dolor de garganta» |
| 68 | Comprobante | `/reservas/{id}/comprobante` | Confirmar la reserva | Cita a nombre de María Elena Rojas Suárez, estado «Programada» |
| 69 | Horario ocupado | `/reservas/reservar` | Dos pestañas, una con `mrojas` y otra con otro paciente con cuenta | Aviso de horario ocupado y horarios actualizados |

| 69 bis | Pago en línea | `/reservas/reservar` | Método Tarjeta (Stripe, modo de prueba, cobro en Bs) | Comprobante con monto, «Pagado» y referencia |

## Transversal

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 70 | Tablet | `/panel` | `admin`, ventana de 768 px | Diseño adaptado (RNF04) |
| 71 | Página inexistente | `/no-existe` | `admin` | Pantalla de error en español, sin rutas ni datos técnicos |

## Ciclo II (CU-12 a CU-22)

Las URL son las reales del sistema (`php artisan route:list`). Antes de capturar: `php artisan migrate:fresh --seed` el mismo día. Los códigos de cita son los de la siembra limpia; los parámetros `{id}` son el identificador interno (se llega con los botones de cada pantalla, sin escribir la URL).

| N.º | CU | Pantalla | URL | Usuario y datos | Qué debe verse |
|---|---|---|---|---|---|
| 72 | CU-12 | Cancelar cita | `/citas/{id}/cancelar` (botón «Cancelar» en `/citas`) | `recepcion`, CM-0007 (Programada, efectivo pendiente) | Motivo obligatorio; sin motivo, error junto al campo; con motivo, aviso verde y estado «Cancelada» |
| 73 | CU-12 | Cancelar con pago | mismo | `recepcion`, CM-0015 (pagada con tarjeta) | Aviso ámbar «el pago no se devuelve» con el monto y el botón «Reprogramar» |
| 74 | CU-12 | Plazo del paciente | mismo | `mrojas`, una cita propia que empiece en menos de 2 horas (créela antes con `recepcion` en CU-09 si hay horario) | «No se puede cancelar con menos de 2 horas de anticipación. Comunícate con recepción.»; con CM-0010 (más de 2 horas) sí deja cancelar |
| 75 | CU-13 | Control de citas | `/control-citas?fecha={fecha de CM-0005}` | `recepcion` | Agenda del día con el estado de cada cita y las acciones Confirmar, Cobrar, Atendida e Inasistencia según corresponda |
| 76 | CU-13 | Cobro en efectivo | `/control-citas?fecha={fecha de CM-0006}` | `recepcion`, CM-0006 (efectivo pendiente), botón «Cobrar» | Aviso verde y pago «Pagado» (el cobro queda en la bitácora con quién cobró) |
| 77 | CU-13 | Corrección | `/control-citas` | `admin`, una cita cualquiera, estado nuevo y justificación | Cambio de estado con la justificación guardada; sin justificación, error |
| 78 | CU-14 | Agenda médica | `/agenda?desde={fecha de CM-0005}` | `cmontano` | Citas de Carla Montaño de ese día con paciente, especialidad, estado y motivo, y sus horarios libres |
| 79 | CU-15 | Mis citas | `/historial-citas` | `mrojas` | Sus 4 citas (CM-0001, CM-0005, CM-0010 y CM-0016) con filtros por estado y fechas y botón «Cancelar» |
| 80 | CU-15 | Búsqueda del personal | `/historial-citas?q=6543210` | `recepcion` | Historial de María Elena Rojas Suárez |
| 81 | CU-16 | Registrar consulta | `/consultas/{id de CM-0005}` (botón en la agenda) | `cmontano`, CM-0005 (Confirmada) | Datos del paciente y de la cita; diagnóstico obligatorio; al guardar, la cita pasa a Atendida |
| 82 | CU-17 | Historia clínica | `/historia-clinica/{id de PAC-0001}` | `cmontano` | Datos generales, consultas en orden y antecedentes de otros hospitales (solo lectura) |
| 83 | CU-17 | Sin acceso | `/historia-clinica` | `recepcion` | «Acceso denegado» |
| 84 | CU-18 | Reportes | `/reportes` y luego «Citas por médico» con las fechas de la siembra | `admin` | Selector de 8 tipos, filtros, modalidad Sin IA / Con IA, totales por estado, médico y especialidad, y el detalle |
| 85 | CU-18 | Resumen con IA | `/reportes?tipo=por_estado&…&ia=con` | `admin`, modalidad «Con IA» (necesita `GEMINI_API_KEY`) | «Resumen generado por IA» con las cifras; sin clave, el aviso «No se pudo generar el resumen con IA» y el reporte completo |
| 86 | CU-18 | Exportar | `/reportes/exportar/pdf` y `/reportes/exportar/csv` (botones del reporte) | `admin` | PDF y CSV abiertos con los mismos totales |
| 87 | CU-18 | Asistente de voz | `/reportes` | `admin`, tocar «Citas por médico del 1 al 31 de octubre» | Formulario lleno, reporte generado sin recargar y estado «Entendí: … — listo.» (en Chrome o Edge además lee los totales) |
| 88 | CU-19 | Bitácora | `/bitacora` | `admin` | Eventos con filtros por usuario, acción, entidad y fechas (incluye cobros, pagos, reportes y recordatorios) |
| 89 | CU-20 | Registro | `/registro` | Sin sesión | Formulario público «Crear cuenta de paciente» con las frases de ayuda |
| 90 | CU-20 | Vincular ficha | `/registro` → `/panel` | CI `8123456`, nacimiento `15/06/1990`, nombres cualquiera, correo propio y contraseña de 8 o más caracteres | Panel del paciente con «Tu cuenta quedó vinculada a tu ficha PAC-0008» y la invitación a completar el perfil; con otra fecha, «Los datos no coinciden con una ficha registrada.» |
| 91 | CU-21 | Mi perfil | `/perfil` | `mrojas` | Datos editables, CI de solo lectura, información médica, contacto de emergencia, preferencias y cambio de contraseña |
| 92 | CU-22 | Recordatorio | `backend/storage/logs/laravel.log` | `php artisan citas:enviar-recordatorios --fecha={fecha de CM-0016}` (correo en `log`) | Salida «[OK] Enviado a PAC-0001 · María Elena Rojas Suárez · CM-0016 … 1 avisos enviados»; en el log, el correo con código, médico, fecha, horario, modalidad y pago pendiente |
| 93 | Transversal | Celular | `/panel` y `/reportes` | `pvargas`, ventana de 390 px | Menú plegable con el botón «Menú» y sin desplazamiento horizontal |
