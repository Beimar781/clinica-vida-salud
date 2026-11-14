<?php

namespace App\Console\Commands;

use App\Support\EventosDeCitasDesdeBitacora;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconstruye a mano el historial de cambios de las citas (cita_eventos) desde la bitácora, para las citas anteriores a esa tabla.
 * Solo lee la bitácora, no repite eventos (se puede ejecutar varias veces) y omite lo que no entiende. No corre solo en el despliegue.
 *
 * @CU CU-10, CU-12, CU-13, CU-15 (extra aprobado)
 */
class ReconstruirHistorialCitasCommand extends Command
{
    protected $signature = 'citas:reconstruir-historial';

    protected $description = 'Rellena el historial de cambios de las citas (cita_eventos) a partir de la bitácora, sin duplicar';

    public function handle(): int
    {
        try {
            $creados = EventosDeCitasDesdeBitacora::ejecutar();
        } catch (Throwable $e) {
            report($e);
            $this->error('No se pudo reconstruir el historial. El detalle quedó en el registro del sistema.');

            return self::FAILURE;
        }

        $this->info("Historial reconstruido: {$creados} cambio(s) nuevo(s).");

        return self::SUCCESS;
    }
}
