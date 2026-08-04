<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `subscriptions.main_domain` verliert seine Eindeutigkeit.
 *
 * **Der Fehler auf dem Zielserver, wörtlich:**
 *
 *     Duplicate entry 'abnahme-web-2.invalid'
 *     for key 'subscriptions_main_domain_unique'
 *
 * Der zweite Abnahmelauf konnte kein Abonnement anlegen, dessen Hauptdomain
 * schon einmal existiert hatte — obwohl beim Rückbau des ersten Laufs jede
 * Domainzeile hart gelöscht worden war.
 *
 * **Zwei Uniques, die gleich aussehen und es nicht sind.** In P1 wurden
 * `system_user` und `main_domain` nebeneinander als eindeutig angelegt, mit
 * derselben Begründung: „Genau ein Systembenutzer, genau eine Hauptdomain."
 * Für den Systembenutzer ist das richtig und muss so bleiben — ein weich
 * gelöschtes Abonnement **verbraucht** seinen `p1000`, weil die UID sonst
 * wiederverwendet würde und Dateien im Dateisystem plötzlich jemand anderem
 * gehörten.
 *
 * Für die Hauptdomain gilt das Gegenteil, und zwar ausdrücklich: Domains haben
 * seit P3 keine weiche Löschung, damit ein Name nach dem Löschen **wieder
 * vergeben werden kann**. Die Abschrift muss derselben Regel folgen wie das,
 * was sie abschreibt — sonst hält eine Kopie einen Namen fest, den das
 * Original längst freigegeben hat.
 *
 * **Und es war ohnehin eine zweite Wahrheit.** Die Zuständigkeit für „diesen
 * Domainnamen gibt es auf diesem Server einmal" liegt bei `domains.name`. Was
 * dort eindeutig ist, ist es in der Abschrift von selbst; ein zweiter Index
 * fügt keine Regel hinzu, sondern eine Stelle, an der dieselbe Regel anders
 * ausfällt.
 *
 * Der Index bleibt als gewöhnlicher: Die Kundenübersicht sucht darüber.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Zuerst die Altlast. Jedes weich gelöschte Abonnement hält seine
         * Abschrift bis heute fest — der Rückbau hat sie nie geleert, siehe
         * `Lifecycle::withdraw()`. Ohne diese Zeile bliebe der Name auf dem
         * Zielserver belegt, obwohl der Index fällt: Die Anzeige zeigte für
         * ein gekündigtes Abonnement eine Domain, die es nicht mehr gibt.
         */
        DB::table('subscriptions')
            ->whereNotNull('deleted_at')
            ->update(['main_domain' => null]);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropUnique(['main_domain']);
            $table->index('main_domain');
        });
    }

    /**
     * Der Rückweg stellt den Index wieder her.
     *
     * Er kann scheitern, und das ist ehrlicher als eine stille Reparatur:
     * Sind inzwischen zwei Abonnements mit derselben Hauptdomain entstanden —
     * eines gekündigt, eines aktiv —, lässt sich der eindeutige Index nicht
     * mehr anlegen. Wer zurück muss, sieht dann, was im Weg steht.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['main_domain']);
            $table->unique('main_domain');
        });
    }
};
