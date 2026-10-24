<?php

namespace App\Support;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;

/**
 * Catálogo de paquetes y funciones del sistema, y qué ve cada usuario según la unión de sus roles.
 * Alimenta el panel principal (tarjetas por paquete) y el menú superior. Una función solo aparece
 * cuando su ruta existe: el panel solo ofrece lo que está construido.
 *
 * @CU CU-01 @RF RF01 @CI CILogin (mostrarPanel) @CC CCLogin @CE CEUsuario
 */
class PanelPrincipal
{
    /**
     * Paquetes en el orden del documento. Cada función: nombre mostrado, ruta (nombre de ruta),
     * ícono, caso de uso y roles que la ven.
     */
    private const PAQUETES = [
        [
            'clave' => 'seguridad', 'nombre' => 'Seguridad y Usuarios', 'icono' => 'escudo',
            'funciones' => [
                ['nombre' => 'Usuarios y roles', 'ruta' => 'usuarios.index', 'icono' => 'usuarios', 'cu' => 'CU-03', 'roles' => [Rol::ADMINISTRADOR]],
            ],
        ],
        [
            'clave' => 'gestion-medica', 'nombre' => 'Gestión Médica', 'icono' => 'medico',
            'funciones' => [
                ['nombre' => 'Especialidades', 'ruta' => 'especialidades.index', 'icono' => 'cruz', 'cu' => 'CU-04', 'roles' => [Rol::ADMINISTRADOR]],
                ['nombre' => 'Médicos', 'ruta' => 'medicos.index', 'icono' => 'medico', 'cu' => 'CU-05', 'roles' => [Rol::ADMINISTRADOR]],
                ['nombre' => 'Horarios de atención', 'ruta' => 'horarios.index', 'icono' => 'reloj', 'cu' => 'CU-06', 'roles' => [Rol::ADMINISTRADOR]],
                ['nombre' => 'Días sin atención', 'ruta' => 'dias-sin-atencion.index', 'icono' => 'calendario', 'cu' => 'CU-06', 'roles' => [Rol::ADMINISTRADOR]],
            ],
        ],
        [
            'clave' => 'gestion-pacientes', 'nombre' => 'Gestión de Pacientes', 'icono' => 'paciente',
            'funciones' => [
                ['nombre' => 'Pacientes', 'ruta' => 'pacientes.index', 'icono' => 'paciente', 'cu' => 'CU-07', 'roles' => [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA]],
            ],
        ],
        [
            'clave' => 'gestion-citas', 'nombre' => 'Gestión de Citas', 'icono' => 'cita',
            'funciones' => [
                ['nombre' => 'Consultar disponibilidad', 'ruta' => 'disponibilidad.index', 'icono' => 'buscar', 'cu' => 'CU-08', 'roles' => [Rol::RECEPCIONISTA, Rol::PACIENTE]],
                ['nombre' => 'Registrar cita', 'ruta' => 'citas.registrar', 'icono' => 'mas', 'cu' => 'CU-09', 'roles' => [Rol::RECEPCIONISTA]],
                ['nombre' => 'Citas y reprogramación', 'ruta' => 'citas.index', 'icono' => 'calendario', 'cu' => 'CU-10', 'roles' => [Rol::RECEPCIONISTA]],
                ['nombre' => 'Reservar cita', 'ruta' => 'reservas.crear', 'icono' => 'cita', 'cu' => 'CU-11', 'roles' => [Rol::PACIENTE]],
            ],
        ],
        [
            'clave' => 'reportes-bitacora', 'nombre' => 'Reportes y bitácora', 'icono' => 'documento',
            'funciones' => [
                ['nombre' => 'Bitácora', 'ruta' => 'bitacora.index', 'icono' => 'historial', 'cu' => 'CU-19', 'roles' => [Rol::ADMINISTRADOR]],
            ],
        ],
    ];

    /**
     * Paquetes con las funciones que el usuario puede usar (unión de todos sus roles).
     *
     * @param  bool  $soloImplementadas  false lista también las funciones cuya ruta aún no existe
     * @return list<array{clave: string, nombre: string, icono: string, funciones: list<array<string, mixed>>}>
     */
    public static function paquetes(Usuario $usuario, bool $soloImplementadas = true): array
    {
        $paquetes = [];

        foreach (self::PAQUETES as $paquete) {
            $funciones = [];
            foreach ($paquete['funciones'] as $funcion) {
                if (! $usuario->tieneRol(...$funcion['roles'])) {
                    continue;
                }
                $existe = Route::has($funcion['ruta']);
                if ($soloImplementadas && ! $existe) {
                    continue;
                }
                $funciones[] = $funcion + ['url' => $existe ? route($funcion['ruta']) : '#'];
            }

            if ($funciones !== []) {
                $paquetes[] = ['funciones' => $funciones] + $paquete;
            }
        }

        return $paquetes;
    }

    /** Funciones disponibles en una sola lista (menú superior). */
    public static function funciones(Usuario $usuario): array
    {
        return collect(self::paquetes($usuario))->flatMap(fn (array $paquete) => $paquete['funciones'])->all();
    }
}
