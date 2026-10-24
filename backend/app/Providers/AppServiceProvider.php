<?php

namespace App\Providers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
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

        // @recurso('css/vidasalud.css') → URL versionada de un asset de frontend/assets.
        Blade::directive('recurso', fn (string $expresion) => "<?php echo e(\\App\\Support\\Recursos::url({$expresion})); ?>");
    }
}
