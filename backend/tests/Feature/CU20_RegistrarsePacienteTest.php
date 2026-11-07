<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-20: Registrarse como paciente. Los métodos paso_N son las filas del plan de pruebas 8.1;
 * los demás son reglas internas (vinculación, validaciones, rol único, bitácora, enlaces de entrada).
 */
class CU20_RegistrarsePacienteTest extends SistemaTestCase
{
    private const CLAVE_NUEVA = 'ClaveSegura1';

    /** Datos válidos del formulario con CI y correo nuevos. */
    private function datos(array $cambios = []): array
    {
        return array_merge([
            'ci' => 'T-REG0001',
            'nombres' => 'Lucía Fernanda',
            'apellidos' => 'Tapia Rojas',
            'fecha_nacimiento' => '1992-04-18',
            'sexo' => 'F',
            'telefono' => '71234567',
            'correo' => 't-lucia.registro@prueba.test',
            'password' => self::CLAVE_NUEVA,
            'password_confirmation' => self::CLAVE_NUEVA,
        ], $cambios);
    }

    private function registrar(array $cambios = [])
    {
        return $this->post('/registro', $this->datos($cambios));
    }

    // ---------- Plan de pruebas 8.1 ----------

    /** Paso 1: registrarse como paciente con datos completos, CI y correo nuevos → se crean la cuenta, la ficha y su historia clínica, y se inicia la sesión. */
    #[Test]
    public function paso_1_registrarse_con_datos_completos(): void
    {
        $this->registrar()->assertRedirect(route('panel'));

        $usuario = Usuario::where('correo', 't-lucia.registro@prueba.test')->firstOrFail();
        $this->assertAuthenticatedAs($usuario);
        $this->assertTrue($usuario->estado);
        $this->assertNotNull($usuario->ultimo_acceso);
        $this->assertSame([Rol::PACIENTE], $usuario->nombresRoles()->all(), 'Quien se registra solo recibe el rol Paciente.');

        $paciente = Paciente::where('ci', 'T-REG0001')->firstOrFail();
        $this->assertSame($usuario->id, $paciente->usuario_id);
        $this->assertMatchesRegularExpression('/^PAC-\d{4,}$/', $paciente->codigo);
        $this->assertSame('Lucía Fernanda', $paciente->nombres);
        $this->assertSame('1992-04-18', $paciente->fecha_nacimiento->toDateString());
        $this->assertTrue($paciente->recibe_recordatorios);
        $this->assertTrue($paciente->mostrar_contacto_medico);
        $this->assertTrue($paciente->estado);
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $paciente->id)->count(), 'Se crea una historia clínica.');

        // Ya está dentro: el panel muestra su cuenta (el nombre de usuario sale de la parte local del correo).
        $this->assertSame('t-lucia.registro', $usuario->nombre_usuario);
        $this->get('/panel')->assertOk()->assertSee('Bienvenido, T-lucia.registro')->assertSee('Paciente');
    }

    /** Paso 2: registrarse con un CI que ya tiene cuenta → el sistema rechaza el registro e indica iniciar sesión. */
    #[Test]
    public function paso_2_registrarse_con_un_ci_que_ya_tiene_cuenta(): void
    {
        $cuenta = $this->crearUsuario('t-ya-tiene-cuenta', [Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $cuenta->id, 'ci' => 'T-CON-CTA']);
        $usuarios = Usuario::count();
        $pacientes = Paciente::count();

        $this->registrar(['ci' => 'T-CON-CTA'])
            ->assertSessionHasErrors(['ci' => 'Ese CI ya tiene una cuenta. Inicia sesión.']);

        $this->assertGuest();
        $this->assertSame($usuarios, Usuario::count());
        $this->assertSame($pacientes, Paciente::count());
    }

    /** Paso 3: registrarse con un CI registrado por recepción (sin cuenta) y su fecha de nacimiento → la cuenta se vincula a la ficha existente y conserva su historia clínica. */
    #[Test]
    public function paso_3_registrarse_con_un_ci_registrado_por_recepcion(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP01', 'nombres' => 'Marcos', 'apellidos' => 'De Recepción', 'fecha_nacimiento' => '1985-03-20', 'correo' => null]);
        $historia = HistoriaClinica::crearParaPaciente($ficha);
        $historia->update(['alergias' => 'Penicilina']);
        $cita = $this->crearCita(
            $this->crearMedico([], [$especialidad = $this->crearEspecialidad('T-Vinculo')]), $ficha, $especialidad,
            $this->proximaFecha(3), '09:00', '09:30'
        );
        $pacientes = Paciente::count();

        $this->registrar(['ci' => 'T-RECEP01', 'fecha_nacimiento' => '1985-03-20', 'nombres' => 'Otro', 'apellidos' => 'Nombre', 'correo' => 't-marcos.vinculo@prueba.test'])
            ->assertRedirect(route('panel'));

        $usuario = Usuario::where('correo', 't-marcos.vinculo@prueba.test')->firstOrFail();
        $this->assertAuthenticatedAs($usuario);
        $this->assertSame([Rol::PACIENTE], $usuario->nombresRoles()->all());

        $this->assertSame($pacientes, Paciente::count(), 'No se crea una ficha nueva.');
        $ficha->refresh();
        $this->assertSame($usuario->id, $ficha->usuario_id, 'La cuenta quedó vinculada a la ficha existente.');
        $this->assertSame('Marcos', $ficha->nombres, 'Se conservan los datos de la ficha de recepción.');
        $this->assertSame('t-marcos.vinculo@prueba.test', $ficha->correo, 'Si la ficha no tenía correo, recibe el de la cuenta.');
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $ficha->id)->count());
        $this->assertSame($historia->id, $ficha->historiaClinica->id, 'Conserva su historia clínica.');
        $this->assertSame('Penicilina', $ficha->historiaClinica->alergias);
        $this->assertTrue($cita->fresh()->paciente_id === $ficha->id, 'Conserva sus citas.');
        $this->assertDatabaseHas('bitacora', ['usuario_id' => $usuario->id, 'accion' => 'FICHA_VINCULADA']);
    }

    /** Paso 4: registrarse con una contraseña de 5 caracteres → el servidor rechaza el registro. */
    #[Test]
    public function paso_4_registrarse_con_una_contrasena_corta(): void
    {
        $usuarios = Usuario::count();

        $this->registrar(['password' => 'Ab123', 'password_confirmation' => 'Ab123'])
            ->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 8 caracteres.']);

        $this->assertGuest();
        $this->assertSame($usuarios, Usuario::count());
        $this->assertDatabaseMissing('pacientes', ['ci' => 'T-REG0001']);
    }

    // ---------- Reglas internas ----------

    #[Test]
    public function test_el_formulario_es_publico_y_trae_todos_los_campos(): void
    {
        $this->get('/registro')->assertOk()
            ->assertSee('Crear cuenta de paciente')
            ->assertSee('name="ci"', false)->assertSee('name="nombres"', false)->assertSee('name="apellidos"', false)
            ->assertSee('name="fecha_nacimiento"', false)->assertSee('name="sexo"', false)->assertSee('name="telefono"', false)
            ->assertSee('name="correo"', false)->assertSee('name="password"', false)->assertSee('name="password_confirmation"', false)
            ->assertSee('data-validar', false)->assertSee('minlength="8"', false)
            ->assertSee('Inicia sesión');
    }

    #[Test]
    public function test_la_portada_y_el_login_ofrecen_crear_cuenta(): void
    {
        $this->get('/')->assertOk()->assertSee('Crear cuenta')->assertSee(route('registro.mostrar'), false);
        $this->get('/login')->assertOk()->assertSee('Crear cuenta')->assertSee(route('registro.mostrar'), false);
    }

    #[Test]
    public function test_quien_ya_inicio_sesion_no_puede_registrarse_otra_vez(): void
    {
        $usuario = $this->crearUsuario('t-ya-dentro', [Rol::PACIENTE]);

        $this->actingAs($usuario)->get('/registro')->assertRedirect();
        $this->post('/registro', $this->datos())->assertRedirect();
        $this->assertDatabaseMissing('pacientes', ['ci' => 'T-REG0001']);
    }

    #[Test]
    public function test_un_correo_que_ya_tiene_cuenta_se_rechaza(): void
    {
        $this->crearUsuario('t-correo-repetido', [Rol::RECEPCIONISTA]);
        $usuarios = Usuario::count();

        $this->registrar(['correo' => 'T-Correo-Repetido@Prueba.test'])
            ->assertSessionHasErrors(['correo' => 'Ese correo ya tiene una cuenta. Inicia sesión.']);

        $this->assertSame($usuarios, Usuario::count());
        $this->assertDatabaseMissing('pacientes', ['ci' => 'T-REG0001']);
    }

    #[Test]
    public function test_la_contrasena_y_su_confirmacion_deben_coincidir(): void
    {
        $this->registrar(['password_confirmation' => 'OtraClave123'])
            ->assertSessionHasErrors(['password' => 'La confirmación no coincide con la contraseña.']);

        $this->assertGuest();
        $this->assertDatabaseMissing('usuarios', ['correo' => 't-lucia.registro@prueba.test']);
    }

    #[Test]
    public function test_la_contrasena_se_guarda_con_hash_bcrypt(): void
    {
        $this->registrar();

        $usuario = Usuario::where('correo', 't-lucia.registro@prueba.test')->firstOrFail();
        $this->assertNotSame(self::CLAVE_NUEVA, $usuario->password_hash);
        $this->assertStringStartsWith('$2y$', $usuario->password_hash);
        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $usuario->password_hash));
    }

    #[Test]
    public function test_faltan_datos_obligatorios_o_tienen_formato_invalido(): void
    {
        $usuarios = Usuario::count();

        $this->post('/registro', [])->assertSessionHasErrors(['ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono', 'correo', 'password']);
        $this->registrar(['ci' => '!!'])->assertSessionHasErrors('ci');
        $this->registrar(['correo' => 'no-es-correo'])->assertSessionHasErrors('correo');
        $this->registrar(['telefono' => 'abc'])->assertSessionHasErrors('telefono');
        $this->registrar(['sexo' => 'X'])->assertSessionHasErrors('sexo');
        $this->registrar(['fecha_nacimiento' => '2999-01-01'])->assertSessionHasErrors('fecha_nacimiento');
        $this->registrar(['fecha_nacimiento' => '18/04/1992'])->assertSessionHasErrors('fecha_nacimiento');
        $this->registrar(['nombres' => str_repeat('a', 81)])->assertSessionHasErrors('nombres');

        $this->assertGuest();
        $this->assertSame($usuarios, Usuario::count());
    }

    #[Test]
    public function test_el_sexo_es_opcional(): void
    {
        $this->registrar(['sexo' => ''])->assertRedirect(route('panel'));

        $this->assertNull(Paciente::where('ci', 'T-REG0001')->value('sexo'));
    }

    #[Test]
    public function test_si_la_fecha_de_nacimiento_no_coincide_con_la_ficha_no_se_vincula(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP02', 'fecha_nacimiento' => '1985-03-20']);
        $usuarios = Usuario::count();

        $this->registrar(['ci' => 'T-RECEP02', 'fecha_nacimiento' => '1985-03-21'])
            ->assertSessionHasErrors(['fecha_nacimiento' => 'Los datos no coinciden con una ficha registrada. Acude a recepción.']);

        $this->assertGuest();
        $this->assertSame($usuarios, Usuario::count());
        $this->assertNull($ficha->fresh()->usuario_id);
    }

    #[Test]
    public function test_una_ficha_inactiva_no_se_vincula(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP03', 'fecha_nacimiento' => '1985-03-20', 'estado' => false]);

        $this->registrar(['ci' => 'T-RECEP03', 'fecha_nacimiento' => '1985-03-20'])
            ->assertSessionHasErrors(['fecha_nacimiento' => 'Los datos no coinciden con una ficha registrada. Acude a recepción.']);

        $this->assertGuest();
        $this->assertNull($ficha->fresh()->usuario_id);
    }

    #[Test]
    public function test_el_ci_se_normaliza_antes_de_buscar_la_ficha(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP04', 'fecha_nacimiento' => '1985-03-20']);

        $this->registrar(['ci' => '  t-recep04 ', 'fecha_nacimiento' => '1985-03-20'])->assertRedirect(route('panel'));

        $this->assertNotNull($ficha->fresh()->usuario_id);
        $this->assertSame(1, Paciente::where('ci', 'T-RECEP04')->count());
    }

    #[Test]
    public function test_una_ficha_con_correo_propio_lo_conserva_al_vincularse(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP05', 'fecha_nacimiento' => '1985-03-20', 'correo' => 't-correo.ficha@prueba.test']);

        $this->registrar(['ci' => 'T-RECEP05', 'fecha_nacimiento' => '1985-03-20', 'correo' => 't-correo.cuenta@prueba.test']);

        $this->assertSame('t-correo.ficha@prueba.test', $ficha->fresh()->correo);
        $this->assertSame('t-correo.cuenta@prueba.test', $ficha->fresh()->usuario->correo);
    }

    #[Test]
    public function test_nadie_puede_darse_otro_rol_al_registrarse(): void
    {
        $this->registrar(['roles' => [Rol::ADMINISTRADOR, Rol::MEDICO], 'rol' => Rol::ADMINISTRADOR, 'estado' => false]);

        $usuario = Usuario::where('correo', 't-lucia.registro@prueba.test')->firstOrFail();
        $this->assertSame([Rol::PACIENTE], $usuario->nombresRoles()->all());
        $this->assertTrue($usuario->estado);
    }

    #[Test]
    public function test_el_nombre_de_usuario_sale_del_correo_y_no_se_repite(): void
    {
        $this->crearUsuario('t-homonimo', [Rol::PACIENTE]);

        $this->registrar(['correo' => 'T-Homonimo@otro.test', 'ci' => 'T-REG0002']);
        $this->assertTrue(Usuario::where('correo', 't-homonimo@otro.test')->where('nombre_usuario', 't-homonimo2')->exists());

        $this->post('/logout');
        $this->registrar(['correo' => 'T-Homonimo@tercero.test', 'ci' => 'T-REG0003']);
        $this->assertTrue(Usuario::where('correo', 't-homonimo@tercero.test')->where('nombre_usuario', 't-homonimo3')->exists());
    }

    #[Test]
    public function test_la_bitacora_registra_el_alta_sin_guardar_la_contrasena(): void
    {
        $this->registrar();

        $usuario = Usuario::where('correo', 't-lucia.registro@prueba.test')->firstOrFail();
        $registro = DB::table('bitacora')->where('usuario_id', $usuario->id)->where('accion', 'PACIENTE_REGISTRADO')->first();
        $this->assertNotNull($registro);
        $this->assertStringContainsString('T-REG0001', $registro->detalle);
        $this->assertStringNotContainsString(self::CLAVE_NUEVA, $registro->detalle);
        $this->assertDatabaseHas('bitacora', ['usuario_id' => $usuario->id, 'accion' => 'LOGIN']);
    }

    #[Test]
    public function test_todo_o_nada_si_algo_falla_no_queda_ninguna_cuenta_a_medias(): void
    {
        // El correo ya existe: se rechaza antes de crear nada; tampoco debe quedar ficha ni historia.
        $this->crearUsuario('t-atomico', [Rol::PACIENTE]);
        $historias = HistoriaClinica::count();

        $this->registrar(['correo' => 't-atomico@prueba.test'])->assertSessionHasErrors('correo');

        $this->assertDatabaseMissing('pacientes', ['ci' => 'T-REG0001']);
        $this->assertSame($historias, HistoriaClinica::count());
    }

    #[Test]
    public function test_la_ficha_vinculada_no_puede_tomarse_otra_vez(): void
    {
        $ficha = $this->crearPaciente(['ci' => 'T-RECEP06', 'fecha_nacimiento' => '1985-03-20']);

        $this->registrar(['ci' => 'T-RECEP06', 'fecha_nacimiento' => '1985-03-20', 'correo' => 't-primero@prueba.test']);
        $this->post('/logout');
        $this->registrar(['ci' => 'T-RECEP06', 'fecha_nacimiento' => '1985-03-20', 'correo' => 't-segundo@prueba.test'])
            ->assertSessionHasErrors(['ci' => 'Ese CI ya tiene una cuenta. Inicia sesión.']);

        $this->assertSame('t-primero@prueba.test', $ficha->fresh()->usuario->correo);
        $this->assertDatabaseMissing('usuarios', ['correo' => 't-segundo@prueba.test']);
    }

    #[Test]
    public function test_la_cuenta_nueva_puede_volver_a_ingresar_con_su_correo_y_contrasena(): void
    {
        $this->registrar();
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['identificador' => 't-lucia.registro@prueba.test', 'password' => self::CLAVE_NUEVA])->assertRedirect(route('panel'));
        $this->assertAuthenticated();
    }

    #[Test]
    public function test_el_paciente_recien_registrado_ve_su_panel_y_puede_reservar(): void
    {
        $this->registrar();

        $this->get('/panel')->assertOk()->assertSee('Reservar cita')->assertSee('Mis citas');
        $this->get(route('reservas.crear'))->assertOk();
        // No es personal: no entra a las funciones de recepción ni de administración.
        $this->get(route('pacientes.index'))->assertForbidden();
        $this->get(route('usuarios.index'))->assertForbidden();
    }

    #[Test]
    public function test_el_registro_no_toca_las_citas_ni_pacientes_de_otros(): void
    {
        $otro = $this->crearPaciente(['ci' => 'T-OTRO-PAC']);
        $especialidad = Especialidad::first() ?? $this->crearEspecialidad();
        $citas = Cita::count();

        $this->registrar();

        $this->assertSame($citas, Cita::count());
        $this->assertNull($otro->fresh()->usuario_id);
    }
}
