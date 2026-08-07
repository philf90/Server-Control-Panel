<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Die Grabsteine gehen — ihre Namen stehen jetzt woanders (docs/35 §4, Schritt 3).
 *
 * **Das hier ist der unumkehrbare Teil.** `down()` legt `deleted_at` wieder an
 * und stellt keine einzige Zeile wieder her; es gibt keine Stelle, aus der sie
 * kämen. Der einzige Rückweg ist die Sicherung, die docs/35 Schritt 0 verlangt:
 *
 *     mariadb-dump srvpanel > /root/vor-35.sql
 *
 * **Und deshalb wird abgeglichen und geprüft, bevor etwas angefasst wird.**
 * Erst zieht {@see self::syncTheLedger()} jeden Namen nach, der noch fehlt —
 * der Grund dafür steht dort und ist der Fehlschlag vom 7. August selbst. Dann
 * prüft {@see self::everyNameIsInTheLedger()}, dass jeder Grabstein wirklich
 * eingetragen ist; sonst wäre eine UID wieder frei, und genau das soll nie
 * passieren. Heilen und danach nachsehen, nicht das eine statt des anderen.
 *
 * **Hier stand ein dritter Wächter, und der Zielserver hat ihn ausgelöst.** Er
 * brach ab, sobald an einem Grabstein noch ein Zertifikat hing — bei zwölf
 * Stück. Sein Grund war richtig: `certificates.subscription_id` kaskadierte,
 * die Zeilen gingen mit, und die Dateien gehören dem Agenten und blieben
 * liegen, samt privatem Schlüssel. Nur war Abbrechen die falsche Antwort, weil
 * es keinen Weg gab, es von Hand richtig zu machen. Seit der vorigen Migration
 * tragen die Zertifikate ihre Abschrift, werden hier abgelöst statt gelöscht,
 * und `srvpanel tls prune` räumt danach auf — je Ablageort und mit dem Agenten.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->syncTheLedger();
        $this->everyNameIsInTheLedger();

        /*
         * **Die Vorgänge ablösen, bevor die Zeile fällt — in PHP.**
         *
         * Auf MariaDB steht der Fremdschlüssel seit der vorigen Migration auf
         * `nullOnDelete` und täte das von selbst. Auf SQLite steht er weiter auf
         * `cascadeOnDelete`, weil sich ein Fremdschlüssel dort nicht ändern
         * lässt — und dann nähme das `DELETE` unten das Vorgangsprotokoll mit.
         * Ein Umbau, der auf dem Server etwas anderes tut als im Test, ist
         * genau die Sorte Fehler, die docs/35 §7 benennt.
         *
         * Der Name steht zu diesem Zeitpunkt schon in `subscription_name`.
         */
        $tombstones = DB::table('subscriptions')->whereNotNull('deleted_at')->pluck('id')->all();

        if ($tombstones !== []) {
            DB::table('operations')
                ->whereIn('subscription_id', $tombstones)
                ->update(['subscription_id' => null]);

            // **Und die Zertifikate genauso.** Hier stand bis zum 7. August ein
            // Wächter, der abgebrochen hat, sobald an einem Grabstein noch ein
            // Zertifikat hing — und auf dem Zielserver hat er das auch getan,
            // bei zwölf Stück. Er hatte recht: `cascadeOnDelete` hätte die
            // Zeilen genommen und die Dateien liegen lassen, samt privatem
            // Schlüssel und ohne irgendetwas, das noch auf sie zeigt.
            //
            // Abbrechen war trotzdem die falsche Antwort, weil es keinen Weg
            // gab, es von Hand richtig zu machen: Zwei Zertifikate können
            // denselben Ablageort haben, und auf dem Server war einer davon
            // zwischen einem toten und einem **lebenden** geteilt. Wer dort
            // `rm -rf` sagt, nimmt eine laufende Website mit.
            //
            // Die Zeilen werden deshalb abgelöst statt gelöscht — die
            // vorige Migration hat ihnen dafür die Abschrift gegeben. Aufgeräumt
            // wird danach mit `srvpanel tls prune`, das je Ablageort entscheidet
            // und den Agenten fragt.
            DB::table('certificates')
                ->whereIn('subscription_id', $tombstones)
                ->update(['subscription_id' => null]);
        }

        DB::table('subscriptions')->whereNotNull('deleted_at')->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }

    /**
     * `deleted_at` kommt zurück — die Zeilen nicht.
     *
     * Das steht hier, damit niemand diese Methode für einen Rückweg hält. Sie
     * stellt die Spalte wieder her, und danach ist die Tabelle so leer wie
     * vorher, nur mit einer Spalte mehr.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Das Verzeichnis nachziehen, bevor gelöscht wird.
     *
     * **Der Grund dafür ist ein Abbruch, und er hat eine Falle sichtbar
     * gemacht.** Am 7. August lief auf dem Zielserver Migration 1 und 2 durch
     * und diese hier nicht — die Datenbank stand halb migriert da, mit dem
     * gefüllten Verzeichnis und ohne Purge, während das Panel auf die vorige
     * Fassung zurückrollte. Diese Fassung schreibt beim Anlegen aber **nichts**
     * ins Verzeichnis; sie kennt es nicht.
     *
     * Ein Abonnement, das in diesem Zustand entsteht, fehlt damit im
     * Verzeichnis. Beim zweiten Anlauf wird Migration 1 übersprungen — sie gilt
     * als erledigt —, und der Name wäre für immer draussen. `nextSystemUser()`
     * vergliche danach gegen ein Verzeichnis, das ihn nicht kennt, und vergäbe
     * ihn ein zweites Mal. Genau der Fehler, gegen den dieser ganze Umbau
     * gebaut ist, eingeschleppt durch seinen eigenen Fehlschlag.
     *
     * Deshalb wird hier abgeglichen und nicht nur geprüft: **lebende
     * Abonnements und Grabsteine**, jeder Name, der noch fehlt. Was nachgetragen
     * wurde, steht danach in der Ausgabe — stillschweigend zu heilen wäre
     * dieselbe Sorte Nebenwirkung, die diesen Umbau nötig gemacht hat.
     */
    private function syncTheLedger(): void
    {
        $added = [];

        $rows = DB::table('subscriptions')
            ->whereNotNull('system_user')
            ->where('system_user', 'like', 'p%')
            ->orderBy('id')
            ->get(['system_user', 'name', 'created_at']);

        foreach ($rows as $row) {
            $number = (int) mb_substr((string) $row->system_user, 1);

            if ($number <= 0) {
                continue;
            }

            if (DB::table('system_users')->where('number', $number)->exists()) {
                continue;
            }

            DB::table('system_users')->insertOrIgnore([
                'number' => $number,
                'subscription' => $row->name === null ? null : (string) $row->name,
                'claimed_at' => $row->created_at ?? now(),
            ]);

            $added[] = (string) $row->system_user;
        }

        if ($added !== []) {
            echo '  Verzeichnis nachgezogen: '.implode(', ', $added)."\n";
        }
    }

    /**
     * Steht jeder Name eines Grabsteins im Verzeichnis?
     *
     * Ohne SQL-Dialekt und ohne `CAST(SUBSTRING(...))`: Der Ausdruck fiele auf
     * MariaDB und SQLite verschieden aus, und die Frage, die er beantwortet,
     * ist die einzige, an der dieser ganze Umbau hängt.
     */
    private function everyNameIsInTheLedger(): void
    {
        $missing = [];

        $rows = DB::table('subscriptions')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'system_user']);

        foreach ($rows as $row) {
            if ($row->system_user === null) {
                continue;
            }

            $number = (int) mb_substr((string) $row->system_user, 1);

            if (! DB::table('system_users')->where('number', $number)->exists()) {
                $missing[] = (string) $row->system_user;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(implode("\n", [
                'Diese Namen stehen nicht im Verzeichnis: '.implode(', ', $missing),
                '',
                'Gelöscht wird nichts, solange das so ist — die Zeilen sind der',
                'einzige Ort, an dem diese Namen noch stehen.',
            ]));
        }
    }
};
