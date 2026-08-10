<?php

declare(strict_types=1);

namespace App\Support\Databases;

use App\Enums\DatabaseEngine;
use App\Enums\DumpStatus;
use App\Models\DatabaseDump;
use App\Models\Operation;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;
use SrvPanel\Agent\Db\Dump;

/**
 * Was nach einem Vorgang an einer Sicherung nachzuziehen ist — für beide Systeme.
 *
 * **Eine Klasse je Gegenstand, nicht je System** (`docs/38 §21`, Entscheidung
 * 10). Bis Schritt 6 standen diese vier Aufgaben in {@see DbLifecycle}, und das
 * war richtig, solange es ein Datenbanksystem gab. Was hier geschieht, hängt an
 * keinem: Die Grösse kommt aus der Antwort, die Zeile geht auf `Ready` oder
 * `Failed`, beim Entfernen wird sie gelöscht, beim Zurückspielen nichts getan.
 * Nur die **Namen** der Aufgaben unterscheiden sich, und die stehen in
 * {@see self::tasks()}.
 *
 * Die beiden Alternativen sind vorgelegt und verworfen worden. Dieselbe Logik
 * ein zweites Mal in {@see PgLifecycle} wäre die zweite Fassung, und die zweite
 * ist die, die veraltet. Sie in `DbLifecycle` für beide Systeme laufen zu
 * lassen hiesse, dass der Name lügt — und dass die `engine`-Einschränkungen
 * dort eine unsichtbare Ausnahme bekommen.
 *
 * **Der Anlass ist derselbe wie bei {@see Dump::requireGzip()}**
 * und den drei anderen Helfern, die in Schritt 6 aus `DbDumpImport` nach `Dump`
 * gezogen sind: Eine Sicherung ist eine Datei und eine Zeile, und beide wissen
 * nichts von MariaDB oder PostgreSQL.
 */
final class DumpLifecycle implements AfterOperation
{
    /**
     * Die vier Aufgaben eines Systems — als `match` und nicht als Tabelle.
     *
     * **Der erste Entwurf war eine Konstante, die beide Systeme als
     * Zeichenkette benannte, und `DatabaseEngineTest` hat sie zurückgewiesen.**
     * Die
     * Regel ist richtig und hat schon in Lauf 451 zugebissen: Die Werte eines
     * Systems stehen im Enum und nirgends sonst — eine Zeichenkette daneben ist
     * eine zweite Fassung, die beim Umbenennen stehen bleibt.
     *
     * Der `match` ist vollständig ohne `default`, wie {@see Databases::driver()}:
     * Käme ein drittes System hinzu, meldete es der Übersetzer hier und nicht
     * ein Kunde später.
     *
     * Zwei Namen fallen dabei auf, und beide mit Grund: `restore` heisst in
     * PostgreSQL `pg.restore` und nicht `pg.dump.restore`, weil das Gegenstück
     * in P5 auch `db.restore` heisst; und **`remove` ist für beide dieselbe
     * Aufgabe** — `db.dump.remove` entfernt eine Datei, und eine Datei hat kein
     * Datenbanksystem (`docs/38 §13`).
     *
     * @return array<string, string>
     */
    public static function tasks(DatabaseEngine $engine): array
    {
        return match ($engine) {
            DatabaseEngine::MariaDb => [
                'create' => 'db.dump.create',
                'import' => 'db.dump.import',
                'restore' => 'db.restore',
                'remove' => 'db.dump.remove',
            ],
            DatabaseEngine::Postgres => [
                'create' => 'pg.dump.create',
                'import' => 'pg.dump.import',
                'restore' => 'pg.restore',
                'remove' => 'db.dump.remove',
            ],
        };
    }

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Die Aufgabe zu einer Handlung — die eine Stelle, die den Namen kennt.
     *
     * {@see Dumps} fragt hier, und nicht der Treiber: Welche Aufgabe eine
     * Sicherung auslöst, gehört zu dem, was mit ihr geschieht, und nicht dazu,
     * wie eine Datenbank angelegt wird.
     */
    public static function task(DatabaseEngine $engine, string $action): string
    {
        return self::tasks($engine)[$action];
    }

    /** @return list<string> */
    public static function handles(): array
    {
        $tasks = [];

        foreach (DatabaseEngine::cases() as $engine) {
            foreach (self::tasks($engine) as $task) {
                $tasks[$task] = true;
            }
        }

        return array_keys($tasks);
    }

