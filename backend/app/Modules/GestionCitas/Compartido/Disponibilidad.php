<?php

namespace App\Modules\GestionCitas\Compartido;

/**
 * Resultado de calcular la disponibilidad de un médico en una fecha: los horarios libres o el motivo
 * por el que no se ofrece ninguno. Lo producen DisponibilidadService y lo reutilizan CU-08, CU-09, CU-10 y CU-11.
 *
 * @CU CU-08 @RF RF08 @CI CIDisponibilidad (mostrarHorarios, mostrarMensaje) @CC CCDisponibilidad (calcularLibres)
 */
final class Disponibilidad
{
    public const FECHA_PASADA = 'fecha_pasada';

    public const DIA_SIN_ATENCION = 'dia_sin_atencion';

    public const MEDICO_INACTIVO = 'medico_inactivo';

    public const NO_ATIENDE = 'no_atiende';

    public const SIN_HORARIOS = 'sin_horarios';

    /**
     * @param  list<array{inicio: string, fin: string}>  $libres  bloques libres, ordenados («HH:MM»)
     * @param  list<array{inicio: string, fin: string}>  $todos  todos los bloques del día según el horario, libres u ocupados
     * @param  ?string  $mensaje  texto para el usuario cuando no hay horarios que ofrecer
     * @param  ?string  $motivo  una de las constantes de esta clase (null si hay horarios libres)
     */
    public function __construct(
        public readonly array $libres,
        public readonly array $todos,
        public readonly ?string $mensaje,
        public readonly ?string $motivo,
    ) {}

    public function hayLibres(): bool
    {
        return $this->libres !== [];
    }

    /** El bloque libre que empieza a esa hora («HH:MM»), o null. */
    public function libre(string $inicio): ?array
    {
        foreach ($this->libres as $bloque) {
            if ($bloque['inicio'] === $inicio) {
                return $bloque;
            }
        }

        return null;
    }

    /** El bloque del horario del médico que empieza a esa hora (libre u ocupado), o null si está fuera de su horario. */
    public function bloque(string $inicio): ?array
    {
        foreach ($this->todos as $bloque) {
            if ($bloque['inicio'] === $inicio) {
                return $bloque;
            }
        }

        return null;
    }
}
