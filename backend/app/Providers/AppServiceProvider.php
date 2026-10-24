<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use App\Support\PanelPrincipal;
use Illuminate\Support\Facades\Blade;
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

        // El menú superior muestra las funciones que permiten los roles del usuario (ver PanelPrincipal).
        View::composer('layouts.app', function ($vista) {
            $usuario = auth()->user();
            $vista->with('menuFunciones', $usuario ? PanelPrincipal::funciones($usuario) : []);
        });

        // @recurso('css/vidasalud.css') → URL versionada de un asset de frontend/assets.
        Blade::directive('recurso', fn (string $expresion) => "<?php echo e(\\App\\Support\\Recursos::url({$expresion})); ?>");
    }
}
