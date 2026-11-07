<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\ReportesBitacora\CU18_GenerarReportesConIA\ReporteService;
use App\Modules\ReportesBitacora\CU18_GenerarReportesConIA\ResumenIAService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-18: Generar reportes con IA. Los métodos paso_N son las filas del plan de pruebas 8.1; los demás son reglas internas.
 * Los datos de prueba están en marzo de 2035 (sin citas de la demostración) y la IA siempre se simula con Http::fake(): ninguna prueba sale a internet.
 */
class CU18_GenerarReportesConIATest extends SistemaTestCase
{
    private const DESDE = '2035-03-05';

    private const HASTA = '2035-03-09';

    private const TEXTO_IA = 'En el período se registraron 6 citas: 2 atendidas, 1 cancelada y 1 inasistencia. Se cobraron Bs 300,00 en total.';

    private Usuario $admin;

    private Usuario $recepcion;

    private Medico $medico1;

    private Medico $medico2;

    private Especialidad $esp1;

    private Especialidad $esp2;

    private Paciente $pacienteReconocible;

    /** @var array<string, Cita> */
    private array $citas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-rep-admin', [Rol::ADMINISTRADOR]);
        $this->recepcion = $this->crearUsuario('t-rep-recep', [Rol::RECEPCIONISTA]);
        config(['services.ia.gemini_key' => 'clave-falsa-de-prueba', 'services.ia.modelo' => 'gemini-3.5-flash-lite']);
    }

    /**
     * Seis citas entre el 05 y el 09/03/2035: 1 programada, 1 confirmada, 2 atendidas, 1 cancelada, 1 inasistencia;
     * cobradas en efectivo (c2, c6) y con tarjeta (c3, c5). El paciente c1/c3 tiene datos reconocibles para la prueba de privacidad.
     */
    private function escenario(): void
    {
        $this->esp1 = $this->crearEspecialidad('T-Rep-General', precio: 100);
        $this->esp2 = $this->crearEspecialidad('T-Rep-Laboratorio', tipo: Especialidad::LABORATORIO, precio: 50);
        $this->medico1 = $this->crearMedico(['nombres' => 'Rosario', 'apellidos' => 'Tdoctora Uno'], [$this->esp1, $this->esp2]);
        $this->medico2 = $this->crearMedico(['nombres' => 'Gustavo', 'apellidos' => 'Tdoctor Dos'], [$this->esp2]);

        $this->pacienteReconocible = $this->crearPaciente([
            'nombres' => 'Zuleica Marquesina', 'apellidos' => 'Quispe Tzapata', 'ci' => 'T-9998877', 'telefono' => '78889999', 'correo' => 'zuleica.tzapata@prueba.test',
        ]);
        $p2 = $this->crearPaciente(['nombres' => 'Segundo', 'apellidos' => 'Tpaciente']);
        $p3 = $this->crearPaciente(['nombres' => 'Tercero', 'apellidos' => 'Tpaciente']);

        $c = fn (string $clave, Medico $m, Paciente $p, Especialidad $e, string $fecha, string $inicio, string $estado, string $metodo, string $pago, float $monto, ?string $fechaPago = null) => $this->citas[$clave] = tap(
            $this->crearCita($m, $p, $e, $fecha, $inicio, date('H:i', strtotime($inicio) + 1800), $estado),
            fn (Cita $cita) => $cita->update(['metodo_pago' => $metodo, 'estado_pago' => $pago, 'monto' => $monto, 'fecha_pago' => $fechaPago])
        );

        $c('c1', $this->medico1, $this->pacienteReconocible, $this->esp1, '2035-03-05', '09:00', Cita::PROGRAMADA, 'EFECTIVO', 'PENDIENTE', 100);
        $c('c2', $this->medico1, $p2, $this->esp1, '2035-03-05', '10:00', Cita::CONFIRMADA, 'EFECTIVO', 'PAGADO', 100, '2035-03-05 08:00:00');
        $c('c3', $this->medico1, $this->pacienteReconocible, $this->esp2, '2035-03-05', '11:00', Cita::ATENDIDA, 'TARJETA', 'PAGADO', 50, '2035-03-04 12:00:00');
        $c('c4', $this->medico2, $p2, $this->esp2, '2035-03-05', '09:00', Cita::CANCELADA, 'EFECTIVO', 'PENDIENTE', 50);
        $c('c5', $this->medico2, $p3, $this->esp2, '2035-03-05', '12:00', Cita::INASISTENCIA, 'TARJETA', 'PAGADO', 50, '2035-03-05 09:00:00');
        $c('c6', $this->medico1, $p3, $this->esp1, '2035-03-07', '09:00', Cita::ATENDIDA, 'EFECTIVO', 'PAGADO', 100, '2035-03-07 10:00:00');
    }

    private function ruta(array $filtros, string $base = '/reportes'): string
    {
        return $base.'?'.http_build_query($filtros + ['desde' => self::DESDE, 'hasta' => self::HASTA, 'ia' => 'sin']);
    }

    private function fingirIA(string $texto = self::TEXTO_IA): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => $texto]]]]]], 200)]);
    }

    /** Reporte que arma la pantalla (los datos que recibe la vista). */
    private function reporte(TestResponse $respuesta): array
    {
        return $respuesta->viewData('reporte');
    }

    // ---------- Plan de pruebas 8.1 ----------

    /** Paso 1: generar un reporte por período (rango de fechas con citas) → se muestra el detalle y los totales de citas por estado, médico y especialidad. */
    #[Test]
    public function paso_1_generar_un_reporte_por_periodo(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->recepcion)->get($this->ruta(['tipo' => 'por_medico']))->assertOk()
            ->assertSee('Citas por médico')->assertSee('05/03/2035 al 09/03/2035', false);
        $reporte = $this->reporte($respuesta);

        // Totales por estado.
        $t = $reporte['totales'];
        $this->assertSame(6, $t['total']);
        $this->assertSame([1, 1, 2, 1, 1], [$t['programadas'], $t['confirmadas'], $t['atendidas'], $t['canceladas'], $t['inasistencias']]);
        $this->assertSame(300.0, $t['ingreso_total']);

        // Detalle: una fila por cita, con su paciente, médico, especialidad y estado.
        $this->assertCount(6, $reporte['filas']);
        $this->assertEqualsCanonicalizing(array_values(array_map(fn ($c) => $c->codigo, $this->citas)), array_column($reporte['filas'], 'codigo'));
        $respuesta->assertSee($this->citas['c1']->codigo)->assertSee('Zuleica Marquesina Quispe Tzapata')->assertSee('T-9998877')
            ->assertSee('Rosario Tdoctora Uno')->assertSee('T-Rep-General')->assertSee('Programada')->assertSee('Inasistencia');

        // Totales por médico y por especialidad.
        [$porMedico, $porEspecialidad] = $reporte['grupos'];
        $this->assertSame('Por médico', $porMedico['titulo']);
        $this->assertSame([['Gustavo Tdoctor Dos', '2'], ['Rosario Tdoctora Uno', '4']], array_map(fn ($f) => [$f[0], $f[1]], $porMedico['filas']));
        $this->assertSame('Por especialidad', $porEspecialidad['titulo']);
        $this->assertSame([['T-Rep-General', '3'], ['T-Rep-Laboratorio', '3']], array_map(fn ($f) => [$f[0], $f[1]], $porEspecialidad['filas']));
        $respuesta->assertSee('Total de citas')->assertSee('Programadas')->assertSee('Inasistencias')->assertSee('Bs 300,00');
    }

    /** Paso 2: generar un reporte con IA (rango con citas y modalidad con IA) → se muestra el reporte con sus totales y un resumen redactado por IA. */
    #[Test]
    public function paso_2_generar_un_reporte_con_ia(): void
    {
        $this->escenario();
        $this->fingirIA();

        $respuesta = $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk()
            ->assertSee('Resumen generado por IA')->assertSee('En el período se registraron 6 citas')
            ->assertSee('Total de citas')->assertDontSee('No se pudo generar el resumen con IA');
        $this->assertSame(6, $this->reporte($respuesta)['totales']['total']);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $peticion) {
            return $peticion->method() === 'POST'
                && str_starts_with($peticion->url(), 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent')
                && $peticion->header('x-goog-api-key') === ['clave-falsa-de-prueba']
                && ! str_contains($peticion->url(), 'clave-falsa-de-prueba');
        });
    }

    /** Paso 3: IA no disponible (modalidad con IA y servicio de IA caído) → se muestra el reporte sin resumen y se informa que no se pudo generar con IA. */
    #[Test]
    public function paso_3_ia_no_disponible(): void
    {
        $this->escenario();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'Servicio no disponible']], 503)]);

        $respuesta = $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk()
            ->assertSee('No se pudo generar el resumen con IA')
            ->assertDontSee('Resumen generado por IA')->assertSee('Total de citas')->assertSee($this->citas['c1']->codigo);
        $this->assertSame(6, $this->reporte($respuesta)['totales']['total']);
        $this->assertDatabaseHas('bitacora', ['accion' => 'REPORTE_GENERADO', 'usuario_id' => $this->admin->id]);
    }

    /** Paso 4: generar el reporte de ingresos por método de pago (citas pagadas en efectivo y con tarjeta) → se muestran los totales cobrados por método de pago. */
    #[Test]
    public function paso_4_reporte_de_ingresos_por_metodo_de_pago(): void
    {
        $this->escenario();

        $respuesta = $this->actingAs($this->recepcion)->get($this->ruta(['tipo' => 'ingresos']))->assertOk()->assertSee('Ingresos por método de pago');
        $reporte = $this->reporte($respuesta);

        // Se cuentan por la fecha del pago (fecha_pago): c3 se pagó el 04/03, fuera del rango, y las pendientes no cuentan.
        $t = $reporte['totales'];
        $this->assertSame(3, $t['total']);
        $this->assertSame([2, 200.0, 1, 50.0, 250.0], [$t['pagos_efectivo'], $t['ingreso_efectivo'], $t['pagos_tarjeta'], $t['ingreso_tarjeta'], $t['ingreso_total']]);
        $this->assertEqualsCanonicalizing([$this->citas['c2']->codigo, $this->citas['c5']->codigo, $this->citas['c6']->codigo], array_column($reporte['filas'], 'codigo'));
        $respuesta->assertSee('Ingresos · efectivo (2 pagos)')->assertSee('Bs 200,00')->assertSee('Ingresos · tarjeta (1 pagos)')->assertSee('Bs 50,00')->assertSee('Bs 250,00')
            ->assertSee('Efectivo')->assertSee('Tarjeta (Stripe)');
    }

    /** Paso 5: rango de fechas inválido (fecha final anterior a la inicial) → el sistema lo indica y no genera el reporte. */
    #[Test]
    public function paso_5_rango_de_fechas_invalido(): void
    {
        $this->escenario();
        $antes = DB::table('bitacora')->where('accion', 'REPORTE_GENERADO')->count();

        $this->actingAs($this->admin)->get('/reportes?tipo=por_medico&desde=2035-03-09&hasta=2035-03-05&ia=sin')
            ->assertSessionHasErrors(['hasta' => 'La fecha final no puede ser anterior a la fecha inicial.']);

        $this->followingRedirects()->get('/reportes?tipo=por_medico&desde=2035-03-09&hasta=2035-03-05&ia=sin')
            ->assertSee('La fecha final no puede ser anterior a la fecha inicial.')->assertSee('No se generó el reporte')
            ->assertDontSee('Total de citas');
        $this->assertSame($antes, DB::table('bitacora')->where('accion', 'REPORTE_GENERADO')->count(), 'No se genera ni se registra el reporte.');
    }

    /** Paso 6: generar el reporte de pacientes registrados (rango de fechas de registro) → se listan los pacientes registrados con su total. */
    #[Test]
    public function paso_6_reporte_de_pacientes_registrados(): void
    {
        $uno = $this->crearPaciente(['nombres' => 'Registrada', 'apellidos' => 'Primero Tpac', 'ci' => 'T-REG-01']);
        $dos = $this->crearPaciente(['nombres' => 'Registrada', 'apellidos' => 'Segundo Tpac', 'ci' => 'T-REG-02', 'estado' => false]);
        $tres = $this->crearPaciente(['nombres' => 'Registrada', 'apellidos' => 'Fuera Tpac', 'ci' => 'T-REG-03']);
        HistoriaClinica::create(['paciente_id' => $uno->id, 'fecha_apertura' => '2035-03-05']);
        HistoriaClinica::create(['paciente_id' => $dos->id, 'fecha_apertura' => '2035-03-09']);
        HistoriaClinica::create(['paciente_id' => $tres->id, 'fecha_apertura' => '2035-03-12']);

        $respuesta = $this->actingAs($this->recepcion)->get($this->ruta(['tipo' => 'pacientes']))->assertOk()
            ->assertSee('Pacientes registrados')->assertSee('T-REG-01')->assertSee('T-REG-02')->assertDontSee('T-REG-03')
            ->assertSee('05/03/2035')->assertSee('Primero Tpac')->assertSee('Inactivo');
        $reporte = $this->reporte($respuesta);

        $this->assertSame(['total' => 2, 'activos' => 1, 'inactivos' => 1], $reporte['totales']);
        $this->assertSame(2, $reporte['total_filas']);
        $this->assertSame(['T-REG-01', 'T-REG-02'], array_column($reporte['filas'], 'ci'), 'Por fecha de apertura de la historia clínica.');
    }

    /** Paso 7: exportar un reporte (reporte generado y formato CSV o PDF) → se descarga el archivo con el mismo detalle y los mismos totales. */
    #[Test]
    public function paso_7_exportar_un_reporte_en_csv_y_pdf(): void
    {
        $this->escenario();
        $filtros = ['tipo' => 'por_medico'];
        $reporte = $this->reporte($this->actingAs($this->recepcion)->get($this->ruta($filtros)));

        // CSV: UTF-8 con BOM, separador «;», todo el detalle y los mismos totales.
        $csv = $this->get($this->ruta($filtros, '/reportes/exportar/csv'));
        $csv->assertOk()->assertDownload('reporte_por_medico_2035-03-05_2035-03-09.csv');
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $contenido = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido);
        $lineas = preg_split('/\r\n|\n/', substr($contenido, 3));
        $this->assertSame('Citas por médico', $lineas[0]);
        $this->assertContains('Código;Fecha;Horario;Paciente;CI;Médico;Especialidad;Estado;Pago;Monto', $lineas);
        foreach ($reporte['filas'] as $fila) {
            $this->assertStringContainsString($fila['codigo'].';', $contenido, 'El CSV trae cada cita del detalle.');
        }
        foreach ($reporte['resumen'] as [$etiqueta, $valor]) {
            $this->assertContains("$etiqueta;$valor", $lineas, 'El CSV trae los mismos totales que la pantalla.');
        }
        $this->assertStringContainsString('Gustavo Tdoctor Dos;2;', $contenido);
        $this->assertStringContainsString(';100,00', $contenido, 'Montos con coma decimal.');
        $this->assertSame(substr_count($contenido, ';') > 60, true);

        // PDF: archivo PDF con el mismo detalle y totales (se comprueba la vista que lo genera con los mismos datos).
        $pdf = $this->get($this->ruta($filtros, '/reportes/exportar/pdf'));
        $pdf->assertOk()->assertDownload('reporte_por_medico_2035-03-05_2035-03-09.pdf');
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $html = view('reportesbitacora::CU18_GenerarReportesConIA.pdf', ['reporte' => app(ReporteService::class)->generar([
            'tipo' => 'por_medico', 'desde' => self::DESDE, 'hasta' => self::HASTA, 'medico' => null, 'especialidad' => null, 'estado' => null,
        ], ReporteService::LIMITE_PDF), 'resumenIA' => null])->render();
        foreach ($reporte['filas'] as $fila) {
            $this->assertStringContainsString($fila['codigo'], $html);
        }
        foreach ($reporte['resumen'] as [$etiqueta, $valor]) {
            $this->assertStringContainsString($etiqueta, $html);
            $this->assertStringContainsString($valor, $html);
        }
        $this->assertStringNotContainsString('http', preg_replace('/<title>.*?<\/title>/s', '', $html), 'Sin recursos externos.');
        $this->assertDatabaseHas('bitacora', ['accion' => 'REPORTE_EXPORTADO', 'usuario_id' => $this->recepcion->id]);
    }

    // ---------- Reglas internas ----------

    #[Test]
    public function test_la_primera_visita_muestra_solo_el_formulario_con_los_ocho_tipos(): void
    {
        $respuesta = $this->actingAs($this->admin)->get('/reportes')->assertOk()->assertSee('Tipo de reporte y filtros')->assertDontSee('Totales');
        foreach (ReporteService::TIPOS as $texto) {
            $respuesta->assertSee($texto);
        }
        $respuesta->assertSee('Sin IA')->assertSee('Con IA')->assertSee('data-validar', false);
        $this->assertDatabaseMissing('bitacora', ['accion' => 'REPORTE_GENERADO', 'usuario_id' => $this->admin->id]);
    }

    #[Test]
    public function test_el_administrador_y_la_recepcion_entran_el_medico_y_el_paciente_no(): void
    {
        $this->get('/reportes')->assertRedirect(route('login'));
        $this->get('/reportes/exportar/csv?tipo=por_medico&desde=2035-03-05&hasta=2035-03-09')->assertRedirect(route('login'));

        foreach ([$this->admin, $this->recepcion] as $usuario) {
            $this->actingAs($usuario)->get('/reportes')->assertOk();
        }
        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $otro = $this->crearUsuario('t-rep-'.mb_strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($otro)->get('/reportes')->assertForbidden();
            $this->get($this->ruta(['tipo' => 'por_medico']))->assertForbidden();
            $this->get($this->ruta(['tipo' => 'por_medico'], '/reportes/exportar/csv'))->assertForbidden();
            $this->get($this->ruta(['tipo' => 'por_medico'], '/reportes/exportar/pdf'))->assertForbidden();
        }
    }

    #[Test]
    public function test_la_ia_recibe_solo_totales_nunca_nombres_ci_correos_telefonos_ni_datos_clinicos(): void
    {
        $this->escenario();
        $this->pacienteReconocible->update(['direccion' => 'Calle Reservada 123']);
        $historia = HistoriaClinica::create(['paciente_id' => $this->pacienteReconocible->id, 'alergias' => 'Alergia Confidencial', 'enfermedades_cronicas' => 'Crónica Confidencial']);
        $this->citas['c1']->update(['motivo' => 'Motivo Confidencial']);
        $this->fingirIA();

        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_medico', 'ia' => 'con']))->assertOk()->assertSee('Resumen generado por IA');

        Http::assertSent(function (Request $peticion) {
            // Cuerpo ya decodificado (con acentos y «/» reales): así las comprobaciones de texto no dependen del escape JSON.
            $cuerpo = json_encode($peticion->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['Zuleica', 'Marquesina', 'Quispe', 'Tzapata', 'T-9998877', '78889999', 'zuleica.tzapata@prueba.test', 'prueba.test',
                'Calle Reservada', 'Alergia Confidencial', 'Crónica Confidencial', 'Motivo Confidencial', 'Segundo', 'Tercero', 'Tpaciente',
                $this->citas['c1']->codigo] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $cuerpo, "La IA no debe recibir «{$prohibido}».");
            }
            // Sí recibe el tipo, el período y las cifras.
            $this->assertStringContainsString('Citas por médico', $cuerpo);
            $this->assertStringContainsString('05/03/2035 al 09/03/2035', $cuerpo);
            $datos = json_decode($peticion['contents'][0]['parts'][0]['text'], true);
            $this->assertSame(6, $datos['totales']['total']);
            $this->assertSame(300.0, (float) $datos['totales']['ingreso_total']);
            $this->assertSame(4, $datos['por_medico'][1]['total']);
            $this->assertStringContainsString('SOLO', $peticion['systemInstruction']['parts'][0]['text']);

            return true;
        });
        $this->assertNotNull($historia);
    }

    #[Test]
    public function test_totales_para_la_ia_solo_contiene_cifras_y_nombres_de_medicos_y_especialidades(): void
    {
        $this->escenario();
        $servicio = app(ReporteService::class);
        $reporte = $servicio->generar(['tipo' => 'por_especialidad', 'desde' => self::DESDE, 'hasta' => self::HASTA, 'medico' => null, 'especialidad' => null, 'estado' => null]);

        $datos = $servicio->totalesParaIA($reporte);

        $this->assertSame(['tipo_de_reporte', 'periodo', 'filtros_aplicados', 'totales', 'por_medico', 'por_especialidad'], array_keys($datos));
        $this->assertArrayNotHasKey('filas', $datos);
        $this->assertSame(['T-Rep-General', 'T-Rep-Laboratorio'], array_column($datos['por_especialidad'], 'nombre'));
    }

    #[Test]
    public function test_sin_clave_de_ia_no_se_llama_a_nadie_y_se_avisa(): void
    {
        $this->escenario();
        config(['services.ia.gemini_key' => '']);
        Http::fake();

        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk()
            ->assertSee('No se pudo generar el resumen con IA')->assertDontSee('Resumen generado por IA')->assertSee('Total de citas');

        Http::assertNothingSent();
    }

    #[Test]
    public function test_si_la_ia_tarda_demasiado_o_no_responde_el_reporte_sale_sin_resumen(): void
    {
        $this->escenario();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds')]);

        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk()
            ->assertSee('No se pudo generar el resumen con IA')->assertSee('Total de citas')->assertDontSee('cURL')->assertDontSee('Operation timed out');
    }

    #[Test]
    public function test_una_respuesta_vacia_o_bloqueada_de_la_ia_tambien_es_un_fallo(): void
    {
        $this->escenario();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'SAFETY']]], 200)]);

        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk()
            ->assertSee('No se pudo generar el resumen con IA')->assertDontSee('Resumen generado por IA');
    }

    #[Test]
    public function test_la_ia_se_pide_con_timeout_de_20_segundos_y_sin_clave_en_la_url(): void
    {
        $this->assertSame(20, ResumenIAService::TIEMPO_MAXIMO);
        $this->fingirIA();

        $texto = app(ResumenIAService::class)->resumir(['tipo_de_reporte' => 'x', 'totales' => ['total' => 1]]);

        $this->assertSame(self::TEXTO_IA, $texto);
        Http::assertSent(fn (Request $p) => ! str_contains($p->url(), 'key=') && $p->hasHeader('x-goog-api-key'));
    }

    #[Test]
    public function test_un_reporte_sin_citas_es_vacio_con_su_mensaje_y_no_llama_a_la_ia(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->get('/reportes?tipo=por_medico&desde=2036-01-01&hasta=2036-01-31&ia=con')->assertOk()
            ->assertSee('No hay citas en el período seleccionado.')->assertDontSee('Totales')->assertDontSee('CSV');
        Http::assertNothingSent();

        $this->get('/reportes?tipo=pacientes&desde=2036-01-01&hasta=2036-01-31&ia=sin')->assertOk()->assertSee('No hay pacientes registrados en el período seleccionado.');
    }

    #[Test]
    public function test_citas_de_una_fecha_ignora_el_hasta_y_ordena_por_hora(): void
    {
        $this->escenario();

        $reporte = $this->reporte($this->actingAs($this->recepcion)->get('/reportes?tipo=citas_fecha&desde=2035-03-05&hasta=2035-03-09&ia=sin')->assertOk());

        $this->assertSame('05/03/2035', $reporte['periodo'], 'Es de un solo día.');
        $this->assertSame(5, $reporte['totales']['total'], 'No incluye la cita del 07/03.');
        $horas = array_map(fn ($f) => substr($f['horario'], 0, 5), $reporte['filas']);
        $ordenadas = $horas;
        sort($ordenadas);
        $this->assertSame($ordenadas, $horas);
    }

    #[Test]
    public function test_la_agenda_diaria_exige_el_medico_y_muestra_solo_sus_citas(): void
    {
        $this->escenario();

        $this->actingAs($this->admin)->get('/reportes?tipo=agenda_medico&desde=2035-03-05&ia=sin')->assertSessionHasErrors(['medico' => 'Seleccione el médico de la agenda.']);

        $reporte = $this->reporte($this->get('/reportes?tipo=agenda_medico&desde=2035-03-05&medico='.$this->medico1->id.'&ia=sin')->assertOk()->assertSee('Rosario Tdoctora Uno'));
        $this->assertSame(3, $reporte['totales']['total']);
        $this->assertEqualsCanonicalizing([$this->citas['c1']->codigo, $this->citas['c2']->codigo, $this->citas['c3']->codigo], array_column($reporte['filas'], 'codigo'));
        $this->assertSame(['09:00', '10:00', '11:00'], array_map(fn ($f) => substr($f['horario'], 0, 5), $reporte['filas']));
    }

    #[Test]
    public function test_los_filtros_de_medico_especialidad_y_estado_se_aplican(): void
    {
        $this->escenario();
        $this->actingAs($this->admin);

        $r = $this->reporte($this->get($this->ruta(['tipo' => 'por_especialidad', 'medico' => $this->medico2->id]))->assertOk());
        $this->assertSame(2, $r['totales']['total']);
        $this->assertSame(['Médico: Gustavo Tdoctor Dos'], $r['filtros']);

        $r = $this->reporte($this->get($this->ruta(['tipo' => 'por_estado', 'especialidad' => $this->esp1->id]))->assertOk());
        $this->assertSame(3, $r['totales']['total']);

        $r = $this->reporte($this->get($this->ruta(['tipo' => 'por_estado', 'estado' => 'ATENDIDA']))->assertOk());
        $this->assertSame(2, $r['totales']['total']);
        $this->assertSame(2, $r['totales']['atendidas']);
        $this->assertSame(['Estado: Atendida'], $r['filtros']);

        $this->get($this->ruta(['tipo' => 'por_estado', 'estado' => 'INVENTADO']))->assertSessionHasErrors('estado');
        $this->get($this->ruta(['tipo' => 'por_medico', 'medico' => 999999999]))->assertSessionHasErrors('medico');
        $this->get($this->ruta(['tipo' => 'por_medico', 'especialidad' => 999999999]))->assertSessionHasErrors('especialidad');
    }

    #[Test]
    public function test_atendidas_canceladas_e_inasistencias_del_periodo_excluye_las_programadas(): void
    {
        $this->escenario();

        $r = $this->reporte($this->actingAs($this->admin)->get($this->ruta(['tipo' => 'periodo']))->assertOk()->assertSee('Atendidas, canceladas e inasistencias'));

        $this->assertSame(4, $r['totales']['total']);
        $this->assertSame([0, 0, 2, 1, 1], [$r['totales']['programadas'], $r['totales']['confirmadas'], $r['totales']['atendidas'], $r['totales']['canceladas'], $r['totales']['inasistencias']]);
        $this->assertNotContains($this->citas['c1']->codigo, array_column($r['filas'], 'codigo'));

        // Un estado que no es del reporte no lo vacía: se ignora.
        $this->assertSame(4, $this->reporte($this->get($this->ruta(['tipo' => 'periodo', 'estado' => 'PROGRAMADA']))->assertOk())['totales']['total']);
        $this->assertSame(1, $this->reporte($this->get($this->ruta(['tipo' => 'periodo', 'estado' => 'CANCELADA']))->assertOk())['totales']['total']);
    }

    #[Test]
    public function test_los_datos_de_la_demostracion_tambien_generan_los_ocho_reportes(): void
    {
        $base = ['desde' => '2026-01-01', 'hasta' => '2026-12-31', 'ia' => 'sin'];
        $medico = Medico::first();
        $this->actingAs($this->admin);

        foreach (array_keys(ReporteService::TIPOS) as $tipo) {
            $f = $base + ['tipo' => $tipo, 'medico' => $tipo === 'agenda_medico' ? $medico?->id : null];
            $this->get('/reportes?'.http_build_query($f))->assertOk()->assertDontSee('Whoops');
            $this->get('/reportes/exportar/csv?'.http_build_query($f))->assertOk();
            $this->get('/reportes/exportar/pdf?'.http_build_query($f))->assertOk();
        }
    }

    #[Test]
    public function test_la_exportacion_incluye_el_resumen_de_ia_solo_si_se_eligio_y_ya_se_genero(): void
    {
        $this->escenario();
        $this->fingirIA();
        $filtros = ['tipo' => 'por_estado', 'ia' => 'con'];

        // Con IA pero sin haber generado antes el resumen en pantalla: no se inventa uno ni se llama a la IA.
        $this->actingAs($this->admin)->get($this->ruta($filtros, '/reportes/exportar/csv'))->assertOk();
        Http::assertNothingSent();
        $this->assertStringNotContainsString('Resumen generado por IA', $this->get($this->ruta($filtros, '/reportes/exportar/csv'))->streamedContent());

        // Se genera en pantalla (una llamada) y luego el CSV lo incluye sin llamar de nuevo.
        $this->get($this->ruta($filtros))->assertOk()->assertSee('Resumen generado por IA');
        $csv = $this->get($this->ruta($filtros, '/reportes/exportar/csv'))->streamedContent();
        $this->assertStringContainsString('Resumen generado por IA', $csv);
        $this->assertStringContainsString('En el período se registraron 6 citas', $csv);
        Http::assertSentCount(1);

        // En la modalidad sin IA nunca se incluye.
        $sin = $this->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'sin'], '/reportes/exportar/csv'))->streamedContent();
        $this->assertStringNotContainsString('Resumen generado por IA', $sin);
        $this->assertStringNotContainsString('En el período se registraron', $sin);
    }

    #[Test]
    public function test_el_csv_neutraliza_formulas_y_trae_todas_las_filas_aunque_la_pantalla_se_limite(): void
    {
        $this->escenario();
        $this->pacienteReconocible->update(['nombres' => '=HYPERLINK("http://malo")']);

        $csv = $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_medico'], '/reportes/exportar/csv'))->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);

        $reporte = app(ReporteService::class)->generar(['tipo' => 'por_medico', 'desde' => self::DESDE, 'hasta' => self::HASTA, 'medico' => null, 'especialidad' => null, 'estado' => null], 2);
        $this->assertCount(2, $reporte['filas']);
        $this->assertTrue($reporte['truncado']);
        $this->assertSame(6, $reporte['total_filas']);
        $this->assertSame(6, $reporte['totales']['total'], 'Los totales incluyen todas las citas aunque el detalle se recorte.');
    }

    #[Test]
    public function test_el_rango_no_puede_ser_enorme_ni_las_fechas_invalidas(): void
    {
        $this->actingAs($this->admin);

        $this->get('/reportes?tipo=por_medico&desde=2030-01-01&hasta=2033-01-01&ia=sin')->assertSessionHasErrors('hasta');
        $this->get('/reportes?tipo=por_medico&desde=2035-02-31&hasta=2035-03-01&ia=sin')->assertSessionHasErrors('desde');
        $this->get('/reportes?tipo=por_medico&desde=&hasta=&ia=sin')->assertSessionHasErrors('desde');
        $this->get('/reportes?tipo=inventado&desde=2035-03-05&hasta=2035-03-05&ia=sin')->assertSessionHasErrors('tipo');
        $this->get('/reportes?tipo=&desde=2035-03-05&ia=sin')->assertSessionHasErrors('tipo');
        $this->get('/reportes?tipo=por_medico&desde=2035-03-05&hasta=2035-03-05&ia=quizas')->assertSessionHasErrors('ia');
        $this->get('/reportes/exportar/xls?tipo=por_medico&desde=2035-03-05&hasta=2035-03-05&ia=sin')->assertNotFound();
        $this->get('/reportes/exportar/csv')->assertNotFound();
    }

    #[Test]
    public function test_generar_y_exportar_quedan_en_la_bitacora_sin_datos_de_pacientes(): void
    {
        $this->escenario();

        $this->actingAs($this->recepcion)->get($this->ruta(['tipo' => 'por_medico']))->assertOk();
        $this->get($this->ruta(['tipo' => 'por_medico'], '/reportes/exportar/csv'))->assertOk()->streamedContent();

        $generado = DB::table('bitacora')->where('usuario_id', $this->recepcion->id)->where('accion', 'REPORTE_GENERADO')->first();
        $this->assertStringContainsString('Citas por médico', $generado->detalle);
        $this->assertStringContainsString('05/03/2035 al 09/03/2035', $generado->detalle);
        $this->assertStringContainsString('sin IA', $generado->detalle);
        $exportado = DB::table('bitacora')->where('usuario_id', $this->recepcion->id)->where('accion', 'REPORTE_EXPORTADO')->first();
        $this->assertStringContainsString('CSV', $exportado->detalle);
        foreach ([$generado->detalle, $exportado->detalle] as $detalle) {
            $this->assertStringNotContainsString('Zuleica', $detalle);
            $this->assertStringNotContainsString('T-9998877', $detalle);
        }
    }

    #[Test]
    public function test_con_ia_la_bitacora_dice_si_el_resumen_se_genero_o_no(): void
    {
        $this->escenario();
        $this->fingirIA();
        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk();
        $this->assertStringContainsString('con IA (resumen generado)', DB::table('bitacora')->where('accion', 'REPORTE_GENERADO')->latest('id')->value('detalle'));

        config(['services.ia.gemini_key' => '']);
        $this->get($this->ruta(['tipo' => 'por_estado', 'ia' => 'con']))->assertOk();
        $this->assertStringContainsString('con IA (no disponible, sin resumen)', DB::table('bitacora')->where('accion', 'REPORTE_GENERADO')->latest('id')->value('detalle'));
    }

    #[Test]
    public function test_consultar_reportes_no_modifica_ninguna_cita_ni_paciente(): void
    {
        $this->escenario();
        $foto = fn () => [Cita::count(), Paciente::count(), Medico::count(), Cita::where('estado', 'PROGRAMADA')->count(), (float) Cita::sum('monto')];
        $antes = $foto();

        $this->actingAs($this->admin);
        foreach (array_keys(ReporteService::TIPOS) as $tipo) {
            $this->get($this->ruta(['tipo' => $tipo, 'medico' => $this->medico1->id]))->assertOk();
            $this->get($this->ruta(['tipo' => $tipo, 'medico' => $this->medico1->id], '/reportes/exportar/csv'))->assertOk()->streamedContent();
        }

        $this->assertSame($antes, $foto());
    }

    #[Test]
    public function test_el_panel_ofrece_reportes_al_administrador_y_a_la_recepcion_y_bitacora_solo_al_administrador(): void
    {
        $this->actingAs($this->admin)->get('/panel')->assertOk()->assertSee('Reportes')->assertSee('CU-18')->assertSee('Bitácora')->assertSee('CU-19');
        $this->actingAs($this->recepcion)->get('/panel')->assertOk()->assertSee('CU-18')->assertDontSee('CU-19');

        $medico = $this->crearUsuario('t-rep-panel-med', [Rol::MEDICO]);
        $this->actingAs($medico)->get('/panel')->assertOk()->assertDontSee('CU-18');
    }

    #[Test]
    public function test_la_pantalla_trae_la_impresion_y_los_enlaces_de_exportacion_con_los_mismos_filtros(): void
    {
        $this->escenario();

        $this->actingAs($this->admin)->get($this->ruta(['tipo' => 'por_medico']))->assertOk()
            ->assertSee('data-imprimir', false)->assertSee('sin-impresion', false)
            ->assertSee(e(route('reportes.exportar', ['formato' => 'csv', 'tipo' => 'por_medico', 'desde' => self::DESDE, 'hasta' => self::HASTA, 'ia' => 'sin'])), false)
            ->assertSee(e(route('reportes.exportar', ['formato' => 'pdf', 'tipo' => 'por_medico', 'desde' => self::DESDE, 'hasta' => self::HASTA, 'ia' => 'sin'])), false);
    }
}