    /**
     * Ein gescheitertes Sichern oder Übernehmen — die Zeile sagt, warum.
     *
     * **Der Grund kommt aus dem Vorgang und nicht aus einer Vermutung.**
     * `message` trägt die Begründung des Agenten, wortgleich mit dem, was auf
     * der Vorgangsseite steht — zwei Formulierungen desselben Fehlschlags wären
     * zwei Auskünfte, und die zweite ist die, die veraltet.
     *
     * **Ein gescheitertes Zurückspielen ändert an der Sicherung nichts.** Sie
     * war vorher fertig und ist es danach; was schiefging, betrifft die
     * Datenbank. Und ein gescheitertes Entfernen lässt die Zeile stehen, damit
     * jemand einen zweiten Anlauf nehmen kann.
     */
    public function afterFailure(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if (! $this->isOneOf($task, ['create', 'import'])) {
            return;
        }

        // Die Datei zuerst: Sie liegt unabhängig davon in der Übergabe, ob es
        // die Zeile noch gibt — und ein Rückbau zwischendurch soll nicht dazu
        // führen, dass ein halbes Gigabyte stehenbleibt.
        if (str_ends_with($task, '.dump.import')) {
            Staging::forget($operation->payload['source'] ?? null);
        }

        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $dump = DatabaseDump::query()->find($operation->subject_id);

            if ($dump === null) {
                return;
            }

            $dump->forceFill([
                'status' => DumpStatus::Failed,
                'last_error' => $operation->message,
            ])->save();
        });
    }

    /**
     * Die Sicherung ist fertig — jetzt erst steht es auch im Bestand.
     *
     * **Die Grösse kommt aus der Antwort des Agenten und nicht aus einer
     * Schätzung.** Er hat die Datei geschrieben; das Panel hat sie nie gesehen.
     * Dieselbe Entscheidung wie in `CertificateRecord`: Was gilt, steht in der
     * Antwort.
     *
     * **Und ein Zurückspielen ändert an der Sicherung nichts.** Sie war vorher
     * fertig und ist es danach — was sich geändert hat, ist die *Datenbank*.
     * Ein Zustand „eingespielt" an der Sicherung wäre eine Aussage über etwas
     * anderes als über sie.
     */
    public function afterSuccess(Operation $operation): void
    {
        $task = (string) ($operation->task ?? '');

        if ($this->isOneOf($task, ['restore'])) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation, $task): void {
            $dump = DatabaseDump::query()->find($operation->subject_id);

            if ($dump === null) {
                $this->removedAllDumps($operation, $task);

                return;
            }

            if ($this->isOneOf($task, ['remove'])) {
                $dump->delete();

                return;
            }

            $bytes = $operation->result['bytes'] ?? null;

            $dump->forceFill([
                'status' => DumpStatus::Ready,
                'bytes' => is_numeric($bytes) ? (int) $bytes : null,
                'last_error' => null,
            ])->save();
        });
    }

    /**
     * Ist diese Aufgabe eine der genannten Handlungen — in irgendeinem System?
     *
     * @param  list<string>  $actions
     */
    private function isOneOf(string $task, array $actions): bool
    {
        foreach (DatabaseEngine::cases() as $engine) {
            foreach ($actions as $action) {
                if (self::tasks($engine)[$action] === $task) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Der Rückbau eines ganzen Abonnements — ein Vorgang ohne Gegenstand.
     *
     * **Hier stand ein Kommentar, der das Gegenteil versprach.** „Dort
     * verschwinden die Zeilen mit dem Abonnement" — sie verschwinden nicht:
     * `database_dumps.subscription_id` steht mit Absicht auf `nullOnDelete`
     * (`docs/36 §7.2`), damit eine Sicherung ihre Datenbank überlebt. Die Zeile
     * ist der Wegweiser zu einer Datei, auf die sonst nichts mehr zeigt, und
     * genau davon lebt `srvpanel db --prune`.
     *
     * **Nach einem erfolgreichen Rückbau ist die Datei aber fort.** Am
     * 8. August 2026 gemessen: `srvpanel db` zählte danach drei Sicherungen,
     * während zwei auf der Platte lagen (`docs/36 §22.3r`). Ein Melder, der
     * nach jedem sauberen Rückbau Alarm gibt, wird bald gelesen wie ein
     * Rauschen.
     *
     * **Und hier fällt mit Schritt 6 eine Einschränkung weg, die es geben
     * musste.** Bis hierher stand `->where('engine', MariaDb)` in dieser
     * Abfrage: `db.dump.remove` gehörte `DbLifecycle`, und ohne die Zeile hätte
     * es die PostgreSQL-Sicherungen desselben Abonnements mitgelöscht — Zeilen
     * für Dateien, die noch liegen. Seit `db.dump.remove` **beide** Systeme
     * bedient und der Agent beim Rückbau das ganze Verzeichnis entfernt, wäre
     * dieselbe Zeile der Fehler: Sie liesse die PostgreSQL-Zeilen stehen, und
     * `srvpanel db` meldete sie als verwaist.
     */
    private function removedAllDumps(Operation $operation, string $task): void
    {
        if (! $this->isOneOf($task, ['remove']) || $operation->subscription_id === null) {
            return;
        }

        DatabaseDump::query()->where('subscription_id', $operation->subscription_id)->delete();
    }
}
