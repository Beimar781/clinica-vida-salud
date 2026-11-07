<?php

namespace App\Modules\GestionPacientes\CU20_RegistrarsePaciente;

use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Support\Codigos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registro de un paciente desde el sitio web: crea la cuenta (rol Paciente, contraseña con hash), la ficha con su código
 * y su historia clínica en una sola transacción, e inicia la sesión. Si la recepción ya había registrado ese CI sin cuenta,
 * verifica la fecha de nacimiento y vincula la cuenta nueva a esa ficha, conservando su historia clínica y sus citas.
 *
 * @CU CU-20 @RF RF20 @CI CIRegistro @CC CCRegistro @CE CEUsuario (crear), CEPaciente (existeCI, crear), CEHistoriaClinica (crearParaPaciente)
 */
class RegistroPacienteService
{
    public const MENSAJE_CI_CON_CUENTA = 'Ese CI ya tiene una cuenta. Inicia sesión.';

    public const MENSAJE_CORREO_CON_CUENTA = 'Ese correo ya tiene una cuenta. Inicia sesión.';

    public const MENSAJE_NO_COINCIDE = 'Los datos no coinciden con una ficha registrada. Acude a recepción.';

    /**
     * ¿Ya existe una ficha con ese CI? (null si el CI es nuevo.)
     *
     * @CC CCRegistro (verificarCI) @CE CEPaciente (existeCI)
     */
    public function verificarCI(string $ci): ?Paciente
    {
        return Paciente::where('ci', $ci)->first();
    }

    /**
     * Pasos 4 a 6: verifica CI y correo, crea la cuenta, la ficha y la historia (o vincula la ficha de recepción) e inicia la sesión.
     *
     * @CC CCRegistro (registrar, crearCuenta) @CE CEUsuario (crear), CEPaciente (crear), CEHistoriaClinica (crearParaPaciente)
     *
     * @param  array<string, mixed>  $datos  campos validados de RegistroRequest
     * @return array{usuario: Usuario, paciente: Paciente, vinculada: bool}
     *
     * @throws ValidationException si el CI o el correo ya tienen cuenta, o la ficha no coincide
     */
    public function registrar(array $datos): array
    {
        try {
            $resultado = DB::transaction(function () use ($datos) {
                if (Usuario::where('correo', $datos['correo'])->exists()) {
                    throw ValidationException::withMessages(['correo' => self::MENSAJE_CORREO_CON_CUENTA]);
                }

                // Se bloquea la ficha del CI para que dos registros simultáneos no la vinculen a dos cuentas.
                $ficha = Paciente::where('ci', $datos['ci'])->lockForUpdate()->first();
                if ($ficha?->usuario_id !== null) {
                    throw ValidationException::withMessages(['ci' => self::MENSAJE_CI_CON_CUENTA]);
                }
                if ($ficha !== null && ! $this->coincide($ficha, $datos)) {
                    throw ValidationException::withMessages(['fecha_nacimiento' => self::MENSAJE_NO_COINCIDE]);
                }

                $usuario = $this->crearCuenta($datos);

                if ($ficha !== null) {
                    // La ficha de recepción conserva sus datos, su historia y sus citas; solo se le agrega la cuenta (y el correo si no tenía).
                    $ficha->usuario_id = $usuario->id;
                    $ficha->correo ??= $datos['correo'];
                    $ficha->save();
                    HistoriaClinica::firstOrCreate(['paciente_id' => $ficha->id]);
                    BitacoraService::registrar(
                        'FICHA_VINCULADA', 'Paciente',
                        "Cuenta {$usuario->nombre_usuario} vinculada a la ficha {$ficha->codigo} registrada por recepción (CI {$ficha->ci}); se conserva su historia clínica",
                        $usuario->id
                    );

                    return ['usuario' => $usuario, 'paciente' => $ficha, 'vinculada' => true];
                }

                $paciente = Paciente::create([
                    'usuario_id' => $usuario->id,
                    'codigo' => Codigos::paciente(),
                    'ci' => $datos['ci'],
                    'nombres' => $datos['nombres'],
                    'apellidos' => $datos['apellidos'],
                    'fecha_nacimiento' => $datos['fecha_nacimiento'],
                    'sexo' => $datos['sexo'] ?? null,
                    'telefono' => $datos['telefono'],
                    'correo' => $datos['correo'],
                    'recibe_recordatorios' => true,
                    'mostrar_contacto_medico' => true,
                    'estado' => true,
                ]);
                HistoriaClinica::crearParaPaciente($paciente);
                BitacoraService::registrar(
                    'PACIENTE_REGISTRADO', 'Paciente',
                    "Registro desde la web: {$paciente->codigo} {$paciente->nombre_completo} (CI {$paciente->ci}), cuenta {$usuario->nombre_usuario}; historia clínica creada",
                    $usuario->id
                );

                return ['usuario' => $usuario, 'paciente' => $paciente, 'vinculada' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // Dos registros simultáneos con el mismo CI o correo: la restricción UNIQUE de la base rechaza el segundo.
            throw ValidationException::withMessages(['ci' => self::MENSAJE_CI_CON_CUENTA]);
        }

        $this->iniciarSesion($resultado['usuario']);

        return $resultado;
    }

    /** La ficha de recepción pertenece a quien se registra si está activa y la fecha de nacimiento coincide. */
    private function coincide(Paciente $ficha, array $datos): bool
    {
        return $ficha->estado && $ficha->fecha_nacimiento->toDateString() === $datos['fecha_nacimiento'];
    }

    /**
     * Cuenta con el rol Paciente (y solo ese): quien se registra desde la web nunca recibe otro rol.
     *
     * @CE CEUsuario (crear)
     */
    private function crearCuenta(array $datos): Usuario
    {
        $usuario = Usuario::create([
            'nombre_usuario' => $this->nombreUsuarioLibre($datos['correo']),
            'correo' => $datos['correo'],
            'password_hash' => $datos['password'], // el cast «hashed» del modelo guarda el hash bcrypt
            'estado' => true,
        ]);
        $usuario->roles()->attach(Rol::where('nombre', Rol::PACIENTE)->value('id'));

        return $usuario;
    }

    /** Nombre de usuario a partir de la parte local del correo (minúsculas, sin símbolos raros), con número si ya existe. */
    private function nombreUsuarioLibre(string $correo): string
    {
        $base = Str::of(Str::before($correo, '@'))->lower()->replaceMatches('/[^a-z0-9._-]/', '')->limit(40, '')->toString();
        $base = $base !== '' ? $base : 'paciente';

        $nombre = $base;
        for ($n = 2; Usuario::where('nombre_usuario', $nombre)->exists(); $n++) {
            $nombre = $base.$n;
        }

        return $nombre;
    }

    /** Paso 6: abre la sesión igual que el inicio de sesión (CU-01): sesión nueva, último acceso y bitácora. */
    private function iniciarSesion(Usuario $usuario): void
    {
        Auth::login($usuario);
        request()->session()->regenerate();
        $usuario->registrarAcceso();
        BitacoraService::registrar('LOGIN', 'Usuario', 'Inicio de sesión de '.$usuario->nombre_usuario.' (registro nuevo)', $usuario->id);
    }
}
