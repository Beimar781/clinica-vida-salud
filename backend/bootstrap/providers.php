<?php

use App\Modules\GestionCitas\GestionCitasServiceProvider;
use App\Modules\GestionMedica\GestionMedicaServiceProvider;
use App\Modules\GestionPacientes\GestionPacientesServiceProvider;
use App\Modules\Seguridad\SeguridadServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SeguridadServiceProvider::class,
    GestionMedicaServiceProvider::class,
    GestionPacientesServiceProvider::class,
    GestionCitasServiceProvider::class,
];
