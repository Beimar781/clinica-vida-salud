<?php

namespace App\Modules\SeguridadUsuarios\CU03_GestionarUsuariosRoles;

use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacienteService;
use App\Services\BitacoraService;
use App\Support\Codigos;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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

    /** Qué pasó con la ficha en el último guardar/actualizar: ['paciente' => Paciente, 'vinculada' => bool] o null (para el mensaje en pantalla). */
    public ?array $fichaResultante = null;

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
            $this->asegurarFicha($usuario, $datos, $actor);

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
            $this->asegurarFicha($usuario, $datos, $actor);

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

    /**
     * Garantiza que ninguna cuenta con el rol Paciente quede sin ficha (dentro de la transacción del alta o la edición): si ya existe una ficha
     * con ese CI y sin cuenta, la vincula (conserva su historia y sus citas); si esa ficha ya tiene otra cuenta, rechaza; si no existe, crea la
     * ficha (con su código y su historia clínica). Quitar el rol Paciente NO borra la ficha.
     *
     * @CC CCUsuarios (guardar, actualizar) @CE CEPaciente (existeCI, crear), CEHistoriaClinica (crearParaPaciente)
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    private function asegurarFicha(Usuario $usuario, array $datos, Usuario $actor): void
    {
        $this->fichaResultante = null;
        $idPaciente = Rol::where('nombre', Rol::PACIENTE)->value('id');
        $tieneRol = in_array($idPaciente, array_map('intval', $datos['roles'] ?? []), true);

        if (! $tieneRol || Paciente::where('usuario_id', $usuario->id)->exists()) {
            return;
        }

        $ci = PacienteService::normalizarCI((string) ($datos['ci'] ?? ''));
        try {
            $ficha = Paciente::where('ci', $ci)->lockForUpdate()->first();

            if ($ficha && $ficha->usuario_id !== null) {
                throw ValidationException::withMessages(['ci' => "Ese CI ya pertenece a la ficha {$ficha->codigo}, que ya tiene otra cuenta de usuario."]);
            }

            if ($ficha) {
                $ficha->usuario_id = $usuario->id;
                $ficha->correo ??= $usuario->correo;
                $ficha->save();
                HistoriaClinica::firstOrCreate(['paciente_id' => $ficha->id]);
                BitacoraService::registrar(
                    'FICHA_VINCULADA', 'Paciente',
                    "Cuenta {$usuario->nombre_usuario} vinculada por {$actor->nombre_usuario} a la ficha {$ficha->codigo} (CI {$ficha->ci}); se conservan su historia clínica y sus citas",
                    $actor->id
                );
                $this->fichaResultante = ['paciente' => $ficha, 'vinculada' => true];

                return;
            }

            $ficha = Paciente::create([
                'usuario_id' => $usuario->id,
                'codigo' => Codigos::paciente(),
                'ci' => $ci,
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'],
                'telefono' => $datos['telefono'],
                'correo' => $usuario->correo,
                'recibe_recordatorios' => true,
                'mostrar_contacto_medico' => true,
                'estado' => true,
            ]);
            HistoriaClinica::crearParaPaciente($ficha);
            BitacoraService::registrar(
                'PACIENTE_REGISTRADO', 'Paciente',
                "Ficha {$ficha->codigo} {$ficha->nombre_completo} (CI {$ficha->ci}) creada por {$actor->nombre_usuario} junto con la cuenta {$usuario->nombre_usuario}; historia clínica creada",
                $actor->id
            );
            $this->fichaResultante = ['paciente' => $ficha, 'vinculada' => false];
        } catch (UniqueConstraintViolationException) {
            // Dos altas a la vez con el mismo CI: la restricción UNIQUE de la base rechaza la segunda.
            throw ValidationException::withMessages(['ci' => 'Ese CI ya está registrado en otra ficha. Vuelva a intentarlo.']);
        }
    }

    private function resumen(Usuario $usuario): string
    {
        return $usuario->nombre_usuario.' ('.$usuario->correo.'); roles: '.$usuario->nombresRoles()->implode(', ');
    }
}
