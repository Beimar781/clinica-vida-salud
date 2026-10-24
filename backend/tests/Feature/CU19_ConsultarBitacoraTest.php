<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\ReportesBitacora\CU19_ConsultarBitacora\AccionesBitacora;
use App\Services\BitacoraService;
use App\Support\PanelPrincipal;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CU-19: Consultar bitácora. Los registros de prueba llevan la entidad «T19-...» para filtrarlos sin tocar
 * los demás; cada prueba se revierte al terminar (DatabaseTransactions).
 */
class CU19_ConsultarBitacoraTest extends SistemaTestCase
{
    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin-bit', [Rol::ADMINISTRADOR]);
    }

    /** Inserta un registro de bitácora de prueba (la tabla solo admite agregar). */
    private function registro(array $datos = []): void
    {
        DB::table('bitacora')->insert($datos + [
            'usuario_id' => null,
            'accion' => 'LOGIN',
            'entidad' => 'T19-Entidad',
            'detalle' => 'Detalle de prueba',
            'ip' => null,
            'fecha_hora' => '2030-05-10 08:15:30',
        ]);
    }

    private function ver(array $filtros = [])
    {
        return $this->actingAs($this->admin)->get(route('bitacora.index', $filtros));
    }

    // ---------- Acceso ----------

    public function test_el_administrador_ve_el_listado_con_fecha_usuario_accion_entidad_detalle_e_ip(): void
    {
        $this->registro([
            'usuario_id' => $this->admin->id, 'accion' => 'LOGIN_FALLIDO', 'entidad' => 'T19-Ver',
            'detalle' => 'Intento fallido T19 visible', 'ip' => '203.0.113.5', 'fecha_hora' => '2030-05-10 08:15:30',
        ]);

        $this->ver(['entidad' => 'T19-Ver'])->assertOk()
            ->assertSee('Bitácora')
            ->assertSeeInOrder(['Fecha y hora', 'Usuario', 'Acción', 'Entidad', 'Detalle', 'IP'])
            ->assertSee('10/05/2030 08:15:30')
            ->assertSee('t-admin-bit')
            ->assertSee('Inicio de sesión fallido')
            ->assertSee('accion-rojo', false)
            ->assertSee('T19-Ver')
            ->assertSee('Intento fallido T19 visible')
            ->assertSee('203.0.113.5');
    }

    public function test_recepcionista_medico_y_paciente_reciben_403_y_queda_registrado(): void
    {
        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-bit-'.strtolower(substr($rol, 0, 3)), [$rol]);

            $this->actingAs($usuario)->get(route('bitacora.index'))->assertForbidden();

            $this->assertDatabaseHas('bitacora', ['accion' => 'ACCESO_DENEGADO', 'usuario_id' => $usuario->id, 'detalle' => 'Ruta: bitacora']);
        }
    }

    public function test_un_invitado_va_al_inicio_de_sesion(): void
    {
        $this->get(route('bitacora.index'))->assertRedirect('/login');
    }

    public function test_la_bitacora_es_de_solo_lectura_no_hay_rutas_de_escritura(): void
    {
        $metodos = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($ruta) => str_starts_with($ruta->uri(), 'bitacora'))
            ->flatMap(fn ($ruta) => $ruta->methods())
            ->unique()->sort()->values()->all();

        $this->assertSame(['GET', 'HEAD'], $metodos);

        $this->actingAs($this->admin)->post('/bitacora', [])->assertStatus(405);
        $this->actingAs($this->admin)->delete('/bitacora/1')->assertStatus(404);
    }

    // ---------- Orden y formato ----------

    public function test_muestra_primero_el_registro_mas_reciente(): void
    {
        $this->registro(['detalle' => 'T19 antiguo', 'fecha_hora' => '2030-01-01 10:00:00']);
        $this->registro(['detalle' => 'T19 reciente', 'fecha_hora' => '2030-01-02 10:00:00']);

        $this->ver(['entidad' => 'T19-Entidad'])->assertSeeInOrder(['T19 reciente', 'T19 antiguo']);
    }

    public function test_un_registro_sin_usuario_dice_visitante_o_sistema_y_sin_ip_muestra_raya(): void
    {
        $this->registro(['detalle' => 'T19 sin usuario ni ip', 'entidad' => 'T19-Sin', 'usuario_id' => null, 'ip' => null]);

        $this->ver(['entidad' => 'T19-Sin'])->assertOk()
            ->assertSeeInOrder(['Visitante / sistema', 'T19-Sin', 'T19 sin usuario ni ip', '—']);
    }

    public function test_la_hora_que_se_muestra_es_la_de_bolivia(): void
    {
        $this->assertSame('America/La_Paz', config('app.timezone'));

        Carbon::setTestNow(Carbon::create(2031, 6, 1, 15, 20, 10, 'America/La_Paz'));
        BitacoraService::registrar('T19_HORA', 'T19-Hora', 'Registro de hora local', $this->admin->id);

        $this->ver(['entidad' => 'T19-Hora'])->assertSee('01/06/2031 15:20:10');
    }

    // ---------- Filtros ----------

    public function test_filtra_por_usuario_y_por_visitante_o_sistema(): void
    {
        $otro = $this->crearUsuario('t-otro-bit', [Rol::PACIENTE]);
        $this->registro(['usuario_id' => $this->admin->id, 'detalle' => 'T19 del admin']);
        $this->registro(['usuario_id' => $otro->id, 'detalle' => 'T19 del otro']);
        $this->registro(['usuario_id' => null, 'detalle' => 'T19 de nadie']);

        $this->ver(['entidad' => 'T19-Entidad', 'usuario' => $otro->id])
            ->assertSee('T19 del otro')->assertDontSee('T19 del admin')->assertDontSee('T19 de nadie');
        $this->ver(['entidad' => 'T19-Entidad', 'usuario' => 'sin'])
            ->assertSee('T19 de nadie')->assertDontSee('T19 del otro')->assertDontSee('T19 del admin');
    }

    public function test_filtra_por_entidad_y_por_accion(): void
    {
        $this->registro(['entidad' => 'T19-A', 'accion' => 'CITA_REGISTRADA', 'detalle' => 'T19 cita A']);
        $this->registro(['entidad' => 'T19-B', 'accion' => 'LOGOUT', 'detalle' => 'T19 salida B']);

        $this->ver(['entidad' => 'T19-A'])->assertSee('T19 cita A')->assertDontSee('T19 salida B');
        $this->ver(['entidad' => 'T19-B', 'accion' => 'LOGOUT'])->assertSee('T19 salida B')->assertDontSee('T19 cita A');
        $this->ver(['entidad' => 'T19-A', 'accion' => 'LOGOUT'])->assertSee('No hay registros con esos filtros.');
    }

    public function test_filtra_por_rango_de_fechas_y_el_hasta_incluye_todo_el_dia(): void
    {
        $this->registro(['detalle' => 'T19 dia 9', 'fecha_hora' => '2030-05-09 23:59:59']);
        $this->registro(['detalle' => 'T19 dia 10 inicio', 'fecha_hora' => '2030-05-10 00:00:00']);
        $this->registro(['detalle' => 'T19 dia 10 fin', 'fecha_hora' => '2030-05-10 23:59:59']);
        $this->registro(['detalle' => 'T19 dia 11', 'fecha_hora' => '2030-05-11 00:00:00']);

        $this->ver(['entidad' => 'T19-Entidad', 'desde' => '2030-05-10', 'hasta' => '2030-05-10'])
            ->assertSee('T19 dia 10 inicio')->assertSee('T19 dia 10 fin')
            ->assertDontSee('T19 dia 9')->assertDontSee('T19 dia 11');

        $this->ver(['entidad' => 'T19-Entidad', 'desde' => '2030-05-10'])
            ->assertSee('T19 dia 11')->assertDontSee('T19 dia 9');

        $this->ver(['entidad' => 'T19-Entidad', 'hasta' => '2030-05-09'])
            ->assertSee('T19 dia 9')->assertDontSee('T19 dia 10 inicio');
    }

    public function test_busca_texto_en_el_detalle_sin_distinguir_tildes_ni_mayusculas_y_en_la_ip(): void
    {
        $this->registro(['detalle' => 'Reprogramación de la CITA del paciente', 'ip' => '198.51.100.23']);
        $this->registro(['detalle' => 'Otro movimiento', 'ip' => '203.0.113.9']);

        $this->ver(['entidad' => 'T19-Entidad', 'q' => 'reprogramacion de la cita'])
            ->assertSee('Reprogramación de la CITA')->assertDontSee('Otro movimiento');
        $this->ver(['entidad' => 'T19-Entidad', 'q' => '203.0.113'])
            ->assertSee('Otro movimiento')->assertDontSee('Reprogramación de la CITA');
        $this->ver(['entidad' => 'T19-Entidad', 'q' => '50%_'])->assertSee('No hay registros con esos filtros.');
    }

    public function test_sin_resultados_muestra_el_mensaje(): void
    {
        $this->ver(['q' => 'texto-que-no-existe-en-ningun-registro-T19'])
            ->assertOk()->assertSee('No hay registros con esos filtros.')->assertSee('0 registros');
    }

    public function test_los_filtros_se_validan(): void
    {
        $this->actingAs($this->admin)->from(route('bitacora.index'))
            ->get(route('bitacora.index', ['desde' => '2030-05-10', 'hasta' => '2030-05-01']))
            ->assertRedirect(route('bitacora.index'))
            ->assertSessionHasErrors(['hasta' => 'La fecha «hasta» no puede ser anterior a la fecha «desde».']);

        $this->actingAs($this->admin)->from(route('bitacora.index'))
            ->get(route('bitacora.index', ['desde' => 'ayer', 'usuario' => 'x; drop']))
            ->assertSessionHasErrors(['desde', 'usuario']);
    }

    public function test_pagina_de_25_en_25_y_conserva_los_filtros_en_los_enlaces(): void
    {
        foreach (range(1, 30) as $n) {
            $this->registro(['entidad' => 'T19-Pagina', 'detalle' => sprintf('T19 fila %02d', $n), 'fecha_hora' => sprintf('2030-03-01 10:00:%02d', $n)]);
        }

        $primera = $this->ver(['entidad' => 'T19-Pagina'])->assertOk()->assertSee('30 registros');
        $this->assertSame(25, substr_count($primera->getContent(), 'T19 fila '));
        $primera->assertSee('T19 fila 30')->assertDontSee('T19 fila 05')
            ->assertSee('entidad=T19-Pagina', false)->assertSee('page=2', false);

        $segunda = $this->ver(['entidad' => 'T19-Pagina', 'page' => 2])->assertOk();
        $this->assertSame(5, substr_count($segunda->getContent(), 'T19 fila '));
        $segunda->assertSee('T19 fila 05')->assertSee('T19 fila 01')->assertDontSee('T19 fila 06');
    }

    public function test_el_formulario_conserva_los_filtros_y_tiene_filtrar_y_limpiar(): void
    {
        $this->ver(['entidad' => 'T19-Entidad', 'desde' => '2030-05-01', 'q' => 'abc'])->assertOk()
            ->assertSee('value="2030-05-01"', false)->assertSee('value="abc"', false)
            ->assertSee('Filtrar')->assertSee('Limpiar')
            ->assertSee('Hasta (incluye todo el día)');
    }

    // ---------- IP ----------

    public function test_la_ip_se_guarda_en_un_registro_nuevo_y_se_muestra(): void
    {
        $this->crearUsuario('t-ip-login', [Rol::PACIENTE], 'Clave1234');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->post('/login', ['identificador' => 't-ip-login', 'password' => 'incorrecta'])
            ->assertSessionHas('error');

        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN_FALLIDO', 'ip' => '203.0.113.77']);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->ver(['q' => '203.0.113.77'])->assertOk()->assertSee('203.0.113.77')->assertSee('Inicio de sesión fallido');
    }

    public function test_el_servicio_guarda_la_ip_de_la_peticion_y_el_resto_de_la_firma_no_cambia(): void
    {
        BitacoraService::registrar('T19_FIRMA', 'T19-Firma', 'Sin usuario explícito');
        BitacoraService::registrar('T19_FIRMA', 'T19-Firma', 'Con usuario', $this->admin->id);

        $this->assertSame(2, DB::table('bitacora')->where('accion', 'T19_FIRMA')->count());
        $this->assertSame(1, DB::table('bitacora')->where('accion', 'T19_FIRMA')->where('usuario_id', $this->admin->id)->count());
        $this->assertSame(0, DB::table('bitacora')->where('accion', 'T19_FIRMA')->whereNull('ip')->count());
    }

    public function test_los_registros_viejos_sin_ip_siguen_siendo_validos(): void
    {
        $this->assertTrue(Schema::hasColumn('bitacora', 'ip'));
        $this->registro(['detalle' => 'T19 viejo', 'ip' => null]);

        $this->assertNull(Bitacora::where('detalle', 'T19 viejo')->value('ip'));
    }

    // ---------- Etiquetas ----------

    public function test_el_catalogo_traduce_todas_las_acciones_que_el_sistema_registra(): void
    {
        $acciones = [];
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterador as $archivo) {
            if ($archivo->getExtension() === 'php' && preg_match_all("/registrar\(\s*'([A-Z][A-Z_]+)'/", file_get_contents($archivo->getPathname()), $coincidencias)) {
                $acciones = array_merge($acciones, $coincidencias[1]);
            }
        }
        $acciones = array_unique($acciones);

        $this->assertGreaterThan(30, count($acciones));
        foreach ($acciones as $accion) {
            $this->assertArrayHasKey($accion, AccionesBitacora::ETIQUETAS, "Falta la etiqueta de la acción {$accion}");
        }
    }

    public function test_los_colores_de_las_etiquetas(): void
    {
        foreach (['LOGIN_FALLIDO', 'LOGIN_BLOQUEADO', 'LOGIN_RECHAZADO', 'ACCESO_DENEGADO'] as $accion) {
            $this->assertSame('rojo', AccionesBitacora::tono($accion), $accion);
        }
        foreach (['LOGIN', 'USUARIO_CREADO', 'ESPECIALIDAD_CREADA', 'PACIENTE_ACTIVADO', 'HORARIO_ACTIVADO', 'CITA_REGISTRADA', 'CITA_RESERVADA'] as $accion) {
            $this->assertSame('verde', AccionesBitacora::tono($accion), $accion);
        }
        $this->assertSame('gris', AccionesBitacora::tono('LOGOUT'));
        foreach (['USUARIO_EDITADO', 'MEDICO_DESACTIVADO', 'CITA_REPROGRAMADA', 'RECUPERACION_SOLICITADA'] as $accion) {
            $this->assertSame('azul', AccionesBitacora::tono($accion), $accion);
        }
        $this->assertSame('Accion nueva', AccionesBitacora::etiqueta('ACCION_NUEVA'));
    }

    // ---------- Menú y panel ----------

    public function test_el_administrador_ve_bitacora_en_el_panel_y_los_demas_no(): void
    {
        $nombres = fn (Usuario $u) => collect(PanelPrincipal::paquetes($u))->flatMap(fn ($p) => collect($p['funciones'])->pluck('nombre'))->all();

        $this->assertContains('Bitácora', $nombres($this->admin));
        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $this->assertNotContains('Bitácora', $nombres($this->crearUsuario('t-pan-bit-'.strtolower(substr($rol, 0, 3)), [$rol])));
        }

        $this->actingAs($this->admin)->get('/panel')->assertOk()->assertSee('Reportes y bitácora')->assertSee('Bitácora')->assertSee('CU-19');
    }

    // ---------- Solo lectura ----------

    public function test_la_tabla_sigue_sin_admitir_update_ni_delete(): void
    {
        $this->registro(['accion' => 'T19_INMUTABLE', 'detalle' => 'T19 inmutable']);

        foreach (['UPDATE bitacora SET detalle = ? WHERE accion = ?' => ['cambiado', 'T19_INMUTABLE'],
            'DELETE FROM bitacora WHERE accion = ?' => ['T19_INMUTABLE']] as $sql => $parametros) {
            try {
                DB::transaction(fn () => DB::statement($sql, $parametros));
                $this->fail('La bitácora aceptó una modificación: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('La bitácora no admite modificaciones ni eliminaciones', $e->getMessage());
            }
        }

        $this->assertSame('T19 inmutable', DB::table('bitacora')->where('accion', 'T19_INMUTABLE')->value('detalle'));
    }

    public function test_el_modelo_no_admite_asignacion_masiva(): void
    {
        $this->expectException(MassAssignmentException::class);

        Bitacora::create(['accion' => 'T19_MASIVA', 'fecha_hora' => now()]);
    }
}
