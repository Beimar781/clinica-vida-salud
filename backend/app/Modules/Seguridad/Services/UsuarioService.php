<?php

namespace App\Modules\Seguridad\Services;

use App\Modules\Seguridad\Models\Rol;
use App\Modules\Seguridad\Models\Usuario;
use App\Support\BitacoraService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de cuentas de usuario y de sus roles. Nada se elimina: las cuentas solo se desactivan.
 *
 * @CU CU-03 @RF RF03 @CI CIUsuarios @CC CCUsuarios @CE CEUsuario, CERol
 */
class UsuarioService
{
    public const POR_PAGINA = 10;

    /**
     * Listado con búsqueda por nombre de usuario o correo.
     *
     * @CC CCUsuarios (mostrarListado) @CE CEUsuario
     */
    public function listar(?string $buscar = null): LengthAwarePaginator
    {
        $buscar = trim((string) $buscar);

        return Usuario::with('roles')
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                $patron = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($buscar)).'%';
                $consulta->where(fn ($q) => $q
                    ->whereRaw('lower(nombre_usuario) like ?', [$patron])
                    ->orWhereRaw('lower(correo) like ?', [$patron]));
            })
            ->orderBy('nombre_usuario')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Roles que se pueden asignar.
     *
     * @CC CCUsuarios (crear, editar) @CE CERol (listar)
     *
     * @return Collection<int, Rol>
     */
    public function rolesDisponibles(): Collection
    {
        return Rol::orderBy('id')->get();
    }

    /**
     * Registra la cuenta con su contraseña en hash y los roles elegidos.
     *
     * @CC CCUsuarios (guardar) @CE CEUsuario (crear)
     *
     * @param  array{nombre_usuario: string, correo: string, password: string, roles: list<int>}  $datos
     */
    public function guardar(array $datos, Usuario $actor): Usuario
    {
        return DB::transaction(function () use ($datos, $actor) {
            $usuario = Usuario::create([
                'nombre_usuario' => trim($datos['nombre_usuario']),
                'correo' => mb_strtolower(trim($datos['correo'])),
                'password_hash' => $datos['password'],
                'estado' => true,
            ]);
            $usuario->roles()->sync($datos['roles']);

            BitacoraService::registrar('USUARIO_CREADO', 'Usuario', $this->resumen($usuario), $actor->id);

            return $usuario;
        });
    }

    /**
     * Actualiza datos y roles; la contraseña solo cambia si se envía una nueva (restablecer).
     * El administrador no puede quitarse a sí mismo el rol Administrador.
     *
     * @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar)
     *
     * @param  array{nombre_usuario: string, correo: string, password?: ?string, roles: list<int>}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(Usuario $usuario, array $datos, Usuario $actor): Usuario
    {
        $idAdministrador = Rol::where('nombre', Rol::ADMINISTRADOR)->value('id');
        if ($usuario->is($actor) && ! in_array($idAdministrador, array_map('intval', $datos['roles']), true)) {
            throw ValidationException::withMessages(['roles' => 'No puede quitarse a sí mismo el rol Administrador.']);
        }

        return DB::transaction(function () use ($usuario, $datos, $actor) {
            $rolesAntes = $usuario->nombresRoles()->sort()->values()->all();

            $cambios = [
                'nombre_usuario' => trim($datos['nombre_usuario']),
                'correo' => mb_strtolower(trim($datos['correo'])),
            ];
            $restablece = filled($datos['password'] ?? null);
            if ($restablece) {
                $cambios['password_hash'] = $datos['password'];
            }
            $usuario->update($cambios);
            $usuario->roles()->sync($datos['roles']);
            $usuario->load('roles');

            BitacoraService::registrar('USUARIO_EDITADO', 'Usuario', $this->resumen($usuario).'; roles antes: '.implode(', ', $rolesAntes), $actor->id);
            if ($restablece) {
                BitacoraService::registrar('CONTRASENA_RESTABLECIDA', 'Usuario', 'Contraseña restablecida de '.$usuario->nombre_usuario, $actor->id);
            }

            return $usuario;
        });
    }

    /**
     * Desactiva la cuenta (se conserva el registro y no puede iniciar sesión). Nunca la propia.
     *
     * @CC CCUsuarios (desactivar) @CE CEUsuario (desactivar)
     *
     * @throws DomainException
     */
    public function desactivar(Usuario $usuario, Usuario $actor): void
    {
        if ($usuario->is($actor)) {
            throw new DomainException('No puede desactivar su propia cuenta.');
        }

        $usuario->update(['estado' => false]);
        BitacoraService::registrar('USUARIO_DESACTIVADO', 'Usuario', 'Cuenta desactivada: '.$usuario->nombre_usuario, $actor->id);
    }

    /** @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar) */
    public function activar(Usuario $usuario, Usuario $actor): void
    {
        $usuario->update(['estado' => true]);
        BitacoraService::registrar('USUARIO_ACTIVADO', 'Usuario', 'Cuenta activada: '.$usuario->nombre_usuario, $actor->id);
    }

    private function resumen(Usuario $usuario): string
    {
        return $usuario->nombre_usuario.' ('.$usuario->correo.'); roles: '.$usuario->nombresRoles()->implode(', ');
    }
}
