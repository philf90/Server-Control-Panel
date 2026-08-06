<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die Anmeldeadresse eines zurückgezogenen Kontos wird frei.
 *
 * **Der Fall, der das ausgelöst hat.** Ein Kunde wurde zurückgezogen; beim
 * Anlegen des nächsten mit derselben Anmeldeadresse passierte nichts. Die
 * Nummer *soll* vergeben bleiben — sie steht in Rechnungen, und zwei
 * Vertragspartner mit derselben Nummer wären ein Buchhaltungsproblem. Die
 * Adresse soll es nicht: Wer einen Kunden zurückzieht und ihn neu anlegt, hat
 * dieselbe Person vor sich.
 *
 * **Warum die Spalte und nicht die Regel.** `accounts.email` trägt einen
 * Unique-Index. Nur die Validierung zu lockern hiesse, die Prüfung dorthin zu
 * verschieben, wo sie als Duplikatfehler ankommt — aus einer Meldung im
 * Formular würde ein Serverfehler. Frei wird die Adresse erst, wenn die Zeile
 * sie nicht mehr belegt.
 *
 * **Warum `null` und kein Platzhalter.** Die Alternative wäre eine Adresse
 * gewesen, die niemand tippen kann — `zurueckgezogen-12@invalid` oder
 * dergleichen. Das ist genau die Sorte Zeichenkette, an der dieses Projekt
 * wiederholt verloren hat: Sie sieht aus wie eine Adresse, ist keine, und
 * jede Stelle, die sie anzeigt oder anschreibt, müsste das wissen. `null` sagt
 * dasselbe im Typ, und ein Unique-Index in MariaDB verträgt beliebig viele
 * davon.
 *
 * **Das Konto selbst bleibt.** Es trägt die Kennung, auf die das Prüfprotokoll
 * zeigt; ein Eintrag ohne Urheber ist als Protokoll wertlos. Was verloren
 * ginge — welche Adresse es war —, hält der Eintrag `customer.withdrawn` fest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    /**
     * Zurück geht es nur, solange keine Adresse freigegeben ist.
     *
     * Eine freigegebene Adresse liesse sich nicht zurückholen: Sie steht
     * nirgends mehr in der Spalte, und `NOT NULL` bräuchte einen Wert. Statt
     * hier einen zu erfinden, bleibt die Spalte in diesem Fall nullable — ein
     * Rückbau, der Daten erfindet, ist schlimmer als einer, der nicht läuft.
     */
    public function down(): void
    {
        if (DB::table('accounts')->whereNull('email')->exists()) {
            return;
        }

        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }
};
