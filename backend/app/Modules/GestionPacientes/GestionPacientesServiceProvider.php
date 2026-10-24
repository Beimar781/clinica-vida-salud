<?php

namespace App\Modules\GestionPacientes;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P3 · Gestión de Pacientes.
 * Carga las rutas del módulo y sus vistas, que están en frontend/views/gestion-pacientes (namespace «gestion-pacientes»).
 */
class GestionPacientesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(config('vidasalud.frontend').'/views/gestion-pacientes', 'gestion-pacientes');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
