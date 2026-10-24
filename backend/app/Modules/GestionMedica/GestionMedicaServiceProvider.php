<?php

namespace App\Modules\GestionMedica;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P2 · Gestión Médica.
 * Carga las rutas del módulo y sus vistas, que están en frontend/views/gestion-medica (namespace «gestion-medica»).
 */
class GestionMedicaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(config('vidasalud.frontend').'/views/gestion-medica', 'gestion-medica');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
