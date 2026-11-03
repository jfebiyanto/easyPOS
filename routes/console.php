<?php

use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tarik data baru/berubah dari SAP B1; interval sebenarnya diatur di konfigurasi (menit).
Schedule::command('sap:sync')->everyMinute()->withoutOverlapping();

// Jalankan scheduler ikut `composer run dev` (di produksi pakai cron: schedule:run tiap menit).
DevCommands::artisan('schedule:work', 'scheduler');
