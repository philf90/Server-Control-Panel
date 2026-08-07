<?php

declare(strict_types=1);

use App\Support\Subscriptions\Lifecycle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein Vorgang überlebt das Abonnement, von dem er handelte (docs/35 §3.3).
 *
 * **Diese Migration muss vor dem Purge laufen, und danach nie wieder.**
 * `operations.subscription_id` stand bis hierher auf `cascadeOnDelete`. Ein
 * hartes Löschen nähme das Vorgangsprotokoll mit — und die Rückfüllung des
 * Namens gibt es genau einmal: Sind die Zeilen in `subscriptions` fort, kann
 * keine spätere Migration sie rekonstruieren.
 *
 * **Folge, die man wissen muss:** Ein Vorgang ohne `subscription_id` fällt aus
 * der Mandantenklammer heraus — sie fragt `subscription_id in (…)`, und `NULL`
 * ist in keiner Liste. Verwaiste Vorgänge sind damit nur noch für den Admin
 * sichtbar. Das ist richtig, der Kunde hat das Abonnement nicht mehr; es ist
 * aber eine Verhaltensänderung und steht deshalb im CHANGELOG.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->string('subscription_name')->nullable()->after('subscription_id');
        });

        $this->carryTheNamesOver();

        $this->relaxTheForeignKey();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('operations', function (Blueprint $table) {
                $table->dropForeign(['subscription_id']);
                $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            });
        }

        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('subscription_name');
        });
    }

    /**
     * Die Namen rückwirkend abschreiben, solange die Zeilen noch da sind.
     *
     * **In PHP und nicht als `UPDATE … JOIN`.** Der naheliegende Einzeiler
     * läuft auf MariaDB und nicht auf SQLite, und die Tests laufen auf SQLite:
     * Eine Migration, die nur den Server kennt, bricht `php artisan test` —
     * ausgerechnet bei dem Umbau, dessen ganzer Zweck es ist, die Reservierung
     * eines Bezeichners nicht zu verlieren.
     *
     * Über die Abonnements und nicht über die Vorgänge, weil das eine Abfrage
     * je Abonnement kostet statt eine je Vorgang — und weil auf
     * `operations.subscription_id` ein Index liegt.
     */
    private function carryTheNamesOver(): void
    {
        DB::table('subscriptions')->orderBy('id')->chunkById(200, function ($subscriptions): void {
            foreach ($subscriptions as $subscription) {
                DB::table('operations')
                    ->where('subscription_id', $subscription->id)
                    ->update(['subscription_name' => $subscription->name]);
            }
        });
    }

    /**
     * `cascadeOnDelete` wird zu `nullOnDelete` — wo der Treiber das kann.
     *
     * **SQLite kann es nicht, und zwar überhaupt nicht.** Ein Fremdschlüssel
     * gehört dort zur Tabellendefinition; es gibt kein `ALTER TABLE … DROP
     * FOREIGN KEY`. Der Plan in docs/35 nennt zwei Stolpersteine für diesen
     * Schritt (`UPDATE … JOIN` und den Indexnamen) und diesen dritten nicht —
     * er ist beim Bauen herausgefallen.
     *
     * **Deshalb hängt das Verhalten nicht an dieser Zeile.** Wer ein Abonnement
     * zurückbaut, löst seine Vorgänge vorher ausdrücklich ab
     * ({@see Lifecycle::withdraw()}), und der Purge
     * in der nächsten Migration tut dasselbe. Beides läuft auf beiden Treibern
     * gleich. Was hier umgestellt wird, ist die Sicherung darunter: ein `DELETE`
     * von Hand auf der Konsole des Servers, das am Panel vorbeigeht.
     */
    private function relaxTheForeignKey(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('operations', function (Blueprint $table) {
            // `['subscription_id']` leitet den Indexnamen ab; trifft das nicht,
            // heisst er `operations_subscription_id_foreign`.
            $table->dropForeign(['subscription_id']);
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
        });
    }
};
