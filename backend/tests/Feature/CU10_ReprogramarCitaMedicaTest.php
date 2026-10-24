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
use App\Modules\GestionCitas\CU10_ReprogramarCitaMedica\ReprogramarCitaService;
use Illuminate\Support\Carbon;

/** CU-10: Reprogramar cita médica. */
class CU10_ReprogramarCitaMedicaTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Especialidad $especialidad;

    private Medico $medico;

    private Paciente $paciente;

    private string $lunes;

    private string $otroLunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-rep', [Rol::RECEPCIONISTA]);
        $this->especialidad = $this->crearEspecialidad('T-Reprogramar');
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->paciente = $this->crearPaciente(['nombres' => 'Reprog', 'apellidos' => 'Tprueba']);
        $this->lunes = $this->proximaFecha(1);
        // El lunes siguiente al primero (si hay un feriado de por medio, el primer lunes libre posterior).
        $this->otroLunes = $this->proximaFecha(1, (int) Carbon::today()->diffInDays(Carbon::parse($this->lunes)) + 1);
    }

    /** Caso 1: reprogramar una cita a un horario libre (cita Programada y nuevo horario libre del mismo médico) → la cita cambia de fecha y hora, conserva su código y el horario anterior queda libre. */
    public function test_reprogramar_una_cita_a_un_horario_libre(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $codigo = $cita->codigo;

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramada', $cita))
            ->assertSessionHas('exito')
            ->assertSessionHas('reprogramacion.motivo', 'El paciente pidió otro día');

        $cita->refresh();
        $this->assertSame($codigo, $cita->codigo);
        $this->assertSame($this->otroLunes, $cita->fecha->toDateString());
        $this->assertSame('10:00', $cita->hora_inicio_corta);
        $this->assertSame('10:30', $cita->hora_fin_corta);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_REPROGRAMADA', 'usuario_id' => $this->recepcion->id]);

        // El horario anterior queda libre y el nuevo ocupado.
        $disponibilidad = app(DisponibilidadService::class);
        $this->assertNotNull($disponibilidad->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
        $this->assertNull($disponibilidad->calcularLibres($this->medico, $this->otroLunes)->libre('10:00'));

        // El comprobante actualizado muestra el horario anterior y el motivo.
        $this->get(route('citas.reprogramada', $cita))->assertOk()
            ->assertSee($codigo)->assertSee('10:00 – 10:30')->assertSee('Horario anterior')->assertSee('El paciente pidió otro día');
    }

    /** Caso 2: reprogramar a un horario ocupado (nuevo horario ya ocupado por otra cita) → el sistema rechaza el cambio y muestra los horarios libres. */
    public function test_reprogramar_a_un_horario_ocupado(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->otroLunes, '10:00', '10:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertRedirect(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $cita->refresh();
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('10:30 – 11:00')->assertDontSee('10:00 – 10:30');
    }

    /** Caso 3: reprogramar una cita atendida (cita en estado Atendida) → el sistema no permite reprogramarla y sugiere registrar una nueva cita. */
    public function test_reprogramar_una_cita_atendida(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::ATENDIDA, 'La cita está atendida: no se puede reprogramar. Registre una cita nueva.');
    }

    /** Caso 4: reprogramar una cita cancelada (cita en estado Cancelada) → el sistema no permite reprogramarla y sugiere registrar una nueva cita. */
    public function test_reprogramar_una_cita_cancelada(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::CANCELADA, 'La cita está cancelada: no se puede reprogramar. Registre una cita nueva.');
    }

    public function test_una_cita_con_inasistencia_tampoco_se_reprograma(): void
    {
        $this->assertNoSePuedeReprogramar(Cita::INASISTENCIA, 'La cita está con inasistencia: no se puede reprogramar. Registre una cita nueva.');
    }

    public function test_una_cita_confirmada_se_reprograma_y_vuelve_a_programada(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30', Cita::CONFIRMADA);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())->assertRedirect(route('citas.reprogramada', $cita));

        $this->assertSame(Cita::PROGRAMADA, $cita->fresh()->estado);
    }

    public function test_el_motivo_del_cambio_es_obligatorio(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['motivo_cambio' => '']))
            ->assertSessionHasErrors(['motivo_cambio' => 'Ingrese el motivo del cambio.']);
        $this->put(route('citas.actualizar', $cita), ['fecha' => 'x', 'hora_inicio' => ''])
            ->assertSessionHasErrors(['fecha' => 'La fecha no es válida.', 'hora_inicio' => 'Seleccione uno de los horarios libres.']);

        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    public function test_no_se_reprograma_a_una_fecha_pasada_a_un_feriado_ni_fuera_del_horario(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->actingAs($this->recepcion);

        $this->put(route('citas.actualizar', $cita), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        BloqueoAgenda::create(['fecha' => $this->otroLunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->put(route('citas.actualizar', $cita), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->otroLunes)->delete();

        $this->put(route('citas.actualizar', $cita), $this->datos(['hora_inicio' => '15:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');
        $this->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->proximaFecha(2)]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_NO_ATIENDE);

        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    public function test_no_se_reprograma_al_mismo_horario_que_ya_tiene(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        // Su propio horario aparece libre para ella misma (al reprogramar no choca consigo), pero igual se pide otro.
        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->lunes, 'hora_inicio' => '09:00']))
            ->assertSessionHas('error', 'El nuevo horario es el mismo que ya tiene la cita. Elija otro.');
    }

    public function test_se_puede_mover_dentro_del_mismo_dia_a_un_bloque_que_solapa_con_su_horario_actual(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos(['fecha' => $this->lunes, 'hora_inicio' => '09:30']))
            ->assertRedirect(route('citas.reprogramada', $cita));

        $this->assertSame('09:30', $cita->fresh()->hora_inicio_corta);
    }

    public function test_el_paciente_no_puede_quedar_con_dos_citas_a_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->otroLunes, '10:00', '10:30');

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertSessionHas('error', 'El paciente ya tiene una cita activa a esa hora.');
        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    public function test_la_base_de_datos_rechaza_la_superposicion_aunque_el_calculo_la_deje_pasar(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->otroLunes, '10:00', '10:30');
        $this->app->instance(DisponibilidadService::class, new class extends DisponibilidadService
        {
            public function calcularLibres(Medico $medico, Carbon|string $fecha, ?int $ignorarCitaId = null, ?Carbon $ahora = null): Disponibilidad
            {
                $bloque = ['inicio' => '10:00', 'fin' => '10:30'];

                return new Disponibilidad([$bloque], [$bloque], null, null);
            }
        });

        $this->expectException(HorarioOcupadoException::class);
        app(AgendarCitaService::class)->mover($cita, $this->otroLunes, '10:00');
    }

    public function test_no_se_reprograma_con_un_medico_inactivo(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->medico->update(['estado' => false]);

        $this->actingAs($this->recepcion)->put(route('citas.actualizar', $cita), $this->datos())
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_MEDICO_INACTIVO);
    }

    public function test_la_pantalla_muestra_la_cita_y_los_horarios_libres_del_mismo_medico(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->actingAs($this->recepcion);

        $this->get(route('citas.reprogramar', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Reprog Tprueba')->assertSee('Nueva fecha con '.$this->medico->nombre_completo)->assertDontSee('Confirmar reprogramación');

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))->assertOk()
            ->assertSee('Horarios libres (8)')->assertSee('Motivo del cambio')->assertSee('Confirmar reprogramación');

        $this->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->proximaFecha(2)]))->assertOk()
            ->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar reprogramación');
    }

    public function test_busca_la_cita_por_codigo_ci_o_nombre_del_paciente(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $otra = $this->crearCita($this->medico, $this->crearPaciente(['nombres' => 'Otra', 'apellidos' => 'Tdistinta']), $this->especialidad, $this->lunes, '10:00', '10:30', Cita::ATENDIDA);
        $this->actingAs($this->recepcion);

        $this->get(route('citas.index', ['q' => $cita->codigo]))->assertOk()->assertSee('Reprog Tprueba')->assertSee('Reprogramar</a>', false)->assertDontSee('Otra Tdistinta');
        $this->get(route('citas.index', ['q' => $this->paciente->ci]))->assertOk()->assertSee($cita->codigo)->assertDontSee($otra->codigo);
        $this->get(route('citas.index', ['q' => 'tdistinta']))->assertOk()->assertSee($otra->codigo)->assertDontSee($cita->codigo);
        $this->get(route('citas.index', ['q' => 'no-existe-xyz']))->assertOk()->assertSee('No se encontraron citas');

        // Una cita atendida aparece en la búsqueda pero sin botón «Reprogramar».
        $this->get(route('citas.index', ['q' => $otra->codigo]))->assertOk()->assertSee($otra->codigo)->assertDontSee('Reprogramar</a>', false);
    }

    public function test_sin_texto_el_listado_muestra_las_citas_vigentes_desde_hoy(): void
    {
        $vigente = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $cancelada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '10:00', '10:30', Cita::CANCELADA);
        $pasada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, '2020-02-03', '10:00', '10:30');

        $this->actingAs($this->recepcion)->get(route('citas.index', ['page' => 1]))->assertOk()->assertSee('Citas vigentes');
        $codigos = app(ReprogramarCitaService::class)->buscar(null)->getCollection()->pluck('codigo');
        $this->assertTrue($codigos->contains($vigente->codigo) || $codigos->count() === 10, 'La cita vigente debe estar entre las próximas.');
        $this->assertFalse($codigos->contains($cancelada->codigo));
        $this->assertFalse($codigos->contains($pasada->codigo));
    }

    public function test_solo_la_recepcionista_reprograma_citas(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->get(route('citas.index'))->assertRedirect('/login');

        foreach ([Rol::ADMINISTRADOR, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-rep-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('citas.index'))->assertForbidden();
            $this->actingAs($usuario)->get(route('citas.reprogramar', $cita))->assertForbidden();
            $this->actingAs($usuario)->put(route('citas.actualizar', $cita), $this->datos())->assertForbidden();
        }
        $this->assertSame($this->lunes, $cita->fresh()->fecha->toDateString());
    }

    /** La cita en ese estado no se reprograma: ni la pantalla ni el envío lo permiten y se sugiere registrar una cita nueva. */
    private function assertNoSePuedeReprogramar(string $estado, string $mensaje): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30', $estado);

        $this->actingAs($this->recepcion)->get(route('citas.reprogramar', ['cita' => $cita->id, 'fecha' => $this->otroLunes]))
            ->assertOk()->assertSee($mensaje)->assertSee('Registrar una cita nueva')->assertDontSee('Confirmar reprogramación');

        $this->put(route('citas.actualizar', $cita), $this->datos())->assertSessionHas('error', $mensaje);

        $cita->refresh();
        $this->assertSame($estado, $cita->estado);
        $this->assertSame($this->lunes, $cita->fecha->toDateString());
        $this->assertSame('09:00', $cita->hora_inicio_corta);
    }

    /** Datos válidos para pasar la cita al otro lunes a las 10:00. */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['fecha' => $this->otroLunes, 'hora_inicio' => '10:00', 'motivo_cambio' => 'El paciente pidió otro día'];
    }
}
