<?php

namespace App\Modules\GestionPacientes;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P3 · Gestión de Pacientes.
 * Carga las rutas y las vistas del módulo (vistas con el namespace «gestion-pacientes»).
 */
class GestionPacientesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'gestion-pacientes');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
