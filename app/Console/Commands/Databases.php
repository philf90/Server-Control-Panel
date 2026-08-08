<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Database;
use App\Models\DatabaseDump;
use App\Models\DbUser;
use App\Support\Databases\DatabasePrune;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Der Datenbankserver von der Kommandozeile — nachsehen und aufräumen.
 *
 * **Zwei Dinge, und beide gehören auf dieselbe Seite**, weil beide dieselbe
 * Frage beantworten: Was liegt auf diesem Server, das das Panel angeht?
 *
 * `srvpanel db` liest — Version, Geschmacksrichtung, Horchadresse, der Bestand
 * des Panels, und was ein misslungener Rückbau liegengelassen hat.
 * `srvpanel db --prune` räumt es weg, nach Rückfrage und in der Reihenfolge, in
 * der es sicher ist.
 *
 * **Warum es das überhaupt gibt.** `docs/35` hat freigelegt, dass sich in
 * diesem System ein Zertifikat nie löschen liess — ein Jahr lang, weil `create`
 * zuerst gebaut wurde und danach funktionierte. P5 legt drei weitere Dinge auf
 * dem System ab: Schemata, Datenbankbenutzer und Sicherungsdateien. Der Weg
 * zurück ist deshalb Teil desselben Beitrags und nicht die Nacharbeit, an die
 * ein Jahr später niemand denkt.
 *
 * **`--remote=on|off` ist der Schalter aus `docs/36 §12`**, und er steht hier
 * und nicht im Panel: Er lässt einen Dienst auf einer erreichbaren Adresse
 * horchen, und das ist eine serverweite Änderung. Leitbild 1 — „der Bestand ist
 * Gesetz" — verbietet, sie nebenbei zu tun, weil ein Kunde ein Häkchen gesetzt
 * hat. Erst wenn der Server tatsächlich horcht, bietet das Panel einem Kunden
 * überhaupt an, einen Zugang für eine fremde Adresse anzulegen.
 *
 * **Und der Neustart wird angesagt, bevor er passiert.** Der Datenbankserver
 * trägt auch das Panel; ein `--remote` ohne Rückfrage wäre eine Unterbrechung,
 * die niemand erwartet hat.
 */
final class Databases extends Command
{
    protected $signature = 'srvpanel:db
        {--prune : Reste eines misslungenen Rückbaus entfernen — Schemata, Zugänge und Sicherungen}
        {--dry-run : Mit --prune: nur zeigen, was entfernt würde}
        {--remote= : on oder off — ob der Datenbankserver auf einer erreichbaren Adresse horcht}
        {--bind=0.0.0.0 : Mit --remote=on: 0.0.0.0 für IPv4 oder :: für beide Stapel}';

    protected $description = 'Zeigt den Datenbankserver und räumt auf, was ein Rückbau liegenliess';

    public function handle(Client $agent, DatabasePrune $prune, Tenancy $tenancy): int
    {
        if ($this->option('prune') === true) {
            return $this->prune($agent, $prune);
        }

        if ($this->option('remote') !== null) {
            return $this->remote($agent, $tenancy);
        }

        return $this->showServer($agent, $prune, $tenancy);
    }

