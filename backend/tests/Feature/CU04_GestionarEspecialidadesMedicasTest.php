<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Rol;
use App\Models\Usuario;

/**
 * CU-04: Gestionar especialidades médicas.
 *
 * El plan 8.1 usa «Cardiología» y «cardiologia », pero esa especialidad ya está en los datos de
 * demostración; los casos 1 y 2 se prueban con «T-Cardiología» para no chocar con ella.
 */
class CU04_GestionarEspecialidadesMedicasTest extends SistemaTestCase
{
    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin-esp', [Rol::ADMINISTRADOR]);
    }

    /** Caso 1: registrar una especialidad nueva → queda guardada y activa. */
    public function test_registrar_especialidad_nueva(): void
    {
        $this->actingAs($this->admin)
            ->post(route('especialidades.guardar'), ['nombre' => 'T-Cardiología', 'descripcion' => 'Corazón'])
            ->assertRedirect(route('especialidades.index'))
            ->assertSessionHas('exito');

        $especialidad = Especialidad::where('nombre_normalizado', 't-cardiologia')->firstOrFail();
        $this->assertSame('T-Cardiología', $especialidad->nombre);
        $this->assertTrue($especialidad->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'ESPECIALIDAD_CREADA', 'usuario_id' => $this->admin->id]);
    }

    /** Caso 2: registrar una especialidad con nombre repetido («cardiologia » en minúsculas y sin tilde) → rechaza el duplicado normalizado. */
    public function test_registrar_especialidad_con_nombre_repetido(): void
    {
        $this->crearEspecialidad('T-Cardiología');

        $this->actingAs($this->admin)->from(route('especialidades.crear'))
            ->post(route('especialidades.guardar'), ['nombre' => 't-cardiologia '])
            ->assertRedirect(route('especialidades.crear'))
            ->assertSessionHasErrors(['nombre' => 'Ya existe una especialidad con ese nombre (se comparan sin mayúsculas, tildes ni espacios sobrantes).']);

        $this->assertSame(1, Especialidad::where('nombre_normalizado', 't-cardiologia')->count());
    }

    public function test_rechaza_la_especialidad_de_demostracion_aunque_cambien_tildes_y_mayusculas(): void
    {
        $this->actingAs($this->admin)->post(route('especialidades.guardar'), ['nombre' => ' CARDIOLOGIA  '])
            ->assertSessionHasErrors('nombre');
    }

    public function test_el_nombre_es_obligatorio_y_la_descripcion_tiene_limite(): void
    {
        $this->actingAs($this->admin)->post(route('especialidades.guardar'), ['nombre' => '', 'descripcion' => str_repeat('a', 201)])
            ->assertSessionHasErrors(['nombre' => 'Ingrese el nombre de la especialidad.', 'descripcion' => 'La descripción no puede superar los 200 caracteres.']);
    }

    public function test_editar_conserva_su_propio_nombre_y_rechaza_el_de_otra(): void
    {
        $uno = $this->crearEspecialidad('T-Uno');
        $this->crearEspecialidad('T-Dos');

        // Mismo nombre (con otras mayúsculas) y nueva descripción: se permite.
        $this->actingAs($this->admin)->put(route('especialidades.actualizar', $uno), ['nombre' => 'T-UNO', 'descripcion' => 'Nueva'])
            ->assertRedirect(route('especialidades.index'));
        $this->assertSame('Nueva', $uno->fresh()->descripcion);
        $this->assertSame('t-uno', $uno->fresh()->nombre_normalizado);

        // El nombre de otra especialidad: se rechaza.
        $this->put(route('especialidades.actualizar', $uno), ['nombre' => 't-dos'])->assertSessionHasErrors('nombre');
        $this->assertSame('T-UNO', $uno->fresh()->nombre);
    }

    public function test_desactivar_conserva_el_registro_y_las_citas_historicas(): void
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

    public function test_el_listado_muestra_el_estado_y_busca_por_nombre(): void
    {
        $this->crearEspecialidad('T-Visible');
        $this->crearEspecialidad('T-Oculta', activa: false);

        $this->actingAs($this->admin)->get(route('especialidades.index', ['q' => 't-visible']))
            ->assertOk()->assertSee('T-Visible')->assertDontSee('T-Oculta');

        $this->get(route('especialidades.index', ['q' => 'T-OCULTA']))
            ->assertOk()->assertSee('T-Oculta')->assertSee('Inactiva');

        $this->get(route('especialidades.index'))->assertOk()->assertSee('Medicina general')->assertSee('Activa');
    }

    public function test_solo_el_administrador_gestiona_especialidades(): void
    {
        $this->get(route('especialidades.index'))->assertRedirect('/login');

        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-esp-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('especialidades.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('especialidades.guardar'), ['nombre' => 'T-Intruso'])->assertForbidden();
        }
        $this->assertDatabaseMissing('especialidades', ['nombre_normalizado' => 't-intruso']);
    }
}
