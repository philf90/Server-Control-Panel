<?php

declare(strict_types=1);

use App\Console\Commands\Access;
use App\Models\AuditEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Das Protokoll behält den Namen eines gelöschten Kontos (`docs/901 §3.1`).
 *
 * **Diese Migration läuft vor dem Löschweg, und der Nachtrag gibt es genau
 * einmal.** `audit_events.account_id` und `operations.account_id` stehen seit
 * P1 auf `nullOnDelete()` — der Eintrag bleibt also stehen, der Handelnde
 * nicht. Solange das so war, ist Sperren die ehrlichere Antwort gewesen
 * (`docs/82 §1.1`); mit der Abschrift daneben ist es das nicht mehr.
 *
 * > **Löschen und Vergessen sind zwei Dinge. Die Zeile darf verschwinden; was
 * > sie getan hat, darf es nicht.**
 *
 * **Warum die Abschrift und kein Sammelname.** Der naheliegende Entwurf wäre
 * gewesen, die Zeilen eines gelöschten Kontos beim Löschen mit „gelöschter
 * Benutzer" zu versehen. Das geht nicht, weil `account_id = NULL` hier **schon
 * eine Bedeutung trägt**: {@see Access} schreibt seinen
 * Eintrag ohne Konto, und `Operations::dispatch()` tut dasselbe für jede
 * Automatik. Ein Sammelname beschriftete damit jeden Cron-Lauf als gelöschten
 * Benutzer.
 *
 * > **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen —
 * > die beiden Fälle sehen danach gleich aus.**
 *
 * **Und die Fremdschlüssel bleiben, wie sie sind.** Das Vorbild
 * `2026_08_07_100100_operations_survive_a_deleted_subscription` musste zusätzlich
 * `cascadeOnDelete` lockern und ist dabei über SQLite gestolpert, das gar kein
 * `ALTER TABLE … DROP FOREIGN KEY` kennt. Hier steht schon überall
 * `nullOnDelete`; diese Migration fasst keinen Fremdschlüssel an und läuft
 * deshalb auf beiden Treibern gleich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('account_name')->nullable()->after('account_id');
        });

        Schema::table('operations', function (Blueprint $table): void {
            $table->string('account_name')->nullable()->after('account_id');
        });

        $this->carryTheNamesOver();
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('account_name');
        });

        Schema::table('operations', function (Blueprint $table): void {
            $table->dropColumn('account_name');
        });
    }

    /**
     * Die Namen rückwirkend abschreiben, solange die Konten noch da sind.
     *
     * **In PHP und nicht als `UPDATE … JOIN`.** Der naheliegende Einzeiler
     * läuft auf MariaDB und nicht auf SQLite, und die Tests laufen auf SQLite —
     * dieselbe Lehre wie beim Vorbild aus `docs/35`.
     *
     * **Über die Konten und nicht über die Protokollzeilen**, weil das eine
     * Abfrage je Konto kostet statt eine je Zeile. Ob auf `account_id` ein Index
     * liegt, ist ungemessen (`docs/901 §3.3`): `constrained()` legt keinen an,
     * InnoDB tut es für jeden Fremdschlüssel von sich aus, SQLite nicht. Der
     * Grund oben trägt ohne diese Annahme.
     *
     * **Über alle Konten und nicht nur über Adminkonten**, obwohl heute nur
     * Adminkonten löschbar werden. `docs/901 §3.3` sah die Einschränkung vor;
     * sie hätte einen dritten Zustand geschaffen — Kennung gesetzt, Abschrift
     * leer —, den keine Anzeige braucht. Die Regel lautet einfach: **Wo eine
     * Kennung steht, steht auch ein Name.**
     *
     * **Was der Nachtrag nicht kann:** Er schreibt den **heutigen** Namen auf
     * alle alten Zeilen, auch auf die, die unter einem früheren entstanden
     * sind. Der frühere steht nirgends; ab hier hält
     * {@see AuditEvent::booted()} fest, was zum Zeitpunkt der
     * Handlung galt.
     *
     * > **Ein Nachtrag kann nur abschreiben, was heute dasteht — nicht, was
     * > damals galt.**
     */
    private function carryTheNamesOver(): void
    {
        DB::table('accounts')->orderBy('id')->chunkById(200, function ($accounts): void {
            foreach ($accounts as $account) {
                foreach (['audit_events', 'operations'] as $table) {
                    DB::table($table)
                        ->where('account_id', $account->id)
                        ->update(['account_name' => $account->name]);
                }
            }
        });
    }
};
