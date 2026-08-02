<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Absichtlich leer.
 *
 * Ein Panel, das als root auf einem Server läuft, darf keinen Testbenutzer mit
 * bekanntem Namen und bekanntem Passwort mitbringen — auch nicht
 * auskommentiert, auch nicht „nur für die Entwicklung". Das Adminkonto
 * entsteht bei der Ersteinrichtung, und zwar genau eines.
 *
 * Testdaten gehören in Factories und damit in die Tests, nicht in einen
 * Seeder, den jemand versehentlich auf einem echten System ausführt.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void {}
}
