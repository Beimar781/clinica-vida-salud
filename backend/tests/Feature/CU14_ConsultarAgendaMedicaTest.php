<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\CU14_ConsultarAgendaMedica\AgendaService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/** CU-14: Consultar agenda médica. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU14_ConsultarAgendaMedicaTest extends SistemaTestCase
{
    private Usuario $usuarioMedico;

    private Usuario $recepcion;

    private Usuario $admin;

    private Medico $medico;

    private Especialidad $especialidad;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioMedico = $this->crearUsuario('t-medico-ag', [Rol::MEDICO]);
        $this->recepcion = $this->crearUsuario('t-recep-ag', [Rol::RECEPCIONISTA]);
        $this->admin = $this->crearUsuario('t-admin-ag', [Rol::ADMINISTRADOR]);
        $this->especialidad = $this->crearEspecialidad('T-Agenda', precio: 100);
        $this->medico = $this->crearMedico(['usuario_id' => $this->usuarioMedico->id, 'nombres' => 'Agenda', 'apellidos' => 'Tmédica'], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: consultar la agenda de un médico (médico y fecha con citas) → se muestran las citas ordenadas por hora con su estado. */
    #[Test]
    public function paso_1_consultar_la_agenda_de_un_medico(): void
    {
        $tarde = $this->cita('11:00', Cita::PROGRAMADA, 'Control de presión');
        $temprano = $this->cita('08:30', Cita::CONFIRMADA, 'Dolor de cabeza');
        $medio = $this->cita('09:30', Cita::ATENDIDA, null);

        $pantalla = $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()
            ->assertSee('Agenda médica')->assertSee('08:30 – 09:00')->assertSee('Dolor de cabeza')->assertSee('Control de presión')
            ->assertSee($temprano->codigo)->assertSee('Confirmada')->assertSee('Atendida')->assertSee('Programada')->assertSee('T-Agenda');

        $contenido = $pantalla->getContent();
        $this->assertTrue(
            strpos($contenido, $temprano->codigo) < strpos($contenido, $medio->codigo) && strpos($contenido, $medio->codigo) < strpos($contenido, $tarde->codigo),
            'Las citas salen ordenadas por hora.'
        );
    }

    /** Paso 2: identificar citas pendientes, atendidas y canceladas (agenda con citas en distintos estados) → cada cita muestra su estado y se puede filtrar por estado. */
    #[Test]
    public function paso_2_identificar_citas_pendientes_atendidas_y_canceladas(): void
    {
        $programada = $this->cita('08:00', Cita::PROGRAMADA);
        $confirmada = $this->cita('08:30', Cita::CONFIRMADA);
        $atendida = $this->cita('09:00', Cita::ATENDIDA);
        $cancelada = $this->cita('09:30', Cita::CANCELADA);
        $inasistencia = $this->cita('10:00', Cita::INASISTENCIA);
        $this->actingAs($this->usuarioMedico);

        // Resumen y estado de cada cita.
        $todas = $this->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk();
        $this->assertSame(['pendientes' => 2, 'atendidas' => 1, 'canceladas' => 1, 'inasistencias' => 1], $todas->viewData('resumen'));
        foreach ([$programada, $confirmada, $atendida, $cancelada, $inasistencia] as $cita) {
            $todas->assertSee($cita->codigo);
        }

        // Filtro por estado.
        $esperado = ['pendientes' => [$programada, $confirmada], 'atendidas' => [$atendida], 'canceladas' => [$cancelada], 'confirmadas' => [$confirmada], 'inasistencias' => [$inasistencia]];
        $citas = [$programada, $confirmada, $atendida, $cancelada, $inasistencia];
        foreach ($esperado as $filtro => $incluidas) {
            $pantalla = $this->get(route('agenda.index', ['desde' => $this->lunes, 'estado' => $filtro]))->assertOk();
            foreach ($citas as $cita) {
                in_array($cita, $incluidas, true) ? $pantalla->assertSee($cita->codigo) : $pantalla->assertDontSee($cita->codigo);
            }
        }
    }

    /** Paso 3: el médico consulta la agenda de otro médico (médico autenticado selecciona a otro médico) → el sistema solo muestra su propia agenda. */
    #[Test]
    public function paso_3_el_medico_consulta_la_agenda_de_otro_medico(): void
    {
        $otroUsuario = $this->crearUsuario('t-medico-ag-otro', [Rol::MEDICO]);
        $otro = $this->crearMedico(['usuario_id' => $otroUsuario->id, 'nombres' => 'Otro', 'apellidos' => 'Tmédico'], [$this->especialidad]);
        $propia = $this->cita('09:00', Cita::PROGRAMADA);
        $ajena = $this->crearCita($otro, $this->crearPaciente(['nombres' => 'Paciente', 'apellidos' => 'DelOtro']), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $otro->id]))->assertOk()
            ->assertSee($propia->codigo)->assertDontSee($ajena->codigo)->assertDontSee('DelOtro')
            ->assertDontSee('— Seleccione un médico —'); // el médico no tiene lista de médicos para elegir
    }

    /** Paso 4: ocultar el contacto del paciente al médico (paciente con la privacidad activada y el médico abre su cita) → el médico ve los datos básicos sin teléfono ni correo. */
    #[Test]
    public function paso_4_ocultar_el_contacto_del_paciente_al_medico(): void
    {
        $privado = $this->crearPaciente([
            'nombres' => 'Privada', 'apellidos' => 'Tpaciente', 'telefono' => '71111111', 'correo' => 'privada@prueba.test',
            'fecha_nacimiento' => Carbon::today()->subYears(30)->subDays(10)->toDateString(), 'sexo' => 'F', 'mostrar_contacto_medico' => false,
        ]);
        $this->crearCita($this->medico, $privado, $this->especialidad, $this->lunes, '09:00', '09:30');

        $pantalla = $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()
            ->assertSee('Privada Tpaciente')->assertSee('30 años')->assertSee('Femenino') // datos básicos: nombre, edad y sexo
            ->assertSee('El paciente mantiene su teléfono y correo privados');
        // El teléfono y el correo ni siquiera llegan al HTML.
        $pantalla->assertDontSee('71111111')->assertDontSee('privada@prueba.test');

        // La recepcionista sí los ve (la privacidad aplica al rol Médico).
        $this->actingAs($this->recepcion)->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $this->medico->id]))->assertOk()
            ->assertSee('71111111')->assertSee('privada@prueba.test');
    }

    // ------------------------------------------------------------------ Contacto, motivo y datos del paciente

    #[Test]
    public function si_el_paciente_lo_permite_el_medico_ve_su_telefono_y_correo(): void
    {
        $abierto = $this->crearPaciente(['nombres' => 'Abierto', 'apellidos' => 'Tpaciente', 'telefono' => '72222222', 'correo' => 'abierto@prueba.test', 'sexo' => 'M', 'mostrar_contacto_medico' => true]);
        $this->crearCita($this->medico, $abierto, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()
            ->assertSee('72222222')->assertSee('abierto@prueba.test')->assertSee('Masculino')->assertDontSee('mantiene su teléfono y correo privados');
    }

    #[Test]
    public function el_motivo_se_oculta_solo_a_quien_no_tiene_permiso_para_verlo(): void
    {
        $cita = $this->cita('09:00', Cita::PROGRAMADA, 'Motivo reservado');
        $servicio = app(AgendaService::class);
        $otroMedico = $this->crearUsuario('t-medico-ag-3', [Rol::MEDICO]);
        $this->crearMedico(['usuario_id' => $otroMedico->id], [$this->especialidad]);
        $paciente = $this->crearUsuario('t-pac-ag', [Rol::PACIENTE]);
        $doble = $this->crearUsuario('t-medico-recep-ag', [Rol::MEDICO, Rol::RECEPCIONISTA]);

        $this->assertTrue($servicio->puedeVerMotivo($this->usuarioMedico, $cita), 'El médico de la cita.');
        $this->assertTrue($servicio->puedeVerMotivo($this->recepcion, $cita));
        $this->assertTrue($servicio->puedeVerMotivo($this->admin, $cita));
        $this->assertTrue($servicio->puedeVerMotivo($doble, $cita));
        $this->assertFalse($servicio->puedeVerMotivo($otroMedico, $cita), 'Otro médico no ve el motivo de una cita ajena.');
        $this->assertFalse($servicio->puedeVerMotivo($paciente, $cita));

        // En pantalla, quien sí tiene permiso lo ve.
        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()->assertSee('Motivo reservado');
        $this->actingAs($this->admin)->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $this->medico->id]))->assertOk()->assertSee('Motivo reservado');
    }

    #[Test]
    public function la_edad_se_calcula_en_anos_cumplidos_y_el_sexo_tiene_su_texto(): void
    {
        $paciente = $this->crearPaciente(['fecha_nacimiento' => Carbon::today()->subYears(40)->addDay()->toDateString()]); // cumple 40 mañana
        $this->assertSame(39, AgendaService::edad($paciente));
        $paciente->fecha_nacimiento = Carbon::today()->subYears(40);
        $this->assertSame(40, AgendaService::edad($paciente));

        $this->assertSame('Masculino', AgendaService::textoSexo('M'));
        $this->assertSame('Femenino', AgendaService::textoSexo('F'));
        $this->assertSame('No indicado', AgendaService::textoSexo(null));
    }

    // ------------------------------------------------------------------ Fechas, rango y horarios libres

    #[Test]
    public function sin_citas_dice_que_no_hay_citas_para_la_fecha_seleccionada(): void
    {
        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()->assertSee('No hay citas para la fecha seleccionada');

        $this->cita('09:00', Cita::ATENDIDA);
        $this->get(route('agenda.index', ['desde' => $this->lunes, 'estado' => 'canceladas']))->assertOk()->assertSee('No hay citas para la fecha seleccionada');
    }

    #[Test]
    public function un_rango_invalido_avisa_y_no_consulta_y_un_rango_valido_agrupa_las_citas_por_dia(): void
    {
        $uno = $this->cita('09:00');
        $siguiente = Carbon::parse($this->lunes)->addDays(7)->toDateString();
        $dos = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $siguiente, '09:00', '09:30');
        $this->actingAs($this->usuarioMedico);

        // Fin anterior al inicio.
        $this->get(route('agenda.index', ['desde' => $siguiente, 'hasta' => $this->lunes]))->assertOk()
            ->assertSee(AgendaService::MENSAJE_RANGO_INVALIDO)->assertDontSee($uno->codigo)->assertDontSee($dos->codigo);

        // Más de 31 días.
        $this->get(route('agenda.index', ['desde' => $this->lunes, 'hasta' => Carbon::parse($this->lunes)->addDays(31)->toDateString()]))->assertOk()
            ->assertSee(AgendaService::MENSAJE_RANGO_LARGO)->assertDontSee($uno->codigo);

        // Exactamente 31 días (el límite) sí se consulta.
        $this->get(route('agenda.index', ['desde' => $this->lunes, 'hasta' => Carbon::parse($this->lunes)->addDays(30)->toDateString()]))->assertOk()
            ->assertDontSee(AgendaService::MENSAJE_RANGO_LARGO)->assertSee($uno->codigo)->assertSee($dos->codigo);

        // Rango de dos fechas: las dos citas, cada una en su día, y sin horarios libres (solo se muestran en un día).
        $rango = $this->get(route('agenda.index', ['desde' => $this->lunes, 'hasta' => $siguiente]))->assertOk()
            ->assertSee($uno->codigo)->assertSee($dos->codigo)->assertDontSee('Horarios libres');
        $this->assertCount(2, $rango->viewData('porDia'));
    }

    #[Test]
    public function en_un_solo_dia_se_muestran_tambien_los_horarios_libres_del_medico(): void
    {
        $this->cita('09:00', Cita::PROGRAMADA);
        $this->cita('09:30', Cita::CANCELADA); // una cita cancelada no ocupa horario

        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()
            ->assertSee('Horarios libres')->assertSee('08:00 – 08:30')->assertSee('09:30 – 10:00')->assertDontSee('09:00 – 09:30</span>', false);

        // Un día en que el médico no atiende: el aviso de CU-08 en lugar de los horarios.
        $this->get(route('agenda.index', ['desde' => $this->proximaFecha(2)]))->assertOk()->assertSee('El médico no atiende en esa fecha');
    }

    #[Test]
    public function las_fechas_mal_escritas_se_reemplazan_y_un_estado_inventado_se_ignora(): void
    {
        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => '2026-02-31', 'hasta' => 'mañana', 'estado' => 'XYZ']))->assertOk()
            ->assertSee(Carbon::today()->format('d/m/Y'));
    }

    // ------------------------------------------------------------------ Recepción, administrador y permisos

    #[Test]
    public function la_recepcion_y_el_administrador_eligen_el_medico_y_sin_elegir_se_les_pide(): void
    {
        $cita = $this->cita('09:00', Cita::PROGRAMADA, 'Revisión');

        foreach ([$this->recepcion, $this->admin] as $usuario) {
            $this->actingAs($usuario)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()->assertSee('Elija un médico para ver su agenda.')->assertDontSee($cita->codigo);
            $this->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $this->medico->id]))->assertOk()
                ->assertSee($cita->codigo)->assertSee('Agenda Tmédica')->assertSee('Revisión');
        }
    }

    #[Test]
    public function un_medico_sin_ficha_de_medico_ve_el_aviso_y_ninguna_agenda(): void
    {
        $sinFicha = $this->crearUsuario('t-medico-ag-sin-ficha', [Rol::MEDICO]);
        $cita = $this->cita('09:00');

        $this->actingAs($sinFicha)->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $this->medico->id]))->assertOk()
            ->assertSee(AgendaService::MENSAJE_SIN_FICHA_MEDICO)->assertDontSee($cita->codigo);
    }

    #[Test]
    public function la_consulta_no_modifica_ningun_dato_y_los_pacientes_no_acceden(): void
    {
        $cita = $this->cita('09:00', Cita::PROGRAMADA);
        $this->get(route('agenda.index'))->assertRedirect('/login');

        $paciente = $this->crearUsuario('t-pac-ag-2', [Rol::PACIENTE]);
        $this->actingAs($paciente)->get(route('agenda.index', ['desde' => $this->lunes]))->assertForbidden();

        $antes = $cita->fresh()->toArray();
        $this->actingAs($this->usuarioMedico)->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk();
        $this->assertSame($antes, $cita->fresh()->toArray());
        $this->post(route('agenda.index'))->assertStatus(405); // solo se consulta
    }

    #[Test]
    public function el_panel_ofrece_la_agenda_medica_al_medico_a_la_recepcion_y_al_administrador(): void
    {
        foreach ([$this->usuarioMedico, $this->recepcion, $this->admin] as $usuario) {
            $this->actingAs($usuario)->get(route('panel'))->assertOk()->assertSee('Agenda médica')->assertSee('CU-14');
        }
        $this->actingAs($this->crearUsuario('t-pac-ag-3', [Rol::PACIENTE]))->get(route('panel'))->assertOk()->assertDontSee('Agenda médica');
    }

    /** Cita de la prueba con el médico de la prueba, de 30 minutos desde la hora indicada, un paciente nuevo cada vez. */
    private function cita(string $inicio, string $estado = Cita::PROGRAMADA, ?string $motivo = null): Cita
    {
        $fin = Carbon::parse($inicio)->addMinutes(30)->format('H:i');
        $cita = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, $inicio, $fin, $estado);
        $cita->update(['motivo' => $motivo]);

        return $cita->fresh();
    }
}
