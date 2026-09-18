<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\BackupStatus;
use App\Enums\OperationSubject;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;

/**
 * Was aus einer Sicherung wird, nachdem der Agent geantwortet hat.
 *
 * **Die zweite Grenze** (`docs/20 §4`): Der Zustand folgt dem Agenten und nicht
 * dem Klick. Eine Zeile steht auf `pending`, bis `backup.create` zurückkommt —
 * und auf `failed`, wenn es mit einer Begründung zurückkommt.
 *
 * ## Die Zeile kommt über `subject_id` — seit es die Seite gibt
 *
 * In Schritt 3+4 stand hier eine Suche über `storage_name`, weil
 * `OperationSubject` für jeden Fall einen **Ort** verlangt und
 * `OperationOriginTest` hält, dass jeder genannte Pfad eine angemeldete
 * GET-Route ist. Die Seite gab es noch nicht.
 *
 * **Mit Schritt 5 gibt es sie, und die Suche ist fort** — nicht daneben stehen
 * geblieben. Zwei Wege von einem Vorgang zu seiner Zeile wären zwei Fassungen
 * derselben Frage, und die zweite ist die, die veraltet.
 *
 * > **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung** —
 * > deshalb stand im Kopf dieser Klasse, wann sie sich ändert, und nicht bloss,
 * > wie sie damals war.
 */
