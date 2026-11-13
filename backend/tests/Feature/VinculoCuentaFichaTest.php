<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * CU-03 y CU-07 (extra aprobado): toda cuenta con el rol Paciente tiene su ficha, y una cuenta y una ficha existentes se pueden vincular
 * (al crear la cuenta con el CI de la ficha, automáticamente por correo al registrar la ficha, o a mano desde la ficha del paciente).
 */
class VinculoCuentaFichaTest extends SistemaTestCase
{
    private Usuario $admin;

    private Usuario $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-vin-admin', [Rol::ADMINISTRADOR]);
        $this->recepcion = $this->crearUsuario('t-vin-recep', [Rol::RECEPCIONISTA]);
    }

    /** Datos del formulario de usuarios con rol Paciente y los datos mínimos de la ficha. */
    private function datosUsuario(array $cambios = []): array
    {
        return $cambios + [
            'nombre_usuario' => 't-vin-nuevo', 'correo' => 't-vin-nuevo@prueba.test', 'password' => 'Clave1234',
            'roles' => [$this->idRol(Rol::PACIENTE)],
            'ci' => 'T-VIN0001', 'nombres' => 'Vinculada', 'apellidos' => 'Tficha', 'fecha_nacimiento' => '1992-03-04', 'telefono' => '71234567',
        ];
    }

    /** Ficha de recepción sin cuenta, con historia clínica. */
    private function fichaSinCuenta(string $ci = 'T-VIN-REC1', array $datos = []): Paciente
    {
        $ficha = $this->crearPaciente($datos + ['ci' => $ci, 'nombres' => 'Ficha', 'apellidos' => 'DeRecepcion', 'correo' => null]);
        HistoriaClinica::crearParaPaciente($ficha);

        return $ficha;
    }

    private function cuentaSinFicha(string $nombre, array $roles = [Rol::PACIENTE]): Usuario
    {
        return $this->crearUsuario($nombre, $roles);
    }

    // ---------- Caso 1: CU-03 ----------

    #[Test]
    public function el_administrador_crea_una_cuenta_paciente_y_se_crea_su_ficha_con_historia(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datosUsuario())
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'Se creó la ficha de paciente PAC-'));

        $usuario = Usuario::where('nombre_usuario', 't-vin-nuevo')->firstOrFail();
        $ficha = $usuario->paciente;
        $this->assertNotNull($ficha, 'La cuenta con rol Paciente tiene su ficha.');
        $this->assertFalse($usuario->esPacienteSinFicha());
        $this->assertMatchesRegularExpression('/^PAC-\d{4,}$/', $ficha->codigo);
        $this->assertSame(['T-VIN0001', 'Vinculada', 'Tficha', '71234567', 't-vin-nuevo@prueba.test'], [$ficha->ci, $ficha->nombres, $ficha->apellidos, $ficha->telefono, $ficha->correo]);
        $this->assertTrue($ficha->recibe_recordatorios && $ficha->estado);
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $ficha->id)->count());
        $registro = DB::table('bitacora')->where('accion', 'PACIENTE_REGISTRADO')->latest('id')->first();
        $this->assertSame($this->admin->id, $registro->usuario_id);
        $this->assertStringContainsString('t-vin-admin', $registro->detalle);
        $this->assertStringContainsString($ficha->codigo, $registro->detalle);
    }

    #[Test]
    public function el_administrador_crea_una_cuenta_con_el_ci_de_una_ficha_sin_cuenta_y_se_vincula(): void
    {
        $ficha = $this->fichaSinCuenta();
        $historia = $ficha->historiaClinica;
        $cita = $this->crearCita($this->crearMedico([], [$esp = $this->crearEspecialidad('T-Vin')]), $ficha, $esp, $this->proximaFecha(3), '09:00', '09:30');
        $fichas = Paciente::count();

        // Solo se pide el CI: el resto (fecha, nombres, teléfono) ya está en la ficha.
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), [
            'nombre_usuario' => 't-vin-nuevo', 'correo' => 't-vin-nuevo@prueba.test', 'password' => 'Clave1234',
            'roles' => [$this->idRol(Rol::PACIENTE)], 'ci' => ' t-vin-rec1 ',
        ])->assertRedirect(route('usuarios.index'))->assertSessionHas('exito', fn ($m) => str_contains($m, "Se vinculó con la ficha {$ficha->codigo}"));

        $usuario = Usuario::where('nombre_usuario', 't-vin-nuevo')->firstOrFail();
        $this->assertSame($fichas, Paciente::count(), 'No se crea una ficha nueva.');
        $this->assertSame($usuario->id, $ficha->fresh()->usuario_id);
        $this->assertSame('Ficha', $ficha->fresh()->nombres, 'Conserva los datos de la ficha.');
        $this->assertSame($historia->id, $ficha->fresh()->historiaClinica->id, 'Conserva su historia.');
        $this->assertSame($ficha->id, $cita->fresh()->paciente_id, 'Conserva sus citas.');
        $this->assertSame('t-vin-nuevo@prueba.test', $ficha->fresh()->correo, 'Sin correo propio, recibe el de la cuenta.');
        $registro = DB::table('bitacora')->where('accion', 'FICHA_VINCULADA')->latest('id')->first();
        $this->assertSame($this->admin->id, $registro->usuario_id);
        $this->assertStringContainsString('t-vin-admin', $registro->detalle);
    }

    #[Test]
    public function si_el_ci_ya_tiene_otra_cuenta_se_rechaza_y_no_queda_la_cuenta_nueva(): void
    {
        $otra = $this->cuentaSinFicha('t-vin-otra');
        $ficha = $this->fichaSinCuenta('T-VIN-OCUP', ['usuario_id' => $otra->id]);
        $usuarios = Usuario::count();

        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datosUsuario(['ci' => 'T-VIN-OCUP']))
            ->assertSessionHasErrors(['ci' => "Ese CI ya pertenece a la ficha {$ficha->codigo}, que ya tiene otra cuenta de usuario."]);

        $this->assertSame($usuarios, Usuario::count(), 'La transacción deshace la cuenta.');
        $this->assertSame($otra->id, $ficha->fresh()->usuario_id);
    }

    #[Test]
    public function con_el_rol_paciente_se_piden_los_datos_de_la_ficha_y_sin_el_rol_no(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), [
            'nombre_usuario' => 't-vin-sin', 'correo' => 't-vin-sin@prueba.test', 'password' => 'Clave1234', 'roles' => [$this->idRol(Rol::PACIENTE)],
        ])->assertSessionHasErrors(['ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'telefono']);
        $this->assertDatabaseMissing('usuarios', ['nombre_usuario' => 't-vin-sin']);

        $this->post(route('usuarios.guardar'), $this->datosUsuario(['ci' => '!!', 'telefono' => 'abc', 'fecha_nacimiento' => '2999-01-01']))
            ->assertSessionHasErrors(['ci', 'telefono', 'fecha_nacimiento']);

        // Sin el rol Paciente no se pide nada de la ficha.
        $this->post(route('usuarios.guardar'), [
            'nombre_usuario' => 't-vin-recep2', 'correo' => 't-vin-recep2@prueba.test', 'password' => 'Clave1234', 'roles' => [$this->idRol(Rol::RECEPCIONISTA)],
        ])->assertRedirect(route('usuarios.index'));
        $this->assertNull(Usuario::where('nombre_usuario', 't-vin-recep2')->first()->paciente);
    }

    #[Test]
    public function al_agregar_el_rol_paciente_a_una_cuenta_sin_ficha_se_crea_o_vincula_y_quitar_el_rol_no_borra_la_ficha(): void
    {
        $cuenta = $this->crearUsuario('t-vin-edit', [Rol::RECEPCIONISTA]);

        // Agregar el rol Paciente sin datos de ficha: se piden.
        $this->actingAs($this->admin)->put(route('usuarios.actualizar', $cuenta), [
            'nombre_usuario' => 't-vin-edit', 'correo' => 't-vin-edit@prueba.test', 'roles' => [$this->idRol(Rol::RECEPCIONISTA), $this->idRol(Rol::PACIENTE)],
        ])->assertSessionHasErrors(['ci']);
        $this->assertNull($cuenta->fresh()->paciente);

        // Con los datos: se crea la ficha.
        $this->put(route('usuarios.actualizar', $cuenta), [
            'nombre_usuario' => 't-vin-edit', 'correo' => 't-vin-edit@prueba.test', 'roles' => [$this->idRol(Rol::RECEPCIONISTA), $this->idRol(Rol::PACIENTE)],
            'ci' => 'T-VIN-EDIT', 'nombres' => 'Editada', 'apellidos' => 'Tcuenta', 'fecha_nacimiento' => '1985-01-01', 'telefono' => '72222222',
        ])->assertRedirect(route('usuarios.index'));
        $ficha = $cuenta->fresh()->paciente;
        $this->assertNotNull($ficha);

        // Quitar el rol Paciente NO borra la ficha.
        $this->put(route('usuarios.actualizar', $cuenta), [
            'nombre_usuario' => 't-vin-edit', 'correo' => 't-vin-edit@prueba.test', 'roles' => [$this->idRol(Rol::RECEPCIONISTA)],
        ])->assertRedirect(route('usuarios.index'));
        $this->assertDatabaseHas('pacientes', ['id' => $ficha->id, 'usuario_id' => $cuenta->id]);
        $this->assertSame(1, HistoriaClinica::where('paciente_id', $ficha->id)->count());

        // Volver a darle el rol, con la ficha ya existente, no pide datos ni crea otra ficha.
        $fichas = Paciente::count();
        $this->put(route('usuarios.actualizar', $cuenta), [
            'nombre_usuario' => 't-vin-edit', 'correo' => 't-vin-edit@prueba.test', 'roles' => [$this->idRol(Rol::RECEPCIONISTA), $this->idRol(Rol::PACIENTE)],
        ])->assertRedirect(route('usuarios.index'));
        $this->assertSame($fichas, Paciente::count());
    }

    #[Test]
    public function una_cuenta_paciente_antigua_sin_ficha_se_repara_al_editarla(): void
    {
        $antigua = $this->cuentaSinFicha('t-vin-antigua'); // creada antes de esta regla
        $this->assertTrue($antigua->esPacienteSinFicha());

        $this->actingAs($this->admin)->get(route('usuarios.editar', $antigua))->assertOk()->assertSee('data-ficha-paciente', false)->assertSee('name="ci"', false);
        $this->put(route('usuarios.actualizar', $antigua), [
            'nombre_usuario' => 't-vin-antigua', 'correo' => 't-vin-antigua@prueba.test', 'roles' => [$this->idRol(Rol::PACIENTE)],
            'ci' => 'T-VIN-ANT', 'nombres' => 'Antigua', 'apellidos' => 'Tcuenta', 'fecha_nacimiento' => '1980-02-02', 'telefono' => '73333333',
        ])->assertRedirect(route('usuarios.index'));

        $this->assertFalse($antigua->fresh()->esPacienteSinFicha());
    }

    #[Test]
    public function el_formulario_de_usuarios_trae_la_ficha_solo_visible_con_el_rol_paciente(): void
    {
        $this->actingAs($this->admin)->get(route('usuarios.crear'))->assertOk()
            ->assertSee('id="ficha-paciente"', false)->assertSee('data-rol-paciente', false)->assertSee('name="fecha_nacimiento"', false)->assertSee('name="telefono"', false)
            ->assertSee('hidden', false);

        $ficha = $this->crearPaciente(['usuario_id' => $this->cuentaSinFicha('t-vin-con')->id]);
        $this->get(route('usuarios.editar', $ficha->usuario_id))->assertOk()->assertSee("La cuenta ya tiene su ficha <strong>{$ficha->codigo}</strong>", false);
    }

    // ---------- Caso 2: CU-07 ----------

    #[Test]
    public function al_registrar_una_ficha_con_el_correo_de_una_cuenta_paciente_sin_ficha_se_vincula_sola(): void
    {
        $cuenta = $this->cuentaSinFicha('t-vin-correo');

        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), [
            'ci' => 'T-VIN-COR1', 'nombres' => 'Por', 'apellidos' => 'Correo', 'fecha_nacimiento' => '1991-01-01', 'telefono' => '74444444',
            'correo' => 'T-VIN-CORREO@Prueba.test',
        ])->assertRedirect(route('pacientes.index'))->assertSessionHas('exito', fn ($m) => str_contains($m, 'Se vinculó con la cuenta t-vin-correo.'));

        $ficha = Paciente::where('ci', 'T-VIN-COR1')->firstOrFail();
        $this->assertSame($cuenta->id, $ficha->usuario_id);
        $this->assertFalse($cuenta->fresh()->esPacienteSinFicha());
        $registro = DB::table('bitacora')->where('accion', 'FICHA_VINCULADA')->latest('id')->first();
        $this->assertSame($this->recepcion->id, $registro->usuario_id);
        $this->assertStringContainsString('t-vin-correo', $registro->detalle);
    }

    #[Test]
    public function no_se_vincula_sola_si_el_correo_no_coincide_la_cuenta_ya_tiene_ficha_o_no_es_paciente(): void
    {
        $this->cuentaSinFicha('t-vin-distinto');
        $conFicha = $this->cuentaSinFicha('t-vin-yaficha');
        $this->crearPaciente(['usuario_id' => $conFicha->id]);
        $this->crearUsuario('t-vin-medico', [Rol::MEDICO]);
        $this->actingAs($this->recepcion);

        foreach (['otro@prueba.test' => 'T-VIN-N1', 't-vin-yaficha@prueba.test' => 'T-VIN-N2', 't-vin-medico@prueba.test' => 'T-VIN-N3', '' => 'T-VIN-N4'] as $correo => $ci) {
            $this->post(route('pacientes.guardar'), [
                'ci' => $ci, 'nombres' => 'Sin', 'apellidos' => 'Vinculo', 'fecha_nacimiento' => '1991-01-01', 'telefono' => '74444444', 'correo' => $correo,
            ])->assertRedirect(route('pacientes.index'))->assertSessionHas('exito', fn ($m) => ! str_contains($m, 'Se vinculó'));
            $this->assertNull(Paciente::where('ci', $ci)->value('usuario_id'), $correo);
        }
    }

    #[Test]
    public function la_ficha_ofrece_vincular_una_cuenta_existente_y_se_vincula_a_mano(): void
    {
        $ficha = $this->fichaSinCuenta('T-VIN-MAN1');
        $cuenta = $this->cuentaSinFicha('t-vin-manual');

        $this->actingAs($this->recepcion)->get(route('pacientes.editar', $ficha))->assertOk()
            ->assertSee('Cuenta con rol Paciente sin ficha')->assertSee('t-vin-manual · t-vin-manual@prueba.test')->assertSee('Vincular cuenta')
            ->assertSee(route('pacientes.vincular-cuenta', $ficha), false);

        $this->post(route('pacientes.vincular-cuenta', $ficha), ['usuario_id' => $cuenta->id])
            ->assertRedirect(route('pacientes.editar', $ficha))->assertSessionHas('exito', fn ($m) => str_contains($m, 't-vin-manual'));

        $this->assertSame($cuenta->id, $ficha->fresh()->usuario_id);
        $this->assertSame($ficha->historiaClinica->id, $ficha->fresh()->historiaClinica->id);
        $registro = DB::table('bitacora')->where('accion', 'FICHA_VINCULADA')->latest('id')->first();
        $this->assertSame($this->recepcion->id, $registro->usuario_id);
        $this->get(route('pacientes.editar', $ficha))->assertOk()->assertSee('vinculada con la cuenta')->assertDontSee('Vincular cuenta');
        $this->assertFalse($cuenta->fresh()->esPacienteSinFicha());
    }

    #[Test]
    public function el_administrador_tambien_puede_vincular_a_mano(): void
    {
        $ficha = $this->fichaSinCuenta('T-VIN-MAN2');
        $cuenta = $this->cuentaSinFicha('t-vin-manual2');

        $this->actingAs($this->admin)->post(route('pacientes.vincular-cuenta', $ficha), ['usuario_id' => $cuenta->id])->assertSessionHas('exito');

        $this->assertSame($cuenta->id, $ficha->fresh()->usuario_id);
    }

    #[Test]
    public function vincular_con_una_ficha_o_una_cuenta_ya_enlazadas_se_rechaza(): void
    {
        $this->actingAs($this->recepcion);
        $cuentaA = $this->cuentaSinFicha('t-vin-a');
        $fichaConCuenta = $this->fichaSinCuenta('T-VIN-ENL1', ['usuario_id' => $cuentaA->id]);
        $cuentaLibre = $this->cuentaSinFicha('t-vin-libre');

        // La ficha ya tiene cuenta.
        $this->post(route('pacientes.vincular-cuenta', $fichaConCuenta), ['usuario_id' => $cuentaLibre->id])
            ->assertSessionHasErrors(['usuario_id' => 'Esta ficha ya tiene una cuenta de usuario vinculada.']);
        $this->assertSame($cuentaA->id, $fichaConCuenta->fresh()->usuario_id);

        // La cuenta ya tiene otra ficha.
        $fichaLibre = $this->fichaSinCuenta('T-VIN-LIB1');
        $this->post(route('pacientes.vincular-cuenta', $fichaLibre), ['usuario_id' => $cuentaA->id])
            ->assertSessionHasErrors(['usuario_id' => 'Esa cuenta ya tiene otra ficha de paciente.']);
        $this->assertNull($fichaLibre->fresh()->usuario_id);

        // La cuenta no tiene el rol Paciente, no existe o no se eligió.
        $medico = $this->crearUsuario('t-vin-med2', [Rol::MEDICO]);
        $this->post(route('pacientes.vincular-cuenta', $fichaLibre), ['usuario_id' => $medico->id])->assertSessionHasErrors(['usuario_id' => 'Seleccione una cuenta con el rol Paciente.']);
        $this->post(route('pacientes.vincular-cuenta', $fichaLibre), ['usuario_id' => 999999999])->assertSessionHasErrors('usuario_id');
        $this->post(route('pacientes.vincular-cuenta', $fichaLibre), [])->assertSessionHasErrors(['usuario_id' => 'Seleccione la cuenta que quiere vincular.']);
        $this->assertNull($fichaLibre->fresh()->usuario_id);
    }

    #[Test]
    public function el_medico_y_el_paciente_reciben_403_al_vincular_y_sin_sesion_va_al_login(): void
    {
        $ficha = $this->fichaSinCuenta('T-VIN-403');
        $cuenta = $this->cuentaSinFicha('t-vin-403');

        $this->post(route('pacientes.vincular-cuenta', $ficha), ['usuario_id' => $cuenta->id])->assertRedirect(route('login'));
        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-vin-'.mb_strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->post(route('pacientes.vincular-cuenta', $ficha), ['usuario_id' => $cuenta->id])->assertForbidden();
        }

        $this->assertNull($ficha->fresh()->usuario_id);
    }

    #[Test]
    public function el_escenario_de_produccion_ya_no_deja_cuenta_y_ficha_separadas(): void
    {
        // Antes: el administrador creaba la cuenta Paciente (sin ficha) y luego recepción registraba la ficha con el mismo correo: quedaban separadas.
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datosUsuario(['ci' => 'T-VIN-PROD', 'correo' => 't-vin-prod@prueba.test', 'nombre_usuario' => 't-vin-prod']));
        $cuenta = Usuario::where('nombre_usuario', 't-vin-prod')->firstOrFail();
        $this->assertNotNull($cuenta->paciente, 'La cuenta nace con su ficha.');

        // Si luego recepción intenta registrar otra ficha con el mismo CI, se rechaza (CI único) y la cuenta sigue con la suya.
        $this->actingAs($this->recepcion)->post(route('pacientes.guardar'), [
            'ci' => 'T-VIN-PROD', 'nombres' => 'Duplicada', 'apellidos' => 'Ficha', 'fecha_nacimiento' => '1992-03-04', 'telefono' => '71234567', 'correo' => 't-vin-prod@prueba.test',
        ])->assertSessionHasErrors('ci');
        $this->assertSame(1, Paciente::where('ci', 'T-VIN-PROD')->count());
        $this->assertSame(0, Cita::where('paciente_id', 0)->count());
    }
}
