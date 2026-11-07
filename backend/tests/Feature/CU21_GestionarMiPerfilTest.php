<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Consulta;
use App\Models\Especialidad;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-21: Gestionar mi perfil. Los métodos paso_N son las filas del plan de pruebas 8.1;
 * los demás son reglas internas (validaciones, CI, permisos, bitácora, privacidad).
 */
class CU21_GestionarMiPerfilTest extends SistemaTestCase
{
    private Usuario $usuario;

    private Paciente $paciente;

    private HistoriaClinica $historia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->crearUsuario('t-perfil', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente([
            'usuario_id' => $this->usuario->id, 'ci' => 'T-PERFIL1', 'nombres' => 'Perla', 'apellidos' => 'Tperfil',
            'telefono' => '70011223', 'direccion' => 'Calle Vieja 100', 'correo' => 't-perfil.contacto@prueba.test',
        ]);
        $this->historia = HistoriaClinica::crearParaPaciente($this->paciente);
    }

    /** Datos del formulario con los valores actuales de la ficha y los cambios indicados. */
    private function datos(array $cambios = []): array
    {
        $p = $this->paciente->fresh();
        $h = $this->historia->fresh();

        return array_merge([
            'nombres' => $p->nombres, 'apellidos' => $p->apellidos, 'fecha_nacimiento' => $p->fecha_nacimiento->toDateString(),
            'sexo' => $p->sexo, 'telefono' => $p->telefono, 'correo' => $p->correo, 'direccion' => $p->direccion,
            'tipo_sangre' => $h->tipo_sangre, 'alergias' => $h->alergias, 'enfermedades_cronicas' => $h->enfermedades_cronicas,
            'medicamentos' => $h->medicamentos, 'antecedentes_personales' => $h->antecedentes_personales,
            'emergencia_nombre' => $p->emergencia_nombre, 'emergencia_parentesco' => $p->emergencia_parentesco, 'emergencia_telefono' => $p->emergencia_telefono,
            'recibe_recordatorios' => $p->recibe_recordatorios ? '1' : '0', 'mostrar_contacto_medico' => $p->mostrar_contacto_medico ? '1' : '0',
        ], $cambios);
    }

    private function guardar(array $cambios = [])
    {
        return $this->actingAs($this->usuario)->put('/perfil', $this->datos($cambios));
    }

    // ---------- Plan de pruebas 8.1 ----------

    /** Paso 1: editar el perfil (cambio de teléfono, dirección y contacto de emergencia) → los datos se guardan y el perfil se muestra actualizado. */
    #[Test]
    public function paso_1_editar_telefono_direccion_y_contacto_de_emergencia(): void
    {
        $this->guardar([
            'telefono' => '78889999', 'direccion' => 'Av. Nueva 456',
            'emergencia_nombre' => 'Rosa Tperfil', 'emergencia_parentesco' => 'Madre', 'emergencia_telefono' => '76665544',
        ])->assertRedirect(route('perfil.mostrar'))->assertSessionHas('exito');

        $p = $this->paciente->fresh();
        $this->assertSame('78889999', $p->telefono);
        $this->assertSame('Av. Nueva 456', $p->direccion);
        $this->assertSame('Rosa Tperfil', $p->emergencia_nombre);
        $this->assertSame('Madre', $p->emergencia_parentesco);
        $this->assertSame('76665544', $p->emergencia_telefono);

        $this->get('/perfil')->assertOk()
            ->assertSee('Perfil actualizado correctamente')
            ->assertSee('value="78889999"', false)->assertSee('Av. Nueva 456')->assertSee('Rosa Tperfil')->assertSee('Madre');

        $registro = DB::table('bitacora')->where('usuario_id', $this->usuario->id)->where('accion', 'PERFIL_ACTUALIZADO')->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('contacto', $registro->detalle);
        $this->assertStringContainsString('contacto de emergencia', $registro->detalle);
    }

