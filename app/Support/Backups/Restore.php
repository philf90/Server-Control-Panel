<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\OperationStatus;
use App\Enums\OperationSubject;
use App\Enums\SubscriptionStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Databases\Databases;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Backup\Manifest;
use ZipArchive;

/**
 * Der Weg zurück: aus einer Sicherung wird wieder ein Abonnement (`docs/117 §6`
 * Schritte 7 und 8).
 *
 * ## Form A, und was das kostet
 *
 * Der Betreiber hat am 16. September 2026 **Form A** entschieden (`docs/117
 * §3`): Die Wiederherstellung holt keine Reservierung zurück, sondern nimmt
 * über {@see Lifecycle::claim()} die nächste freie Nummer. `docs/35` bleibt
 * unberührt — kein neuer Weg, keine neue Ausnahme.
 *
 * Der Preis steht in derselben Entscheidung und ist **dreiteilig**: neuer
 * Systembenutzer (also ein neuer SFTP-Benutzername), neues `db_prefix` (also
 * andere Datenbanknamen, die in `wp-config.php` stehen) und neue
 * Datenbankpasswörter. Alle drei nennt die Seite nach dem Lauf; eine
 * Wiederherstellung, die schweigt, lässt den Kunden vor einem Auftritt stehen,
 * der nicht läuft, ohne ihm zu sagen warum.
 *
 * ## Zwei Vorgänge und nicht einer
 *
 * `subscription.provision` legt Unix-Konto, Verzeichnisschema und Quota an;
 * `backup.restore` packt hinein. **Keine Operation dieses Agenten ruft eine
 * andere** — die Reihenfolge stellt das Panel her, wie schon bei
 * {@see Backups::create()}. Sie trägt, weil `queue:work` einspurig ist und die
 * Datenbank-Warteschlange FIFO liefert.
 *
 * Und sie trägt **laut**: Läuft die Bereitstellung nicht durch, findet
 * `backup.restore` kein Verzeichnis und bricht mit genau diesem Satz ab. Ein
 * Auspacken, das sich sein Ziel selbst anlegt, wäre die halbe Bereitstellung in
 * einer zweiten Fassung — ohne Konto, ohne Quota, ohne Schema.
 *
 * ## Das Verzeichnis liest das Panel selbst
 *
 * Es steht **im Archiv** und reist nicht über die Leitung (`docs/117 §4`, M4:
 * bei rund 14 000 Einträgen wäre `Connection::CONTENT_MAX` erreicht). Die
 * Sicherung liegt `root:srvpanel 0640` in einem Verzeichnis `0710
 * root:srvpanel` — das Panel läuft als `srvpanel` und kommt über die Gruppe
 * heran, genau wie beim Herunterladen.
 */
