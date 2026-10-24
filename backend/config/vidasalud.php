<?php

return [

    /*
    | Carpeta del frontend (vistas Blade y assets CSS/JS/imágenes), hermana de backend/.
    */
    'frontend' => dirname(base_path()).DIRECTORY_SEPARATOR.'frontend',

    /*
    | Contraseña del administrador inicial (opcional). Si ADMIN_PASSWORD está definida en .env o en las variables
    | del servicio, el administrador la usa; si no, usa la contraseña de demostración (demo_password).
    */
    'admin_password' => env('ADMIN_PASSWORD'),

    /*
    | Correo del administrador inicial (opcional). Por defecto el del seeder; en un despliegue real conviene
    | poner uno al que se tenga acceso, porque a ese correo llega el enlace de «¿Olvidaste tu contraseña?».
    */
    'admin_correo' => env('ADMIN_CORREO', 'admin@vidasalud.test'),

    /*
    | ¿Se cargan los datos de demostración (usuarios, médicos con horarios, pacientes y citas)?
    | Con SEMBRAR_DEMO=true sí; con SEMBRAR_DEMO=false solo se crean los roles, las especialidades base y el
    | administrador. Si no se define: sí en local y en pruebas (el README sigue funcionando con
    | `php artisan migrate --seed`) y no en cualquier otro entorno (producción).
    */
    'sembrar_demo' => env('SEMBRAR_DEMO', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    /*
    | Contraseña de las cuentas de demostración (administrador, recepcionista, médicos y pacientes).
    | Está documentada en el README; cámbiela en cualquier instalación real.
    */
    'demo_password' => 'Demo1234*',

];
