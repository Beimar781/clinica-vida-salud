<?php

namespace App\Modules\SeguridadUsuarios\CU01_IniciarSesion;

use RuntimeException;

/**
 * Motivo por el que no se pudo iniciar sesión. El mensaje es seguro para mostrarlo al usuario
 * («Credenciales incorrectas», «El acceso no está habilitado», bloqueo temporal).
 *
 * @CU CU-01 @RF RF01 @CI CILogin (mostrarError) @CC CCLogin
 */
class LoginFallidoException extends RuntimeException {}
