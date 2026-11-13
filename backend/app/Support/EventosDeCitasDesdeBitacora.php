<?php

namespace App\Support;

use App\Models\Cita;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reconstruye el historial de cambios de las citas (tabla cita_eventos) a partir de la bitácora, para las citas anteriores a la tabla.
 * Idempotente (no repite un evento que ya existe: misma cita, mismo tipo y mismo momento) y tolerante: una fila que no se entiende se
 * omite y se sigue con la siguiente. La bitácora no se modifica.
 *
 * @CU CU-10, CU-12, CU-13, CU-15
 */
class EventosDeCitasDesdeBitacora
{
    private const ACCIONES = [
        'CITA_REGISTRADA' => 'REGISTRADA',
        'CITA_RESERVADA' => 'REGISTRADA',
        'CITA_RESERVADA_EN_LINEA' => 'REGISTRADA',
        'CITA_CONFIRMADA' => 'CONFIRMADA',
        'INASISTENCIA_REGISTRADA' => 'INASISTENCIA',
        'CITA_REPROGRAMADA' => 'REPROGRAMADA',
        'CITA_CANCELADA' => 'CANCELADA',
    ];

    /** @return int cantidad de eventos creados */
    public static function ejecutar(): int
    {
        $creados = 0;
        $citas = Cita::pluck('id', 'codigo');

        DB::table('bitacora')->whereIn('accion', array_keys(self::ACCIONES))->orderBy('id')->chunkById(500, function ($filas) use ($citas, &$creados) {
            foreach ($filas as $fila) {
                try {
                    $datos = self::interpretar($fila->accion, (string) $fila->detalle);
                    $citaId = $datos && preg_match('/\bCM-\d{4,}\b/', (string) $fila->detalle, $m) ? ($citas[$m[0]] ?? null) : null;
                    if (! $datos || ! $citaId) {
                        continue;
                    }

                    $ya = DB::table('cita_eventos')->where('cita_id', $citaId)->where('tipo', $datos['tipo'])->where('created_at', $fila->fecha_hora)->exists();
                    if (! $ya) {
                        DB::table('cita_eventos')->insert($datos + ['cita_id' => $citaId, 'usuario_id' => $fila->usuario_id, 'created_at' => $fila->fecha_hora]);
                        $creados++;
                    }
                } catch (Throwable) {
                    // Una fila con un texto inesperado no detiene el resto.
                }
            }
        });

        return $creados;
    }

    /** @return array<string, mixed>|null columnas del evento (sin cita, usuario ni momento) o null si no se entiende */
    public static function interpretar(string $accion, string $detalle): ?array
    {
        $tipo = self::ACCIONES[$accion] ?? null;
        if (! $tipo) {
            return null;
        }
        $base = ['tipo' => $tipo, 'fecha_anterior' => null, 'hora_anterior' => null, 'fecha_nueva' => null, 'hora_nueva' => null, 'estado_anterior' => null, 'estado_nuevo' => null, 'motivo' => null];

        return match ($tipo) {
            'REGISTRADA' => [...$base, 'estado_nuevo' => Cita::PROGRAMADA],
            'CONFIRMADA' => [...$base, 'estado_anterior' => Cita::PROGRAMADA, 'estado_nuevo' => Cita::CONFIRMADA],
            'INASISTENCIA' => [...$base, 'estado_anterior' => Cita::CONFIRMADA, 'estado_nuevo' => Cita::INASISTENCIA],
            'CANCELADA' => [...$base, 'estado_nuevo' => Cita::CANCELADA, 'motivo' => preg_match('/Motivo: (.+)$/su', $detalle, $m) ? trim($m[1]) : null],
            'REPROGRAMADA' => self::reprogramada($base, $detalle),
        };
    }

    /** «reprogramada de 13/11/2026 14:00 – 14:30 a 17/11/2026 08:00 – 08:30. Motivo: …» */
    private static function reprogramada(array $base, string $detalle): ?array
    {
        $fecha = '(\d{2})\/(\d{2})\/(\d{4}) (\d{2}:\d{2})';
        if (! preg_match("/de {$fecha} [–-] \d{2}:\d{2} a {$fecha}/u", $detalle, $m)) {
            return null;
        }

        return [...$base,
            'fecha_anterior' => "{$m[3]}-{$m[2]}-{$m[1]}", 'hora_anterior' => $m[4],
            'fecha_nueva' => "{$m[7]}-{$m[6]}-{$m[5]}", 'hora_nueva' => $m[8],
            'estado_anterior' => str_contains($detalle, 'inasistencia pagada') ? Cita::INASISTENCIA : null,
            'estado_nuevo' => Cita::PROGRAMADA,
            'motivo' => preg_match('/Motivo: (.+)$/su', $detalle, $mm) ? trim($mm[1]) : null,
        ];
    }
}
