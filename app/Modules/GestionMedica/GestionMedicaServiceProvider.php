<?php

namespace App\Modules\GestionMedica;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P2 · Gestión Médica.
 * Carga las rutas y las vistas del módulo (vistas con el namespace «gestion-medica»).
 */
class GestionMedicaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'gestion-medica');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
