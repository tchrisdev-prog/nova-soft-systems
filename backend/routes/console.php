<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Abre cada mes de las mensualidades. Corre todos los días por si el servidor
// estuvo apagado el día 1; el comando no duplica periodos.
Schedule::command('nova:contracts-renew')->dailyAt('00:15')->timezone(config('nova.timezone', 'America/Bogota'))->withoutOverlapping();
