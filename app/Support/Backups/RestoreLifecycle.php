<?php

declare(strict_types=1);

namespace App\Support\Backups;

use App\Enums\CertificateSource;
use App\Enums\CertificateStatus;
use App\Enums\DatabaseEngine;
use App\Enums\DomainType;
use App\Models\Backup;
use App\Models\Certificate;
use App\Models\Database;
use App\Models\Operation;
use App\Models\Subscription;
use App\Support\Cron\Cron;
use App\Support\Databases\Databases;
use App\Support\Databases\Dumps;
use App\Support\Databases\RemoteAccess;
use App\Support\Operations\AfterOperation;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Domains;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Was nach dem Auspacken **erzeugt** wird — Schritt 8 aus `docs/117 §6`.
 *
 * ## Erzeugt und nicht zurückgespielt, und das ist gemessen
 *
 * Eine wörtlich zurückgespielte Vhost-Datei aus einer älteren Fassung meldet der
 * Nachtlauf in der Nacht darauf als `directive_lost` (`docs/116` M6, an der
 * echten Vorlage und am echten Leser). Deshalb liegt in der Sicherung die
 * **Beschreibung**, und hier entsteht daraus neu, was die laufende Fassung
 * schreibt: Domains, Datenbanken, Zugänge, Cronjobs.
 *
 * > **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
 * > Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
 * > nächsten Fassung.**
 *
 * ## Warum hier und nicht in einem eigenen Vorgang
 *
 * Die zweite Grenze (`docs/20 §4`): Der Zustand folgt dem Agenten. Erst wenn
 * `backup.restore` geantwortet hat, steht der Baum — und vorher hätte eine
 * Datenbank nichts, wozu sie gehört.
 *
 * ## Und warum ein Fehlschlag hier nichts abbricht
 *
 * Er wird **gesammelt und ausgewiesen**, nicht geworfen. Der Grund ist nicht
 * Nachsicht, sondern der Unterschied zwischen zwei Auskünften: Wirft die
 * fünfte von acht Domains, wäre der Vorgang rot und die Zuordnung der
 * Datenbanken — die der Kunde braucht, um seinen Auftritt wieder zum Laufen zu
 * bringen — fort. Gesammelt steht beides da.
 *
 * > **Ein Abbruch, der nach dem ersten Fehlschlag alles verwirft, macht aus
 * > einem gesperrten Paket eine gesperrte Umgebung.**
 *
 * Still ist dabei nichts: Jeder Fehlschlag steht mit seinem Gegenstand im
 * Ergebnis des Vorgangs, und die Seite zeigt es.
 *
 * ## Kein Geheimnis im Ergebnis
 *
 * `Operations/Show.vue` rendert `result` als JSON, und `OperationPolicy::view()`
 * lässt jeden Admin und den Kunden des Abonnements hindurch. Was hier
 * hineingeht, sind **Namen**: die Zuordnung alt → neu und der neue
 * Systembenutzer. Die Passwörter der Zugänge stehen nirgends — dieses Panel
 * hält keine (`docs/117 §4`) —, und deshalb sagt das Ergebnis, dass jeder
 * Zugang ein neues braucht, statt eines zu nennen.
 *
 * > **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
 * > Vorgangsseite.**
 */
