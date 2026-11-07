<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\AgendarCitaService;
use App\Modules\GestionCitas\Compartido\CatalogoMedicosService;
use App\Modules\GestionCitas\Compartido\ReglaCitaException;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-04: Gestionar especialidades y servicios.
 *
 * El plan 8.1 usa «Cardiología» y «cardiologia », pero esa especialidad ya está en los datos de
 * demostración; los pasos 1 y 2 se prueban con «T-Cardiología» para no chocar con ella (otra prueba
 * comprueba que la de demostración también se rechaza). Los métodos paso_N son las filas del plan 8.1.
 */
class CU04_GestionarEspecialidadesServiciosTest extends SistemaTestCase
{
    private const MENSAJE_DUPLICADO = 'Ya existe una especialidad con ese nombre (se comparan sin mayúsculas, tildes ni espacios sobrantes).';

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin-esp', [Rol::ADMINISTRADOR]);
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: registrar especialidad nueva (nombre «Cardiología») → la especialidad queda guardada y activa. */
    #[Test]
    public function paso_1_registrar_especialidad_nueva(): void
    {
        $this->actingAs($this->admin)
            ->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Cardiología', 'descripcion' => 'Corazón']))
            ->assertRedirect(route('especialidades.index'))
            ->assertSessionHas('exito');

        $especialidad = Especialidad::where('nombre_normalizado', 't-cardiologia')->firstOrFail();
        $this->assertSame('T-Cardiología', $especialidad->nombre);
        $this->assertSame(Especialidad::CONSULTA, $especialidad->tipo);
        $this->assertEquals(150, $especialidad->precio);
        $this->assertTrue($especialidad->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'ESPECIALIDAD_CREADA', 'usuario_id' => $this->admin->id]);
    }

    /** Paso 2: registrar especialidad con nombre repetido («cardiologia » en minúsculas y sin tilde) → el sistema rechaza el duplicado normalizado. */
    #[Test]
    public function paso_2_registrar_especialidad_con_nombre_repetido(): void
    {
        $this->crearEspecialidad('T-Cardiología');

        $this->actingAs($this->admin)->from(route('especialidades.crear'))
            ->post(route('especialidades.guardar'), $this->datos(['nombre' => 't-cardiologia ']))
            ->assertRedirect(route('especialidades.crear'))
            ->assertSessionHasErrors(['nombre' => self::MENSAJE_DUPLICADO]);

        $this->assertSame(1, Especialidad::where('nombre_normalizado', 't-cardiologia')->count());
    }

    /** Paso 3: registrar un servicio de laboratorio (nombre «Laboratorio», tipo Laboratorio y precio) → queda guardado con su tipo y su precio y se ofrece para nuevas citas. */
    #[Test]
    public function paso_3_registrar_servicio_de_laboratorio_con_tipo_y_precio(): void
    {
        $this->actingAs($this->admin)
            ->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Laboratorio', 'tipo' => 'LABORATORIO', 'precio' => '80', 'descripcion' => 'Análisis básicos']))
            ->assertRedirect(route('especialidades.index'))
            ->assertSessionHas('exito', 'T-Laboratorio registrada correctamente (Laboratorio, Bs 80,00).');

        $servicio = Especialidad::where('nombre_normalizado', 't-laboratorio')->firstOrFail();
        $this->assertSame(Especialidad::LABORATORIO, $servicio->tipo);
        $this->assertEquals(80, $servicio->precio);
        $this->assertTrue($servicio->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'ESPECIALIDAD_CREADA', 'detalle' => 'Especialidad registrada: T-Laboratorio (Laboratorio, Bs 80,00)']);

        // «Se ofrece para nuevas citas»: con un profesional asignado aparece en el catálogo de CU-09 y CU-11 y se puede agendar.
        $medico = $this->crearMedico([], [$servicio]);
        $this->crearHorario($medico, 1, '07:00', '11:00', 20);
        $catalogo = app(CatalogoMedicosService::class);
        $this->assertTrue($catalogo->especialidadesConMedicos()->contains('id', $servicio->id));
        $this->assertTrue($catalogo->medicosDe($servicio->id)->contains('id', $medico->id));

        $cita = app(AgendarCitaService::class)->crear($this->crearPaciente(), $medico, $servicio, $this->proximaFecha(1), '07:00', Cita::PRESENCIAL, null, $this->admin->id);
        $this->assertSame($servicio->id, $cita->especialidad_id);
    }

    // ------------------------------------------------------------------ Reglas internas de la ficha

    #[Test]
    public function rechaza_la_especialidad_de_demostracion_aunque_cambien_tildes_y_mayusculas(): void
    {
        $this->actingAs($this->admin)->post(route('especialidades.guardar'), $this->datos(['nombre' => ' CARDIOLOGIA  ']))
            ->assertSessionHasErrors(['nombre' => self::MENSAJE_DUPLICADO]);
    }

    #[Test]
    public function el_nombre_y_la_descripcion_se_validan(): void
    {
        $this->actingAs($this->admin)->post(route('especialidades.guardar'), $this->datos(['nombre' => '', 'descripcion' => str_repeat('a', 201)]))
            ->assertSessionHasErrors(['nombre' => 'Ingrese el nombre de la especialidad o del servicio.', 'descripcion' => 'La descripción no puede superar los 200 caracteres.']);

        $this->post(route('especialidades.guardar'), $this->datos(['nombre' => str_repeat('a', 81)]))
            ->assertSessionHasErrors(['nombre' => 'El nombre no puede superar los 80 caracteres.']);
    }

    #[Test]
    public function el_tipo_es_obligatorio_y_solo_admite_consulta_laboratorio_o_enfermeria(): void
    {
        $mensaje = 'Seleccione el tipo: consulta médica, laboratorio o enfermería.';

        foreach (['', 'RAYOS_X', 'consulta'] as $invalido) {
            $this->actingAs($this->admin)->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Tipo', 'tipo' => $invalido]))
                ->assertSessionHasErrors(['tipo' => $mensaje]);
        }
        $sinTipo = $this->datos(['nombre' => 'T-Tipo']);
        unset($sinTipo['tipo']);
        $this->post(route('especialidades.guardar'), $sinTipo)->assertSessionHasErrors(['tipo' => $mensaje]);

        foreach (['CONSULTA', 'LABORATORIO', 'ENFERMERIA'] as $i => $valido) {
            $this->post(route('especialidades.guardar'), $this->datos(['nombre' => "T-Tipo $i", 'tipo' => $valido]))->assertSessionHasNoErrors();
        }
        $this->assertDatabaseMissing('especialidades', ['nombre_normalizado' => 't-tipo']);
        $this->assertSame(3, Especialidad::where('nombre_normalizado', 'like', 't-tipo %')->count());
    }

    #[Test]
    public function el_precio_es_obligatorio_numerico_de_cero_a_999999_con_dos_decimales(): void
    {
        $casos = [
            '' => 'Ingrese el precio en bolivianos (puede ser 0).',
            'abc' => 'El precio debe ser un número, por ejemplo 100 o 100,50.',
            '-1' => 'El precio no puede ser negativo.',
            '1000000' => 'El precio no puede superar Bs 999.999,99.',
            '10.555' => 'El precio admite hasta dos decimales, por ejemplo 100,50.',
        ];

        foreach ($casos as $precio => $mensaje) {
            $this->actingAs($this->admin)->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Precio', 'precio' => (string) $precio]))
                ->assertSessionHasErrors(['precio' => $mensaje]);
        }
        $this->assertDatabaseMissing('especialidades', ['nombre_normalizado' => 't-precio']);
    }

    #[Test]
    public function acepta_precio_cero_el_maximo_y_la_coma_decimal(): void
    {
        $esperado = ['0' => 0, '999999.99' => 999999.99, '100,5' => 100.5, ' 45 ' => 45, '12.34' => 12.34];
        $i = 0;

        foreach ($esperado as $escrito => $guardado) {
            $nombre = 'T-Precio ok '.++$i;
            $this->actingAs($this->admin)->post(route('especialidades.guardar'), $this->datos(['nombre' => $nombre, 'precio' => (string) $escrito]))
                ->assertSessionHasNoErrors();
            $this->assertEquals($guardado, Especialidad::where('nombre', $nombre)->firstOrFail()->precio, "Precio escrito «{$escrito}»");
        }
    }

    #[Test]
    public function editar_conserva_su_propio_nombre_y_rechaza_el_de_otra(): void
    {
        $uno = $this->crearEspecialidad('T-Uno');
        $this->crearEspecialidad('T-Dos');

        // Mismo nombre (con otras mayúsculas) y nueva descripción: se permite.
        $this->actingAs($this->admin)->put(route('especialidades.actualizar', $uno), $this->datos(['nombre' => 'T-UNO', 'descripcion' => 'Nueva']))
            ->assertRedirect(route('especialidades.index'));
        $this->assertSame('Nueva', $uno->fresh()->descripcion);
        $this->assertSame('t-uno', $uno->fresh()->nombre_normalizado);

        // El nombre de otra especialidad: se rechaza.
        $this->put(route('especialidades.actualizar', $uno), $this->datos(['nombre' => 't-dos']))->assertSessionHasErrors('nombre');
        $this->assertSame('T-UNO', $uno->fresh()->nombre);
    }

    #[Test]
    public function editar_cambia_tipo_y_precio_y_la_bitacora_guarda_el_antes_y_el_despues(): void
    {
        $servicio = $this->crearEspecialidad('T-Servicio', tipo: Especialidad::CONSULTA, precio: 100);

        $this->actingAs($this->admin)->put(route('especialidades.actualizar', $servicio), $this->datos(['nombre' => 'T-Servicio', 'tipo' => 'ENFERMERIA', 'precio' => '120,50']))
            ->assertRedirect(route('especialidades.index'))
            ->assertSessionHas('exito', 'T-Servicio actualizada correctamente (Enfermería, Bs 120,50). Las citas ya registradas conservan su monto.');

        $servicio->refresh();
        $this->assertSame('ENFERMERIA', $servicio->tipo);
        $this->assertEquals(120.5, $servicio->precio);
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'ESPECIALIDAD_EDITADA', 'usuario_id' => $this->admin->id,
            'detalle' => 'Especialidad actualizada: T-Servicio (Consulta médica, Bs 100,00 → Enfermería, Bs 120,50)',
        ]);
    }

    #[Test]
    public function cambiar_el_precio_no_altera_el_monto_de_las_citas_ya_registradas(): void
    {
        $especialidad = $this->crearEspecialidad('T-Precio cambia', precio: 100);
        $medico = $this->crearMedico([], [$especialidad]);
        $cita = $this->crearCita($medico, $this->crearPaciente(), $especialidad, '2031-03-03', '08:00', '08:30');
        $cita->update(['monto' => 100]);

        $this->actingAs($this->admin)->put(route('especialidades.actualizar', $especialidad), $this->datos(['nombre' => 'T-Precio cambia', 'precio' => '250']))
            ->assertSessionHasNoErrors();

        $this->assertEquals(250, $especialidad->fresh()->precio);
        $this->assertEquals(100, $cita->fresh()->monto, 'La cita guarda el precio del momento en que se registró.');
    }

    #[Test]
    public function desactivar_conserva_el_registro_y_las_citas_historicas(): void
    {
        $especialidad = $this->crearEspecialidad('T-Historica');
        $medico = $this->crearMedico([], [$especialidad]);
        $cita = $this->crearCita($medico, $this->crearPaciente(), $especialidad, '2020-01-06', '08:00', '08:30', Cita::ATENDIDA);

        $this->actingAs($this->admin)->post(route('especialidades.desactivar', $especialidad))->assertSessionHas('exito');

        $this->assertFalse($especialidad->fresh()->estado);
        $this->assertDatabaseHas('especialidades', ['id' => $especialidad->id]);
        $this->assertDatabaseHas('citas', ['id' => $cita->id, 'especialidad_id' => $especialidad->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'ESPECIALIDAD_DESACTIVADA', 'usuario_id' => $this->admin->id]);

        $this->post(route('especialidades.activar', $especialidad))->assertSessionHas('exito');
        $this->assertTrue($especialidad->fresh()->estado);
    }

    #[Test]
    public function una_especialidad_o_servicio_desactivado_deja_de_ofrecerse_para_nuevas_citas(): void
    {
        $servicio = $this->crearEspecialidad('T-Servicio baja', tipo: Especialidad::LABORATORIO, precio: 80);
        $medico = $this->crearMedico([], [$servicio]);
        $this->crearHorario($medico, 1, '07:00', '11:00', 20);
        $paciente = $this->crearPaciente();
        $catalogo = app(CatalogoMedicosService::class);
        $this->assertTrue($catalogo->especialidadesConMedicos()->contains('id', $servicio->id));

        $this->actingAs($this->admin)->post(route('especialidades.desactivar', $servicio));

        $this->assertFalse($catalogo->especialidadesConMedicos()->contains('id', $servicio->id), 'No aparece en la búsqueda de CU-09 ni de CU-11.');
        $this->assertTrue($catalogo->medicosDe($servicio->id)->isEmpty());

        // Aunque alguien fuerce el identificador, el agendado la rechaza.
        try {
            app(AgendarCitaService::class)->crear($paciente, $medico, $servicio->fresh(), $this->proximaFecha(1), '07:00', Cita::PRESENCIAL, null, $this->admin->id);
            $this->fail('Se agendó una cita con una especialidad inactiva.');
        } catch (ReglaCitaException $e) {
            $this->assertSame('La especialidad está inactiva: no se ofrece para nuevas citas.', $e->getMessage());
        }
        $this->assertSame(0, Cita::where('medico_id', $medico->id)->count());

        // Y al volver a activarla se ofrece otra vez.
        $this->post(route('especialidades.activar', $servicio));
        $this->assertTrue($catalogo->especialidadesConMedicos()->contains('id', $servicio->id));
    }

    #[Test]
    public function el_listado_muestra_tipo_precio_y_estado_y_busca_por_nombre(): void
    {
        $this->crearEspecialidad('T-Visible', tipo: Especialidad::LABORATORIO, precio: 80);
        $this->crearEspecialidad('T-Oculta', activa: false);

        $this->actingAs($this->admin)->get(route('especialidades.index', ['q' => 't-visible']))
            ->assertOk()->assertSee('T-Visible')->assertSee('Laboratorio')->assertSee('Bs 80,00')->assertDontSee('T-Oculta');

        $this->get(route('especialidades.index', ['q' => 'T-OCULTA']))
            ->assertOk()->assertSee('T-Oculta')->assertSee('Inactiva');

        $this->get(route('especialidades.index'))
            ->assertOk()->assertSee('Medicina general')->assertSee('Bs 100,00')->assertSee('Consulta médica')->assertSee('Activa')
            ->assertSee('Especialidades y servicios');
    }

    #[Test]
    public function el_listado_filtra_por_tipo_y_ignora_un_tipo_invalido(): void
    {
        $this->crearEspecialidad('T-Lab filtro', tipo: Especialidad::LABORATORIO, precio: 80);
        $this->crearEspecialidad('T-Enf filtro', tipo: Especialidad::ENFERMERIA, precio: 40);

        $this->actingAs($this->admin)->get(route('especialidades.index', ['tipo' => 'LABORATORIO', 'q' => 'filtro']))
            ->assertOk()->assertSee('T-Lab filtro')->assertDontSee('T-Enf filtro');

        $this->get(route('especialidades.index', ['tipo' => 'ENFERMERIA', 'q' => 'filtro']))
            ->assertOk()->assertSee('T-Enf filtro')->assertDontSee('T-Lab filtro');

        // Un tipo inventado no filtra ni rompe la pantalla.
        $this->get(route('especialidades.index', ['tipo' => 'XYZ', 'q' => 'filtro']))
            ->assertOk()->assertSee('T-Lab filtro')->assertSee('T-Enf filtro');
    }

    #[Test]
    public function los_formularios_piden_nombre_tipo_precio_y_descripcion_y_muestran_los_valores_al_editar(): void
    {
        $this->actingAs($this->admin)->get(route('especialidades.crear'))
            ->assertOk()->assertSee('name="nombre"', false)->assertSee('name="tipo"', false)->assertSee('name="precio"', false)
            ->assertSee('name="descripcion"', false)->assertSee('Consulta médica')->assertSee('Laboratorio')->assertSee('Enfermería')
            ->assertSee('data-validar', false);

        $servicio = $this->crearEspecialidad('T-Editar form', tipo: Especialidad::ENFERMERIA, precio: 40.5);
        $this->get(route('especialidades.editar', $servicio))
            ->assertOk()->assertSee('value="T-Editar form"', false)->assertSee('value="40.50"', false)
            ->assertSee('<option value="ENFERMERIA" selected>', false);
    }

    #[Test]
    public function un_error_de_validacion_conserva_lo_escrito(): void
    {
        $this->actingAs($this->admin)->from(route('especialidades.crear'))
            ->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Conserva', 'tipo' => 'LABORATORIO', 'precio' => '-5']));

        $this->get(route('especialidades.crear'))
            ->assertSee('value="T-Conserva"', false)->assertSee('<option value="LABORATORIO" selected>', false)
            ->assertSee('El precio no puede ser negativo.');
    }

    #[Test]
    public function solo_el_administrador_gestiona_especialidades(): void
    {
        $this->get(route('especialidades.index'))->assertRedirect('/login');

        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-esp-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('especialidades.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('especialidades.guardar'), $this->datos(['nombre' => 'T-Intruso']))->assertForbidden();
        }
        $this->assertDatabaseMissing('especialidades', ['nombre_normalizado' => 't-intruso']);
    }

    /** Datos válidos de una especialidad (Consulta médica, Bs 150), con los cambios indicados. */
    private function datos(array $cambios = []): array
    {
        return $cambios + ['nombre' => 'T-Especialidad', 'tipo' => 'CONSULTA', 'precio' => '150', 'descripcion' => null];
    }
}