    /**
     * Nachsehen — der Server und der Bestand.
     *
     * **Der Server wird gefragt und nicht das Panel.** Was hier interessiert,
     * ist der Unterschied zwischen beiden: Ein Schema, das im Panel steht und
     * auf dem Server fehlt, ist eine andere Lage als eines, das der Server hat
     * und das Panel nicht. Die erste Frage beantwortet `db.server.info`, die
     * zweite `srvpanel db --prune`.
     */
    private function showServer(Client $agent, DatabasePrune $prune, Tenancy $tenancy): int
    {
        try {
            $info = $agent->call('db.server.info', [], $this->actor());
        } catch (AgentException $error) {
            $this->error('Der Agent hat abgewiesen: '.$error->getMessage());

            return self::FAILURE;
        }

        if (($info['available'] ?? false) !== true) {
            $this->warn('Kein Datenbankserver erreichbar: '.($info['reason'] ?? 'kein Grund genannt'));
        } else {
            $this->line(sprintf(
                '%s %s — %s',
                $info['flavour'] ?? 'unbekannt',
                $info['version'] ?? '?',
                ($info['usable'] ?? false) === true
                    ? 'nutzbar'
                    : 'nicht nutzbar: '.($info['reason'] ?? 'kein Grund genannt'),
            ));

            $this->line(sprintf(
                'Horcht auf %s — Fernzugriff %s.',
                $info['bind_address'] ?? 'unbekannt',
                ($info['remote'] ?? false) === true ? 'möglich' : 'aus',
            ));
        }

        /*
         * **Ohne Mandantenklammer, und ausdrücklich.** Auf der Kommandozeile
         * ist niemand angemeldet; der Grundzustand der Klammer ist „nichts",
         * und damit zählte dieses Kommando null Datenbanken auf einem Server
         * voller Datenbanken. Dieselbe Stelle, derselbe Name wie in
         * `Lifecycle::afterSuccess()` und `Usage::apply()`.
         */
        $bestand = $tenancy->withoutRestriction(static fn (): array => [
            'databases' => Database::query()->count(),
            'users' => DbUser::query()->count(),
            'dumps' => DatabaseDump::query()->count(),
        ]);

        $this->line(sprintf(
            '%d Datenbank(en), %d Zugang/Zugänge, %d Sicherung(en) im Bestand.',
            $bestand['databases'],
            $bestand['users'],
            $bestand['dumps'],
        ));

        /*
         * **Befristete Zugänge, die stehengeblieben sind.** Das Zurückspielen
         * legt einen an und entfernt ihn im `finally`; ein abgebrochener Lauf —
         * Stromausfall, SIGKILL — kann trotzdem einen zurücklassen, und das
         * wäre ein Zugang ohne Besitzer. Der Agent meldet nur solche, die älter
         * als eine Stunde sind (`DbServerInfo::GRACE_SECONDS`) — einer von vor
         * fünf Minuten gehört sehr wahrscheinlich zu einem Lauf, der gerade
         * arbeitet.
         */
        $stale = is_array($info['stale_users'] ?? null) ? $info['stale_users'] : [];

        if ($stale !== []) {
            $this->warn(sprintf('%d befristete(r) Zugang/Zugänge blieben stehen:', count($stale)));

            foreach ($stale as $user) {
                $this->line('  '.$user);
            }

            $this->line('Sie gehen mit `srvpanel db --prune`.');
        }

        $plan = $prune->plan();

        if ($plan['total'] === 0) {
            $this->info('Nichts liegengeblieben.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d Zeile(n) ohne Abonnement — siehe `srvpanel db --prune`.', $plan['total']));
        $this->printPlan($plan);

        return self::SUCCESS;
    }

