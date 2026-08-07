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
 * **Und deshalb prüft diese Migration zweimal, bevor sie etwas anfasst.** Erst,
 * dass jeder Name eines Grabsteins im Verzeichnis steht — sonst wäre eine UID
 * wieder frei, und genau das soll nie passieren. Dann, dass an keinem Grabstein
 * ein Zertifikat hängt: `certificates.subscription_id` kaskadiert, die Zeilen
 * gingen mit, und die Dateien auf der Platte gehören dem Agenten und
 * verschwänden dadurch **nicht**. Das ist ein eigener Punkt im Rückbau und
 * nichts, was nebenbei in dieser Migration passieren darf.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->everyNameIsInTheLedger();
        $this->noCertificateHangsOnATombstone();

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

    /**
     * Hängt an einem Grabstein noch ein Zertifikat?
     *
     * `certificates.subscription_id` kaskadiert. Für ein zurückgebautes
     * Abonnement ist das richtig — nur gehören die Dateien dem Agenten und
     * bleiben liegen. docs/35 Schritt 0 lässt den Betreiber hier anhalten und
     * entscheiden; diese Prüfung sorgt dafür, dass er es auch dann tut, wenn er
     * den Vorflug übersprungen hat.
     */
    private function noCertificateHangsOnATombstone(): void
    {
        $count = DB::table('certificates')
            ->join('subscriptions', 'subscriptions.id', '=', 'certificates.subscription_id')
            ->whereNotNull('subscriptions.deleted_at')
            ->count();

        if ($count > 0) {
            throw new RuntimeException(implode("\n", [
                "An zurückgebauten Abonnements hängen noch {$count} Zertifikate.",
                '',
                'Sie gingen mit dem Purge verloren, die Dateien auf der Platte aber',
                'nicht — die gehören dem Agenten. Siehe docs/35, Schritt 0: Das',
                'gehört in den Rückbau und nicht in diese Migration.',
            ]));
        }
    }
};
