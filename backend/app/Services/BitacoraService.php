<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de la bitácora (solo agregar: la tabla no admite UPDATE ni DELETE).
 *
 * @CU CU-19 (transversal desde el Ciclo I) @RF RF19 (los diagramas del Ciclo II aún no fijan sus clases)
 */
class BitacoraService
{
    /**
     * Registra una operación importante. Si no se indica usuario, usa el de la sesión (o null si no hay).
     * Los textos se recortan al largo de las columnas (accion 60, entidad 40, detalle 300).
     */
    public static function registrar(string $accion, ?string $entidad = null, ?string $detalle = null, ?int $usuarioId = null): void
    {
        DB::table('bitacora')->insert([
            'usuario_id' => $usuarioId ?? Auth::id(),
            'accion' => Str::limit($accion, 60, ''),
            'entidad' => $entidad === null ? null : Str::limit($entidad, 40, ''),
            'detalle' => $detalle === null ? null : Str::limit($detalle, 300, ''),
            'fecha_hora' => now(),
        ]);
    }
}
