<?php

namespace App\Modules\SeguridadUsuarios\CU03_GestionarUsuariosRoles;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-03: Gestionar usuarios y roles (paquete P1 Seguridad y Usuarios, RF03). Solo el Administrador.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El administrador accede a la sección «Usuarios y roles».           -> index() (la ruta exige el rol Administrador)
 *  2. El sistema muestra el listado de usuarios con su rol y estado,
 *     con búsqueda por nombre o correo.                                  -> index() con UsuarioService::listar()
 *  3. El administrador selecciona crear o editar un usuario.             -> crear() / editar()
 *  4. Ingresa nombre de usuario, correo, contraseña (solo al crear o
 *     restablecer) y selecciona uno o más roles.                         -> pantallas crear.blade.php, editar.blade.php y campos.blade.php
 *  5. El sistema valida los datos, guarda la contraseña con hash,
 *     registra el usuario y le asigna los roles elegidos.                -> UsuarioRequest + guardar() / actualizar() con UsuarioService
 *  6. El sistema muestra el listado actualizado con un mensaje de
 *     confirmación.                                                      -> redirige a index() con el aviso verde
 *
 * Excepciones:
 *  - Correo o nombre de usuario repetido: se rechaza e indica el campo repetido (UsuarioRequest).
 *  - Contraseña de menos de 8 caracteres: se indica y no se guarda (UsuarioRequest).
 *  - Ningún rol seleccionado: se solicita y no se guarda (UsuarioRequest).
 *  - Desactivar un usuario: se conserva el registro y se le impide iniciar sesión; no se eliminan usuarios
 *    -> desactivar() / activar(). Reglas añadidas: el administrador no puede desactivarse ni quitarse su rol.
 *
 * @CU CU-03 @RF RF03 @CI CIUsuarios @CC CCUsuarios @CE CEUsuario, CERol
 */
class UsuariosController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    /**
     * Pasos 1 y 2: listado con búsqueda.
     *
     * @CI CIUsuarios (mostrarListado) @CE CEUsuario, CERol
     */
    public function index(Request $request): View
    {
        return view('seguridadusuarios::CU03_GestionarUsuariosRoles.index', [
            'usuarios' => $this->usuarios->listar($request->query('q')),
            'buscar' => (string) $request->query('q'),
        ]);
    }

    /**
     * Pasos 3 y 4 (alta): formulario vacío con los roles disponibles.
     *
     * @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios (crear) @CE CERol (listar)
     */
    public function crear(): View
    {
        return view('seguridadusuarios::CU03_GestionarUsuariosRoles.crear', [
            'roles' => $this->usuarios->rolesDisponibles(),
            'rolesDelUsuario' => [],
        ]);
    }

    /**
     * Paso 5 y 6 (alta): valida (UsuarioRequest), guarda y vuelve al listado.
     *
     * @CI CIUsuarios (enviarDatos, mostrarResultado) @CC CCUsuarios (guardar) @CE CEUsuario (crear)
     */
    public function guardar(UsuarioRequest $request): RedirectResponse
    {
        $usuario = $this->usuarios->guardar($request->validated(), $request->user());

        return redirect()->route('usuarios.index')->with('exito', "Usuario {$usuario->nombre_usuario} registrado correctamente.");
    }

    /**
     * Pasos 3 y 4 (edición): formulario con los datos y los roles actuales.
     *
     * @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios (editar) @CE CEUsuario, CERol
     */
    public function editar(Usuario $usuario): View
    {
        return view('seguridadusuarios::CU03_GestionarUsuariosRoles.editar', [
            'usuario' => $usuario,
            'roles' => $this->usuarios->rolesDisponibles(),
            'rolesDelUsuario' => $usuario->roles()->pluck('roles.id')->all(),
        ]);
    }

    /**
     * Paso 5 y 6 (edición): valida, actualiza datos y roles (y restablece la contraseña si se escribió una nueva).
     *
     * @CI CIUsuarios (enviarDatos, mostrarResultado) @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar)
     */
    public function actualizar(UsuarioRequest $request, Usuario $usuario): RedirectResponse
    {
        $this->usuarios->actualizar($usuario, $request->validated(), $request->user());

        return redirect()->route('usuarios.index')->with('exito', "Usuario {$usuario->nombre_usuario} actualizado correctamente.");
    }

    /**
     * Excepción de desactivar: conserva el registro y la cuenta ya no puede iniciar sesión.
     *
     * @CI CIUsuarios (mostrarResultado) @CC CCUsuarios (desactivar) @CE CEUsuario (desactivar)
     */
    public function desactivar(Request $request, Usuario $usuario): RedirectResponse
    {
        try {
            $this->usuarios->desactivar($usuario, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('exito', "Usuario {$usuario->nombre_usuario} desactivado. Se conserva su registro.");
    }

    /**
     * Vuelve a habilitar una cuenta desactivada.
     *
     * @CI CIUsuarios (mostrarResultado) @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar)
     */
    public function activar(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->usuarios->activar($usuario, $request->user());

        return back()->with('exito', "Usuario {$usuario->nombre_usuario} activado.");
    }
}