    /**
     * Aufräumen — erst auf dem System, dann im Bestand.
     *
     * **Die Reihenfolge ist dieselbe wie bei `srvpanel tls --prune`, und aus
     * demselben Grund:** Bricht der Agent ab, bleibt die Zeile stehen und zeigt
     * weiter auf das, was sie meint — ein zweiter Lauf holt es nach.
     * Andersherum wäre das Schema danach unauffindbar, und es läge mit den
     * Daten eines Kunden in `/var/lib/mysql`, ohne dass noch irgendetwas darauf
     * zeigt.
     *
     * **Zugänge vor Schemata.** Ein Zugang, dessen Schema schon weg ist, ist
     * ein Zugang auf nichts; ein Schema, dessen Zugang noch da ist, ist ein
     * offener Weg zu Daten. Von den beiden Zwischenzuständen ist der erste der
     * harmlosere.
     */
    private function prune(Client $agent, DatabasePrune $prune): int
    {
        $plan = $prune->plan();

        if ($plan['total'] === 0) {
            $this->info('Nichts liegengeblieben.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d Zeile(n) ohne Abonnement:', $plan['total']));
        $this->printPlan($plan);

        if ($this->option('dry-run') === true) {
            $this->info('--dry-run: es wurde nichts angefasst.');

            return self::SUCCESS;
        }

        /*
         * Vorgabe `false`: Ohne Rückfrage — etwa unter `--no-interaction` —
         * wird nichts gelöscht. Bei einem Kommando, das die Daten eines Kunden
         * von der Platte nimmt, ist das die richtige Richtung. Wortgleich zu
         * `srvpanel tls --prune`.
         */
        if (! $this->confirm('Diese Reste entfernen? Der Vorgang ist nicht rückgängig zu machen.', false)) {
            $this->line('Abgebrochen.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($plan['users'] as $user) {
            $failed += $this->removeOne(
                $agent,
                'db.user.remove',
                ['name' => $user['name'], 'host' => $user['host'], 'user' => $this->prefixOf($user['name'])],
                $user['name'].'@'.$user['host'],
                fn (): int => $prune->forgetUser($user['id']),
            );
        }

        foreach ($plan['databases'] as $database) {
            $failed += $this->removeOne(
                $agent,
                'db.database.remove',
                ['name' => $database['name'], 'user' => $this->prefixOf($database['name'])],
                $database['name'],
                fn (): int => $prune->forgetDatabase($database['id']),
            );
        }

        foreach ($plan['dumps'] as $dump) {
            $failed += $this->removeOne(
                $agent,
                'db.dump.remove',
                ['subscription' => $dump['subscription'], 'storage' => $dump['name']],
                $dump['name'],
                fn (): int => $prune->forgetDump($dump['id']),
            );
        }

        if ($failed > 0) {
            $this->error(sprintf('%d Rest(e) blieben liegen — ein zweiter Lauf holt sie nach.', $failed));

            return self::FAILURE;
        }

        $this->info('Aufgeräumt.');

        return self::SUCCESS;
    }

    /**
     * Einen Rest entfernen: erst der Agent, dann die Zeile.
     *
     * @param  array<string, mixed>  $arguments
     * @param  callable(): int  $forget
     * @return int 1, wenn er liegenblieb
     */
    private function removeOne(Client $agent, string $operation, array $arguments, string $label, callable $forget): int
    {
        try {
            $agent->call($operation, $arguments, $this->actor());
        } catch (AgentException $error) {
            $this->error("  {$label}: {$error->getMessage()}");

            return 1;
        }

        $forget();
        $this->line("  {$label}: entfernt");

        return 0;
    }

    /**
     * Der Systembenutzer aus einem Namen.
     *
     * **Er wird gebraucht, obwohl das Abonnement fort ist.** Der Agent prüft
     * jede Operation gegen das Präfix — `Names::belongsTo()` —, damit ein
     * `DROP DATABASE mysql` an dieser Zeile scheitert. Die Prüfung entfällt
     * nicht, nur weil hier niemand mehr zuständig ist; sie bekommt das Präfix
     * aus dem Namen selbst, und der stammt aus einer abgelegten Zeile, die der
     * Agent seinerzeit erzeugt hat.
     */
    private function prefixOf(string $name): string
    {
        return explode('_', $name, 2)[0];
    }

    /**
     * @param  array{
     *     databases: list<array{id: int, name: string, subscription: string, size_bytes: int|null}>,
     *     users: list<array{id: int, name: string, host: string, subscription: string}>,
     *     dumps: list<array{id: int, name: string, subscription: string, bytes: int|null}>,
     *     total: int,
     * }  $plan
     */
    private function printPlan(array $plan): void
    {
        foreach ($plan['databases'] as $database) {
            $this->line(sprintf(
                '  Datenbank %s (%s, %s)',
                $database['name'],
                $database['subscription'],
                $this->size($database['size_bytes'], 'nicht gemessen'),
            ));
        }

        foreach ($plan['users'] as $user) {
            $this->line(sprintf('  Zugang %s@%s (%s)', $user['name'], $user['host'], $user['subscription']));
        }

        foreach ($plan['dumps'] as $dump) {
            $this->line(sprintf(
                '  Sicherung %s (%s, %s)',
                $dump['name'],
                $dump['subscription'],
                $this->size($dump['bytes'], 'Grösse unbekannt'),
            ));
        }
    }

    /**
     * Eine Byte-Zahl in der Einheit, die sie noch unterscheidbar lässt.
     *
     * **Hier stand zweimal `intdiv($bytes, 1024 * 1024).' MB'`,** und das ist
     * genau die Rundung, die der dritte Abnahmelauf als Fehler gezeigt hat: Eine
     * Datenbank mit 300 KB und eine leere lesen sich beide als „0 MB". Wer diese
     * Liste ansieht, sucht meist nach etwas, das er vermisst — und dann ist der
     * Unterschied zwischen „leer" und „klein" die ganze Auskunft (docs/36
     * §22.3j).
     */
    private function size(?int $bytes, string $whenNull): string
    {
        if ($bytes === null) {
            return $whenNull;
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return intdiv($bytes, 1024).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.').' GB';
    }

    /** @return array<string, string> */
    /**
     * Den Fernzugriff ein- oder ausschalten (`docs/36 §12`).
     *
     * **Drei Dinge stehen vor dem Aufruf, und jedes hat einen Anlass.**
     *
     * 1. **Wer ausschaltet, erfährt vorher, wen er aussperrt.** Ein Zugang für
     *    eine fremde Adresse ist in MariaDB ein eigener Benutzer; nach
     *    `--remote=off` erreicht ihn niemand mehr, und die Anwendung dahinter
     *    steht. Die Zahl kommt aus dem Bestand des Panels — der Agent kennt sie
     *    nicht, und er soll sie auch nicht kennen.
     * 2. **Der Neustart wird angesagt.** Der Datenbankserver trägt auch das
     *    Panel. Eine Rückfrage vor einer Unterbrechung ist das Mindeste;
     *    Vorgabe `false`, wie bei `--prune`, damit `--no-interaction` nichts tut.
     * 3. **Gemeldet wird, worauf der Server danach horcht** — seine Antwort und
     *    nicht die Datei, die wir geschrieben haben. Genau hier fällt auf, wenn
     *    eine andere Include-Datei gewinnt.
     */
    private function remote(Client $agent, Tenancy $tenancy): int
    {
        $mode = strtolower(trim((string) $this->option('remote')));

        if (! in_array($mode, ['on', 'off'], true)) {
            $this->error('--remote erwartet on oder off.');

            return self::FAILURE;
        }

        $address = (string) $this->option('bind');

        if ($mode === 'on' && ! in_array($address, ['0.0.0.0', '::'], true)) {
            $this->error('--bind erwartet 0.0.0.0 (IPv4) oder :: (beide Stapel).');

            return self::FAILURE;
        }

        $fern = $tenancy->withoutRestriction(
            static fn (): int => DbUser::query()->where('host', '!=', 'localhost')->count(),
        );

        if ($mode === 'off' && $fern > 0) {
            $this->warn(sprintf(
                '%d Zugang/Zugänge sind für eine fremde Adresse angelegt. Nach dem Ausschalten '
                .'erreicht sie niemand mehr — die Zeilen bleiben, die Verbindung nicht.',
                $fern,
            ));
        }

        $this->line($mode === 'on'
            ? sprintf('Der Datenbankserver wird auf %s horchen. Die IP-Beschränkung gilt in MariaDB '
                .'und nicht im Paketfilter — die Firewall kommt mit P9.', $address)
            : 'Der Datenbankserver wird wieder nur lokal erreichbar sein.');

        if (! $this->confirm('Dafür wird der Datenbankserver neu gestartet. Das Panel ist dabei kurz ohne Datenbank. Weiter?', false)) {
            $this->line('Abgebrochen.');

            return self::SUCCESS;
        }

        try {
            $result = $agent->call('db.remote.access', ['mode' => $mode, 'address' => $address], $this->actor());
        } catch (AgentException $error) {
            $this->error('Der Agent hat abgewiesen: '.$error->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('%s: %s', $result['path'] ?? '?', $mode === 'on' ? 'geschrieben' : 'entfernt'));

        $this->line(sprintf(
            'Horcht auf %s — Fernzugriff %s.',
            $result['bind_address'] ?? 'unbekannt',
            ($result['remote'] ?? false) === true ? 'möglich' : 'aus',
        ));

        /*
         * **Die Gegenprobe, und sie ist der Grund für die Zeile darüber.** Der
         * Agent meldet, was der Server nach dem Neustart sagt. Weicht das von
         * dem ab, was gewünscht war, ist der Lauf nicht erfolgreich, sondern
         * ein Befund — meistens eine andere Include-Datei, die später gelesen
         * wird als unsere.
         */
        if ((bool) ($result['remote'] ?? false) !== ($mode === 'on')) {
            $this->error(
                'Der Server horcht nicht so, wie es angefordert wurde. Vermutlich setzt eine andere '
                .'Include-Datei die Horchadresse später — sie werden lexikalisch gelesen.',
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:db'];
    }
}
