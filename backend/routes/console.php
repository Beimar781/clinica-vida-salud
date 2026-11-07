<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CU-22: Enviar recordatorios de citas. Una vez al día, a las 18:00 hora de La Paz, avisa por correo de las citas de mañana.
// La programación solo corre si algo ejecuta `php artisan schedule:run` cada minuto; en Railway basta un servicio cron aparte que
// ejecute `php artisan citas:enviar-recordatorios` una vez al día (ver el README).
Schedule::command('citas:enviar-recordatorios')->dailyAt('18:00')->timezone('America/La_Paz')->withoutOverlapping();
