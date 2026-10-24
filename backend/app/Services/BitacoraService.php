<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de la bitácora (solo agregar: la tabla no admite UPDATE ni DELETE).
 *
 * @CU CU-01 a CU-11 (transversal: toda operación importante deja su registro)
 */
class BitacoraService
{
    /**
     * Registra una operación importante. Si no se indica usuario, usa el de la sesión (o null si no hay).
     * Guarda también la IP de la petición (null si no hay petición, por ejemplo en la consola).
     * Los textos se recortan al largo de las columnas (accion 60, entidad 40, detalle 300).
     */
    public static function registrar(string $accion, ?string $entidad = null, ?string $detalle = null, ?int $usuarioId = null): void
    {
        DB::table('bitacora')->insert([
            'usuario_id' => $usuarioId ?? Auth::id(),
            'accion' => Str::limit($accion, 60, ''),
            'entidad' => $entidad === null ? null : Str::limit($entidad, 40, ''),
            'detalle' => $detalle === null ? null : Str::limit($detalle, 300, ''),
            'ip' => self::ip(),
            'fecha_hora' => now(),
        ]);
    }

    /**
     * Dirección IP de quien hizo la petición (detrás del proxy de Railway, la del cliente). Es null cuando no hay
     * petición web: en los comandos de artisan (sembrado, tareas programadas) Laravel arma una petición ficticia
     * con 127.0.0.1, que no se debe guardar como si fuera una persona. Las pruebas sí usan la petición simulada.
     */
    private static function ip(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        $ip = request()->ip();

        return $ip === null ? null : Str::limit($ip, 45, '');
    }
}
