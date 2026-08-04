<?php

declare(strict_types=1);

use App\Enums\OperationSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wovon ein Vorgang handelt.
 *
 * **Bis P2 reichte `subscription_id`.** Jeder Vorgang betraf entweder den
 * Server als Ganzes oder genau ein Abonnement, und der Lebenslauf konnte aus
 * `task` ablesen, was zu tun war. Mit P3 gibt es Vorgänge, die eine *Domain*
 * betreffen — und nach `web.site.apply` muss jemand wissen, **welche** Domain
 * jetzt auf „aktiv" steht.
 *
 * **Warum nicht `domain_id`.** Weil dieselbe Frage in P5 für Datenbanken, in
 * P6 für Cronjobs und in P7 für Zonen wiederkommt. Fünf Spalten, von denen bei
 * jedem Vorgang vier leer sind, wären fünf Gelegenheiten, die falsche zu
 * füllen.
 *
 * **Warum kein Klassenname in der Spalte.** Laravels polymorphe Beziehung
 * legt dort `App\Models\Domain` ab — eine Zeichenkette, die auf eine Klasse
 * zeigt, ohne dass etwas den Bezug prüft. Genau das Muster, das dieses Projekt
 * schon sechsmal eingeholt hat: Nach einer Umbenennung stehen in der Datenbank
 * Zeilen, die auf eine Klasse verweisen, die es nicht mehr gibt. Was hier
 * steht, ist der Wert einer Aufzählung ({@see OperationSubject}) —
 * und die beantwortet die Frage nach der Klasse im Quelltext, wo ein
 * Tippfehler beim Übersetzen auffällt.
 *
 * Beide Spalten sind `nullable`: Ein Vorgang des Betreibers — Agent anpingen,
 * nginx neu laden — handelt von nichts Einzelnem, und das ist eine Aussage
 * und keine Lücke.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('subject_type', 32)->nullable()->after('subscription_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
