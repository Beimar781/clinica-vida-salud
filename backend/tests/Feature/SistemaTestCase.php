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
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    /** Centavos de la última sesión de Checkout que el sistema pidió a la API fingida de Stripe. */
    protected ?int $centavosStripe = null;

    /** Estado que la API fingida de Stripe le da al PaymentIntent después de capturarlo o cancelarlo. */
    protected ?string $estadoFinalStripe = null;

    /** Token de la reserva que el sistema envió en metadata al crear la sesión de Checkout. */
    protected ?string $tokenStripe = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Ninguna prueba sale a internet: toda petición HTTP debe estar simulada (Stripe, IA...), y el pago con
        // tarjeta solo está disponible en las pruebas que lo activan con activarStripe().
        Http::preventStrayRequests();
        config(['services.stripe.secret' => null]);
    }

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

    /** Especialidad o servicio de prueba; el nombre normalizado se calcula igual que en el sistema (v30: tipo y precio en Bs). */
    protected function crearEspecialidad(string $nombre = 'T-Especialidad', bool $activa = true, string $tipo = Especialidad::CONSULTA, float $precio = 0): Especialidad
    {
        return Especialidad::create([
            'nombre' => $nombre,
            'nombre_normalizado' => Texto::normalizar($nombre),
            'tipo' => $tipo,
            'precio' => $precio,
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

    /**
     * Día sin atención de prueba. Desde el v30 la tabla exige registrado_por: si no se indica, lo registra el
     * administrador de demostración.
     *
     * @param  array{fecha: string, motivo: string, estado?: bool, registrado_por?: int}  $datos
     */
    protected function crearBloqueo(array $datos): BloqueoAgenda
    {
        return BloqueoAgenda::create($datos + [
            'registrado_por' => Usuario::where('nombre_usuario', 'admin')->value('id'),
        ]);
    }

    /** Activa el pago con tarjeta en la prueba (clave falsa; la API de Stripe se simula con fingirStripe()). */
    protected function activarStripe(float $tipoCambio = 1, string $moneda = 'bob'): void
    {
        config(['services.stripe.secret' => 'sk_test_clave_de_prueba', 'vidasalud.tipo_cambio' => $tipoCambio, 'vidasalud.stripe_moneda' => $moneda]);
    }

    /**
     * Simula la API REST de Stripe (nunca se llama a Stripe de verdad). Opciones:
     *  'crear' (200) · 'consultar' (200) · 'captura' (200; otro valor = Stripe rechaza la captura) · 'estado' del PaymentIntent
     *  ('requires_capture' = pago autorizado) · 'token' (por defecto el que envió el sistema al crear la sesión) · 'centavos' y
     *  'moneda' (por defecto los que pidió el sistema) · 'sesion' y 'intento' (identificadores).
     */
    protected function fingirStripe(array $opciones = []): void
    {
        $o = $opciones + [
            'sesion' => 'cs_test_123', 'intento' => 'pi_test_123', 'estado' => 'requires_capture', 'crear' => 200,
            'consultar' => 200, 'captura' => 200, 'token' => null, 'centavos' => null, 'moneda' => null,
        ];
        // Cliente HTTP nuevo: un segundo fingirStripe() REEMPLAZA al anterior (Http::fake() acumularía simuladores y ganaría el primero).
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();

        $this->centavosStripe = null;
        $this->estadoFinalStripe = null;
        $this->tokenStripe = null;
        $moneda = fn () => $o['moneda'] ?? config('vidasalud.stripe_moneda');

        Http::fake(function (Request $peticion) use ($o, $moneda) {
            $url = $peticion->url();
            $error = fn (int $http) => Http::response(['error' => ['type' => 'api_error', 'code' => 'prueba', 'message' => 'Error simulado']], $http);

            if ($peticion->method() === 'POST' && $url === 'https://api.stripe.com/v1/checkout/sessions') {
                if ($o['crear'] !== 200) {
                    return $error($o['crear']);
                }
                $this->centavosStripe = (int) $peticion['line_items'][0]['price_data']['unit_amount'];
                $this->tokenStripe = $peticion['metadata']['token'] ?? null;

                return Http::response(['id' => $o['sesion'], 'url' => 'https://checkout.stripe.com/c/pay/'.$o['sesion'], 'payment_intent' => null]);
            }
            if ($peticion->method() === 'GET' && str_starts_with($url, 'https://api.stripe.com/v1/checkout/sessions/'.$o['sesion'])) {
                if ($o['consultar'] !== 200) {
                    return $error($o['consultar']);
                }

                return Http::response([
                    'id' => $o['sesion'], 'url' => null,
                    'metadata' => ['token' => $o['token'] ?? $this->tokenStripe ?? 'sin-token'],
                    'payment_intent' => ['id' => $o['intento'], 'status' => $this->estadoFinalStripe ?? $o['estado'], 'amount' => $o['centavos'] ?? $this->centavosStripe, 'currency' => $moneda()],
                ]);
            }
            if ($peticion->method() === 'POST' && $url === 'https://api.stripe.com/v1/payment_intents/'.$o['intento'].'/capture') {
                if ($o['captura'] !== 200) {
                    return $error($o['captura']);
                }
                $this->estadoFinalStripe = 'succeeded';

                return Http::response(['id' => $o['intento'], 'status' => 'succeeded']);
            }
            if ($peticion->method() === 'POST' && $url === 'https://api.stripe.com/v1/payment_intents/'.$o['intento'].'/cancel') {
                $this->estadoFinalStripe = 'canceled';

                return Http::response(['id' => $o['intento'], 'status' => 'canceled']);
            }

            return $error(404);
        });
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
