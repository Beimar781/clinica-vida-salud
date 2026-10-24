<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Texto;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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

    /** Especialidad de prueba; el nombre normalizado se calcula igual que en el sistema. */
    protected function crearEspecialidad(string $nombre = 'T-Especialidad', bool $activa = true): Especialidad
    {
        return Especialidad::create([
            'nombre' => $nombre,
            'nombre_normalizado' => Texto::normalizar($nombre),
            'estado' => $activa,
        ]);
    }

    /** Médico de prueba (código y registro «T-...»), con las especialidades indicadas. */
    protected function crearMedico(array $datos = [], array $especialidades = []): Medico
    {
        $sufijo = strtoupper(Str::random(6));
        $medico = Medico::create($datos + [
            'codigo' => 'T-'.$sufijo,
            'nro_registro' => 'T-REG-'.$sufijo,
            'nombres' => 'Prueba',
            'apellidos' => 'Médico '.$sufijo,
            'ubicacion' => 'Sede de prueba',
            'estado' => true,
        ]);
        $medico->especialidades()->sync(collect($especialidades)->map(fn ($e) => $e->id)->all());

        return $medico;
    }

    /** Paciente de prueba (CI «T-...»). */
    protected function crearPaciente(array $datos = []): Paciente
    {
        $sufijo = strtoupper(Str::random(6));

        return Paciente::create($datos + [
            'codigo' => 'T-'.$sufijo,
            'ci' => 'T-'.$sufijo,
            'nombres' => 'Paciente',
            'apellidos' => 'Prueba '.$sufijo,
            'fecha_nacimiento' => '1990-01-01',
            'telefono' => '70000000',
            'estado' => true,
        ]);
    }

    /** Bloque de atención de un médico (dia_semana: 1 = lunes ... 7 = domingo). */
    protected function crearHorario(Medico $medico, int $dia, string $inicio, string $fin, int $duracion = 30, bool $activo = true): HorarioAtencion
    {
        return HorarioAtencion::create([
            'medico_id' => $medico->id, 'dia_semana' => $dia, 'hora_inicio' => $inicio,
            'hora_fin' => $fin, 'duracion_min' => $duracion, 'estado' => $activo,
        ]);
    }

    /** Cita de prueba (código «T-...»). */
    protected function crearCita(Medico $medico, Paciente $paciente, Especialidad $especialidad, string $fecha, string $inicio, string $fin, string $estado = Cita::PROGRAMADA): Cita
    {
        return Cita::create([
            'codigo' => 'T-'.strtoupper(Str::random(6)), 'paciente_id' => $paciente->id, 'medico_id' => $medico->id,
            'especialidad_id' => $especialidad->id, 'fecha' => $fecha, 'hora_inicio' => $inicio, 'hora_fin' => $fin,
            'estado' => $estado,
        ]);
    }

    /**
     * Próxima fecha (Y-m-d) con ese día de la semana (1 = lunes ... 7 = domingo), al menos $desdeDias días
     * después de hoy, que no sea un día sin atención ya registrado (por ejemplo los feriados de demostración).
     */
    protected function proximaFecha(int $diaSemana, int $desdeDias = 7): string
    {
        $fecha = Carbon::today()->addDays($desdeDias);

        while ($fecha->dayOfWeekIso !== $diaSemana || BloqueoAgenda::where('estado', true)->whereDate('fecha', $fecha->toDateString())->exists()) {
            $fecha->addDay();
        }

        return $fecha->toDateString();
    }
}
