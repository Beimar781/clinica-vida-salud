<?php

return [

    /*
    | Carpeta del frontend (vistas Blade y assets CSS/JS/imágenes), hermana de backend/.
    */
    'frontend' => dirname(base_path()).DIRECTORY_SEPARATOR.'frontend',

    /*
    | Contraseña del administrador inicial (la usa el seeder). Se toma de .env (ADMIN_PASSWORD).
    */
    'admin_password' => env('ADMIN_PASSWORD'),

    /*
    | Contraseña de las cuentas de demostración (recepcionista, médicos y pacientes).
    | Está documentada en el README; cámbiela en cualquier instalación real.
    */
    'demo_password' => 'Demo1234*',

];
