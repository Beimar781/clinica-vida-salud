<?php

namespace App\Modules\GestionCitas\Compartido;

use RuntimeException;

/**
 * El horario elegido ya no está libre: otra persona lo ocupó mientras tanto (se detectó al volver a verificar
 * dentro de la transacción o por la restricción EXCLUDE de PostgreSQL, SQLSTATE 23P01). El controlador vuelve
 * a mostrar los horarios libres actualizados.
 *
 * @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11
 */
class HorarioOcupadoException extends RuntimeException
{
    public const MENSAJE = 'El horario fue ocupado por otro usuario. Elija otro de los horarios libres que se muestran.';

    public function __construct(string $mensaje = self::MENSAJE)
    {
        parent::__construct($mensaje);
    }
}