final class RestoreLifecycle implements AfterOperation
{
    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Restore $restore,
        private readonly Backups $backups,
        private readonly Databases $databases,
        private readonly Dumps $dumps,
        private readonly Domains $domains,
        private readonly Cron $cron,
        private readonly RemoteAccess $networks,
    ) {}

    /** @return list<string> */
    public static function handles(): array
    {
        return ['backup.restore'];
    }

    public function afterSuccess(Operation $operation): void
    {
        $this->tenancy->withoutRestriction(function () use ($operation): void {
            $subscription = Subscription::query()->find($operation->subscription_id);
            $backup = Backup::query()->find($operation->subject_id);

            if ($subscription === null || $backup === null) {
                return;
            }

            $manifest = $this->restore->manifest($backup);
            $beschreibung = is_array($manifest['description'] ?? null) ? $manifest['description'] : [];

            $fehler = [];
            $datenbanken = $this->rebuildDatabases($subscription, $beschreibung, $fehler);

            $this->rebuildUsers($subscription, $beschreibung, $datenbanken, $fehler);
            $this->rebuildDumps($beschreibung, $datenbanken, $fehler);
            $this->rebuildDomains($subscription, $beschreibung, $fehler);
            $this->rebuildCron($subscription, $beschreibung, $fehler);
            $this->rebuildCertificates($subscription, $beschreibung, $fehler);

            $this->record($operation, $manifest, $subscription, $datenbanken, $fehler);
        });
    }

    public function afterFailure(Operation $operation): void {}

    /**
     * Die Datenbanken — **unter neuen Namen**, weil Form A ein neues Präfix
     * vergibt.
     *
     * Der alte Name ist die einzige Auskunft, aus der die Zuordnung entsteht;
     * er kommt aus der Beschreibung und nicht aus einem Muster.
     *
     * @param  array<string,mixed>  $beschreibung
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     * @return array<string, Database> alter Name => neue Zeile
     */
    private function rebuildDatabases(Subscription $subscription, array $beschreibung, array &$fehler): array
    {
        $zuordnung = [];

        foreach ($this->listOf($beschreibung, 'databases') as $eintrag) {
            $alt = is_string($eintrag['name'] ?? null) ? $eintrag['name'] : null;
            $engine = DatabaseEngine::tryFrom((string) ($eintrag['engine'] ?? ''));

            if ($alt === null || $engine === null) {
                $fehler[] = ['gegenstand' => (string) ($eintrag['name'] ?? '?'), 'grund' => 'Die Beschreibung nennt kein System.'];

                continue;
            }

            try {
                $zuordnung[$alt] = $this->databases->create(
                    $subscription,
                    (string) ($eintrag['label'] ?? $alt),
                    is_string($eintrag['collation'] ?? null) ? $eintrag['collation'] : null,
                    $engine,
                );
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => $alt, 'grund' => $e->getMessage()];
            }
        }

        return $zuordnung;
    }

    /**
     * Die Zugänge — **ohne Passwort, und das ist kein Vergessen.**
     *
     * `createUser()` erzeugt eines und gibt es zurück; hier wird es verworfen.
     * Es abzulegen verstiesse gegen `docs/36 §4`, und es in das Ergebnis des
     * Vorgangs zu schreiben hiesse, es auf die Vorgangsseite zu stellen. Der
     * Zugang steht damit mit seinen Rechten da, und sein Passwort setzt der
     * Kunde neu — die Seite sagt es.
     *
     * @param  array<string,mixed>  $beschreibung
     * @param  array<string, Database>  $datenbanken
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function rebuildUsers(Subscription $subscription, array $beschreibung, array $datenbanken, array &$fehler): void
    {
        foreach ($this->listOf($beschreibung, 'db_users') as $eintrag) {
            $engine = DatabaseEngine::tryFrom((string) ($eintrag['engine'] ?? ''));
            $label = is_string($eintrag['label'] ?? null) ? $eintrag['label'] : null;

            if ($engine === null || $label === null) {
                $fehler[] = ['gegenstand' => (string) ($eintrag['name'] ?? '?'), 'grund' => 'Die Beschreibung des Zugangs ist unvollständig.'];

                continue;
            }

            $seine = [];

            foreach (is_array($eintrag['databases'] ?? null) ? $eintrag['databases'] : [] as $alt) {
                if (is_string($alt) && isset($datenbanken[$alt])) {
                    $seine[] = $datenbanken[$alt];
                }
            }

            try {
                [$user] = $this->databases->createUser(
                    $subscription,
                    $label,
                    $seine,
                    (string) ($eintrag['host'] ?? 'localhost'),
                    $engine,
                );

                // Die Netze sind eine **Beschränkung** und kein Geheimnis. Ein
                // Zugang, der nach der Wiederherstellung von überall darf, wäre
                // offener als der gesicherte.
                foreach (is_array($eintrag['networks'] ?? null) ? $eintrag['networks'] : [] as $cidr) {
                    if (is_string($cidr) && $cidr !== '') {
                        $this->networks->add($user, $cidr);
                    }
                }
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => $label, 'grund' => $e->getMessage()];
            }
        }
    }

    /**
     * Die Dumps einspielen — über den Weg, den es seit P5 gibt.
     *
     * `backup.restore` hat die Dateien in die Ablage des neuen Abonnements
     * gelegt; hier bekommen sie ihre Zeile und werden eingereiht. **Kein neuer
     * Weg**: Das Einspielen selbst ist `db.dump.restore` beziehungsweise
     * `pg.dump.restore`.
     *
     * @param  array<string,mixed>  $beschreibung
     * @param  array<string, Database>  $datenbanken
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function rebuildDumps(array $beschreibung, array $datenbanken, array &$fehler): void
    {
        foreach ($this->listOf($beschreibung, 'dumps') as $eintrag) {
            $alt = is_string($eintrag['database'] ?? null) ? $eintrag['database'] : null;
            $storage = is_string($eintrag['storage'] ?? null) ? $eintrag['storage'] : null;

            if ($alt === null || $storage === null || ! isset($datenbanken[$alt])) {
                $fehler[] = [
                    'gegenstand' => (string) ($storage ?? '?'),
                    'grund' => 'Zu dieser Sicherung gibt es keine Datenbank, in die sie gehört.',
                ];

                continue;
            }

            try {
                $this->dumps->restore($this->dumps->adopt($datenbanken[$alt], $storage), $datenbanken[$alt]);
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => $storage, 'grund' => $e->getMessage()];
            }
        }
    }

    /**
     * Die Domains — **Eltern zuerst**.
     *
     * Eine Subdomain und ein Alias brauchen die Zeile, unter der sie hängen
     * ({@see Domains::create()}), und die Beschreibung nennt sie beim **Namen**
     * und nicht mit einer Kennung: Eine Kennung dieses Panels führte auf einem
     * anderen Server ins Leere.
     *
     * > **Ein Wert, der sich beim Zurückspielen sowieso ändert, gehört nicht in
     * > die Sicherung — er gehört neu gerechnet.**
     *
     * @param  array<string,mixed>  $beschreibung
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function rebuildDomains(Subscription $subscription, array $beschreibung, array &$fehler): void
    {
        $alle = $this->listOf($beschreibung, 'domains');

        // Erst die, die selbst Inhalt ausliefern, dann die, die an ihnen
        // hängen. Innerhalb der beiden Gruppen bleibt die Reihenfolge der
        // Beschreibung — sie ist nach Namen sortiert und damit wiederholbar.
        $zuerst = [];
        $danach = [];

        foreach ($alle as $eintrag) {
            $typ = DomainType::tryFrom((string) ($eintrag['type'] ?? ''));

            if ($typ !== null && $typ->requiresParent()) {
                $danach[] = $eintrag;

                continue;
            }

            $zuerst[] = $eintrag;
        }

        $nachName = [];

        foreach ([...$zuerst, ...$danach] as $eintrag) {
            $name = is_string($eintrag['name'] ?? null) ? $eintrag['name'] : null;

            if ($name === null) {
                continue;
            }

            $daten = $eintrag;
            $elternName = is_string($eintrag['parent'] ?? null) ? $eintrag['parent'] : null;

            if ($elternName !== null) {
                $eltern = $nachName[$elternName] ?? null;

                if ($eltern === null) {
                    $fehler[] = ['gegenstand' => $name, 'grund' => sprintf('Die Domain %s, unter der sie hängt, ist nicht angelegt worden.', $elternName)];

                    continue;
                }

                $daten['parent_domain_id'] = (int) $eltern->id;
            }

            try {
                $nachName[$name] = $this->domains->create($subscription, $daten);
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => $name, 'grund' => $this->messageOf($e)];
            }
        }
    }

    /**
     * Die Cronjobs — **auch die abgeschalteten.**
     *
     * Ein Job, den jemand angehalten hat, ist eine Entscheidung und kein Rest;
     * ihn wegzulassen kehrte sie stillschweigend um, und zwar in die Richtung,
     * in der etwas *läuft*, das nicht laufen sollte.
     *
     * @param  array<string,mixed>  $beschreibung
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function rebuildCron(Subscription $subscription, array $beschreibung, array &$fehler): void
    {
        foreach ($this->listOf($beschreibung, 'cron') as $eintrag) {
            try {
                $this->cron->create($subscription, $eintrag);
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => (string) ($eintrag['label'] ?? '?'), 'grund' => $this->messageOf($e)];
            }
        }
    }

    /**
     * Die Zeilen der hochgeladenen Zertifikate — das Material liegt schon.
     *
     * **Der Agent hat die Dateien zurückgelegt, bevor diese Methode läuft.**
     * `backup.restore` schreibt sie über `Acme\Store::write()` in den
     * Ablageort; hier entsteht nur, was das Panel führt.
     *
     * **Ohne diese Zeilen wäre das Material ein Rest.** Nichts zeigte darauf,
     * der Nachtlauf meldete `orphan.row / certificate`, und `srvpanel tls
     * --prune` entfernte den privaten Schlüssel unter einer Website, die ihn
     * gerade zurückbekommen hat.
     *
     * > **Eine Datei ohne ihre Zeile ist ein Rest, auch wenn sie gerade erst
     * > entstanden ist.**
     *
     * **Die Domains werden hier nicht verknüpft**, und das ist Absicht: Welche
     * Domain welches Zertifikat benutzt, entscheidet die **Deckung** und nicht
     * eine Zuordnung — dieselbe Frage, die `CertificatePrune` seit P7 stellt.
     * Ein Platzhalter deckt eine Domain, ohne ihr zugeordnet zu sein.
     *
     * **`status` kommt nicht aus der Beschreibung.** Ob ein Zertifikat gilt,
     * hängt an `not_after` und an dem, was wirklich auf der Platte liegt; ein
     * abgeschriebener Zustand wäre eine Zusage über einen Augenblick, der
     * vorbei ist. Abgelaufen ist abgelaufen, und das rechnet die Seite.
     *
     * @param  array<string, mixed>  $beschreibung
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function rebuildCertificates(Subscription $subscription, array $beschreibung, array &$fehler): void
    {
        foreach ($this->listOf($beschreibung, 'certificates') as $eintrag) {
            $name = (string) ($eintrag['storage_name'] ?? '');

            try {
                if ($name === '') {
                    throw new RuntimeException('Ein Zertifikat ohne Ablagenamen.');
                }

                Certificate::query()->create([
                    'subscription_id' => $subscription->id,
                    'names' => is_array($eintrag['names'] ?? null) ? $eintrag['names'] : [],
                    'storage_name' => $name,
                    'status' => CertificateStatus::Active,
                    'source' => CertificateSource::Uploaded,
                    'issuer' => $eintrag['issuer'] ?? null,
                    'serial' => $eintrag['serial'] ?? null,
                    'not_before' => $eintrag['not_before'] ?? null,
                    'not_after' => $eintrag['not_after'] ?? null,
                ]);
            } catch (Throwable $e) {
                $fehler[] = ['gegenstand' => $name === '' ? '?' : $name, 'grund' => $this->messageOf($e)];
            }
        }
    }

    /**
     * Was sich geändert hat — das Abnahmekriterium §8 Punkt 6.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string, Database>  $datenbanken
     * @param  list<array{gegenstand: string, grund: string}>  $fehler
     */
    private function record(
        Operation $operation,
        array $manifest,
        Subscription $subscription,
        array $datenbanken,
        array $fehler,
    ): void {
        $zuordnung = [];

        foreach ($datenbanken as $alt => $neu) {
            $zuordnung[] = ['alt' => $alt, 'neu' => (string) $neu->name];
        }

        $ergebnis = is_array($operation->result) ? $operation->result : [];

        $ergebnis['restored'] = [
            'subscription' => (string) $subscription->name,
            'from' => (string) ($manifest['subscription'] ?? ''),
            'system_user' => ['alt' => $manifest['system_user'] ?? null, 'neu' => (string) $subscription->system_user],
            /*
             * **Das Präfix kommt aus `system_users` und nicht vom
             * Abonnement.** `subscriptions` führt keine solche Spalte;
             * `$subscription->db_prefix` wäre wortlos `null`, und die
             * Zuordnung, für die es diese Zeile gibt, stünde leer da.
             *
             * Gefragt wird {@see Backups::prefixOf()} — dieselbe Stelle, die
             * den Wert beim **Anlegen** in das Verzeichnis der Sicherung
             * schreibt. Ein zweiter Leser hier wäre die Fassung, die veraltet.
             */
            'db_prefix' => ['alt' => $manifest['db_prefix'] ?? null, 'neu' => $this->backups->prefixOf($subscription)],
            'databases' => $zuordnung,

            // **Kein Passwort, und die Zeile sagt es.** Sie steht hier, damit
            // die Auskunft nicht davon abhängt, dass jemand die richtige Seite
            // aufschlägt.
            'passwords' => 'Die Datenbankzugänge stehen wieder da und haben ein neues, unbekanntes Passwort. Jeder braucht einmal „Passwort neu setzen".',
            'failures' => $fehler,
        ];

        $operation->result = $ergebnis;
        $operation->save();
    }

    /**
     * Eine Liste aus der Beschreibung — oder eine leere.
     *
     * @param  array<string,mixed>  $beschreibung
     * @return list<array<string,mixed>>
     */
    private function listOf(array $beschreibung, string $key): array
    {
        $liste = $beschreibung[$key] ?? null;

        if (! is_array($liste)) {
            return [];
        }

        return array_values(array_filter($liste, 'is_array'));
    }

    /**
     * Die Meldung einer Ausnahme, auch wenn es eine Prüfmeldung ist.
     *
     * Eine `ValidationException` trägt ihren Satz in den Fehlern und nicht in
     * `getMessage()` — dort steht „The given data was invalid.", und das hilft
     * niemandem beim Suchen.
     */
    private function messageOf(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $erste = $e->validator->errors()->first();

            return $erste !== '' ? $erste : $e->getMessage();
        }

        return $e->getMessage();
    }
}
