<?php

namespace App\Modules\Seguridad;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P1 · Seguridad y Usuarios.
 * Carga las rutas del módulo y sus vistas, que están en frontend/views/seguridad (namespace «seguridad»).
 */
class SeguridadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(config('vidasalud.frontend').'/views/seguridad', 'seguridad');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
