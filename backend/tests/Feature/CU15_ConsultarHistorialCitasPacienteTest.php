<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionPacientes\CU15_ConsultarHistorialCitasPaciente\HistorialCitasService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/** CU-15: Consultar historial de citas del paciente. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU15_ConsultarHistorialCitasPacienteTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Usuario $usuarioPaciente;

    private Paciente $paciente;

    private Especialidad $especialidad;

    private Medico $medico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-hist', [Rol::RECEPCIONISTA]);
        $this->usuarioPaciente = $this->crearUsuario('t-pac-hist', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuarioPaciente->id, 'nombres' => 'Historia', 'apellidos' => 'Tclínica']);
        $this->especialidad = $this->crearEspecialidad('T-Historial', precio: 100);
        $this->medico = $this->crearMedico(['nombres' => 'Doctora', 'apellidos' => 'Thistorial'], [$this->especialidad]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: consultar el historial de un paciente (CI de un paciente con citas pasadas y canceladas) → se listan todas sus citas con fecha y hora, médico, especialidad, estado y fecha de registro. */
    #[Test]
    public function paso_1_consultar_el_historial_de_un_paciente(): void
    {
        $pasada = $this->cita('2026-01-12', '09:00', Cita::ATENDIDA);
        $cancelada = $this->cita('2026-02-16', '10:30', Cita::CANCELADA);
        $futura = $this->cita(Carbon::today()->addDays(10)->toDateString(), '08:00', Cita::PROGRAMADA);
        $otra = $this->crearCita($this->medico, $this->crearPaciente(['nombres' => 'Ajeno']), $this->especialidad, '2026-01-12', '11:00', '11:30');

        // La recepcionista busca por CI: al ser una sola coincidencia se muestra directamente el historial.
        $pantalla = $this->actingAs($this->recepcion)->get(route('historial.index', ['q' => $this->paciente->ci]))->assertOk()
            ->assertSee('Historia Tclínica')->assertSee('Historial de citas')
            ->assertSee($pasada->codigo)->assertSee($cancelada->codigo)->assertSee($futura->codigo)->assertDontSee($otra->codigo)
            ->assertSee('12/01/2026')->assertSee('09:00 – 09:30')->assertSee('Doctora Thistorial')->assertSee('T-Historial')
            ->assertSee('Atendida')->assertSee('Cancelada')->assertSee('Programada')
            ->assertSee($pasada->created_at->format('d/m/Y'))->assertSee('Registrada');

        // Del más reciente al más antiguo.
        $contenido = $pantalla->getContent();
        $this->assertTrue(strpos($contenido, $futura->codigo) < strpos($contenido, $cancelada->codigo) && strpos($contenido, $cancelada->codigo) < strpos($contenido, $pasada->codigo));
    }

    /** Paso 2: el paciente consulta el historial de otro paciente (paciente autenticado intenta ver a otro paciente) → el sistema no lo permite. */
    #[Test]
    public function paso_2_el_paciente_consulta_el_historial_de_otro_paciente(): void
    {
        $propia = $this->cita('2026-03-02', '09:00');
        $otroPaciente = $this->crearPaciente(['nombres' => 'Otro', 'apellidos' => 'Tpaciente']);
        $ajena = $this->crearCita($this->medico, $otroPaciente, $this->especialidad, '2026-03-02', '10:00', '10:30');
        $this->actingAs($this->usuarioPaciente);

        // Pedir el identificador de otro paciente en la URL: acceso denegado.
        $this->get(route('historial.index', ['paciente' => $otroPaciente->id]))->assertForbidden();

        // Buscar por CI ajeno tampoco sirve: la ficha siempre es la de su cuenta y no ve nada del otro.
        $this->get(route('historial.index', ['q' => $otroPaciente->ci]))->assertOk()
            ->assertSee($propia->codigo)->assertDontSee($ajena->codigo)->assertDontSee('Otro Tpaciente')->assertDontSee('Buscar paciente por CI o nombre');
    }

    // ------------------------------------------------------------------ Mis citas (el paciente)

    #[Test]
    public function el_paciente_ve_directamente_su_propio_historial_como_mis_citas(): void
    {
        $cita = $this->cita('2026-03-02', '09:00');

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee('Mis citas')->assertSee($cita->codigo)->assertSee('Reservar cita')->assertDontSee('Buscar paciente por CI o nombre');
        // Pedir explícitamente su propia ficha por la URL es válido.
        $this->get(route('historial.index', ['paciente' => $this->paciente->id]))->assertOk()->assertSee($cita->codigo);
    }

    #[Test]
    public function el_paciente_cancela_desde_mis_citas_solo_las_cancelables_y_con_anticipacion(): void
    {
        $dia = Carbon::today()->addDays(5)->toDateString();
        $lejana = $this->cita($dia, '11:00', Cita::PROGRAMADA);   // faltan 3 horas
        $cercana = $this->cita($dia, '09:00', Cita::CONFIRMADA);  // falta 1 hora
        $atendida = $this->cita('2026-01-05', '09:00', Cita::ATENDIDA);
        Carbon::setTestNow(Carbon::parse($dia.' 08:00:00'));

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee(route('citas.cancelar', $lejana), false)
            ->assertDontSee(route('citas.cancelar', $cercana), false)
            ->assertDontSee(route('citas.cancelar', $atendida), false)
            ->assertSee('Faltan menos de 2 h: comunícate con recepción');
    }

    #[Test]
    public function el_paciente_sin_ficha_ve_el_aviso_y_ninguna_cita(): void
    {
        $sinFicha = $this->crearUsuario('t-pac-sin-ficha-hist', [Rol::PACIENTE]);
        $ajena = $this->cita('2026-03-02', '09:00');

        $this->actingAs($sinFicha)->get(route('historial.index'))->assertOk()
            ->assertSee(Paciente::MENSAJE_SIN_FICHA)->assertDontSee($ajena->codigo)->assertDontSee('Filtros');
        // Tampoco puede pedir la ficha de otro.
        $this->get(route('historial.index', ['paciente' => $this->paciente->id]))->assertForbidden();
    }

    // ------------------------------------------------------------------ Búsqueda del personal

    #[Test]
    public function el_personal_busca_por_ci_nombre_o_apellido_sin_distinguir_tildes_y_ve_varias_coincidencias(): void
    {
        $segundo = $this->crearPaciente(['nombres' => 'Histórica', 'apellidos' => 'Tclínica Segunda']);
        $this->actingAs($this->recepcion);

        $this->get(route('historial.index', ['q' => 'TCLINICA']))->assertOk()
            ->assertSee('Historia Tclínica')->assertSee('Histórica Tclínica Segunda')->assertSee('Ver historial');
        $this->get(route('historial.index', ['q' => 'historica']))->assertOk()->assertSee('Histórica Tclínica Segunda')->assertDontSee('Ver historial');
        $this->get(route('historial.index', ['q' => 'xyz-no-existe']))->assertOk()->assertSee('No se encontraron pacientes');
        $this->get(route('historial.index'))->assertOk()->assertSee('Busque a un paciente por CI o nombre');

        $cita = $this->cita('2026-04-06', '09:00');
        $this->get(route('historial.index', ['paciente' => $this->paciente->id]))->assertOk()->assertSee($cita->codigo);
        $this->assertSame(0, $segundo->citas()->count());
    }

    #[Test]
    public function el_historial_de_un_paciente_inactivo_tambien_se_consulta(): void
    {
        $this->paciente->update(['estado' => false]);
        $cita = $this->cita('2026-04-06', '09:00', Cita::ATENDIDA);

        $this->actingAs($this->recepcion)->get(route('historial.index', ['q' => $this->paciente->ci]))->assertOk()
            ->assertSee('Inactivo')->assertSee($cita->codigo);
    }

    #[Test]
    public function el_administrador_hereda_la_consulta_y_los_medicos_y_visitantes_no_entran(): void
    {
        $admin = $this->crearUsuario('t-admin-hist', [Rol::ADMINISTRADOR]);
        $this->actingAs($admin)->get(route('historial.index'))->assertOk()->assertSee('Buscar paciente por CI o nombre');

        $this->app['auth']->forgetGuards();
        $this->get(route('historial.index'))->assertRedirect('/login');

        $medico = $this->crearUsuario('t-medico-hist', [Rol::MEDICO]);
        $this->actingAs($medico)->get(route('historial.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------ Filtros

    #[Test]
    public function los_filtros_por_estado_se_aplican_y_pendientes_son_las_programadas_y_las_confirmadas(): void
    {
        $programada = $this->cita('2026-05-04', '08:00', Cita::PROGRAMADA);
        $confirmada = $this->cita('2026-05-04', '09:00', Cita::CONFIRMADA);
        $atendida = $this->cita('2026-05-04', '10:00', Cita::ATENDIDA);
        $cancelada = $this->cita('2026-05-04', '11:00', Cita::CANCELADA);
        $inasistencia = $this->cita('2026-05-05', '08:00', Cita::INASISTENCIA);
        $this->actingAs($this->usuarioPaciente);

        $esperado = [
            'pendientes' => [$programada, $confirmada], 'confirmadas' => [$confirmada], 'atendidas' => [$atendida],
            'canceladas' => [$cancelada], 'inasistencias' => [$inasistencia],
        ];
        $todas = [$programada, $confirmada, $atendida, $cancelada, $inasistencia];

        foreach ($esperado as $filtro => $citas) {
            $pantalla = $this->get(route('historial.index', ['estado' => $filtro]))->assertOk();
            foreach ($todas as $cita) {
                in_array($cita, $citas, true) ? $pantalla->assertSee($cita->codigo) : $pantalla->assertDontSee($cita->codigo);
            }
        }

        // Un filtro inventado se ignora y se ven todas.
        $pantalla = $this->get(route('historial.index', ['estado' => 'cualquiera']))->assertOk();
        foreach ($todas as $cita) {
            $pantalla->assertSee($cita->codigo);
        }
    }

    #[Test]
    public function el_filtro_por_rango_de_fechas_incluye_los_extremos_y_un_rango_invalido_avisa_sin_consultar(): void
    {
        $antes = $this->cita('2026-06-01', '09:00');
        $inicio = $this->cita('2026-06-10', '09:00');
        $fin = $this->cita('2026-06-20', '09:00');
        $despues = $this->cita('2026-07-01', '09:00');
        $this->actingAs($this->usuarioPaciente);

        $this->get(route('historial.index', ['desde' => '2026-06-10', 'hasta' => '2026-06-20']))->assertOk()
            ->assertSee($inicio->codigo)->assertSee($fin->codigo)->assertDontSee($antes->codigo)->assertDontSee($despues->codigo);
        $this->get(route('historial.index', ['desde' => '2026-06-20']))->assertOk()->assertSee($fin->codigo)->assertSee($despues->codigo)->assertDontSee($inicio->codigo);
        $this->get(route('historial.index', ['hasta' => '2026-06-10']))->assertOk()->assertSee($antes->codigo)->assertSee($inicio->codigo)->assertDontSee($fin->codigo);

        $this->get(route('historial.index', ['desde' => '2026-06-20', 'hasta' => '2026-06-10']))->assertOk()
            ->assertSee(HistorialCitasService::MENSAJE_RANGO_INVALIDO)->assertDontSee($inicio->codigo)->assertDontSee($fin->codigo);

        // Una fecha mal escrita se ignora.
        $this->get(route('historial.index', ['desde' => '2026-02-31', 'hasta' => 'mañana']))->assertOk()->assertSee($antes->codigo)->assertSee($despues->codigo);
    }

    #[Test]
    public function sin_citas_dice_que_el_paciente_aun_no_tiene_citas_y_con_filtros_que_no_hay_resultados(): void
    {
        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()->assertSee('El paciente aún no tiene citas.');

        $this->cita('2026-05-04', '08:00', Cita::ATENDIDA);
        $this->get(route('historial.index', ['estado' => 'canceladas']))->assertOk()
            ->assertSee('No hay citas con esos filtros.')->assertDontSee('El paciente aún no tiene citas.');
    }

    #[Test]
    public function el_historial_se_pagina_y_muestra_el_total(): void
    {
        $fecha = Carbon::parse('2026-08-03');
        for ($i = 0; $i < 17; $i++) {
            $this->crearCita($this->medico, $this->paciente, $this->especialidad, $fecha->copy()->addDays($i)->toDateString(), '09:00', '09:30');
        }
        $this->actingAs($this->usuarioPaciente);

        $primera = $this->get(route('historial.index'))->assertOk()->assertSee('17 citas')->assertSee('page=2', false);
        $this->assertCount(HistorialCitasService::POR_PAGINA, $primera->viewData('citas'));
        $segunda = $this->get(route('historial.index', ['page' => 2]))->assertOk();
        $this->assertCount(2, $segunda->viewData('citas'));
    }

    #[Test]
    public function el_historial_muestra_el_monto_y_el_estado_del_pago_de_cada_cita(): void
    {
        $pagada = $this->cita('2026-05-04', '08:00', Cita::ATENDIDA);
        $pagada->update(['monto' => 150, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_hist_1', 'fecha_pago' => now()]);
        $pendiente = $this->cita('2026-05-05', '08:00', Cita::PROGRAMADA);
        $pendiente->update(['monto' => 80]);

        $this->actingAs($this->usuarioPaciente)->get(route('historial.index'))->assertOk()
            ->assertSee('Bs 150,00')->assertSee('Pagado')->assertSee('Bs 80,00')->assertSee('Pendiente');
    }

    #[Test]
    public function el_panel_ofrece_historial_de_citas_a_la_recepcion_y_mis_citas_al_paciente(): void
    {
        $this->actingAs($this->recepcion)->get(route('panel'))->assertOk()->assertSee('Historial de citas')->assertSee('CU-15')->assertDontSee('Mis citas');
        $this->actingAs($this->usuarioPaciente)->get(route('panel'))->assertOk()->assertSee('Mis citas')->assertSee('CU-15')->assertDontSee('Historial de citas');
    }

    /** Cita del paciente de la prueba con el médico de la prueba. */
    private function cita(string $fecha, string $inicio, string $estado = Cita::PROGRAMADA): Cita
    {
        $fin = Carbon::parse($inicio)->addMinutes(30)->format('H:i');

        return $this->crearCita($this->medico, $this->paciente, $this->especialidad, $fecha, $inicio, $fin, $estado);
    }
}