final class Restore
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Lifecycle $lifecycle,
    ) {}

    /**
     * Das Verzeichnis einer Sicherung — gelesen, nicht erfragt.
     *
     * @return array{format: int, panel: string, created_at: string, subscription: string, system_user: int|null, db_prefix: string|null, entries: list<array{path: string, kind: string, mode: string, target?: string}>, description: array<string,mixed>}
     */
    public function manifest(Backup $backup): array
    {
        $pfad = $backup->path();

        if ($pfad === null || ! is_file($pfad)) {
            throw new RuntimeException('Zu dieser Sicherung liegt keine Datei — sie lässt sich nicht zurückspielen.');
        }

        $zip = new ZipArchive;

        if ($zip->open($pfad) !== true) {
            throw new RuntimeException('Die Sicherung liess sich nicht öffnen.');
        }

        try {
            $json = $zip->getFromName(Manifest::ENTRY);
        } finally {
            $zip->close();
        }

        if (! is_string($json)) {
            throw new RuntimeException('Die Sicherung trägt kein Verzeichnis — was daraus zurückkäme, wäre unvollständig.');
        }

        try {
            // **Derselbe Leser wie im Agenten.** `Manifest` ist framework- und
            // abhängigkeitsfrei und aus dem Panel autoladbar; ein zweiter Leser
            // hier wäre die Fassung, die veraltet — und `docs/81 §2.3o` M22 hat
            // gezeigt, was zwei Leser derselben Marken kosten.
            return Manifest::decode($json);
        } catch (AgentException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Was eine Wiederherstellung anlegen würde — für die Vorschau des Formulars.
     *
     * **Zahlen und keine Zusage.** Ob der gewählte Plan sie trägt, entscheidet
     * beim Anlegen die Kontingentprüfung von {@see Domains} und
     * {@see Databases}; sie hier ein zweites Mal zu
     * rechnen wäre dieselbe Regel an zwei Orten.
     *
     * @param  array<string,mixed>  $manifest
     * @return array{subscription: string, plan: string|null, domains: int, databases: int, db_users: int, cron: int, ssh_keys: int, created_at: string, panel: string}
     */
    public function preview(array $manifest): array
    {
        $beschreibung = is_array($manifest['description'] ?? null) ? $manifest['description'] : [];

        $zaehle = static function (string $key) use ($beschreibung): int {
            $liste = $beschreibung[$key] ?? null;

            return is_array($liste) ? count($liste) : 0;
        };

        $abo = is_array($beschreibung['subscription'] ?? null) ? $beschreibung['subscription'] : [];

        return [
            'subscription' => (string) ($manifest['subscription'] ?? ''),
            'plan' => is_string($abo['plan'] ?? null) ? $abo['plan'] : null,
            'domains' => $zaehle('domains'),
            'databases' => $zaehle('databases'),
            'db_users' => $zaehle('db_users'),
            'cron' => $zaehle('cron'),
            'ssh_keys' => $zaehle('ssh_keys'),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'panel' => (string) ($manifest['panel'] ?? ''),
        ];
    }

    /**
     * Schritt 7: das Abonnement anlegen und die beiden Vorgänge einreihen.
     *
     * **`claim()` steht innerhalb der Transaktion**, und der Grund ist derselbe
     * wie in `SubscriptionController::store()`: Es verbraucht eine Nummer
     * dauerhaft. Scheitert das Anlegen danach, soll auch die Reservierung
     * zurückgerollt werden — sonst frisst jeder Fehlversuch eine Nummer, und
     * die Lücke im Zähler ist nicht mehr zu erklären.
     *
     * Zurück kommt der **zweite** Vorgang. Er ist der, den der Betreiber
     * verfolgt: Bis die Bereitstellung durch ist, steht er auf „wartet", und
     * danach läuft er los. Der erste ist eine Vorbedingung und kein Ergebnis.
     */
    public function start(
        Backup $backup,
        int $customerId,
        int $planId,
        string $name,
        ?int $accountId = null,
    ): Operation {
        $herkunft = $this->originOf($backup);

        $subscription = DB::transaction(fn (): Subscription => Subscription::query()->create([
            'customer_id' => $customerId,
            'plan_id' => $planId,
            'name' => $name,
            'system_user' => $this->lifecycle->claim($name),
            'status' => SubscriptionStatus::Provisioning,
        ]));

        $this->lifecycle->dispatch($subscription, 'subscription.provision', 'Abonnement für die Wiederherstellung anlegen');

        return $this->dispatchRestore($subscription, $backup, $herkunft, $accountId);
    }

    /**
     * Zu welchem Abonnement die Sicherung gehört — für den Pfad ihres Archivs.
     *
     * **Aus der Zeile und nicht aus dem Formular.** Der Ablageort hängt am
     * Namen des Abonnements, zu dem die Sicherung gehört, und der kann ein
     * anderer sein als der, unter dem sie zurückkommt: Ist der alte Name
     * inzwischen vergeben, bekommt die Wiederherstellung einen neuen
     * (`docs/117 §3`).
     */
    private function originOf(Backup $backup): string
    {
        $name = $backup->subscription->name ?? $backup->subscription_name;

        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Diese Sicherung nennt kein Abonnement — zu ihr lässt sich keine Datei finden.');
        }

        return $name;
    }

    /**
     * Der Vorgang, der auspackt.
     *
     * Sein **Gegenstand** ist die Sicherung und sein **Abonnement** das neue:
     * Die eine Angabe sagt, woraus wiederhergestellt wurde, die andere, wohin.
     * Beide werden gebraucht, und keine ersetzt die andere.
     */
    private function dispatchRestore(
        Subscription $subscription,
        Backup $backup,
        string $herkunft,
        ?int $accountId,
    ): Operation {
        $operation = Operation::query()->create([
            'subscription_id' => $subscription->id,
            'account_id' => $accountId,
            'type' => 'backup.restore',
            'task' => 'backup.restore',
            'subject_type' => OperationSubject::Backup->value,
            'subject_id' => (int) $backup->id,
            'payload' => [
                'subscription' => (string) $subscription->name,
                'from' => $herkunft,
                'storage' => (string) $backup->storage_name,
                'user' => (string) $subscription->system_user,
            ],
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => sprintf('Sicherung %s wird zurückgespielt', $backup->storage_name),
        ]);

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Ein Name, unter dem die Wiederherstellung laufen kann.
     *
     * Der alte, solange er frei ist — dann bleiben Pfad und Verzeichnisname
     * gleich, und der Kunde merkt von beidem nichts (`docs/117 §3`). Ist er
     * vergeben, gibt es keinen Vorschlag: Einen zu erfinden hiesse, dem
     * Betreiber eine Entscheidung abzunehmen, die er sehen soll.
     *
     * > **Der Pfad bleibt nur, solange der Name frei ist.**
     *
     * @param  array<string,mixed>  $manifest
     */
    public function suggestedName(array $manifest): ?string
    {
        $name = $manifest['subscription'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        $vergeben = $this->tenancy->withoutRestriction(
            fn (): bool => Subscription::query()->where('name', $name)->exists(),
        );

        return $vergeben ? null : $name;
    }
}
