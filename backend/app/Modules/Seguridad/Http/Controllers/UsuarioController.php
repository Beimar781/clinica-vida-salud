<?php

namespace App\Modules\Seguridad\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Seguridad\Http\Requests\UsuarioRequest;
use App\Modules\Seguridad\Models\Usuario;
use App\Modules\Seguridad\Services\UsuarioService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Usuarios y roles (solo Administrador). Controlador delgado: la lógica está en UsuarioService.
 */
class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioService $usuarios) {}

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarListado) @CC CCUsuarios @CE CEUsuario, CERol
     */
    public function index(Request $request): View
    {
        return view('seguridad::cu03-gestionar-usuarios.index', [
            'usuarios' => $this->usuarios->listar($request->query('q')),
            'buscar' => (string) $request->query('q'),
        ]);
    }

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios (crear) @CE CERol (listar)
     */
    public function crear(): View
    {
        return view('seguridad::cu03-gestionar-usuarios.formulario', [
            'usuario' => null,
            'roles' => $this->usuarios->rolesDisponibles(),
            'rolesDelUsuario' => [],
        ]);
    }

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (enviarDatos, mostrarResultado) @CC CCUsuarios (guardar) @CE CEUsuario (crear)
     */
    public function guardar(UsuarioRequest $request): RedirectResponse
    {
        $usuario = $this->usuarios->guardar($request->validated(), $request->user());

        return redirect()->route('usuarios.index')->with('exito', "Usuario {$usuario->nombre_usuario} registrado correctamente.");
    }

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarFormulario) @CC CCUsuarios (editar) @CE CEUsuario, CERol
     */
    public function editar(Usuario $usuario): View
    {
        return view('seguridad::cu03-gestionar-usuarios.formulario', [
            'usuario' => $usuario,
            'roles' => $this->usuarios->rolesDisponibles(),
            'rolesDelUsuario' => $usuario->roles()->pluck('roles.id')->all(),
        ]);
    }

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (enviarDatos, mostrarResultado) @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar)
     */
    public function actualizar(UsuarioRequest $request, Usuario $usuario): RedirectResponse
    {
        $this->usuarios->actualizar($usuario, $request->validated(), $request->user());

        return redirect()->route('usuarios.index')->with('exito', "Usuario {$usuario->nombre_usuario} actualizado correctamente.");
    }

    /**
     * @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarResultado) @CC CCUsuarios (desactivar) @CE CEUsuario (desactivar)
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
     * @CU CU-03 @RF RF03 @CI CIUsuarios (mostrarResultado) @CC CCUsuarios (actualizar) @CE CEUsuario (actualizar)
     */
    public function activar(Request $request, Usuario $usuario): RedirectResponse
    {
        $this->usuarios->activar($usuario, $request->user());

        return back()->with('exito', "Usuario {$usuario->nombre_usuario} activado.");
    }
}
