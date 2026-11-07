<?php

namespace App\Modules\GestionPacientes\CU20_RegistrarsePaciente;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * CU-20: Registrarse como paciente (paquete P3 Gestión de Pacientes, RF20).
 *
 * Precondición: la persona no tiene una cuenta en el sistema (pantalla pública, solo para quien no ha iniciado sesión).
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. La persona accede a «Crear cuenta» desde la página de inicio.            -> mostrar()
 *  2. Ingresa CI, nombres, apellidos, fecha de nacimiento, sexo, teléfono,
 *     correo, contraseña y su confirmación.                                    -> registro.blade.php (CIRegistro)
 *  3. El sistema valida los datos en el cliente y en el servidor.               -> data-validar (JavaScript) y RegistroRequest
 *  4. El sistema verifica que el CI y el correo no tengan una cuenta.           -> RegistroPacienteService::registrar() (verificarCI)
 *  5. El sistema guarda la contraseña con hash y crea la cuenta con el rol
 *     Paciente, la ficha del paciente con su código y su historia clínica.      -> RegistroPacienteService::registrar() (crearCuenta)
 *  6. El sistema inicia la sesión y muestra el panel del paciente con la
 *     invitación a completar su perfil.                                         -> guardar() redirige a /panel
 *
 * Excepciones:
 *  - El CI o el correo ya tienen una cuenta: se rechaza y se indica iniciar sesión.
 *  - El CI ya fue registrado por la recepción sin cuenta: se verifica la fecha de nacimiento y la cuenta nueva se vincula a esa
 *    ficha conservando su historia clínica y sus citas (si la fecha no coincide: «Los datos no coinciden con una ficha registrada.»).
 *  - La contraseña tiene menos de 8 caracteres o no coincide con su confirmación: se indica y no se guarda.
 *  - Faltan datos obligatorios o tienen formato inválido: se indica y no se guarda.
 *
 * @CU CU-20 @RF RF20 @CI CIRegistro @CC CCRegistro @CE CEUsuario, CEPaciente, CEHistoriaClinica
 */
class RegistroController extends Controller
{
    /**
     * Pasos 1 y 2: muestra el formulario «Crear cuenta».
     *
     * @CI CIRegistro (mostrarFormulario)
     */
    public function mostrar(): View
    {
        return view('gestionpacientes::CU20_RegistrarsePaciente.registro');
    }

    /**
     * Pasos 3 a 6: valida, crea la cuenta (o vincula la ficha de recepción), inicia la sesión y va al panel.
     *
     * @CI CIRegistro (enviarRegistro, mostrarPanel) @CC CCRegistro (registrar)
     */
    public function guardar(RegistroRequest $request, RegistroPacienteService $registro): RedirectResponse
    {
        ['paciente' => $paciente, 'vinculada' => $vinculada] = $registro->registrar($request->validated());

        $mensaje = $vinculada
            ? "¡Bienvenido! Tu cuenta quedó vinculada a tu ficha {$paciente->codigo}: conservamos tu historia clínica y tus citas."
            : "¡Cuenta creada! Tu código de paciente es {$paciente->codigo} y tu historia clínica ya está abierta.";

        return redirect()->route('panel')->with('exito', $mensaje)->with('invitar_perfil', true);
    }
}