    /** Paso 2: actualizar la información médica (tipo de sangre, alergias, crónicas y medicamentos) → se guardan en la historia clínica y las consultas no cambian. */
    #[Test]
    public function paso_2_actualizar_la_informacion_medica(): void
    {
        $especialidad = $this->crearEspecialidad('T-Perfil-Esp');
        $medico = $this->crearMedico([], [$especialidad]);
        $cita = $this->crearCita($medico, $this->paciente, $especialidad, '2026-03-02', '09:00', '09:30', Cita::ATENDIDA);
        $consulta = Consulta::create([
            'cita_id' => $cita->id, 'historia_clinica_id' => $this->historia->id, 'medico_id' => $medico->id,
            'diagnostico' => 'Faringitis', 'tratamiento' => 'Reposo', 'observaciones' => 'Control en una semana',
        ]);
        $antes = Consulta::where('historia_clinica_id', $this->historia->id)->orderBy('id')->get()->toArray();

        $this->guardar([
            'tipo_sangre' => 'O+', 'alergias' => 'Penicilina', 'enfermedades_cronicas' => 'Hipertensión', 'medicamentos' => 'Losartán 50 mg',
            'antecedentes_personales' => 'Apendicectomía en 2015',
        ])->assertRedirect(route('perfil.mostrar'));

        $h = $this->historia->fresh();
        $this->assertSame('O+', $h->tipo_sangre);
        $this->assertSame('Penicilina', $h->alergias);
        $this->assertSame('Hipertensión', $h->enfermedades_cronicas);
        $this->assertSame('Losartán 50 mg', $h->medicamentos);
        $this->assertSame('Apendicectomía en 2015', $h->antecedentes_personales);
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $this->paciente->id)->count(), 'Sigue siendo la misma historia.');

        $this->assertSame($antes, Consulta::where('historia_clinica_id', $this->historia->id)->orderBy('id')->get()->toArray(), 'Las consultas no cambian.');
        $this->assertSame('Faringitis', $consulta->fresh()->diagnostico);

        $this->get('/perfil')->assertOk()->assertSee('Penicilina')->assertSee('Losartán 50 mg')->assertSee('información médica');
        $this->assertStringContainsString('información médica', DB::table('bitacora')->where('accion', 'PERFIL_ACTUALIZADO')->latest('id')->value('detalle'));
    }

    /** Paso 3: cambiar la contraseña con la actual incorrecta → el sistema rechaza el cambio. */
    #[Test]
    public function paso_3_cambiar_la_contrasena_con_la_actual_incorrecta(): void
    {
        $hash = $this->usuario->fresh()->password_hash;

        $this->actingAs($this->usuario)->put('/perfil/contrasena', [
            'password_actual' => 'NoEsMiClave1', 'password' => 'ClaveNueva123', 'password_confirmation' => 'ClaveNueva123',
        ])->assertSessionHasErrors(['password_actual' => 'La contraseña actual es incorrecta.']);

        $this->assertSame($hash, $this->usuario->fresh()->password_hash, 'La contraseña no cambia.');
        $this->assertTrue(Hash::check(self::CLAVE, $this->usuario->fresh()->password_hash));
    }

    /** Paso 4: intentar abrir el perfil de otro paciente (paciente autenticado con el identificador de otro) → el sistema solo muestra su propio perfil. */
    #[Test]
    public function paso_4_intentar_abrir_el_perfil_de_otro_paciente(): void
    {
        $otro = $this->crearPaciente(['nombres' => 'Otro', 'apellidos' => 'Pacientecito', 'telefono' => '79998888', 'direccion' => 'Dirección ajena 999']);
        HistoriaClinica::crearParaPaciente($otro)->update(['alergias' => 'Alergia ajena']);

        // Con el identificador del otro paciente en la URL o en la ruta, siempre se ve el propio.
        $this->actingAs($this->usuario)->get('/perfil?paciente='.$otro->id.'&id='.$otro->id)->assertOk()
            ->assertSee('Tperfil')->assertSee('Calle Vieja 100')
            ->assertDontSee('Pacientecito')->assertDontSee('Dirección ajena 999')->assertDontSee('Alergia ajena');
        $this->get('/perfil/'.$otro->id)->assertNotFound();

        // Y al guardar con el identificador del otro, solo se modifica la propia ficha.
        $this->guardar(['paciente_id' => $otro->id, 'id' => $otro->id, 'usuario_id' => $otro->id, 'telefono' => '70000099'])->assertRedirect(route('perfil.mostrar'));
        $this->assertSame('70000099', $this->paciente->fresh()->telefono);
        $this->assertSame('79998888', $otro->fresh()->telefono);
        $this->assertSame($this->usuario->id, $this->paciente->fresh()->usuario_id);
        $this->assertNull($otro->fresh()->usuario_id);
    }

    // ---------- Reglas internas ----------

    #[Test]
    public function test_el_perfil_muestra_todas_las_secciones_y_los_datos_actuales(): void
    {
        $this->actingAs($this->usuario)->get('/perfil')->assertOk()
            ->assertSee('Datos personales')->assertSee('Contacto')->assertSee('Información médica')->assertSee('Contacto de emergencia')
            ->assertSee('Cuenta: notificaciones y privacidad')->assertSee('Cambiar contraseña')
            ->assertSee('T-PERFIL1')->assertSee('Perla')->assertSee('70011223')->assertSee($this->paciente->codigo)
            ->assertSee('t-perfil@prueba.test')
            ->assertSee('data-validar', false);
    }

    #[Test]
    public function test_el_ci_se_muestra_pero_no_se_puede_modificar(): void
    {
        $this->actingAs($this->usuario)->get('/perfil')->assertOk()
            ->assertSee('id="ci-solo-lectura"', false)->assertSee('readonly', false)->assertSee('solicítalo en recepción', false);
        $this->assertStringNotContainsString('name="ci"', $this->get('/perfil')->getContent(), 'El CI no se envía como campo editable.');

        $this->guardar(['ci' => 'T-OTROCI99', 'telefono' => '70055555'])
            ->assertSessionHasErrors(['ci' => 'El CI no se puede modificar. Solicítalo en recepción.']);

        $this->assertSame('T-PERFIL1', $this->paciente->fresh()->ci);
        $this->assertSame('70011223', $this->paciente->fresh()->telefono, 'Si hay un error no se guarda nada.');

        // Enviar el mismo CI (aunque con otra escritura) no es un cambio.
        $this->guardar(['ci' => ' t-perfil1 '])->assertSessionHasNoErrors();
    }

    #[Test]
    public function test_faltan_datos_obligatorios_o_tienen_formato_invalido(): void
    {
        $this->guardar(['nombres' => '', 'apellidos' => '', 'telefono' => '', 'fecha_nacimiento' => ''])
            ->assertSessionHasErrors(['nombres', 'apellidos', 'telefono', 'fecha_nacimiento']);
        $this->guardar(['telefono' => 'abc'])->assertSessionHasErrors('telefono');
        $this->guardar(['correo' => 'no-es-correo'])->assertSessionHasErrors('correo');
        $this->guardar(['sexo' => 'X'])->assertSessionHasErrors('sexo');
        $this->guardar(['fecha_nacimiento' => '2999-01-01'])->assertSessionHasErrors('fecha_nacimiento');
        $this->guardar(['emergencia_telefono' => 'xx'])->assertSessionHasErrors('emergencia_telefono');
        $this->guardar(['tipo_sangre' => 'Z+'])->assertSessionHasErrors('tipo_sangre');

        $this->assertSame('70011223', $this->paciente->fresh()->telefono);
    }

    #[Test]
    public function test_los_limites_de_la_informacion_medica_se_respetan(): void
    {
        $this->guardar(['alergias' => str_repeat('a', 201)])->assertSessionHasErrors('alergias');
        $this->guardar(['enfermedades_cronicas' => str_repeat('a', 301)])->assertSessionHasErrors('enfermedades_cronicas');
        $this->guardar(['medicamentos' => str_repeat('a', 301)])->assertSessionHasErrors('medicamentos');
        $this->guardar(['antecedentes_personales' => str_repeat('a', 401)])->assertSessionHasErrors('antecedentes_personales');
        $this->guardar(['direccion' => str_repeat('a', 201)])->assertSessionHasErrors('direccion');

        $this->guardar([
            'alergias' => str_repeat('a', 200), 'enfermedades_cronicas' => str_repeat('b', 300),
            'medicamentos' => str_repeat('c', 300), 'antecedentes_personales' => str_repeat('d', 400),
        ])->assertSessionHasNoErrors();
    }

    #[Test]
    public function test_los_nombres_y_apellidos_se_limpian_y_los_campos_vacios_quedan_en_null(): void
    {
        $this->guardar(['nombres' => '  Perla   María ', 'alergias' => '', 'direccion' => '', 'emergencia_nombre' => ''])->assertSessionHasNoErrors();

        $this->assertSame('Perla María', $this->paciente->fresh()->nombres);
        $this->assertNull($this->paciente->fresh()->direccion);
        $this->assertNull($this->historia->fresh()->alergias);
    }

    #[Test]
    public function test_las_preferencias_de_notificacion_y_privacidad_se_aplican_de_inmediato(): void
    {
        $this->assertTrue($this->paciente->fresh()->recibe_recordatorios);

        $this->guardar(['recibe_recordatorios' => '0', 'mostrar_contacto_medico' => '0'])->assertRedirect(route('perfil.mostrar'));

        $p = $this->paciente->fresh();
        $this->assertFalse($p->recibe_recordatorios);
        $this->assertFalse($p->mostrar_contacto_medico);
        $this->assertStringContainsString('preferencias de la cuenta', DB::table('bitacora')->where('accion', 'PERFIL_ACTUALIZADO')->latest('id')->value('detalle'));

        $this->guardar(['recibe_recordatorios' => '1', 'mostrar_contacto_medico' => '1']);
        $this->assertTrue($this->paciente->fresh()->recibe_recordatorios);
        $this->assertTrue($this->paciente->fresh()->mostrar_contacto_medico);
    }

    #[Test]
    public function test_la_privacidad_oculta_el_contacto_al_medico_en_la_historia_clinica(): void
    {
        // La preferencia que el paciente cambia aquí es la que usa el resto del sistema (campo mostrar_contacto_medico).
        $this->guardar(['mostrar_contacto_medico' => '0']);

        $this->assertFalse((bool) $this->paciente->fresh()->mostrar_contacto_medico);
    }

    #[Test]
    public function test_guardar_sin_cambios_no_ensucia_la_bitacora(): void
    {
        $antes = DB::table('bitacora')->where('accion', 'PERFIL_ACTUALIZADO')->count();

        $this->guardar()->assertRedirect(route('perfil.mostrar'))->assertSessionHas('exito', 'No hiciste cambios: tu perfil sigue igual.');

        $this->assertSame($antes, DB::table('bitacora')->where('accion', 'PERFIL_ACTUALIZADO')->count());
    }

    #[Test]
    public function test_la_bitacora_dice_que_secciones_cambiaron_sin_datos_clinicos(): void
    {
        $this->guardar(['telefono' => '70099911', 'alergias' => 'Secreto médico confidencial', 'nombres' => 'Perlita']);

        $detalle = DB::table('bitacora')->where('usuario_id', $this->usuario->id)->where('accion', 'PERFIL_ACTUALIZADO')->value('detalle');
        $this->assertStringContainsString($this->paciente->codigo, $detalle);
        foreach (['datos personales', 'contacto', 'información médica'] as $seccion) {
            $this->assertStringContainsString($seccion, $detalle);
        }
        $this->assertStringNotContainsString('Secreto médico confidencial', $detalle);
        $this->assertStringNotContainsString('70099911', $detalle);
    }

    #[Test]
    public function test_la_contrasena_nueva_se_guarda_con_hash_y_sirve_para_ingresar(): void
    {
        $this->actingAs($this->usuario)->put('/perfil/contrasena', [
            'password_actual' => self::CLAVE, 'password' => 'ClaveNueva123', 'password_confirmation' => 'ClaveNueva123',
        ])->assertRedirect(route('perfil.mostrar'))->assertSessionHas('exito', 'Contraseña cambiada correctamente.');

        $hash = $this->usuario->fresh()->password_hash;
        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(Hash::check('ClaveNueva123', $hash));
        $this->assertFalse(Hash::check(self::CLAVE, $hash));

        $detalle = DB::table('bitacora')->where('usuario_id', $this->usuario->id)->where('accion', 'PERFIL_ACTUALIZADO')->latest('id')->value('detalle');
        $this->assertSame('Cambió su contraseña', $detalle);

        $this->post('/logout');
        $this->post('/login', ['identificador' => 't-perfil', 'password' => 'ClaveNueva123'])->assertRedirect(route('panel'));
    }

    #[Test]
    public function test_la_contrasena_nueva_corta_distinta_o_sin_confirmar_se_rechaza(): void
    {
        $envio = fn (array $c) => $this->actingAs($this->usuario)->put('/perfil/contrasena', array_merge(
            ['password_actual' => self::CLAVE, 'password' => 'ClaveNueva123', 'password_confirmation' => 'ClaveNueva123'], $c
        ));

        $envio(['password' => 'Ab123', 'password_confirmation' => 'Ab123'])
            ->assertSessionHasErrors(['password' => 'La contraseña nueva debe tener al menos 8 caracteres.']);
        $envio(['password_confirmation' => 'OtraDistinta1'])
            ->assertSessionHasErrors(['password' => 'La confirmación no coincide con la contraseña nueva.']);
        $envio(['password' => self::CLAVE, 'password_confirmation' => self::CLAVE])
            ->assertSessionHasErrors(['password' => 'La contraseña nueva debe ser distinta de la actual.']);
        $envio(['password_actual' => ''])->assertSessionHasErrors('password_actual');

        $this->assertTrue(Hash::check(self::CLAVE, $this->usuario->fresh()->password_hash));
    }

    #[Test]
    public function test_un_intento_con_la_clave_actual_incorrecta_queda_en_la_bitacora_sin_claves(): void
    {
        $this->actingAs($this->usuario)->put('/perfil/contrasena', [
            'password_actual' => 'IntentoMalo99', 'password' => 'ClaveNueva123', 'password_confirmation' => 'ClaveNueva123',
        ]);

        $detalle = DB::table('bitacora')->where('usuario_id', $this->usuario->id)->where('accion', 'PERFIL_CLAVE_RECHAZADA')->value('detalle');
        $this->assertNotNull($detalle);
        $this->assertStringNotContainsString('IntentoMalo99', $detalle);
        $this->assertStringNotContainsString('ClaveNueva123', $detalle);
    }

    #[Test]
    public function test_cambiar_el_perfil_no_cambia_la_contrasena_ni_el_vinculo_con_la_cuenta(): void
    {
        $hash = $this->usuario->fresh()->password_hash;

        $this->guardar(['telefono' => '70077766', 'password' => 'Intruso12345', 'password_hash' => 'x', 'codigo' => 'PAC-9999', 'estado' => '0']);

        $this->assertSame($hash, $this->usuario->fresh()->password_hash);
        $p = $this->paciente->fresh();
        $this->assertSame($this->usuario->id, $p->usuario_id);
        $this->assertNotSame('PAC-9999', $p->codigo);
        $this->assertTrue($p->estado);
    }

    #[Test]
    public function test_si_el_paciente_no_tenia_historia_se_crea_al_guardar_su_informacion_medica(): void
    {
        $sinHistoria = $this->crearUsuario('t-sin-historia', [Rol::PACIENTE]);
        $ficha = $this->crearPaciente(['usuario_id' => $sinHistoria->id]);
        $this->assertNull($ficha->historiaClinica);

        $this->actingAs($sinHistoria)->get('/perfil')->assertOk();
        $this->assertNull($ficha->fresh()->historiaClinica, 'Consultar el perfil no crea nada.');

        $this->put('/perfil', array_merge($this->datos(), [
            'nombres' => $ficha->nombres, 'apellidos' => $ficha->apellidos, 'telefono' => $ficha->telefono, 'correo' => null, 'direccion' => null,
            'fecha_nacimiento' => '1990-01-01', 'tipo_sangre' => 'A-', 'alergias' => 'Polen',
        ]))->assertRedirect(route('perfil.mostrar'));

        $this->assertSame('A-', $ficha->fresh()->historiaClinica->tipo_sangre);
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $ficha->id)->count());
    }

    #[Test]
    public function test_una_cuenta_paciente_sin_ficha_recibe_el_aviso_y_no_un_error(): void
    {
        $sinFicha = $this->crearUsuario('t-perfil-sin-ficha', [Rol::PACIENTE]);

        $this->actingAs($sinFicha)->get('/perfil')->assertOk()
            ->assertSee(Paciente::MENSAJE_SIN_FICHA)->assertDontSee('Guardar cambios');
        $this->put('/perfil', ['nombres' => 'X'])->assertRedirect(route('perfil.mostrar'));
        $this->assertDatabaseMissing('pacientes', ['usuario_id' => $sinFicha->id]);
    }

    #[Test]
    public function test_solo_el_rol_paciente_entra_y_sin_sesion_va_al_login(): void
    {
        $this->get('/perfil')->assertRedirect(route('login'));
        $this->put('/perfil', $this->datos())->assertRedirect(route('login'));

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::MEDICO] as $rol) {
            $personal = $this->crearUsuario('t-personal-'.mb_strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($personal)->get('/perfil')->assertForbidden();
            $this->put('/perfil', $this->datos())->assertForbidden();
            $this->put('/perfil/contrasena', ['password_actual' => 'x', 'password' => 'ClaveNueva123', 'password_confirmation' => 'ClaveNueva123'])->assertForbidden();
        }
        $this->assertSame('70011223', $this->paciente->fresh()->telefono);
    }

    #[Test]
    public function test_el_panel_ofrece_mi_perfil_al_paciente_y_solo_a_el(): void
    {
        $this->actingAs($this->usuario)->get('/panel')->assertOk()->assertSee('Mi perfil')->assertSee('CU-21');

        $recepcion = $this->crearUsuario('t-recep-perfil', [Rol::RECEPCIONISTA]);
        $this->actingAs($recepcion)->get('/panel')->assertOk()->assertDontSee('Mi perfil');
    }

    #[Test]
    public function test_quien_acaba_de_registrarse_recibe_la_invitacion_a_completar_su_perfil(): void
    {
        $this->post('/logout');
        $this->post('/registro', [
            'ci' => 'T-INVITA01', 'nombres' => 'Nueva', 'apellidos' => 'Paciente', 'fecha_nacimiento' => '1995-05-05', 'telefono' => '71112222',
            'correo' => 't-invitada@prueba.test', 'password' => 'ClaveSegura1', 'password_confirmation' => 'ClaveSegura1',
        ])->assertRedirect(route('panel'));

        $this->get('/panel')->assertOk()->assertSee('Completa tu perfil')->assertSee(route('perfil.mostrar'), false);
        // La invitación se muestra una sola vez.
        $this->get('/panel')->assertOk()->assertDontSee('Completa tu perfil');
    }

    #[Test]
    public function test_no_toca_las_citas_ni_el_historial_de_otros_ni_las_propias(): void
    {
        $especialidad = Especialidad::first() ?? $this->crearEspecialidad();
        $medico = Medico::first() ?? $this->crearMedico([], [$especialidad]);
        $cita = $this->crearCita($medico, $this->paciente, $especialidad, $this->proximaFecha(2), '10:00', '10:30');
        $foto = fn () => $cita->fresh()->only(['estado', 'estado_pago', 'monto', 'hora_inicio', 'hora_fin']) + ['fecha' => $cita->fresh()->fecha->toDateString()];
        $antes = $foto();

        $this->guardar(['telefono' => '70011224']);

        $this->assertSame($antes, $foto());
    }
}
