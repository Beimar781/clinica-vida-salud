# Capturas para la sección 4.4 «Prototipar interfaz de usuario» (Ciclo I)

Una captura por pantalla importante de cada caso de uso, en el orden del documento (CU-01 a CU-11). Para que todas salgan con los mismos datos:

1. Desde `backend/`: `php artisan migrate:fresh --seed` y `php artisan serve` (http://localhost:8000). Las fechas de las citas de demostración se calculan desde el día en que se siembra, así que conviene sembrar el mismo día de las capturas y no mezclar días.
2. Navegador a 1280 px de ancho (PC), zoom 100 %. Una captura final a 768 px (tablet, RNF04).
3. Usuarios (clave `Demo1234*`, salvo `admin`, que usa `ADMIN_PASSWORD` del `.env`): `admin` (Administrador y Recepcionista), `recepcion` (Recepcionista), `cmontano` (Médico), `pvargas` (Médico y Paciente), `mrojas` (Paciente).
4. Nombre sugerido del archivo: `fig-4.4-NN-CUxx-descripcion.png`. Mostrar la barra de direcciones o poner la URL como pie de figura.
5. Datos de la clínica: médicos MED-0001 Carla Montaño Rojas, MED-0002 Luis Fernando Soliz Peña, MED-0003 Patricia Vargas Egüez, MED-0004 Marcelo Antelo Paz, MED-0005 Jorge Rivero Salvatierra; pacientes con CI 6543210 (María Elena Rojas Suárez), 7123456 (Juan Carlos Pérez Mamani), 9012345 (Sofía Camacho Justiniano), 10234567 (Lucía Fernández Roca), 5987654 (Carlos Alberto Mamani Quispe); citas CM-0001 (atendida), CM-0004 (cancelada), CM-0005 (confirmada) y CM-0006 (programada); feriados 02/11, 25/12 y 01/01.

Las URL son relativas a `http://localhost:8000`.

## CU-01 Iniciar sesión (y recuperar contraseña)

| N.º | Pantalla | URL | Usuario y datos | Qué debe verse |
|---|---|---|---|---|
| 01 | Inicio público | `/` | Sin sesión | Logo, nombre de la clínica y botón «Iniciar sesión» |
| 02 | Formulario de ingreso | `/login` | Sin sesión | Campos «Correo o nombre de usuario» y «Contraseña», enlace «¿Olvidaste tu contraseña?» |
| 03 | Error genérico | `/login` | `recepcion` con contraseña `incorrecta1` | Aviso rojo «Credenciales incorrectas.» |
| 04 | Bloqueo temporal | `/login` | `recepcion`: 3 intentos con contraseña errónea y un 4.º con la correcta | «Demasiados intentos fallidos. Espere N segundos e intente nuevamente.» (esperar 60 s antes de seguir) |
| 05 | Cuenta inactiva | `/login` | Antes, con `admin`, desactivar a `jperez` en CU-03; ingresar con `jperez` / `Demo1234*`; volver a activarlo | «El acceso no está habilitado.» |
| 06 | Panel del administrador | `/panel` | `admin` | Cuatro tarjetas (Seguridad y Usuarios, Gestión Médica, Gestión de Pacientes, Gestión de Citas) con sus funciones y etiquetas CU; roles «Administrador» y «Recepcionista»; botón «Cerrar sesión» en la barra |
| 07 | Panel de la recepcionista | `/panel` | `recepcion` | Solo Pacientes y Gestión de Citas |
| 08 | Panel del paciente | `/panel` | `mrojas` | Solo «Consultar disponibilidad» y «Reservar cita» |
| 09 | Panel del médico | `/panel` | `cmontano` | Rol «Médico» y aviso de que sus funciones llegan en la próxima entrega |
| 10 | Panel con dos roles | `/panel` | `pvargas` | Roles «Médico» y «Paciente»; ve las opciones del paciente (unión de roles) |
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

## CU-04 Gestionar especialidades médicas (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 24 | Listado | `/especialidades` | Demostración | 5 especialidades con su estado |
| 25 | Duplicado normalizado | `/especialidades/crear` | Nombre `cardiologia ` (minúsculas, sin tilde, con espacio al final) | Rechazo por nombre repetido |
| 26 | Alta | `/especialidades/crear` → `/especialidades` | Nombre `Dermatología`, descripción «Piel y cabello» | Aviso verde y la especialidad activa en el listado |
| 27 | Desactivada | `/especialidades` | Desactivar `Dermatología` | Estado «Inactiva» |

## CU-05 Gestionar médicos (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 28 | Listado | `/medicos` | Demostración | 5 médicos con código MED-, especialidades y ubicación |
| 29 | Validación | `/medicos/crear` | Enviar vacío | Errores junto a cada campo |
| 30 | Registro repetido | `/medicos/crear` | Cualquier dato y el número de registro de un médico del listado | Rechazo por registro profesional repetido |
| 31 | Alta con cuenta | `/medicos/crear` → `/medicos` | Nombres `Ana`, apellidos `Prueba Soria`, registro `MP-9001`, teléfono `70000001`, correo `ana.prueba@vidasalud.test`, ubicación `Sede Central · Consultorio 301`, dos especialidades, «puede marcar atendidas» y cuenta con usuario `aprueba` | Aviso verde y código MED-0006 |
| 32 | Edición | `/medicos/{id}/editar` | Médico creado | Especialidades marcadas y cuenta existente |
| 33 | Médico desactivado | `/medicos` | Desactivar a MED-0006 | Estado «Inactivo» |

## CU-06 Gestionar horarios y días sin atención (`admin`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 34 | Agenda semanal | `/horarios?medico={id de MED-0001}` | Carla Montaño | Bloques por día de la semana |
| 35 | Horario válido | `/horarios?medico={id de MED-0006}` | Lunes, 08:00, 12:00, 30 min | Aviso verde y «8 citas» |
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
| 43 | Listado | `/pacientes` | Demostración | 8 pacientes con código PAC-, estado y cantidad de citas |
| 44 | Búsqueda | `/pacientes?q=6543` | CI parcial | María Elena Rojas Suárez |
| 45 | Validación | `/pacientes/crear` | Enviar vacío | Errores junto a cada campo |
| 46 | CI duplicado | `/pacientes/crear` | CI `6543210` | Rechazo y tarjeta con el paciente existente |
| 47 | Alta con antecedentes | `/pacientes/crear` → `/pacientes` | CI `T-1234567`, nombres `Rosa`, apellidos `Demo Gómez`, nacimiento `1990-05-12`, sexo F, teléfono `71111111`, con «viene de otro hospital»: Hospital Japonés, diagnóstico «Gastritis», tratamiento «Omeprazol» | Aviso verde y código PAC-0009 |
| 48 | Edición con historia clínica | `/pacientes/{id}/editar` | Paciente creado | Historia clínica y antecedente externo listado |
| 49 | Paciente desactivado | `/pacientes` | Desactivar a PAC-0009 | Estado «Inactivo» y el historial se conserva |

## CU-08 Consultar disponibilidad de horarios (`recepcion` o `mrojas`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 50 | Formulario | `/disponibilidad` | — | Lista de médicos y campo de fecha |
| 51 | Horarios libres | `/disponibilidad?medico={id MED-0001}&fecha={lunes de CM-0005}` | Carla Montaño, el lunes de las citas CM-0005 y CM-0006 | Bloques libres sin 08:00 ni 08:30 |
| 52 | Feriado | `/disponibilidad?medico={id MED-0001}&fecha=2026-11-02` | Día de los Difuntos | Aviso de feriado o día sin atención y ninguna hora |
| 53 | Médico que no atiende | misma URL con un domingo | Carla Montaño | «El médico no atiende en esa fecha» |
| 54 | Fecha pasada | misma URL con una fecha anterior a hoy | — | Aviso de fecha pasada y ninguna hora |

## CU-09 Registrar cita médica (`recepcion`)

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 55 | Buscar al paciente | `/citas/registrar?q=6543` | CI parcial | María Elena Rojas Suárez con el botón «Seleccionar» |
| 56 | Horarios libres | `/citas/registrar` (tras «Seleccionar») | Especialidad Medicina general, médico Carla Montaño, una fecha con atención (miércoles) | Horarios libres y formulario con modalidad y motivo |
| 57 | Comprobante | `/citas/{id}/comprobante` | Un horario libre, modalidad Virtual, motivo «Control de presión» | Código CM-0013 en adelante, paciente, especialidad, médico, fecha, horario, modalidad, estado «Programada» y botón de imprimir |
| 58 | Horario ocupado | `/citas/registrar` | Con dos pestañas abiertas en el mismo horario, confirmar primero en una y luego en la otra | «El horario fue ocupado por otro usuario. Elija otro de los horarios libres que se muestran.» con la lista actualizada |
| 59 | Paciente inactivo | `/citas/registrar?q=` + nombre | Paciente desactivado (figura 49) | Paciente marcado como no seleccionable |

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

## Transversal

| N.º | Pantalla | URL | Datos | Qué debe verse |
|---|---|---|---|---|
| 70 | Tablet | `/panel` | `admin`, ventana de 768 px | Diseño adaptado (RNF04) |
| 71 | Página inexistente | `/no-existe` | `admin` | Pantalla de error en español, sin rutas ni datos técnicos |
