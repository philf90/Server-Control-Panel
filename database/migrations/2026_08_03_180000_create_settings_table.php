<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einstellungen des Betreibers — Schlüssel und Wert, mehr nicht.
 *
 * **Warum eine Tabelle und keine Datei.** Die Zugangsdaten des SMTP-Relays
 * gehören dem Betreiber und nicht der Installation: Er trägt sie in der
 * Oberfläche ein, ändert sie dort, und sie müssen ein Update überleben. Eine
 * Datei unter `/etc/srvpanel` wäre dafür der falsche Ort — sie schreibt nur
 * der Agent, und dann liefe jede Änderung an einer Einstellung als privilegierte
 * Operation über den Socket. Für ein Passwort, das ohnehin verschlüsselt in der
 * Datenbank landet, ist das eine Schicht zu viel.
 *
 * **Der Wert ist verschlüsselt, nicht nur das Passwort darin.** Das Modell
 * castet die ganze Ablage mit `encrypted:array`. Ein Feld einzeln zu
 * verschlüsseln hiesse, bei jedem neuen Feld daran zu denken — und wer einmal
 * nicht daran denkt, legt einen Zugang im Klartext ab, ohne dass es auffällt.
 * Der Schlüssel dafür ist der `APP_KEY` aus `/etc/srvpanel/panel.env`, der
 * ausserhalb des Auslieferungsverzeichnisses liegt und deshalb ein Update
 * übersteht (siehe P0).
 *
 * **Kein `group`, kein `type`, keine Rechteverwaltung je Schlüssel.** Es gibt
 * genau einen Betreiber, und der darf alles. Eine Tabelle, die drei Spalten
 * mitbringt, weil sie irgendwann gebraucht werden könnten, hat drei Spalten,
 * die niemand füllt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
