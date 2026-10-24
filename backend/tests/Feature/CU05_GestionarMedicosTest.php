<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

/** CU-05: Gestionar médicos. */
class CU05_GestionarMedicosTest extends SistemaTestCase
{
    private Usuario $admin;

    private Especialidad $general;

    private Especialidad $pediatria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin-med', [Rol::ADMINISTRADOR]);
        $this->general = $this->crearEspecialidad('T-General');
        $this->pediatria = $this->crearEspecialidad('T-Pediatría');
    }

    /** Caso 1: registrar un médico con sus especialidades (datos completos y 2 especialidades) → queda guardado con su código y especialidades. */
    public function test_registrar_medico_con_sus_especialidades(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos([
            'especialidades' => [$this->general->id, $this->pediatria->id],
        ]))->assertRedirect(route('medicos.index'))->assertSessionHas('exito');

        $medico = Medico::where('nro_registro', 'T-RM-100')->firstOrFail();
        $this->assertMatchesRegularExpression('/^MED-\d{4}$/', $medico->codigo);
        $this->assertEqualsCanonicalizing([$this->general->id, $this->pediatria->id], $medico->especialidades()->pluck('especialidades.id')->all());
        $this->assertTrue($medico->estado);
        $this->assertNull($medico->usuario_id);
        $this->assertDatabaseHas('bitacora', ['accion' => 'MEDICO_CREADO', 'usuario_id' => $this->admin->id]);
    }

    /** Caso 2: registrar un médico con registro profesional repetido (número de registro existente) → rechaza el registro. */
    public function test_registrar_medico_con_registro_profesional_repetido(): void
    {
        $this->crearMedico(['nro_registro' => 'T-RM-100'], [$this->general]);

        $this->actingAs($this->admin)->from(route('medicos.crear'))
            ->post(route('medicos.guardar'), $this->datos(['nro_registro' => 't-rm-100 ']))
            ->assertRedirect(route('medicos.crear'))
            ->assertSessionHasErrors(['nro_registro' => 'El número de registro profesional ya está registrado.']);

        $this->assertSame(1, Medico::where('nro_registro', 'T-RM-100')->count());
    }

    /** Caso 3: desactivar un médico activo con citas históricas → queda inactivo, no aparece para nuevas citas y su historial se conserva. */
    public function test_desactivar_un_medico_con_citas_historicas(): void
    {
        $medico = $this->crearMedico([], [$this->general]);
        $cita = $this->crearCita($medico, $this->crearPaciente(), $this->general, '2020-01-06', '08:00', '08:30', Cita::ATENDIDA);

        $this->actingAs($this->admin)->post(route('medicos.desactivar', $medico))->assertSessionHas('exito');

        $this->assertFalse($medico->fresh()->estado);
        $this->assertFalse(Medico::where('estado', true)->whereKey($medico->id)->exists(), 'No debe aparecer entre los médicos que reciben citas.');
        $this->assertDatabaseHas('medicos', ['id' => $medico->id]);
        $this->assertDatabaseHas('citas', ['id' => $cita->id, 'medico_id' => $medico->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'MEDICO_DESACTIVADO', 'usuario_id' => $this->admin->id]);

        $this->post(route('medicos.activar', $medico))->assertSessionHas('exito');
        $this->assertTrue($medico->fresh()->estado);
    }

    /** Caso 4: asignar ubicación y permiso (médico con consultorio y permiso de marcar citas como atendidas) → los datos quedan guardados. */
    public function test_asignar_ubicacion_y_permiso_a_un_medico(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos([
            'ubicacion' => 'T-Sede Norte · Consultorio 7', 'puede_marcar_atendida' => '1',
        ]))->assertRedirect(route('medicos.index'));
        $this->post(route('medicos.guardar'), $this->datos([
            'nro_registro' => 'T-RM-101', 'ubicacion' => 'T-Sede Sur', 'puede_marcar_atendida' => '0',
        ]))->assertRedirect(route('medicos.index'));

        $con = Medico::where('nro_registro', 'T-RM-100')->firstOrFail();
        $sin = Medico::where('nro_registro', 'T-RM-101')->firstOrFail();
        $this->assertSame('T-Sede Norte · Consultorio 7', $con->ubicacion);
        $this->assertTrue($con->puede_marcar_atendida);
        $this->assertFalse($sin->puede_marcar_atendida);
        // La ubicación sirve para la búsqueda del paciente (CU-11).
        $this->assertSame([$con->id], Medico::where('ubicacion', 'like', 'T-Sede Norte%')->pluck('id')->all());
    }

    public function test_cada_medico_recibe_un_codigo_distinto_de_la_secuencia(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos());
        $this->post(route('medicos.guardar'), $this->datos(['nro_registro' => 'T-RM-101']));

        $codigos = Medico::whereIn('nro_registro', ['T-RM-100', 'T-RM-101'])->orderBy('id')->pluck('codigo')->all();
        $this->assertCount(2, array_unique($codigos));
        $this->assertGreaterThan((int) substr($codigos[0], 4), (int) substr($codigos[1], 4));
    }

    public function test_los_datos_obligatorios_y_las_especialidades_se_validan(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.guardar'), [
            'nombres' => '', 'apellidos' => '', 'nro_registro' => '', 'telefono' => 'abc', 'especialidades' => [],
        ])->assertSessionHasErrors([
            'nombres' => 'Ingrese los nombres.', 'apellidos' => 'Ingrese los apellidos.',
            'nro_registro' => 'Ingrese el número de registro profesional.',
            'telefono' => 'El teléfono solo puede tener números (de 7 a 20 dígitos).',
            'especialidades' => 'Seleccione al menos una especialidad.',
        ]);
    }

    public function test_crea_la_cuenta_de_usuario_con_rol_medico_si_se_indica(): void
    {
        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos([
            'crear_cuenta' => '1', 'nombre_usuario' => 't-dr-cuenta', 'password' => 'Clave12345',
        ]))->assertRedirect(route('medicos.index'));

        $medico = Medico::where('nro_registro', 'T-RM-100')->firstOrFail();
        $cuenta = Usuario::findOrFail($medico->usuario_id);
        $this->assertSame('t-dr-cuenta', $cuenta->nombre_usuario);
        $this->assertSame('t-medico@prueba.test', $cuenta->correo);
        $this->assertSame([Rol::MEDICO], $cuenta->nombresRoles()->all());
        $this->assertTrue(Hash::check('Clave12345', $cuenta->password_hash));
        $this->assertTrue($cuenta->estado);
    }

    public function test_la_cuenta_exige_correo_usuario_y_contrasena_y_no_repite_los_existentes(): void
    {
        $this->crearUsuario('t-ocupado', [Rol::PACIENTE]);

        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos([
            'crear_cuenta' => '1', 'correo' => '', 'nombre_usuario' => '', 'password' => '',
        ]))->assertSessionHasErrors(['correo', 'nombre_usuario', 'password']);

        $this->post(route('medicos.guardar'), $this->datos(['crear_cuenta' => '1', 'nombre_usuario' => 'T-OCUPADO', 'password' => 'Clave12345']))
            ->assertSessionHasErrors(['nombre_usuario' => 'El nombre de usuario ya está registrado.']);

        $this->post(route('medicos.guardar'), $this->datos(['crear_cuenta' => '1', 'correo' => 't-ocupado@prueba.test', 'nombre_usuario' => 't-nuevo-dr', 'password' => 'Clave12345']))
            ->assertSessionHasErrors(['correo' => 'El correo ya está registrado como cuenta de usuario.']);

        $this->assertSame(0, Medico::where('nro_registro', 'T-RM-100')->count());
        $this->assertSame(0, Usuario::where('nombre_usuario', 't-nuevo-dr')->count());
    }

    public function test_sin_cuenta_no_se_crea_ningun_usuario(): void
    {
        $antes = Usuario::count();

        $this->actingAs($this->admin)->post(route('medicos.guardar'), $this->datos(['crear_cuenta' => '0']))
            ->assertRedirect(route('medicos.index'));

        $this->assertSame($antes, Usuario::count());
    }

    public function test_editar_actualiza_datos_y_especialidades_y_permite_conservar_el_registro(): void
    {
        $medico = $this->crearMedico(['nro_registro' => 'T-RM-200'], [$this->general]);

        $this->actingAs($this->admin)->put(route('medicos.actualizar', $medico), $this->datos([
            'nro_registro' => 'T-RM-200', 'nombres' => 'Nombre Nuevo', 'especialidades' => [$this->pediatria->id],
        ]))->assertRedirect(route('medicos.index'));

        $medico->refresh();
        $this->assertSame('Nombre Nuevo', $medico->nombres);
        $this->assertSame([$this->pediatria->id], $medico->especialidades()->pluck('especialidades.id')->all());

        $otro = $this->crearMedico(['nro_registro' => 'T-RM-201'], [$this->general]);
        $this->put(route('medicos.actualizar', $otro), $this->datos(['nro_registro' => 'T-RM-200']))
            ->assertSessionHasErrors('nro_registro');
    }

    public function test_al_editar_un_medico_sin_cuenta_se_le_puede_crear_una(): void
    {
        $medico = $this->crearMedico(['nro_registro' => 'T-RM-300', 'correo' => 't-sin-cuenta@prueba.test'], [$this->general]);

        $this->actingAs($this->admin)->put(route('medicos.actualizar', $medico), $this->datos([
            'nro_registro' => 'T-RM-300', 'correo' => 't-sin-cuenta@prueba.test',
            'crear_cuenta' => '1', 'nombre_usuario' => 't-dr-tardio', 'password' => 'Clave12345',
        ]))->assertRedirect(route('medicos.index'));

        $this->assertNotNull($medico->fresh()->usuario_id);
        $this->assertSame('t-dr-tardio', $medico->fresh()->usuario->nombre_usuario);
    }

    public function test_no_se_puede_registrar_un_medico_si_no_hay_especialidades_activas(): void
    {
        Especialidad::query()->update(['estado' => false]); // se revierte al terminar la prueba

        $this->actingAs($this->admin)->get(route('medicos.crear'))
            ->assertRedirect(route('medicos.index'))
            ->assertSessionHas('error', 'Primero registre al menos una especialidad activa (sección Especialidades).');
    }

    public function test_el_formulario_de_alta_ofrece_solo_las_especialidades_activas(): void
    {
        $this->crearEspecialidad('T-Inactiva', activa: false);

        $this->actingAs($this->admin)->get(route('medicos.crear'))
            ->assertOk()->assertSee('T-General')->assertDontSee('T-Inactiva');
    }

    public function test_el_listado_busca_por_nombre_o_por_especialidad(): void
    {
        $this->crearMedico(['nombres' => 'Zacarías', 'apellidos' => 'Tbusca', 'nro_registro' => 'T-RM-400'], [$this->general]);
        $this->crearMedico(['nombres' => 'Otro', 'apellidos' => 'Tdistinto', 'nro_registro' => 'T-RM-401'], [$this->pediatria]);

        $this->actingAs($this->admin)->get(route('medicos.index', ['q' => 'tbusca']))
            ->assertOk()->assertSee('Tbusca')->assertDontSee('Tdistinto');

        $this->get(route('medicos.index', ['q' => 't-pediatria']))
            ->assertOk()->assertSee('Tdistinto')->assertDontSee('Tbusca');

        $this->get(route('medicos.index', ['q' => 'T-RM-400']))->assertOk()->assertSee('Tbusca');
    }

    public function test_la_busqueda_de_medicos_ignora_tildes_y_mayusculas(): void
    {
        $this->crearMedico(['nombres' => 'Inés', 'apellidos' => 'Tgarcía', 'nro_registro' => 'T-RM-500'], [$this->general]);

        $this->actingAs($this->admin)->get(route('medicos.index', ['q' => 'TGARCIA']))->assertOk()->assertSee('Inés Tgarcía');
        $this->get(route('medicos.index', ['q' => 'ines tgarcia']))->assertOk()->assertSee('Inés Tgarcía');
    }

    public function test_solo_el_administrador_gestiona_medicos(): void
    {
        $this->get(route('medicos.index'))->assertRedirect('/login');

        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-med-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('medicos.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('medicos.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Medico::where('nro_registro', 'T-RM-100')->count());
    }

    /** Datos válidos de un médico nuevo; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombres' => 'Ana', 'apellidos' => 'T-Prueba Vaca', 'nro_registro' => 'T-RM-100',
            'telefono' => '71234567', 'correo' => 't-medico@prueba.test', 'ubicacion' => 'T-Sede Central',
            'especialidades' => [$this->general->id], 'puede_marcar_atendida' => '1',
        ];
    }
}
