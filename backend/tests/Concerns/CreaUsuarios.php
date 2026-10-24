<?php

namespace Tests\Concerns;

use App\Modules\Seguridad\Models\Rol;
use App\Modules\Seguridad\Models\Usuario;

/**
 * Crea usuarios de prueba (nombre «t-...») dentro de la transacción de la prueba.
 */
trait CreaUsuarios
{
    /**
     * @param  list<string>  $roles  nombres de rol (Rol::ADMINISTRADOR, ...)
     */
    protected function crearUsuario(string $nombre, array $roles, string $clave = 'Prueba1234', bool $activo = true): Usuario
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
}
