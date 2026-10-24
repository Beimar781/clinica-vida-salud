<?php

namespace Tests\Feature;

use App\Models\AntecedenteExterno;
use App\Models\Cita;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacienteService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** CU-07: Gestionar pacientes. */
class CU07_GestionarPacientesTest extends SistemaTestCase
{
    private Usuario $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-pac', [Rol::RECEPCIONISTA]);
    }

    /** Caso 1: registrar un paciente nuevo (datos completos y CI nuevo) → queda guardado con su código y se crea su historia clínica. */
    public function test_registrar_un_paciente_nuevo(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos())
            ->assertRedirect(route('pacientes.index'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'historia clínica quedó creada'));

        $paciente = Paciente::where('ci', 'T-1001')->firstOrFail();
        $this->assertMatchesRegularExpression('/^PAC-\d{4}$/', $paciente->codigo);
        $this->assertSame('Luisa', $paciente->nombres);
        $this->assertSame('1990-05-17', $paciente->fecha_nacimiento->toDateString());
        $this->assertSame('F', $paciente->sexo);
        $this->assertTrue($paciente->estado);
        $this->assertSame('Marta Quispe', $paciente->emergencia_nombre);
        $this->assertSame('Madre', $paciente->emergencia_parentesco);
        $this->assertNotNull($paciente->historiaClinica);
        $this->assertNotNull($paciente->historiaClinica->fecha_apertura);
        $this->assertDatabaseHas('bitacora', ['accion' => 'PACIENTE_CREADO', 'usuario_id' => $this->recepcion->id]);
    }

    /** Caso 2: registrar un paciente con CI duplicado (CI ya registrado) → rechaza el registro y muestra al paciente existente. */
    public function test_registrar_un_paciente_con_ci_duplicado(): void
    {
        $existente = $this->crearPaciente(['ci' => 'T-1001', 'nombres' => 'Ya', 'apellidos' => 'Registrado']);

        $this->actingAs($this->recepcion)->from(route('pacientes.crear'))
            ->post(route('pacientes.guardar'), $this->datos(['ci' => 't-1001 ']))
            ->assertRedirect(route('pacientes.crear'))
            ->assertSessionHasErrors(['ci' => 'El CI ya está registrado.']);

        $this->assertSame(1, Paciente::where('ci', 'T-1001')->count());
        // La pantalla muestra al paciente existente.
        $this->get(route('pacientes.crear'))->assertOk()
            ->assertSee('Ese CI ya pertenece al paciente')->assertSee($existente->codigo)->assertSee('Ya Registrado');
    }

    /** Caso 3: buscar un paciente (CI parcial, nombre o apellido) → se listan los pacientes que coinciden. */
    public function test_buscar_paciente(): void
    {
        $this->crearPaciente(['ci' => 'T-77881', 'nombres' => 'Zulema', 'apellidos' => 'Tbuscada']);
        $this->crearPaciente(['ci' => 'T-99002', 'nombres' => 'Otro', 'apellidos' => 'Tdistinto']);

        $this->actingAs($this->recepcion);
        $this->get(route('pacientes.index', ['q' => 'T-778']))->assertOk()->assertSee('Zulema Tbuscada')->assertDontSee('Tdistinto');
        $this->get(route('pacientes.index', ['q' => 'zulema']))->assertOk()->assertSee('Zulema Tbuscada')->assertDontSee('Tdistinto');
        $this->get(route('pacientes.index', ['q' => 'TDISTINTO']))->assertOk()->assertSee('Otro Tdistinto')->assertDontSee('Zulema');
        $this->get(route('pacientes.index', ['q' => 'inexistente-xyz']))->assertOk()->assertSee('No se encontraron pacientes');
    }

    /** Caso 4: registrar un paciente con antecedentes de otro hospital → los antecedentes quedan guardados como antecedentes externos de su historia clínica. */
    public function test_registrar_paciente_con_antecedentes_de_otro_hospital(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos([
            'con_antecedente' => '1', 'ant_hospital_origen' => 'Hospital de Clínicas', 'ant_fecha_atencion' => '2025-03-10',
            'ant_especialidad' => 'Cardiología', 'ant_diagnostico' => 'Hipertensión arterial', 'ant_tratamiento' => 'Losartán 50 mg',
        ]))->assertRedirect(route('pacientes.index'));

        $historia = Paciente::where('ci', 'T-1001')->firstOrFail()->historiaClinica;
        $antecedente = $historia->antecedentesExternos()->firstOrFail();
        $this->assertSame('Hospital de Clínicas', $antecedente->hospital_origen);
        $this->assertSame('2025-03-10', $antecedente->fecha_atencion->toDateString());
        $this->assertSame('Cardiología', $antecedente->especialidad);
        $this->assertSame('Hipertensión arterial', $antecedente->diagnostico);
        $this->assertSame('Losartán 50 mg', $antecedente->tratamiento);
        $this->assertSame($this->recepcion->id, $antecedente->registrado_por);
    }

    /** Caso 5: comprobar que cada paciente tiene una sola historia clínica (registrar un paciente y revisar sus datos) → existe exactamente una historia clínica asociada. */
    public function test_cada_paciente_tiene_una_sola_historia_clinica(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos());
        $paciente = Paciente::where('ci', 'T-1001')->firstOrFail();

        $this->assertSame(1, HistoriaClinica::where('paciente_id', $paciente->id)->count());

        // Editar no crea otra, y la base de datos impide una segunda (paciente_id UNIQUE).
        $this->put(route('pacientes.actualizar', $paciente), $this->datos(['nombres' => 'Luisa María']))->assertRedirect(route('pacientes.index'));
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $paciente->id)->count());
        $this->expectException(QueryException::class);
        DB::transaction(fn () => HistoriaClinica::create(['paciente_id' => $paciente->id]));
    }

    public function test_si_falla_el_guardado_no_queda_el_paciente_sin_historia_clinica(): void
    {
        // Un diagnóstico más largo que la columna (300) falla al guardar el antecedente, ya dentro de la transacción.
        $datos = $this->datos(['con_antecedente' => true, 'ant_hospital_origen' => 'Hospital X', 'ant_diagnostico' => str_repeat('x', 400)]);

        try {
            app(PacienteService::class)->guardar($datos, $this->recepcion);
            $this->fail('Debió fallar al guardar el antecedente.');
        } catch (QueryException) {
            $this->assertSame(0, Paciente::where('ci', 'T-1001')->count(), 'Todo se revierte: no queda un paciente a medias.');
        }
    }

    public function test_dos_registros_con_el_mismo_ci_a_la_vez_dan_el_aviso_de_ci_repetido(): void
    {
        $service = app(PacienteService::class);
        $service->guardar($this->datos(), $this->recepcion);

        // Se salta la validación del Request: la restricción UNIQUE de la base es la última barrera.
        try {
            $service->guardar($this->datos(), $this->recepcion);
            $this->fail('Debió rechazar el CI repetido.');
        } catch (ValidationException $e) {
            $this->assertSame(['El CI ya está registrado.'], $e->errors()['ci']);
        }
        $this->assertSame(1, Paciente::where('ci', 'T-1001')->count());
    }

    public function test_cada_paciente_recibe_un_codigo_distinto_de_la_secuencia(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos());
        $this->post(route('pacientes.guardar'), $this->datos(['ci' => 'T-1002']));

        $codigos = Paciente::whereIn('ci', ['T-1001', 'T-1002'])->orderBy('id')->pluck('codigo')->all();
        $this->assertCount(2, array_unique($codigos));
        $this->assertGreaterThan((int) substr($codigos[0], 4), (int) substr($codigos[1], 4));
    }

    public function test_los_datos_obligatorios_y_su_formato_se_validan(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), [
            'ci' => '', 'nombres' => '', 'apellidos' => '', 'fecha_nacimiento' => '', 'telefono' => '',
        ])->assertSessionHasErrors([
            'ci' => 'Ingrese el CI.', 'nombres' => 'Ingrese los nombres.', 'apellidos' => 'Ingrese los apellidos.',
            'fecha_nacimiento' => 'Ingrese la fecha de nacimiento.', 'telefono' => 'Ingrese el teléfono.',
        ]);

        $this->post(route('pacientes.guardar'), $this->datos([
            'ci' => 'ab', 'telefono' => 'abc', 'correo' => 'no-es-correo', 'sexo' => 'X',
            'fecha_nacimiento' => now()->addDay()->toDateString(), 'emergencia_telefono' => '12',
        ]))->assertSessionHasErrors(['ci', 'telefono', 'correo', 'sexo', 'fecha_nacimiento', 'emergencia_telefono']);

        $this->assertSame(0, Paciente::where('nombres', 'Luisa')->count());
    }

    public function test_con_antecedente_marcado_exige_hospital_y_diagnostico(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos(['con_antecedente' => '1']))
            ->assertSessionHasErrors([
                'ant_hospital_origen' => 'Ingrese el hospital o clínica de origen.',
                'ant_diagnostico' => 'Ingrese el diagnóstico que consta en sus documentos.',
            ]);

        $this->assertSame(0, Paciente::where('ci', 'T-1001')->count());
    }

    public function test_sin_marcar_antecedente_no_se_guarda_ninguno(): void
    {
        $antes = AntecedenteExterno::count();

        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos([
            'con_antecedente' => '0', 'ant_hospital_origen' => 'Ignorado', 'ant_diagnostico' => 'Ignorado',
        ]))->assertRedirect(route('pacientes.index'));

        $this->assertSame($antes, AntecedenteExterno::count());
    }

    public function test_guarda_las_preferencias_de_recordatorios_y_privacidad(): void
    {
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), $this->datos(['recibe_recordatorios' => '0', 'mostrar_contacto_medico' => '0']));
        $this->post(route('pacientes.guardar'), $this->datos(['ci' => 'T-1002', 'recibe_recordatorios' => '1', 'mostrar_contacto_medico' => '1']));

        $sin = Paciente::where('ci', 'T-1001')->firstOrFail();
        $con = Paciente::where('ci', 'T-1002')->firstOrFail();
        $this->assertFalse($sin->recibe_recordatorios);
        $this->assertFalse($sin->mostrar_contacto_medico);
        $this->assertTrue($con->recibe_recordatorios);
        $this->assertTrue($con->mostrar_contacto_medico);
    }

    public function test_editar_actualiza_los_datos_y_permite_conservar_el_ci(): void
    {
        $paciente = $this->crearPaciente(['ci' => 'T-2001']);

        $this->actingAs($this->recepcion)->put(route('pacientes.actualizar', $paciente), $this->datos([
            'ci' => 't-2001', 'nombres' => 'Nombre Nuevo', 'telefono' => '77778888', 'emergencia_nombre' => 'Contacto Nuevo',
        ]))->assertRedirect(route('pacientes.index'))->assertSessionHas('exito');

        $paciente->refresh();
        $this->assertSame('Nombre Nuevo', $paciente->nombres);
        $this->assertSame('77778888', $paciente->telefono);
        $this->assertSame('Contacto Nuevo', $paciente->emergencia_nombre);
        $this->assertSame('T-2001', $paciente->ci);
        $this->assertDatabaseHas('bitacora', ['accion' => 'PACIENTE_EDITADO', 'usuario_id' => $this->recepcion->id]);
    }

    public function test_editar_con_el_ci_de_otro_paciente_se_rechaza_y_muestra_al_otro(): void
    {
        $otro = $this->crearPaciente(['ci' => 'T-3001', 'nombres' => 'Dueño', 'apellidos' => 'Delci']);
        $paciente = $this->crearPaciente(['ci' => 'T-3002']);

        $this->actingAs($this->recepcion)->from(route('pacientes.editar', $paciente))
            ->put(route('pacientes.actualizar', $paciente), $this->datos(['ci' => 'T-3001']))
            ->assertSessionHasErrors(['ci' => 'El CI ya está registrado.']);

        $this->assertSame('T-3002', $paciente->fresh()->ci);
        $this->get(route('pacientes.editar', $paciente))->assertOk()->assertSee($otro->codigo)->assertSee('Dueño Delci');
    }

    public function test_al_editar_se_puede_agregar_un_antecedente_y_se_listan_los_existentes(): void
    {
        $paciente = $this->crearPaciente(['ci' => 'T-4001']);
        $historia = HistoriaClinica::create(['paciente_id' => $paciente->id]);

        $this->actingAs($this->recepcion)->put(route('pacientes.actualizar', $paciente), $this->datos([
            'ci' => 'T-4001', 'con_antecedente' => '1', 'ant_hospital_origen' => 'Clínica del Sur', 'ant_diagnostico' => 'Asma leve',
        ]))->assertRedirect(route('pacientes.index'));

        $this->assertSame(1, $historia->antecedentesExternos()->count());
        $this->get(route('pacientes.editar', $paciente))->assertOk()->assertSee('Clínica del Sur')->assertSee('Asma leve');
    }

    public function test_desactivar_conserva_el_historial_de_citas_y_la_historia_clinica(): void
    {
        $paciente = $this->crearPaciente(['ci' => 'T-5001']);
        $historia = HistoriaClinica::create(['paciente_id' => $paciente->id]);
        $especialidad = $this->crearEspecialidad('T-Conserva');
        $medico = $this->crearMedico([], [$especialidad]);
        $cita = $this->crearCita($medico, $paciente, $especialidad, '2020-02-03', '08:00', '08:30', Cita::ATENDIDA);

        $this->actingAs($this->recepcion)->post(route('pacientes.desactivar', $paciente))->assertSessionHas('exito');

        $this->assertFalse($paciente->fresh()->estado);
        $this->assertDatabaseHas('pacientes', ['id' => $paciente->id]);
        $this->assertDatabaseHas('historias_clinicas', ['id' => $historia->id]);
        $this->assertDatabaseHas('citas', ['id' => $cita->id, 'paciente_id' => $paciente->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'PACIENTE_DESACTIVADO', 'usuario_id' => $this->recepcion->id]);

        $this->get(route('pacientes.index', ['q' => 'T-5001']))->assertOk()->assertSee('Inactivo');
        $this->post(route('pacientes.activar', $paciente))->assertSessionHas('exito');
        $this->assertTrue($paciente->fresh()->estado);
    }

    public function test_el_listado_muestra_codigo_estado_y_cantidad_de_citas(): void
    {
        $paciente = $this->crearPaciente(['ci' => 'T-6001']);
        $especialidad = $this->crearEspecialidad('T-Listado');
        $medico = $this->crearMedico([], [$especialidad]);
        $this->crearCita($medico, $paciente, $especialidad, '2020-02-03', '08:00', '08:30', Cita::ATENDIDA);

        $this->actingAs($this->recepcion)->get(route('pacientes.index', ['q' => 'T-6001']))
            ->assertOk()->assertSee($paciente->codigo)->assertSee('Activo');

        $this->assertSame(1, Paciente::withCount('citas')->find($paciente->id)->citas_count);
    }

    public function test_acceden_la_recepcionista_y_el_administrador_pero_no_el_medico_ni_el_paciente(): void
    {
        $this->get(route('pacientes.index'))->assertRedirect('/login');

        $admin = $this->crearUsuario('t-admin-pac', [Rol::ADMINISTRADOR]);
        $this->actingAs($admin)->get(route('pacientes.index'))->assertOk();
        $this->actingAs($this->recepcion)->get(route('pacientes.index'))->assertOk();

        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-pac-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('pacientes.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('pacientes.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Paciente::where('ci', 'T-1001')->count());
    }

    public function test_la_busqueda_ignora_tildes_y_mayusculas_y_trata_los_simbolos_como_texto(): void
    {
        $this->crearPaciente(['ci' => 'T-8001', 'nombres' => 'José', 'apellidos' => 'Tpérez Ñúñez']);

        $this->actingAs($this->recepcion);
        $this->get(route('pacientes.index', ['q' => 'jose']))->assertOk()->assertSee('Tpérez Ñúñez');
        $this->get(route('pacientes.index', ['q' => 'TPEREZ nunez']))->assertOk()->assertSee('José Tpérez');
        $this->get(route('pacientes.index', ['q' => 'jos%']))->assertOk()->assertDontSee('Tpérez');
        $this->get(route('pacientes.index', ['q' => '_']))->assertOk()->assertDontSee('Tpérez');
    }

    /** Datos válidos de un paciente nuevo; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'ci' => 'T-1001', 'nombres' => 'Luisa', 'apellidos' => 'T-Mamani Rojas', 'fecha_nacimiento' => '1990-05-17',
            'sexo' => 'F', 'telefono' => '71234567', 'correo' => 't-luisa@prueba.test', 'direccion' => 'Calle Prueba 123',
            'emergencia_nombre' => 'Marta Quispe', 'emergencia_parentesco' => 'Madre', 'emergencia_telefono' => '72223333',
            'recibe_recordatorios' => '1', 'mostrar_contacto_medico' => '1',
        ];
    }
}
