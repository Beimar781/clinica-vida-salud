<?php

namespace App\Providers;

use App\Support\BrevoTransport;
use App\Support\PanelPrincipal;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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
        // En producción (Railway) todo el sitio se sirve por https: enlaces, assets, formularios y el enlace del correo.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

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

        // Correo real en producción (MAIL_MAILER=brevo): envío por la API web de Brevo (Railway bloquea el SMTP).
        Mail::extend('brevo', fn (array $config) => new BrevoTransport(config('services.brevo.key')));

        $this->correoDeRecuperacion();

        $this->cargarModulos();
    }

    /**
     * CU-01 (flujo alternativo: recuperar contraseña): correo en español con el nombre de la clínica.
     * En local (MAIL_MAILER=log) el enlace también queda en una línea propia del log, fácil de copiar.
     */
    private function correoDeRecuperacion(): void
    {
        ResetPassword::toMailUsing(function ($usuario, string $token) {
            $url = route('password.reset', ['token' => $token, 'correo' => $usuario->getEmailForPasswordReset()]);

            if (app()->environment('local')) {
                Log::info('ENLACE DE RECUPERACION: '.$url);
            }

            return (new MailMessage)
                ->subject('Recupera tu contraseña · Clínica Vida Salud')
                ->view(
                    ['seguridadusuarios::CU01_IniciarSesion.correo-recuperacion', 'seguridadusuarios::CU01_IniciarSesion.correo-recuperacion-texto'],
                    ['usuario' => $usuario, 'url' => $url, 'minutos' => config('auth.passwords.users.expire')]
                );
        });
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
