<?php

namespace App\Modules\GestionPacientes\CU21_GestionarMiPerfil;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CU-21: Gestionar mi perfil (paquete P3 Gestión de Pacientes, RF21). Solo el rol Paciente, sobre su propia ficha.
 *
 * Precondición: el usuario autenticado es Paciente y tiene su ficha registrada.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El paciente accede a «Mi perfil».                                          -> mostrar() (GET /perfil)
 *  2. El sistema muestra las secciones: datos personales, contacto, información
 *     médica, contacto de emergencia y cuenta.                                    -> perfil.blade.php (CIPerfil)
 *  3. El paciente edita los datos de una o más secciones y, si lo desea, activa
 *     o desactiva las notificaciones y la visibilidad de su contacto.             -> actualizar() (PUT /perfil)
 *  4. Si desea cambiar la contraseña, ingresa la actual y la nueva.               -> cambiarContrasena() (PUT /perfil/contrasena)
 *  5. El sistema valida los datos en el cliente y en el servidor.                 -> data-validar (JavaScript), PerfilRequest y PerfilContrasenaRequest
 *  6. El sistema guarda los cambios, los registra en la bitácora y muestra el
 *     perfil actualizado con un mensaje de confirmación.                          -> PerfilService::actualizar() y redirección a mostrar()
 *
 * Excepciones:
 *  - Faltan datos obligatorios o tienen formato inválido: se indica y no se guarda.
 *  - La contraseña actual es incorrecta o la nueva tiene menos de 8 caracteres: se indica y no se cambia la contraseña.
 *  - El paciente intenta modificar su CI: no se permite («Solicítalo en recepción»).
 *  - Un paciente solo ve y edita su propia ficha: sale siempre de su cuenta y ningún identificador de la URL o del envío la cambia.
 *  - La cuenta tiene el rol Paciente pero no tiene ficha: se muestra el aviso («Acude a recepción») y no el formulario.
 *
 * @CU CU-21 @RF RF21 @CI CIPerfil @CC CCPerfil @CE CEPaciente, CEHistoriaClinica, CEUsuario
 */
class PerfilController extends Controller
{
    public function __construct(private readonly PerfilService $perfil) {}

    /**
     * Pasos 1 y 2: muestra el perfil del paciente autenticado (siempre el suyo, aunque la URL traiga otro identificador).
     *
     * @CI CIPerfil (mostrarPerfil) @CC CCPerfil (obtener)
     */
    public function mostrar(Request $request): View
    {
        $ficha = $this->perfil->fichaDe($request->user());

        return view('gestionpacientes::CU21_GestionarMiPerfil.perfil', [
            'usuario' => $request->user(),
            'paciente' => $ficha,
            'historia' => $ficha ? $this->perfil->historiaDe($ficha) : null,
            'tiposSangre' => PerfilRequest::TIPOS_SANGRE,
        ]);
    }

    /**
     * Pasos 3, 5 y 6: valida y guarda los datos de las secciones del perfil.
     *
     * @CI CIPerfil (enviarDatos) @CC CCPerfil (actualizar)
     */
    public function actualizar(PerfilRequest $request): RedirectResponse
    {
        $ficha = $this->perfil->fichaDe($request->user());
        if ($ficha === null) {
            return redirect()->route('perfil.mostrar');
        }

        $cambios = $this->perfil->actualizar($ficha, $request->validated(), $request->user());

        return redirect()->route('perfil.mostrar')->with(
            'exito',
            $cambios === []
                ? 'No hiciste cambios: tu perfil sigue igual.'
                : 'Perfil actualizado correctamente ('.implode(', ', $cambios).').'
        );
    }

    /**
     * Pasos 4 y 5: cambia la contraseña con la actual y la nueva.
     *
     * @CI CIPerfil (enviarDatos) @CC CCPerfil (cambiarContrasena)
     */
    public function cambiarContrasena(PerfilContrasenaRequest $request): RedirectResponse
    {
        $this->perfil->cambiarContrasena($request->user(), $request->string('password_actual')->toString(), $request->string('password')->toString());

        return redirect()->route('perfil.mostrar')->with('exito', 'Contraseña cambiada correctamente.');
    }
}
