<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Models\CronJob;
use App\Models\Database;
use App\Models\DbUser;
use App\Models\Domain;
use App\Models\SshKey;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;

/**
 * Was eine Sicherung über ein Abonnement **beschreibt**, statt es abzuschreiben.
 *
 * ## Die Unterscheidung, die `docs/117 §4` trägt
 *
 * Drei Arten von Inhalt, und diese Klasse baut die erste:
 *
 * | Art | Was | Wie sie zurückkommt |
 * |---|---|---|
 * | **Beschreibung** | Domains, Cron, SFTP-Schlüssel, Struktur der Datenbanken und Zugänge, Plan, PHP | die Wiederherstellung **erzeugt** daraus neu |
 * | **Wörtlich** | Dateien des Kunden, Inhalt der Datenbanken | liegen als Dateien im Archiv |
 * | **Weder noch** | Datenbankpasswörter | fehlen, und die Seite sagt es |
 *
 * **Warum die Beschreibung erzeugt und nicht zurückgespielt wird**, ist
 * gemessen und keine Vorliebe: Eine wörtlich zurückgespielte Vhost-Datei aus
 * einer älteren Fassung meldet der Nachtlauf in der Nacht darauf als
 * `directive_lost` (`docs/116` M6, an der echten Vorlage und am echten Leser).
 *
 * > **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
 * > Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
 * > nächsten Fassung.**
 *
 * ## Was hier ausdrücklich **nicht** hineinkommt
 *
 * **Kein Geheimnis.** Keine Datenbankpasswörter (die stehen nirgends —
 * `docs/117 §4`), kein privater Schlüssel, kein Token. Der Grund ist nicht
 * Vorsicht, sondern eine gemessene Eigenschaft dieses Panels: Die Beschreibung
 * reist als **Argument eines Vorgangs**, und `Operations/Show.vue` rendert
 * `payload` als JSON — `OperationPolicy::view()` lässt jeden Admin und den
 * Kunden des Abonnements hindurch.
 *
 * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
 * > Vorgangsseite.** `DnsCredentialStore` nennt genau das als Grund, keinen
 * > Vorgang einzureihen.
 *
 * **Und keine Kennungen dieses Panels.** Was hier steht, sind Namen und Werte —
 * keine `id`, kein `subscription_id`. Eine Wiederherstellung legt neue Zeilen
 * an; eine alte Kennung darin wäre eine Einladung, sie zu übernehmen, und nach
 * Form A ist genau das falsch.
 *
 * ## Und warum sie klein bleibt
 *
 * Sie reist über den Socket, und `Connection::CONTENT_MAX` ist 1 MiB abzüglich
 * der Hülle. Ein Abonnement mit zehn Domains, zehn Datenbanken und zehn Jobs
 * liegt weit darunter.
 *
 * **`BackupSecretTest` hält die Felder**, die sie tragen darf — eine
 * Positivliste, kein Ausdruck über verdächtige Wörter. Ein neues Feld macht ihn
 * rot, und dann entscheidet jemand, ob es hinein darf.
 */
