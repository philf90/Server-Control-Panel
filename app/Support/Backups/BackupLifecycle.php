<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Operation;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;

/**
 * Was aus einer Sicherung wird, nachdem der Agent geantwortet hat.
 *
 * **Die zweite Grenze** (`docs/20 §4`): Der Zustand folgt dem Agenten und nicht
 * dem Klick. Eine Zeile steht auf `pending`, bis `backup.create` zurückkommt —
 * und auf `failed`, wenn es mit einer Begründung zurückkommt.
 *
 * ## Warum die Zeile über `storage_name` gefunden wird und nicht über `subject_id`
 *
 * `OperationSubject` verlangt für jeden Fall einen **Ort**, und
 * `OperationOriginTest` hält, dass jeder genannte Pfad eine angemeldete
 * GET-Route ist. Die Seite der Sicherungen ist `docs/117 §6` Schritt 5 und gibt
 * es noch nicht; einen Gegenstand zu erklären, dessen Ort erfunden wäre, hiesse
 * genau die Zeile schreiben, die dieser Wächter verbietet.
 *
 * `storage_name` trägt die Verbindung dafür ohne Umweg: Er ist eindeutig, er
 * steht in der Antwort des Agenten, und er ist derselbe Name, unter dem die
 * Datei liegt.
 *
 * **Mit der Seite kommt der Gegenstand**, und dann ersetzt `subject_id` diese
 * Suche — nicht daneben. Zwei Wege von einem Vorgang zu seiner Zeile wären zwei
 * Fassungen derselben Frage.
 *
 * > **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung.**
 * > Deshalb steht hier, wann diese Stelle sich ändert, und nicht bloss, wie sie
 * > heute ist.
 */
final class BackupLifecycle implements AfterOperation
{
    public function __construct(private readonly Tenancy $tenancy) {}

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
                // Die Datei ist fort; die Zeile hat nichts mehr zu beschreiben.
                $backup->delete();

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
                // Die Datei liegt noch. Die Zeile beschreibt sie weiterhin
                // richtig — sie hat nur einen Fehlschlag daneben.
                $backup->forceFill(['last_error' => $this->reason($operation)])->save();

                return;
            }

            $backup->forceFill([
                'status' => BackupStatus::Failed,
                'last_error' => $this->reason($operation),
            ])->save();
        });
    }

    /** Die Zeile zu einem Vorgang — oder `null`, wenn sie jemand entfernt hat. */
    private function rowOf(Operation $operation): ?Backup
    {
        if (! in_array((string) ($operation->task ?? ''), self::handles(), true)) {
            return null;
        }

        $payload = is_array($operation->payload) ? $operation->payload : [];
        $storage = $payload['storage'] ?? null;

        if (! is_string($storage) || $storage === '') {
            // `backup.remove` ohne `storage` räumt das ganze Verzeichnis eines
            // Abonnements ab — beim Rückbau. Dann gibt es keine einzelne Zeile,
            // und die Zeilen selbst sind mit dem Abonnement schon fort.
            return null;
        }

        return $this->tenancy->withoutRestriction(
            fn (): ?Backup => Backup::query()->where('storage_name', $storage)->first(),
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
