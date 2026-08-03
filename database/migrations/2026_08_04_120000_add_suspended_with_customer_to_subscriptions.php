<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wer hat dieses Abonnement gesperrt — der Betreiber oder die Kundensperre?
 *
 * **Ohne diese Spalte gibt es keine Freigabe, die stimmt.** Sperrt der
 * Betreiber einen Kunden, gehen dessen Abonnements mit; gibt er ihn wieder
 * frei, sollen sie zurückkommen. Nur eben nicht alle: Ein Abonnement, das
 * schon vorher einzeln gesperrt war — wegen Missbrauch, wegen eines
 * Umzugs —, war nie Teil der Kundensperre und darf durch ihre Aufhebung nicht
 * wieder erreichbar werden. Am Zustand allein ist das nicht zu erkennen:
 * „gesperrt" sieht in beiden Fällen gleich aus.
 *
 * Die Spalte hält deshalb fest, welche Sperre zu welcher gehört. Sie ist keine
 * zweite Zustandsspalte: Ob ein Abonnement gesperrt ist, steht weiterhin in
 * `status`. Hier steht nur, ob es das *wegen des Kunden* ist.
 *
 * **Gesetzt wird sie beim Auslösen und nicht nach dem Vorgang.** Das ist die
 * Ausnahme von „der Zustand folgt dem System" (docs/26 §2), und sie ist keine:
 * Der Zustand — gesperrt oder nicht — folgt weiterhin dem Agenten. Diese
 * Spalte ist kein Zustand, sondern die Zugehörigkeit einer Absicht, und die
 * steht schon fest, bevor der erste Vorgang läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('suspended_with_customer')->default(false)->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('suspended_with_customer');
        });
    }
};
