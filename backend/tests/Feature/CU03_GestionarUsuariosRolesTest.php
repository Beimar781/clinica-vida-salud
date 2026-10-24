<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Support\PanelPrincipal;
use Illuminate\Support\Facades\Hash;

/** CU-03: Gestionar usuarios y roles. */
class CU03_GestionarUsuariosRolesTest extends SistemaTestCase
{
    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin', [Rol::ADMINISTRADOR]);
    }

    /** Caso 1: registrar un usuario con correo repetido → rechaza el duplicado e indica el campo. */
    public function test_registrar_usuario_con_correo_repetido(): void
    {
        $existente = $this->crearUsuario('t-existente', [Rol::PACIENTE]);

        $this->actingAs($this->admin)->from(route('usuarios.crear'))
            ->post(route('usuarios.guardar'), $this->datos(['correo' => strtoupper($existente->correo)]))
            ->assertRedirect(route('usuarios.crear'))
            ->assertSessionHasErrors(['correo' => 'El correo ya está registrado.']);

        $this->assertSame(0, Usuario::where('nombre_usuario', 't-nuevo')->count());
    }

    /** Caso 2: asignar un rol y comprobar permisos → solo ve las opciones de Recepcionista; las de Administrador están denegadas. */
    public function test_asignar_rol_y_comprobar_permisos(): void
    {
        $this->actingAs($this->admin)
            ->post(route('usuarios.guardar'), $this->datos(['nombre_usuario' => 't-recep', 'correo' => 't-recep@prueba.test', 'roles' => [$this->idRol(Rol::RECEPCIONISTA)]]))
            ->assertRedirect(route('usuarios.index'));

        $recepcionista = Usuario::where('nombre_usuario', 't-recep')->firstOrFail();

        // Ingresa con la cuenta nueva.
        auth()->logout();
        $this->post('/login', ['identificador' => 't-recep@prueba.test', 'password' => 'Clave1234'])->assertRedirect('/panel');

        $this->get(route('usuarios.index'))->assertForbidden();
        $this->get(route('usuarios.crear'))->assertForbidden();
        $this->get('/panel')->assertOk()->assertDontSee('Usuarios y roles');

        $nombres = $this->funcionesVisibles($recepcionista);
        $this->assertContains('Pacientes', $nombres);
        $this->assertContains('Registrar cita', $nombres);
        $this->assertNotContains('Usuarios y roles', $nombres);
        $this->assertNotContains('Especialidades', $nombres);
    }

    /** Caso 3: asignar más de un rol → al ingresar ve las opciones de ambos roles. */
    public function test_asignar_mas_de_un_rol_a_un_usuario(): void
    {
        $this->actingAs($this->admin)
            ->post(route('usuarios.guardar'), $this->datos([
                'nombre_usuario' => 't-medpac', 'correo' => 't-medpac@prueba.test',
                'roles' => [$this->idRol(Rol::MEDICO), $this->idRol(Rol::PACIENTE)],
            ]))->assertRedirect(route('usuarios.index'));

        $usuario = Usuario::where('nombre_usuario', 't-medpac')->firstOrFail();
        $this->assertEqualsCanonicalizing([Rol::MEDICO, Rol::PACIENTE], $usuario->nombresRoles()->all());

        auth()->logout();
        $this->post('/login', ['identificador' => 't-medpac', 'password' => 'Clave1234'])->assertRedirect('/panel');
        $this->get('/panel')->assertOk()->assertSee('Médico')->assertSee('Paciente');

        // Permisos de la unión de roles: lo del Paciente sí, lo del Administrador no.
        $this->assertContains('Reservar cita', $this->funcionesVisibles($usuario));
        $this->assertTrue($usuario->tieneRol(Rol::MEDICO) && $usuario->tieneRol(Rol::PACIENTE));
        $this->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_el_nombre_de_usuario_repetido_se_rechaza_aunque_cambien_las_mayusculas(): void
    {
        $this->crearUsuario('t-repetido', [Rol::PACIENTE]);

        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datos(['nombre_usuario' => 'T-REPETIDO']))
            ->assertSessionHasErrors(['nombre_usuario' => 'El nombre de usuario ya está registrado.']);
    }

    public function test_la_contrasena_debe_tener_al_menos_8_caracteres(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datos(['password' => 'corta12']))
            ->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 8 caracteres.']);

        $this->assertSame(0, Usuario::where('nombre_usuario', 't-nuevo')->count());
    }

    public function test_si_no_se_selecciona_ningun_rol_lo_solicita_y_no_guarda(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datos(['roles' => []]))
            ->assertSessionHasErrors(['roles' => 'Seleccione al menos un rol.']);

        $this->assertSame(0, Usuario::where('nombre_usuario', 't-nuevo')->count());
    }

    public function test_guarda_la_contrasena_con_hash_y_registra_en_la_bitacora(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.guardar'), $this->datos())
            ->assertRedirect(route('usuarios.index'))
            ->assertSessionHas('exito');

        $nuevo = Usuario::where('nombre_usuario', 't-nuevo')->firstOrFail();
        $this->assertNotSame('Clave1234', $nuevo->password_hash);
        $this->assertTrue(Hash::check('Clave1234', $nuevo->password_hash));
        $this->assertTrue($nuevo->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'USUARIO_CREADO', 'usuario_id' => $this->admin->id]);
    }

    public function test_editar_cambia_datos_y_roles_y_restablece_la_contrasena(): void
    {
        $usuario = $this->crearUsuario('t-editar', [Rol::PACIENTE], 'Vieja12345');
        $hashAnterior = $usuario->password_hash;

        $this->actingAs($this->admin)->put(route('usuarios.actualizar', $usuario), [
            'nombre_usuario' => 't-editado', 'correo' => 't-editado@prueba.test', 'password' => 'Nueva12345',
            'roles' => [$this->idRol(Rol::RECEPCIONISTA), $this->idRol(Rol::MEDICO)],
        ])->assertRedirect(route('usuarios.index'));

        $usuario->refresh();
        $this->assertSame('t-editado', $usuario->nombre_usuario);
        $this->assertEqualsCanonicalizing([Rol::RECEPCIONISTA, Rol::MEDICO], $usuario->nombresRoles()->all());
        $this->assertNotSame($hashAnterior, $usuario->password_hash);
        $this->assertTrue(Hash::check('Nueva12345', $usuario->password_hash));
        $this->assertDatabaseHas('bitacora', ['accion' => 'CONTRASENA_RESTABLECIDA', 'usuario_id' => $this->admin->id]);
    }

    public function test_editar_sin_contrasena_conserva_la_actual(): void
    {
        $usuario = $this->crearUsuario('t-conserva', [Rol::PACIENTE], 'Vieja12345');
        $hash = $usuario->password_hash;

        $this->actingAs($this->admin)->put(route('usuarios.actualizar', $usuario), [
            'nombre_usuario' => 't-conserva', 'correo' => 't-conserva@prueba.test', 'password' => '',
            'roles' => [$this->idRol(Rol::PACIENTE)],
        ])->assertRedirect(route('usuarios.index'));

        $this->assertSame($hash, $usuario->fresh()->password_hash);
    }

    public function test_desactivar_conserva_el_registro_e_impide_iniciar_sesion(): void
    {
        $usuario = $this->crearUsuario('t-baja', [Rol::PACIENTE], 'Clave1234');

        $this->actingAs($this->admin)->post(route('usuarios.desactivar', $usuario))->assertSessionHas('exito');

        $this->assertFalse($usuario->fresh()->estado);
        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'USUARIO_DESACTIVADO', 'usuario_id' => $this->admin->id]);

        auth()->logout();
        $this->post('/login', ['identificador' => 't-baja', 'password' => 'Clave1234'])
            ->assertSessionHas('error', 'El acceso no está habilitado.');
        $this->assertGuest();

        // Se puede volver a activar.
        $this->actingAs($this->admin)->post(route('usuarios.activar', $usuario))->assertSessionHas('exito');
        $this->assertTrue($usuario->fresh()->estado);
    }

    public function test_una_cuenta_desactivada_con_sesion_abierta_pierde_el_acceso(): void
    {
        $usuario = $this->crearUsuario('t-sesion-abierta', [Rol::PACIENTE]);
        $this->actingAs($usuario)->get('/panel')->assertOk();

        $usuario->update(['estado' => false]);

        $this->get('/panel')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_el_administrador_no_puede_desactivar_su_propia_cuenta(): void
    {
        $this->actingAs($this->admin)->post(route('usuarios.desactivar', $this->admin))
            ->assertSessionHas('error', 'No puede desactivar su propia cuenta.');

        $this->assertTrue($this->admin->fresh()->estado);
    }

    public function test_el_administrador_no_puede_quitarse_su_rol(): void
    {
        $this->actingAs($this->admin)->put(route('usuarios.actualizar', $this->admin), [
            'nombre_usuario' => 't-admin', 'correo' => 't-admin@prueba.test',
            'roles' => [$this->idRol(Rol::RECEPCIONISTA)],
        ])->assertSessionHasErrors(['roles' => 'No puede quitarse a sí mismo el rol Administrador.']);

        $this->assertTrue($this->admin->fresh()->tieneRol(Rol::ADMINISTRADOR));
    }

    public function test_el_listado_busca_por_nombre_de_usuario_o_correo(): void
    {
        $this->crearUsuario('t-busca-ana', [Rol::PACIENTE]);
        $this->crearUsuario('t-busca-luis', [Rol::PACIENTE]);

        $this->actingAs($this->admin)->get(route('usuarios.index', ['q' => 'busca-ANA']))
            ->assertOk()->assertSee('t-busca-ana')->assertDontSee('t-busca-luis');

        $this->get(route('usuarios.index', ['q' => 't-busca-luis@prueba']))
            ->assertOk()->assertSee('t-busca-luis')->assertDontSee('t-busca-ana');

        $this->get(route('usuarios.index', ['q' => 'no-existe-xyz']))->assertOk()->assertSee('No se encontraron usuarios');
    }

    public function test_solo_el_administrador_accede_a_la_gestion_de_usuarios(): void
    {
        $this->get(route('usuarios.index'))->assertRedirect('/login');

        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-'.mb_strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('usuarios.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('usuarios.guardar'), $this->datos())->assertForbidden();
        }
    }

    public function test_los_formularios_de_usuario_tienen_el_boton_para_mostrar_la_contrasena(): void
    {
        $this->actingAs($this->admin)->get(route('usuarios.crear'))->assertOk()
            ->assertSee('data-mostrar-clave', false)
            ->assertSee('aria-label="Mostrar contraseña"', false);

        // Al editar (restablecer la contraseña) el botón también está.
        $otro = $this->crearUsuario('t-ojo-editar', [Rol::PACIENTE]);
        $this->actingAs($this->admin)->get(route('usuarios.editar', $otro))->assertOk()
            ->assertSee('data-mostrar-clave', false)
            ->assertSee('Nueva contraseña (restablecer)');
    }

    public function test_el_administrador_ve_el_listado_con_sus_roles_y_estado(): void
    {
        $this->actingAs($this->admin)->get(route('usuarios.index'))
            ->assertOk()->assertSee('Usuarios y roles')->assertSee('t-admin')->assertSee('Administrador')->assertSee('Activo');
    }

    /** @return list<string> */
    private function funcionesVisibles(Usuario $usuario): array
    {
        return collect(PanelPrincipal::paquetes($usuario, soloImplementadas: false))
            ->flatMap(fn ($paquete) => collect($paquete['funciones'])->pluck('nombre'))->all();
    }

    /** Datos válidos de un usuario nuevo; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'nombre_usuario' => 't-nuevo',
            'correo' => 't-nuevo@prueba.test',
            'password' => 'Clave1234',
            'roles' => [$this->idRol(Rol::PACIENTE)],
        ];
    }
}
