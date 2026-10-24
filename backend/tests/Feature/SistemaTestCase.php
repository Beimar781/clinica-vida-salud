<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Base de las pruebas del sistema. Corren sobre la MISMA base de datos del sistema (PostgreSQL; las
 * restricciones EXCLUDE no existen en SQLite) con los seeders ya aplicados (`php artisan migrate --seed`).
 * Cada prueba se revierte al terminar (DatabaseTransactions), así que los datos de demostración no se tocan.
 * Nunca usar RefreshDatabase, migrate:fresh ni db:wipe dentro de las pruebas. Los datos propios llevan el
 * prefijo «t-» (usuarios) o «T-» (CI y códigos) para no chocar con los de demostración.
 */
abstract class SistemaTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña de los usuarios de prueba creados con crearUsuario(). */
    public const CLAVE = 'Prueba1234';

    /**
     * Crea un usuario de prueba (correo «<nombre>@prueba.test») con los roles indicados.
     *
     * @param  list<string>  $roles  nombres de rol (Rol::ADMINISTRADOR, ...)
     */
    protected function crearUsuario(string $nombre, array $roles, string $clave = self::CLAVE, bool $activo = true): Usuario
    {
        $usuario = Usuario::create([
            'nombre_usuario' => $nombre,
            'correo' => $nombre.'@prueba.test',
            'password_hash' => $clave,
            'estado' => $activo,
        ]);
        $usuario->roles()->attach(Rol::whereIn('nombre', $roles)->pluck('id'));

        return $usuario;
    }

    /** Id de un rol por su nombre (Rol::PACIENTE, ...). */
    protected function idRol(string $nombre): int
    {
        return Rol::where('nombre', $nombre)->value('id');
    }
}
