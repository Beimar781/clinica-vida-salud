<?php

namespace App\Modules\GestionCitas;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P4 · Gestión de Citas.
 * Carga las rutas y las vistas del módulo (vistas con el namespace «gestion-citas»).
 */
class GestionCitasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'gestion-citas');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
