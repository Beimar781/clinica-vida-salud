<?php

namespace App\Modules\ReportesBitacora\CU19_ConsultarBitacora;

use Illuminate\Support\Str;

/**
 * Catálogo de las acciones que el sistema deja en la bitácora: texto legible en español y color de la
 * etiqueta. Rojo: ingresos fallidos, bloqueados o rechazados y accesos denegados; verde: ingresos, altas y
 * activaciones; gris: cierres de sesión; azul: todo lo demás.
 *
 * @CU CU-19 @RF RF19 @CI CIBitacora (mostrarListado)
 */
class AccionesBitacora
{
    public const ROJO = 'rojo';

    public const VERDE = 'verde';

    public const GRIS = 'gris';

    public const AZUL = 'azul';

    /** Acción => texto que se muestra. Si se agrega una acción nueva al sistema, se agrega aquí. */
    public const ETIQUETAS = [
        'LOGIN' => 'Inicio de sesión',
        'LOGIN_FALLIDO' => 'Inicio de sesión fallido',
        'LOGIN_BLOQUEADO' => 'Ingreso bloqueado',
        'LOGIN_RECHAZADO' => 'Ingreso rechazado',
        'LOGOUT' => 'Cierre de sesión',
        'SESION_CERRADA_CUENTA_INACTIVA' => 'Sesión cerrada (cuenta inactiva)',
        'ACCESO_DENEGADO' => 'Acceso denegado',
        'RECUPERACION_SOLICITADA' => 'Recuperación de contraseña solicitada',
        'RECUPERACION_COMPLETADA' => 'Contraseña recuperada',
        'RECUPERACION_FALLIDA' => 'Correo de recuperación no enviado',
        'USUARIO_CREADO' => 'Usuario creado',
        'USUARIO_EDITADO' => 'Usuario editado',
        'USUARIO_ACTIVADO' => 'Usuario activado',
        'USUARIO_DESACTIVADO' => 'Usuario desactivado',
        'CONTRASENA_RESTABLECIDA' => 'Contraseña restablecida',
        'ESPECIALIDAD_CREADA' => 'Especialidad creada',
        'ESPECIALIDAD_EDITADA' => 'Especialidad editada',
        'ESPECIALIDAD_ACTIVADA' => 'Especialidad activada',
        'ESPECIALIDAD_DESACTIVADA' => 'Especialidad desactivada',
        'MEDICO_CREADO' => 'Médico creado',
        'MEDICO_EDITADO' => 'Médico editado',
        'MEDICO_ACTIVADO' => 'Médico activado',
        'MEDICO_DESACTIVADO' => 'Médico desactivado',
        'HORARIO_CREADO' => 'Horario creado',
        'HORARIO_EDITADO' => 'Horario editado',
        'HORARIO_ACTIVADO' => 'Horario activado',
        'HORARIO_DESACTIVADO' => 'Horario desactivado',
        'DIA_SIN_ATENCION_CREADO' => 'Día sin atención registrado',
        'DIA_SIN_ATENCION_ACTIVADO' => 'Día sin atención activado',
        'DIA_SIN_ATENCION_DESACTIVADO' => 'Día sin atención desactivado',
        'PACIENTE_CREADO' => 'Paciente creado',
        'PACIENTE_EDITADO' => 'Paciente editado',
        'PACIENTE_ACTIVADO' => 'Paciente activado',
        'PACIENTE_DESACTIVADO' => 'Paciente desactivado',
        'CITA_REGISTRADA' => 'Cita registrada',
        'CITA_REPROGRAMADA' => 'Cita reprogramada',
        'CITA_RESERVADA' => 'Cita reservada en línea',
    ];

    private const ROJAS = ['LOGIN_FALLIDO', 'LOGIN_BLOQUEADO', 'LOGIN_RECHAZADO', 'ACCESO_DENEGADO'];

    /** Texto legible de la acción; una acción desconocida se muestra con su código en forma legible. */
    public static function etiqueta(string $accion): string
    {
        return self::ETIQUETAS[$accion] ?? Str::ucfirst(Str::lower(str_replace('_', ' ', $accion)));
    }

    /** Color de la etiqueta: rojo, verde, gris o azul. */
    public static function tono(string $accion): string
    {
        return match (true) {
            in_array($accion, self::ROJAS, true) => self::ROJO,
            $accion === 'LOGOUT' => self::GRIS,
            $accion === 'LOGIN',
            $accion === 'CITA_REGISTRADA',
            $accion === 'CITA_RESERVADA',
            str_ends_with($accion, '_CREADO'),
            str_ends_with($accion, '_CREADA'),
            str_ends_with($accion, '_ACTIVADO'),
            str_ends_with($accion, '_ACTIVADA') => self::VERDE,
            default => self::AZUL,
        };
    }
}
