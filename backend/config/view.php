<?php

/*
| Las vistas Blade viven en la carpeta frontend/ de la raíz del repositorio (junto a backend/),
| no en resources/views. Las vistas de cada paquete se registran con su ServiceProvider
| (frontend/views/<paquete>/<caso-de-uso>/); aquí quedan el layout, los componentes y la portada.
*/

return [

    'paths' => [
        dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'views',
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
