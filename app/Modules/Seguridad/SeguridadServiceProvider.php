<?php

namespace App\Modules\Seguridad;

use Illuminate\Support\ServiceProvider;

/**
 * Paquete P1 · Seguridad y Usuarios.
 * Carga las rutas y las vistas del módulo (vistas con el namespace «seguridad»).
 */
class SeguridadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'seguridad');
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}
