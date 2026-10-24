<?php

namespace App\Providers;

use App\Support\PanelPrincipal;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fechas y nombres de meses en español (America/La_Paz ya está en config/app.php).
        Carbon::setLocale('es');

        // Paginación con el estilo y los textos del sistema (frontend/layouts/paginacion.blade.php).
        Paginator::defaultView('layouts.paginacion');
        Paginator::defaultSimpleView('layouts.paginacion');

        // El menú superior muestra las funciones que permiten los roles del usuario (ver PanelPrincipal).
        View::composer('layouts.app', function ($vista) {
            $usuario = auth()->user();
            $vista->with('menuFunciones', $usuario ? PanelPrincipal::funciones($usuario) : []);
        });

        // @recurso('css/vidasalud.css') → URL versionada de un asset de frontend/assets.
        Blade::directive('recurso', fn (string $expresion) => "<?php echo e(\\App\\Support\\Recursos::url({$expresion})); ?>");

        $this->cargarModulos();
    }

    /**
     * Carga automáticamente cada paquete que exista en backend/app/Modules:
     *  - sus rutas:  backend/app/Modules/<Paquete>/routes.php (dentro del grupo «web»)
     *  - sus vistas: view('<paquete>::<CU>.<vista>') → frontend/<Paquete>/<CU>/<vista>.blade.php
     * Ejemplo: view('seguridadusuarios::CU01_IniciarSesion.login').
     * Para agregar un paquete o un CU basta con crear su carpeta en backend/app/Modules y en frontend.
     */
    private function cargarModulos(): void
    {
        $frontend = config('vidasalud.frontend');

        foreach (glob(app_path('Modules/*'), GLOB_ONLYDIR) as $carpeta) {
            $paquete = basename($carpeta);
            View::addNamespace(strtolower($paquete), $frontend.DIRECTORY_SEPARATOR.$paquete);

            if (file_exists($carpeta.'/routes.php')) {
                Route::middleware('web')->group($carpeta.'/routes.php');
            }
        }
    }
}
