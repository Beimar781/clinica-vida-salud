<?php

use App\Http\Controllers\RecursoController;
use App\Support\Middleware\SinCache;
use App\Support\Middleware\VerificarRol;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Assets del frontend (frontend/assets). Fuera del grupo «web»: sin sesión ni cookies.
            Route::get('assets/{ruta}', RecursoController::class)->where('ruta', '.+')->name('assets');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Las páginas internas sin sesión redirigen al inicio de sesión; con sesión, /login lleva al panel.
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/panel');
        $middleware->alias(['rol' => VerificarRol::class]);
        // Ninguna página se guarda en el navegador: al cerrar sesión y volver atrás no se ve nada interno.
        $middleware->web(append: [SinCache::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sesión vencida (token CSRF inválido): al cerrar sesión solo se redirige al login;
        // en cualquier otro formulario se avisa y se vuelve a pedir el ingreso.
        // Laravel convierte TokenMismatchException en HttpException 419 antes de llamar a este callback.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->is('logout')) {
                return redirect('/login');
            }

            return redirect('/login')->with('error', 'Su sesión expiró. Ingrese nuevamente.');
        });
    })->create();
