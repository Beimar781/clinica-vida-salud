<?php

return [

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
