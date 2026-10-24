<?php

/*
| Las vistas Blade viven en la carpeta frontend/ de la raíz del repositorio (junto a backend/):
| frontend/layouts, frontend/components y frontend/errors son comunes; las pantallas de cada caso de uso
| están en frontend/<Paquete>/<CU>/ y se llaman con el namespace del paquete
| (ver AppServiceProvider::cargarModulos), por ejemplo view('seguridadusuarios::CU01_IniciarSesion.login').
*/

return [

    'paths' => [
        dirname(base_path()).DIRECTORY_SEPARATOR.'frontend',
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
