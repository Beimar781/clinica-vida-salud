<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;

/** CU-09: Registrar cita médica. */
class CU09_RegistrarCitaMedicaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Especialidad $especialidad;

    private Medico $medico;

    private Paciente $paciente;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-cita', [Rol::RECEPCIONISTA]);
        $this->especialidad = $this->crearEspecialidad('T-Citas');
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->paciente = $this->crearPaciente(['nombres' => 'Cita', 'apellidos' => 'Tprueba']);
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Caso 1: programar una cita en un horario disponible (paciente, especialidad, médico, fecha, horario libre y modalidad) → la cita queda registrada con código, fecha de creación y estado Programada. */
    public function test_programar_una_cita_en_un_horario_disponible(): void
    {
        $respuesta = $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $respuesta->assertRedirect(route('citas.comprobante', $cita));

        $this->assertMatchesRegularExpression('/^CM-\d{4}$/', $cita->codigo);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame(Cita::PRESENCIAL, $cita->modalidad);
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);
        $this->assertSame('09:30', $cita->hora_fin_corta);
        $this->assertSame($this->recepcion->id, $cita->creado_por);
        $this->assertNotNull($cita->created_at);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REGISTRADA', 'usuario_id' => $this->recepcion->id]);

        // El comprobante muestra paciente, especialidad, médico, fecha, horario y motivo; el horario deja de estar disponible.
        $this->get(route('citas.comprobante', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Cita Tprueba')->assertSee('T-Citas')->assertSee($this->medico->nombre_completo)
            ->assertSee('09:00 – 09:30')->assertSee('Control de rutina');
        $this->assertNull(app(DisponibilidadService::class)->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
    }

    /** Caso 2: programar una cita superpuesta para el mismo médico (mismo médico, fecha y horario ya ocupado) → el sistema impide el conflicto y muestra un mensaje explicativo. */
    public function test_programar_una_cita_superpuesta_para_el_mismo_medico(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertRedirect($this->urlRegistrar())
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
        // Vuelve a mostrar los horarios libres: ya no aparece el de las 09:00.
        $this->get($this->urlRegistrar())->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('09:30 – 10:00')->assertDontSee('09:00 – 09:30');
    }

    /** Caso 3: intentar reservar fuera del horario del médico (hora fuera de sus bloques de atención) → el sistema rechaza la cita. */
    public function test_intentar_reservar_fuera_del_horario_del_medico(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['hora_inicio' => '13:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');

        // Una hora que no es el inicio de un bloque también se rechaza.
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:10']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /** Caso 4: intentar reservar en fecha pasada (fecha anterior a hoy) → el sistema rechaza la cita. */
    public function test_intentar_reservar_en_fecha_pasada(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /**
     * Caso 5: dos usuarios reservan el mismo horario a la vez (dos confirmaciones simultáneas) → solo una se registra y la otra es rechazada.
     * Aquí: las dos recepcionistas ven libre el mismo horario y la segunda confirma tarde. La simultaneidad real (dos procesos
     * y la restricción de la base de datos) está en CU09_RegistrarCitaMedicaConcurrenciaTest.
     */
    public function test_dos_usuarios_reservan_el_mismo_horario_a_la_vez(): void
    {
        $otraRecepcion = $this->crearUsuario('t-recep-cita-2', [Rol::RECEPCIONISTA]);
        $otroPaciente = $this->crearPaciente(['nombres' => 'Otro', 'apellidos' => 'Tpaciente']);

        // Las dos pantallas muestran el horario de las 09:00 como libre.
        $this->actingAs($this->recepcion)->get($this->urlRegistrar())->assertOk()->assertSee('09:00 – 09:30');
        $this->actingAs($otraRecepcion)->get($this->urlRegistrar($otroPaciente))->assertOk()->assertSee('09:00 – 09:30');

        // La primera confirma y gana; la segunda recibe el aviso y ve los horarios libres actualizados.
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())->assertRedirect();
        $this->actingAs($otraRecepcion)->post(route('citas.guardar'), $this->datos(['paciente_id' => $otroPaciente->id]))
            ->assertRedirect($this->urlRegistrar($otroPaciente))
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->where('estado', '<>', Cita::CANCELADA)->count());
        $this->get($this->urlRegistrar($otroPaciente))->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    /** Caso 6: programar una cita virtual (cita con modalidad Virtual) → la cita queda registrada con su modalidad Virtual y su fecha de creación. */
    public function test_programar_una_cita_virtual(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['modalidad' => 'VIRTUAL']))->assertRedirect();

        $cita = Cita::where('paciente_id', $this->paciente->id)->firstOrFail();
        $this->assertSame(Cita::VIRTUAL, $cita->modalidad);
        $this->assertNotNull($cita->created_at);
        $this->get(route('citas.comprobante', $cita))->assertOk()->assertSee('Virtual');
    }

    /** Caso 7: provocar un error interno al guardar (falla simulada de la base de datos) → el usuario ve un mensaje claro sin detalles técnicos y el error queda registrado internamente. */
    public function test_provocar_un_error_interno_al_guardar(): void
    {
        Exceptions::fake();
        $this->mock(AgendarCitaService::class, function ($mock) {
            $mock->shouldReceive('crear')->andThrow(new QueryException('pgsql', 'insert into "citas" (codigo) values (?)', ['CM-9999'], new \Exception('SQLSTATE[08006]: conexión perdida con el servidor 10.0.0.5')));
        });

        $respuesta = $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());

        $respuesta->assertRedirect($this->urlRegistrar())->assertSessionHas('error', 'No se pudo registrar la cita por un problema interno. Intente nuevamente; si el problema continúa, avise al administrador.');
        $mensaje = session('error');
        foreach (['SQLSTATE', 'insert', 'citas', '10.0.0.5', 'pgsql'] as $tecnico) {
            $this->assertStringNotContainsString($tecnico, $mensaje, "El mensaje no debe mostrar detalles técnicos ({$tecnico}).");
        }
        Exceptions::assertReported(QueryException::class);
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    public function test_la_base_de_datos_rechaza_la_superposicion_aunque_el_calculo_la_deje_pasar(): void
    {
        // Se simula que el cálculo de disponibilidad no ve la cita (como si la otra transacción aún no hubiera confirmado):
        // la restricción EXCLUDE de PostgreSQL es la última barrera (SQLSTATE 23P01) y el servicio la traduce al aviso.
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->app->instance(DisponibilidadService::class, new class extends DisponibilidadService
        {
            public function calcularLibres(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null, ?Carbon $ahora = null): Disponibilidad
            {
                $bloque = ['inicio' => '09:00', 'fin' => '09:30'];

                return new Disponibilidad([$bloque], [$bloque], null, null);
            }
        });

        try {
            app(AgendarCitaService::class)->crear($this->paciente, $this->medico, $this->especialidad, $this->lunes, '09:00', Cita::PRESENCIAL, null, $this->recepcion->id);
            $this->fail('La restricción EXCLUDE debió rechazar la cita superpuesta.');
        } catch (HorarioOcupadoException $e) {
            $this->assertSame(HorarioOcupadoException::MENSAJE, $e->getMessage());
        }
        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_no_se_registra_la_cita_de_un_paciente_inactivo(): void
    {
        $this->paciente->update(['estado' => false]);

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertSessionHas('error', 'El paciente está inactivo: no se le pueden registrar citas.');
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());

        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id]))->assertOk()->assertSee('El paciente está inactivo');
    }

    public function test_el_paciente_no_puede_tener_dos_citas_activas_a_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos())
            ->assertSessionHas('error', 'El paciente ya tiene una cita activa a esa hora.');

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());

        // Si la otra cita está cancelada, sí se puede.
        Cita::where('medico_id', $otroMedico->id)->update(['estado' => Cita::CANCELADA]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_no_se_registra_con_un_medico_inactivo_ni_en_un_dia_sin_atencion_ni_con_otra_especialidad(): void
    {
        $this->actingAs($this->recepcion);

        $otraEspecialidad = $this->crearEspecialidad('T-Otra');
        $this->post(route('citas.guardar'), $this->datos(['especialidad_id' => $otraEspecialidad->id]))
            ->assertSessionHas('error', 'El médico no atiende la especialidad elegida.');

        BloqueoAgenda::create(['fecha' => $this->lunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->lunes)->delete();

        $this->medico->update(['estado' => false]);
        $this->post(route('citas.guardar'), $this->datos())->assertSessionHas('error', DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_hoy_no_se_puede_reservar_un_horario_que_ya_paso(): void
    {
        Carbon::setTestNow(Carbon::parse($this->lunes.' 10:15:00'));

        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:00']))
            ->assertSessionHas('error', 'Ese horario ya pasó. Elija uno de los horarios libres.');
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '10:30']))->assertSessionMissing('error');
        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    public function test_los_datos_del_formulario_se_validan(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), ['modalidad' => 'TELEFONICA', 'fecha' => 'ayer', 'hora_inicio' => '9', 'motivo' => str_repeat('a', 201)])
            ->assertSessionHasErrors([
                'paciente_id' => 'Seleccione al paciente.', 'especialidad_id' => 'Seleccione la especialidad.', 'medico_id' => 'Seleccione el médico.',
                'fecha' => 'La fecha no es válida.', 'hora_inicio' => 'El horario seleccionado no es válido.',
                'modalidad' => 'La modalidad debe ser presencial o virtual.', 'motivo' => 'El motivo no puede superar los 200 caracteres.',
            ]);
    }

    public function test_cada_cita_recibe_un_codigo_distinto_de_la_secuencia(): void
    {
        $this->actingAs($this->recepcion)->post(route('citas.guardar'), $this->datos());
        $this->post(route('citas.guardar'), $this->datos(['hora_inicio' => '09:30']));

        $codigos = Cita::where('paciente_id', $this->paciente->id)->orderBy('id')->pluck('codigo')->all();
        $this->assertCount(2, $codigos);
        $this->assertCount(2, array_unique($codigos));
        $this->assertGreaterThan((int) substr($codigos[0], 3), (int) substr($codigos[1], 3));
    }

    public function test_la_pantalla_busca_al_paciente_y_ofrece_los_medicos_de_la_especialidad_y_sus_horarios(): void
    {
        $this->actingAs($this->recepcion);

        $this->get(route('citas.registrar', ['q' => $this->paciente->ci]))->assertOk()->assertSee('Cita Tprueba')->assertSee('Seleccionar');
        $this->get(route('citas.registrar', ['q' => 'tprueba']))->assertOk()->assertSee('Cita Tprueba');
        $this->get(route('citas.registrar', ['q' => 'no-existe-xyz']))->assertOk()->assertSee('No se encontraron pacientes');

        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id, 'especialidad' => $this->especialidad->id]))
            ->assertOk()->assertSee($this->medico->nombre_completo);

        $this->get($this->urlRegistrar())->assertOk()->assertSee('Horarios libres (8)')->assertSee('Confirmar cita');

        // Un día en que el médico no atiende: se indica y no se muestran horarios.
        $this->get(route('citas.registrar', ['paciente' => $this->paciente->id, 'especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->proximaFecha(2)]))
            ->assertOk()->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar cita');
    }

    public function test_un_paciente_inactivo_aparece_en_la_busqueda_pero_no_se_puede_seleccionar(): void
    {
        $inactivo = $this->crearPaciente(['nombres' => 'Baja', 'apellidos' => 'Tinactivo', 'estado' => false]);

        $this->actingAs($this->recepcion)->get(route('citas.registrar', ['q' => $inactivo->ci]))
            ->assertOk()->assertSee('Baja Tinactivo')->assertSee('No se puede registrar cita');
    }

    public function test_solo_la_recepcionista_registra_citas(): void
    {
        $this->get(route('citas.registrar'))->assertRedirect('/login');

        // El Administrador hereda las funciones de recepción y también puede abrir la pantalla.
        $admin = $this->crearUsuario('t-admin-cita', [Rol::ADMINISTRADOR]);
        $this->actingAs($admin)->get(route('citas.registrar'))->assertOk();

        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-cita-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('citas.registrar'))->assertForbidden();
            $this->actingAs($usuario)->post(route('citas.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
    }

    /** Datos válidos de la cita de las 09:00 del lunes; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'paciente_id' => $this->paciente->id, 'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id,
            'fecha' => $this->lunes, 'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => 'Control de rutina',
        ];
    }

    private function urlRegistrar(?Paciente $paciente = null): string
    {
        return route('citas.registrar', [
            'paciente' => ($paciente ?? $this->paciente)->id, 'especialidad' => $this->especialidad->id,
            'medico' => $this->medico->id, 'fecha' => $this->lunes,
        ]);
    }
}