final class Description
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Alles, was die Wiederherstellung neu erzeugen muss.
     *
     * **Ohne Mandantenklammer gelesen, und das ist notwendig.** Eine Sicherung
     * kann aus einem nächtlichen Lauf kommen, und dort ist niemand angemeldet —
     * die Klammer stünde auf `whereRaw('0 = 1')` und gäbe eine leere
     * Beschreibung zurück. Wortlos, und die Sicherung sähe vollständig aus.
     *
     * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer
     * > leeren Liste und nicht mit einem Fehler.** (`docs/78`)
     *
     * Das ist derselbe Befund, den `Cron::store()` in P6 gekostet hat: „88
     * eingesammelt, 0 eingepflegt".
     *
     * @return array<string, mixed>
     */
    public function of(Subscription $subscription): array
    {
        return $this->tenancy->withoutRestriction(fn (): array => [
            'subscription' => $this->subscription($subscription),
            'domains' => $this->domains($subscription),
            'databases' => $this->databases($subscription),
            'db_users' => $this->dbUsers($subscription),
            'cron' => $this->cron($subscription),
            'ssh_keys' => $this->sshKeys($subscription),
        ]);
    }

    /**
     * Das Abonnement selbst — Name, Plan und was es an Kontingenten hatte.
     *
     * Der **Plan** steht als Name da und nicht als Kennung: Nach einer
     * Wiederherstellung auf einem anderen Server gibt es die Nummer nicht, den
     * Namen vielleicht schon. Und wo es ihn nicht gibt, ist ein Name eine
     * lesbare Auskunft, während eine Nummer nichts sagt.
     *
     * @return array<string, mixed>
     */
    private function subscription(Subscription $subscription): array
    {
        return [
            'name' => (string) $subscription->name,
            'plan' => $subscription->plan->name ?? null,
            'status' => $subscription->status->value ?? null,
        ];
    }

    /**
     * Die Domains samt dem, was ihre Vhost-Datei daraus macht.
     *
     * **`certificate_id` fehlt hier mit Absicht.** Ein ACME-Zertifikat wird
     * nach der Wiederherstellung neu bestellt — der Weg, den P4 ohnehin geht.
     * Die Nummer eines Zertifikats, das es auf dem Zielserver nicht gibt, wäre
     * eine Zusage, die niemand einlöst.
     *
     * **Der Elternteil steht mit seinem Namen da und nicht mit seiner
     * Kennung**, und ohne ihn wäre die Wiederherstellung einer Subdomain gar
     * nicht möglich: {@see Domains::create()} verlangt für
     * `subdomain` und `alias` die Zeile, unter der sie hängen, und weist sonst
     * mit *„Diese Sorte braucht eine Domain, unter der sie hängt"* ab.
     *
     * Gefunden beim Ausschreiben von Schritt 8 (`docs/117 §15`) und nicht beim
     * Bauen von Schritt 5 — dort sah die Beschreibung vollständig aus, weil
     * niemand sie gelesen hat.
     *
     * > **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht
     * > von einem zu unterscheiden, das es nicht gibt** — und ob es reicht,
     * > sagt erst der Leser.
     *
     * @return list<array<string, mixed>>
     */
    private function domains(Subscription $subscription): array
    {
        return Domain::query()
            ->where('subscription_id', $subscription->id)
            ->with('parent')
            ->orderBy('name')
            ->get()
            ->map(static fn (Domain $domain): array => [
                'name' => $domain->name,
                'parent' => $domain->parent?->name,
                'type' => $domain->type->value,
                'document_root' => $domain->document_root,
                'php_version' => $domain->php_version,
                'php_settings' => $domain->php_settings,
                'nginx_directives' => $domain->nginx_directives,
                'redirect_target' => $domain->redirect_target,
                'redirect_kind' => $domain->redirect_kind?->value,
            ])
            ->all();
    }

    /**
     * Die Datenbanken — **Struktur und nicht Inhalt**.
     *
     * Der Inhalt liegt als Dump im Archiv. Was hier steht, ist das, was eine
     * Wiederherstellung braucht, um die Datenbank überhaupt anzulegen: System,
     * Zeichensatz, Sortierung. Und der Name, damit die Seite hinterher die
     * Zuordnung alt → neu zeigen kann.
     *
     * > **Nach Form A bekommt das Abonnement ein neues `db_prefix`, und damit
     * > heissen die Datenbanken anders.** Der alte Name ist die einzige
     * > Auskunft, aus der die Zuordnung entsteht.
     *
     * @return list<array<string, mixed>>
     */
    private function databases(Subscription $subscription): array
    {
        return Database::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('name')
            ->get()
            ->map(static fn (Database $database): array => [
                'name' => $database->name,
                'label' => $database->label,
                'engine' => $database->engine->value,
                'charset' => $database->charset,
                'collation' => $database->collation,
            ])
            ->all();
    }

    /**
     * Die Datenbankzugänge — ohne Passwort, und das ist kein Mangel.
     *
     * **Passwörter stehen nirgends** (`docs/117 §4`): Dieses Panel hält sie
     * nicht, weder im Klartext noch verschlüsselt. Nach einer
     * Wiederherstellung bekommt jeder Zugang ein neues, und die Seite sagt es.
     *
     * > **Was weder beschrieben noch erzeugt werden kann, muss die Sicherung
     * > selbst tragen — oder die Wiederherstellung muss sagen, dass es
     * > fehlt.**
     *
     * Die **Netze** kommen mit: Sie sind eine Beschränkung und kein Geheimnis,
     * und ein Zugang, der nach der Wiederherstellung von überall darf, wäre
     * offener als der gesicherte.
     *
     * @return list<array<string, mixed>>
     */
    private function dbUsers(Subscription $subscription): array
    {
        return DbUser::query()
            ->where('subscription_id', $subscription->id)
            ->with(['databases', 'networks'])
            ->orderBy('name')
            ->get()
            ->map(static fn (DbUser $user): array => [
                'name' => $user->name,
                'label' => $user->label,
                'host' => $user->host,
                'engine' => $user->engine->value,
                'databases' => $user->databases->pluck('name')->all(),
                'networks' => $user->networks->pluck('cidr')->all(),
            ])
            ->all();
    }

    /**
     * Die Cronjobs.
     *
     * **Auch die abgeschalteten.** Ein Job, den jemand angehalten hat, ist eine
     * Entscheidung und kein Rest; ihn wegzulassen hiesse, die Entscheidung bei
     * der Wiederherstellung stillschweigend umzukehren — und zwar in die
     * Richtung, in der etwas *läuft*, das nicht laufen sollte.
     *
     * @return list<array<string, mixed>>
     */
    private function cron(Subscription $subscription): array
    {
        return CronJob::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('label')
            ->get()
            ->map(static fn (CronJob $job): array => [
                'label' => $job->label,
                'command' => $job->command,
                'minute' => $job->minute,
                'hour' => $job->hour,
                'day_of_month' => $job->day_of_month,
                'month' => $job->month,
                'day_of_week' => $job->day_of_week,
                'active' => $job->active,
            ])
            ->all();
    }

    /**
     * Die SFTP-Schlüssel — **öffentliche**, und nur die gibt es hier.
     *
     * Der private Schlüssel gehört dem Kunden und hat dieses Panel nie gesehen
     * (P6 Schritt 8). Was hier steht, liegt ohnehin in seiner
     * `authorized_keys`; sie wird beim Wiederherstellen **erzeugt** und nicht
     * zurückgespielt, weil ein verwalteter Bereich darin schreibt.
     *
     * @return list<array<string, mixed>>
     */
    private function sshKeys(Subscription $subscription): array
    {
        return SshKey::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('label')
            ->get()
            ->map(static fn (SshKey $key): array => [
                'label' => $key->label,
                'type' => $key->type,
                'bits' => $key->bits,
                'fingerprint' => $key->fingerprint,
                'public_key' => $key->public_key,
            ])
            ->all();
    }
}
