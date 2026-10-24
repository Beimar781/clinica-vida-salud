<?php

namespace App\Modules\GestionCitas;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P4 · Gestión de Citas.
 * Carga las rutas del módulo y sus vistas, que están en frontend/views/gestion-citas (namespace «gestion-citas»).
 */
class GestionCitasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(config('vidasalud.frontend').'/views/gestion-citas', 'gestion-citas');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