final class BackupLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Backups $backups,
    ) {}

    /**
     * Die Aufgaben, die dieser Lebenslauf beantwortet.
     *
     * `AgentOperationReachTest` liest sie: Eine Operation des Agenten, die in
     * keiner solchen Liste steht, gilt als unbenutzt — und das ist sie dann
     * auch. Code, der als root läuft und zu dem kein Weg führt, ist
     * Angriffsfläche ohne Nutzen.
     *
     * @return list<string>
     */
    public static function handles(): array
    {
        return ['backup.create', 'backup.remove'];
    }

    public function afterSuccess(Operation $operation): void
    {
        $backup = $this->rowOf($operation);

        if ($backup === null) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation, $backup): void {
            if (($operation->task ?? '') === 'backup.remove') {
                /*
                 * **Abgelesen, bevor gelöscht wird.** Nach `delete()` sagt die
                 * Zeile über ihr Abonnement nichts mehr — und `subscription_id`
                 * ist die Angabe, an der hängt, ob es das Abonnement noch gibt.
                 */
                $verwaist = $backup->subscription_id === null;
                $name = (string) $backup->subscription_name;

                // Die Datei ist fort; die Zeile hat nichts mehr zu beschreiben.
                $backup->delete();

                if ($verwaist && $this->abandoned($name)) {
                    /*
                     * **Die Kennung kommt vom Anlass und nicht aus dem
                     * Request.** Hier läuft der Arbeiter, und dort ist niemand
                     * angemeldet. Ohne sie stünde das Abräumen als Automatik
                     * da, obwohl es die Folge eines Klicks ist — und
                     * `account_id = NULL` bedeutet seit `docs/901` genau das
                     * andere. Dasselbe Muster wie in `CertificateLifecycle`.
                     */
                    $this->backups->removeDirectory($name, $operation->account_id);
                }

                return;
            }

            $result = is_array($operation->result) ? $operation->result : [];

            $backup->forceFill([
                'status' => BackupStatus::Ready,
                'bytes' => $this->number($result['bytes'] ?? null),
                'files' => $this->number($result['files'] ?? null),
                'entries' => $this->number($result['entries'] ?? null),
                'databases' => $this->number($result['databases'] ?? null),
                'last_error' => null,
            ])->save();
        });
    }

    /**
     * Ein gescheiterter Lauf — die Zeile sagt, warum.
     *
     * **Der Grund kommt aus dem Vorgang und nicht aus einer Vermutung.**
     * `message` trägt die Begründung des Agenten, wortgleich mit dem, was auf
     * der Vorgangsseite steht. Zwei Formulierungen desselben Fehlschlags wären
     * zwei Auskünfte, und die zweite ist die, die veraltet.
     *
     * **Die Zeile bleibt stehen.** Eine gescheiterte Sicherung zu löschen
     * hiesse, den Versuch verschwinden zu lassen — und dann sieht „es gibt
     * keine Sicherung von gestern" so aus wie „es hat nie jemand versucht".
     */
    public function afterFailure(Operation $operation): void
    {
        $backup = $this->rowOf($operation);

        if ($backup === null) {
            return;
        }

        $this->tenancy->withoutRestriction(function () use ($operation, $backup): void {
            if (($operation->task ?? '') === 'backup.remove') {
                /*
                 * Die Datei liegt noch. Die Zeile beschreibt sie weiterhin
                 * richtig — sie hat nur einen Fehlschlag daneben.
                 *
                 * **Und sie geht aus `Removing` zurück auf `Ready`**, seit
                 * {@see Backups::remove()} den Zustand setzt. Ohne diese Zeile
                 * bliebe sie für immer auf „wird entfernt": Die Seite fragte
                 * endlos nach, der Knopf wäre fort, und ein zweiter Versuch
                 * ginge nicht mehr — ein Zustand, aus dem kein Weg führt.
                 *
                 * > **Ein Zustand, der nur beim Gelingen wieder verlassen
                 * > wird, ist beim Fehlschlag eine Sackgasse.**
                 */
                $backup->forceFill([
                    'status' => BackupStatus::Ready,
                    'last_error' => $this->reason($operation),
                ])->save();

                return;
            }

            $backup->forceFill([
                'status' => BackupStatus::Failed,
                'last_error' => $this->reason($operation),
            ])->save();
        });
    }

    /**
     * Rührt noch irgendjemand dieses Verzeichnis an?
     *
     * **Dieselben drei Bedingungen wie die Bestandsdiagnose** — die vierte, ob
     * das Verzeichnis wirklich leer ist, beantwortet der Agent selbst, weil nur
     * er hinsehen kann: `Store::removeDirectory()` ruft `rmdir(2)` und
     * scheitert an allem, was noch darin liegt.
     *
     * - **Keine Zeile mehr**, auch keine auf `pending`. Eine Sicherung, die
     *   gerade geschrieben wird, hat ihre Datei schon; ihr Verzeichnis
     *   abzuräumen hiesse, einen laufenden Vorgang zu treffen.
     *
     *   > **Ein Rest ist, was niemand mehr nennt — nicht, was noch niemand
     *   > fertig genannt hat.**
     *
     * - **Kein lebendes Abonnement dieses Namens.** Ein Name kann nach einem
     *   Rückbau wieder vergeben werden; dann gehört das Verzeichnis dem neuen
     *   Abonnement, und die nächste Sicherung füllt es.
     *
     * **Ohne Namen ist die Frage nicht zu stellen** — und `false` ist die
     * Antwort, die nichts anfasst. Ein Rückfall auf die leere Zeichenkette
     * zeigte auf die Wurzel der Sicherungen; `Backups::dispatch()` weist ihn
     * deshalb laut ab, und hier kommt er gar nicht erst hin.
     *
     * > **Wenn eine Zuordnung schiefgehen kann, entscheidet die Richtung, in
     * > die sie schiefgeht.**
     *
     * Der Aufruf steht **innerhalb** der gelösten Mandantenklammer von
     * {@see self::afterSuccess()}; ein Nachtlauf hat kein angemeldetes Konto,
     * und geklammert gäben beide Abfragen wortlos leer zurück — also „räum ab"
     * für jedes Verzeichnis dieses Servers.
     */
    private function abandoned(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        if (Backup::query()->where('subscription_name', $name)->exists()) {
            return false;
        }

        return ! Subscription::query()->where('name', $name)->exists();
    }

    /**
     * Die Zeile zu einem Vorgang — oder `null`, wenn es keine gibt.
     *
     * `null` heisst hier zweierlei, und beides ist in Ordnung: `backup.remove`
     * ohne Zeile räumt beim Rückbau das ganze Verzeichnis eines Abonnements ab,
     * und eine Zeile, die jemand inzwischen entfernt hat, ist fort.
     */
    private function rowOf(Operation $operation): ?Backup
    {
        if (! in_array((string) ($operation->task ?? ''), self::handles(), true)) {
            return null;
        }

        if ($operation->subject_type !== OperationSubject::Backup->value) {
            return null;
        }

        return $this->tenancy->withoutRestriction(
            fn (): ?Backup => Backup::query()->find($operation->subject_id),
        );
    }

    /** Die Begründung des Agenten, auf die Spaltenbreite gekürzt. */
    private function reason(Operation $operation): string
    {
        $message = (string) ($operation->message ?? '');

        if ($message === '') {
            $message = 'Der Vorgang ist ohne Begründung gescheitert.';
        }

        // **255 Zeichen, und die Kürzung steht hier mit Absicht.** In P5 ist
        // genau daran ein Abnahmelauf gescheitert: Die Begründung passte nicht
        // in ihre Spalte, die `PDOException` riss den `catch`-Zweig mit, und
        // der Vorgang bekam „vermutlich Zeitüberschreitung" nach einer Sekunde.
        //
        // > Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.
        return mb_substr($message, 0, 255);
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
