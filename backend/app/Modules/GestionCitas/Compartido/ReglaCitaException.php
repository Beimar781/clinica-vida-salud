<?php

namespace App\Modules\GestionCitas\Compartido;

use RuntimeException;

/**
 * Una regla del negocio impide registrar, reprogramar o reservar la cita (paciente o médico inactivo, fecha pasada,
 * feriado, fuera del horario del médico, el paciente ya tiene otra cita a esa hora, cita que no se puede reprogramar).
 * El mensaje es seguro para mostrarlo al usuario.
 *
 * @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11
 */
class ReglaCitaException extends RuntimeException {}
