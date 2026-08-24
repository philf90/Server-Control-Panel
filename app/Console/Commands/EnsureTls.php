<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CertificateSource;
use App\Models\Domain;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificatePrune;
use App\Support\Tls\CertificateRecord;
use App\Support\Tls\CertificateRenewal;
use App\Support\Web\WebLifecycle;
use Illuminate\Console\Command;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\Names;

/**
 * Das Zertifikat der Panel-Oberfläche nachsehen und bei Bedarf erneuern.
 *
 * **Warum es dieses Kommando gibt.** Die Prüfung, ob ein Zertifikat noch
 * lange genug gilt, stand von Anfang an in `panel.tls.ensure` — sie lief nur
 * nie: Aufgerufen wurde die Operation ausschliesslich von `srvpanel setup`.
 * Nach der Einrichtung rührte sie niemand mehr an, und das Zertifikat wäre
 * eines Tages abgelaufen, ohne dass etwas passiert. Der erste, der es
 * gemerkt hätte, wäre der Betreiber vor einer Fehlermeldung gewesen.
 *
 * **Und seit P4 setzt es die beiden ACME-Angaben.** Das Formular dafür kommt
 * mit der Oberfläche zu TLS; ohne Kontaktadresse bestellt das Panel aber gar
 * nichts ({@see AcmeSettings}), und ein Server, auf dem TLS deshalb still
 * nichts tut, wäre der schlechteste erste Eindruck. Zwei Optionen an einem
 * Kommando, das ohnehin `srvpanel tls` heisst, sind der kürzeste Weg dahin.
 *
 * **Ein Timer und kein Dauerlauf** — dieselbe Überlegung wie bei der
 * Speichermessung: Ein Zertifikat ändert seinen Zustand einmal im Jahr, und
 * ein Prozess, der darauf wartet, ist ein Prozess, den jemand überwachen muss.
 *
 * **Und kein Eintrag in `srvpanel update`.** Das Kommando stösst dort nur eine
 * systemd-Unit an und ist danach fertig; ein Update kann Monate auseinander
 * liegen. Eine Erneuerung, die an Updates hängt, erneuert genau dann nicht,
 * wenn ein Server lange unangetastet läuft — also im einzigen Fall, der zählt.
 */
final class EnsureTls extends Command
{
    protected $signature = 'srvpanel:tls
        {--force : Neu ausstellen, auch wenn das vorhandene noch gilt}
        {--contact= : Kontaktadresse für ACME setzen — an sie schreibt die Zertifizierungsstelle}
        {--directory= : Zertifizierungsstelle setzen: staging oder production}
        {--upload : Ein eigenes Zertifikat ablegen — mit --domain, --certificate und --key}
        {--domain= : Für welche Domain das hochgeladene Zertifikat gilt}
        {--certificate= : Pfad zur PEM-Kette: erst das eigene, dann die ausstellenden}
        {--key= : Pfad zum privaten Schlüssel, ohne Passwort}
        {--prune : Zertifikate zurückgebauter Abonnements entfernen — Dateien und Zeilen}
        {--dry-run : Mit --prune: nur zeigen, was entfernt würde}';

    protected $description = 'Prüft das Zertifikat der Oberfläche und erneuert es, bevor es abläuft';

    public function handle(
        Client $agent,
        AcmeSettings $settings,
        CertificateRenewal $renewal,
        Tenancy $tenancy,
        CertificateRecord $record,
        WebLifecycle $web,
        CertificatePrune $prune,
    ): int {
        if ($this->option('upload') === true) {
            return $this->upload($agent, $tenancy, $record, $web);
        }

        if ($this->option('prune') === true) {
            return $this->prune($agent, $prune);
        }

        $contact = $this->option('contact');
        $directory = $this->option('directory');

        // Wer etwas setzt, will nichts erneuern. Beides in einem Lauf zu tun,
        // hiesse das Zertifikat der Oberfläche anzufassen, weil jemand eine
        // Adresse eingetragen hat — und dieser Zusammenhang besteht nicht.
        if (is_string($contact) || is_string($directory)) {
            return $this->storeAcme($settings, $contact, $directory);
        }

        try {
            $result = $agent->call(
                'panel.tls.ensure',
                ['force' => (bool) $this->option('force')],
                $this->actor(),
            );
        } catch (AgentException $error) {
            $this->error('Zertifikat: '.$error->getMessage());

            return self::FAILURE;
        }

        $names = $result['names'] ?? ['dns' => [], 'ip' => []];

        // Die Namen stehen in beiden Fällen da, nicht nur beim Ausstellen.
        // „Gilt noch" beantwortet die Frage nicht, die jemand hat, der wegen
        // einer Browserwarnung hier nachsieht: unter welchem Namen denn?
        $liste = implode(', ', [...$names['dns'] ?? [], ...$names['ip'] ?? []]);

        if ($result['created'] !== true) {
            $this->info('Das Zertifikat gilt noch und deckt diesen Rechner ab.');
            $this->line('  Namen: '.$liste);
        } else {
            $this->info('Neues Zertifikat ausgestellt für '.$liste.'.');

            // Ob nginx es schon ausliefert, ist die Angabe, auf die es ankommt:
            // Ein erneuertes Zertifikat, das der Webserver nicht kennt, läuft
            // genauso ab wie ein nicht erneuertes.
            $this->line($result['reloaded'] === true
                ? '  nginx wurde neu geladen.'
                : '  nginx läuft nicht — es wird beim nächsten Start übernommen.');
        }

        $this->showAcme($settings);
        $this->panel($agent, $settings);
        $this->renew($renewal);

        return self::SUCCESS;
    }

    /**
     * Ein vertrautes Zertifikat für die Oberfläche selbst.
     *
     * **Es läuft ohne Vorgang und ohne Zeile im Bestand**, und das ist eine
     * Entscheidung: Das Zertifikat der Oberfläche gehört keinem Kunden, hängt
     * an keiner Domain und wird von keiner Seite verwaltet. Was gilt, steht im
     * Ablageort — dieselbe Aufteilung wie bei `panel.tls.ensure`, das dieses
     * Kommando eine Zeile weiter oben ebenfalls direkt ruft. Eine Zeile in
     * `certificates` bräuchte einen zweiten Erneuerungsweg, und der zweite Weg
     * ist immer der, der veraltet.
     *
     * **Das selbstsignierte bleibt liegen.** Es ist der Rückweg, wenn unter
     * dem Namen dieses Servers nichts mehr steht, und `panel.tls.ensure` hält
     * es weiter gültig. Ein Rückweg, den man erst wieder herstellen muss, ist
     * keiner.
     *
     * **Ein Fehlschlag hält den Lauf nicht an.** Ohne DNS-Eintrag auf diesen
     * Server kann die Prüfung nicht gelingen — das ist beim Einrichten der
     * Normalfall und kein Grund, eine Unit rot zu färben. Die Oberfläche
     * antwortet weiter, nur eben mit einer Browserwarnung.
     */
    private function panel(Client $agent, AcmeSettings $settings): void
    {
        if (! $settings->mayOrderForPanel()) {
            return;
        }

        $name = Names::fqdn();

        if ($name === null) {
            $this->line('  Kein vollständiger Rechnername — für die Oberfläche wird nichts bestellt.');

            return;
        }

        try {
            if ($this->panelCertificateHolds($agent, $name)) {
                return;
            }

            $this->line('  Zertifikat für die Oberfläche wird bestellt: '.$name);

            $agent->call('acme.certificate.issue', [
                'names' => [$name],
                'contact' => $settings->contact(),
                'directory' => $settings->directory(),
            ], $this->actor());

            // Erst der Block, dann gilt es: nginx liest die Datei beim Laden.
            // Ohne diesen Aufruf läge ein vertrautes Zertifikat da, und der
            // Browser bekäme weiter das selbstsignierte — ohne Fehlermeldung.
            $vhost = $agent->call('panel.vhost.apply', [], $this->actor());

            $this->info(sprintf(
                '  Die Oberfläche liefert jetzt ein Zertifikat von Let’s Encrypt aus (Port %d).',
                is_int($vhost['port'] ?? null) ? $vhost['port'] : 0,
            ));
        } catch (AgentException $error) {
            // Kein Abbruch: Die Oberfläche läuft mit dem selbstsignierten
            // weiter, und beim Einrichten fehlt der DNS-Eintrag oft noch.
            $this->warn('  Zertifikat für die Oberfläche: '.$error->getMessage());
        }
    }

    /**
     * Gilt das Zertifikat der Oberfläche noch lange genug?
     *
     * Gefragt wird der Ablageort und nicht der Bestand — dort steht, was nginx
     * ausliefert. Dieselbe Frist wie bei den Kundenzertifikaten: Wer zwei
     * Zahlen führt, führt irgendwann zwei verschiedene.
     */
    private function panelCertificateHolds(Client $agent, string $name): bool
    {
        $info = $agent->call('acme.certificate.info', ['name' => $name], $this->actor());

        if (($info['present'] ?? false) !== true) {
            return false;
        }

        $validTo = is_int($info['valid_to'] ?? null) ? $info['valid_to'] : 0;
        $rest = $validTo - time();

        if ($rest <= CertificateRenewal::LEAD_DAYS * 86400) {
            return false;
        }

        $this->line(sprintf(
            '  Die Oberfläche hat ein Zertifikat für %s, noch %d Tage gültig.',
            $name,
            intdiv($rest, 86400),
        ));

        return true;
    }

    /** @return array<string, string> */
    private function actor(): array
    {
        return ['source' => 'cli', 'command' => 'srvpanel:tls'];
    }

    /**
     * Die ungebrauchten Zertifikate entfernen — Dateien und Zeilen.
     *
     * **Warum es das gibt.** Bis August 2026 konnte dieses System ein
     * Zertifikat anlegen und erneuern, aber nirgends löschen. Ein
     * zurückgebautes Abonnement liess sein Verzeichnis unter
     * `/etc/srvpanel/tls/certs` liegen — **samt privatem Schlüssel** —, denn
     * `subscription.remove` räumt nur auf, was zum Abo-Verzeichnis gehört. Auf
     * dem Zielserver waren es zwölf, und aufgefallen sind sie erst, als die
     * Migration aus docs/35 danach fragte.
     *
     * **Was fort darf, entscheidet {@see CertificatePrune} und nicht dieses
     * Kommando.** Die Auswahl ist die ganze Sicherheit des Vorgangs; sie steht
     * an einer Stelle, damit ein Test sie prüfen kann, ohne sie nachzubauen.
     *
     * **Seit dem 24. August 2026 fasst es zwei Fälle**, und der zweite ist
     * derselbe Fehler eine Ebene tiefer: Auch der Rückbau einer einzelnen
     * **Domain** liess ihr Zertifikat liegen, und weil das Abonnement weiterlebt,
     * verwaiste die Zeile nie. Gemessen auf `cloudsrv24` an `tls.cloudlab24.de`:
     * null verweisende Domains, `privkey.pem` lag, `--prune` führte es nicht auf.
     *
     * **Erst die Datei, dann die Zeile.** Bricht der Agent ab, bleibt die Zeile
     * stehen und zeigt weiter auf ihr Verzeichnis — ein zweiter Lauf holt es
     * nach. Andersherum wäre die Datei danach unauffindbar.
     */
    private function prune(Client $agent, CertificatePrune $prune): int
    {
        $plan = $prune->plan();

        // **Die Frage stellt der Plan und nicht dieses Kommando.** Hier stand
        // bis zum 24. August 2026 `$plan['orphans'] === 0` — eine zweite
        // Fassung der Regel, und beim zweiten Fall war sie veraltet: Das
        // Kommando meldete „keine verwaisten Zertifikate" und liess den
        // privaten Schlüssel liegen.
        if ($plan['nothing']) {
            $this->info('Keine ungebrauchten Zertifikate.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            // **Jede Zahl mit ihrem eigenen Substantiv.** „11 verwaiste und 1
            // Zeile(n) ohne Domain" liess die erste Zahl ohne Wort dastehen —
            // „Zeile(n)" gehörte sichtbar zur zweiten. Gesehen auf dem
            // Zielserver, nicht im Test: `CountedNounTest` prüft eine Zahl mit
            // anschliessendem Mehrzahlwort, und hier fehlte das Wort.
            '%d verwaiste Zeile(n), %d Zeile(n) ohne Domain, %d Ablageort(e) zu entfernen.',
            $plan['orphans'],
            $plan['abandoned'],
            count($plan['removable']),
        ));

        foreach ($plan['shared'] as $name) {
            $this->warn("  {$name}: Ablageort bleibt — er wird noch gebraucht. Nur die Zeile geht.");
        }

        // **Mit dem Grund daneben.** „Was" allein genügt bei einem Vorgang
        // nicht, der einen privaten Schlüssel von der Platte nimmt: Wer
        // bestätigt, soll lesen können, ob das Abonnement fort ist oder ob nur
        // keine Domain mehr darauf zeigt.
        foreach ($plan['removable'] as $name) {
            $this->line(sprintf('  %s: Ablageort und Zeile(n) — %s', $name, $plan['reasons'][$name] ?? 'ohne Grund'));
        }

        if ($this->option('dry-run') === true) {
            $this->info('--dry-run: es wurde nichts angefasst.');

            return self::SUCCESS;
        }

        // Vorgabe `false`: Ohne Rückfrage — etwa unter `--no-interaction` —
        // wird nichts gelöscht. Bei einem Kommando, das private Schlüssel von
        // der Platte nimmt, ist das die richtige Richtung.
        if (! $this->confirm('Diese Zertifikate entfernen? Der Vorgang ist nicht rückgängig zu machen.', false)) {
            $this->line('Abgebrochen.');

            return self::SUCCESS;
        }

        foreach ($plan['removable'] as $name) {
            try {
                $result = $agent->call('acme.certificate.remove', ['name' => $name], $this->actor());
            } catch (AgentException $error) {
                $this->error("  {$name}: {$error->getMessage()}");

                continue;
            }

            $this->line(sprintf(
                '  %s: %s',
                $name,
                ($result['removed'] ?? false) === true ? 'entfernt' : 'war schon fort',
            ));

            $prune->forget($name);
        }

        // Die geteilten Ablageorte behalten ihre Datei und verlieren ihre
        // verwaiste Zeile — die Datei gehört ab jetzt dem, der sie noch nennt.
        foreach ($plan['shared'] as $name) {
            $prune->forget($name);
        }

        $this->info('Aufgeräumt.');

        return self::SUCCESS;
    }

    /**
     * Die Zertifikate der Kunden erneuern, soweit fällig.
     *
     * **Am selben Timer und nicht an einem zweiten.** Die Frage ist dieselbe —
     * läuft ein Zertifikat ab? —, nur für Kundendomains statt für die
     * Oberfläche. Ein eigener Dienst dafür wäre eine zweite Unit, ein zweiter
     * Zeitplan und eine zweite Stelle, an der jemand nachsieht, warum nichts
     * passiert.
     *
     * **Nach dem Panel und nicht davor:** Ist der Agent nicht erreichbar, ist
     * dieser Lauf oben schon beendet. Bestellungen einzureihen, die
     * anschliessend scheitern, verbraucht Fehlversuche bei der
     * Zertifizierungsstelle.
     */
    private function renew(CertificateRenewal $renewal): void
    {
        $report = $renewal->run();

        $this->line(sprintf(
            '  Kundenzertifikate: %d fällig, %d bestellt, %d nachgetragen.',
            $report->due,
            $report->ordered,
            $report->corrected,
        ));

        // Eine Grenze, die niemand nennt, sieht aus wie „alles erledigt".
        if ($report->left > 0) {
            $this->line(sprintf(
                '  %d warten auf den nächsten Lauf — höchstens %d Bestellungen je Lauf.',
                $report->left,
                CertificateRenewal::PER_RUN,
            ));
        }

        // **Und das hier ist keine Zeile unter anderen.** Ein Platzhalter, der
        // sich nicht mehr als Platzhalter bestellen lässt, läuft ab — und mit
        // ihm jede Unterdomain der Zone. Deshalb als Fehler und nicht als
        // Auskunft: Wer den Lauf aus einem Skript fährt, sieht sonst nichts.
        if ($report->blocked > 0) {
            $this->error(sprintf(
                '  %d Platzhalter lassen sich nicht erneuern — es fehlen die DNS-Zugangsdaten ihres Profils.',
                $report->blocked,
            ));
        }
    }

    /**
     * Die beiden ACME-Angaben setzen.
     *
     * Geprüft wird beides hier und nicht erst beim Bestellen: Eine Adresse, die
     * keine ist, fiele sonst erst auf, wenn ein Kunde eine Domain anlegt — und
     * dann als Vorgang, der ohne Zutun scheitert. Der Schlüssel der
     * Zertifizierungsstelle geht durch dieselbe Positivliste, die auch der
     * Agent befragt ({@see Directories}); was hier durchkommt, kann dort keine
     * unbekannte Adresse mehr werden.
     *
     * **Nicht `configure()`.** Der Name gehört Symfony und ist dort `protected`
     * — eine private Methode desselben Namens lässt die Klasse nicht mehr
     * laden, und zwar mit einem fatalen Fehler beim Einlesen der Kommandos.
     * Damit steht nicht ein Kommando still, sondern `artisan` mit allen.
     * Zweiter Fall dieser Sorte in dieser Woche; der erste war `count()` in
     * einem PHPUnit-Testfall.
     */
    /**
     * Ein eigenes Zertifikat ablegen.
     *
     * **Unmittelbar und nicht über die Warteschlange, und das ist der Grund
     * dafür.** Ein eingereihter Vorgang legt seine Argumente in
     * `operations.payload` ab — der private Schlüssel läge damit im Klartext in
     * der Datenbank, und zwar dauerhaft und für jeden lesbar, der sie liest.
     * Er darf den Socket genau einmal überqueren und nirgends sonst stehen.
     * Deshalb ruft dieses Kommando den Agenten selbst und schreibt den Bestand
     * über {@see CertificateRecord}, die dieselbe Zeile erzeugt wie eine
     * Bestellung.
     *
     * **Gelesen werden die Dateien hier und nicht im Agenten.** Ein Pfad, den
     * das Panel an einen Prozess als root weiterreicht, wäre die Erlaubnis,
     * eine beliebige Datei des Servers zu lesen — `/etc/shadow` als
     * „Zertifikat" käme zwar durch keine Prüfung, stünde danach aber im
     * Fehlertext. Was hinausgeht, ist Inhalt und kein Pfad.
     */
    private function upload(Client $agent, Tenancy $tenancy, CertificateRecord $record, WebLifecycle $web): int
    {
        $name = $this->option('domain');

        // **Erst die fehlenden Angaben, dann die Dateien — und nur eine
        // Meldung je Ursache.**
        //
        // Vorher las diese Stelle beides auf einmal und schrieb danach in jedem
        // Fall „Es fehlt eine Angabe". Wer einen unlesbaren Schlüssel angab,
        // bekam zwei Sätze: den richtigen („nicht lesbar") und darunter einen
        // falschen. Der zweite ist der, den man glaubt — und er schickt zur
        // Kommandozeile statt zu den Dateirechten. Im Abnahmelauf am 7. August
        // 2026 genau so passiert.
        $fehlend = [];

        foreach (['domain', 'certificate', 'key'] as $option) {
            $value = $this->option($option);

            if (! is_string($value) || trim($value) === '') {
                $fehlend[] = '--'.$option;
            }
        }

        if ($fehlend !== []) {
            $this->error(sprintf(
                'Es fehlt: %s — --domain, --certificate und --key gehören zusammen.',
                implode(', ', $fehlend),
            ));

            return self::FAILURE;
        }

        $chain = $this->contents($this->option('certificate'), 'certificate');
        $key = $this->contents($this->option('key'), 'key');

        // `contents()` hat schon gesagt, woran es liegt.
        if (! is_string($name) || $chain === null || $key === null) {
            return self::FAILURE;
        }

        $wanted = strtolower(trim($name));

        $domain = $tenancy->withoutRestriction(
            fn (): ?Domain => Domain::query()->where('name', $wanted)->first(),
        );

        if (! $domain instanceof Domain) {
            $this->error('Diese Domain gibt es nicht: '.$wanted);

            return self::FAILURE;
        }

        try {
            $result = $agent->call('tls.certificate.upload', [
                'certificate' => $chain,
                'private_key' => $key,
            ], $this->actor());
        } catch (AgentException $error) {
            // Die Meldung des Agenten nennt den Grund — falsch sortierte Kette,
            // Schlüssel passt nicht, abgelaufen. Sie ist das Wertvollste an
            // diesem Vorgang und wird deshalb wörtlich durchgereicht.
            $this->error('Zertifikat abgewiesen: '.$error->getMessage());

            return self::FAILURE;
        }

        $tenancy->withoutRestriction(function () use ($record, $domain, $result): void {
            $record->store($domain, $result, CertificateSource::Uploaded);
        });

        $until = $result['not_after'] ?? null;

        $this->info(sprintf(
            'Abgelegt für %s: %s (gültig bis %s).',
            $domain->name,
            implode(', ', $this->names($result)),
            is_int($until) && $until > 0 ? gmdate('d.m.Y', $until) : '?',
        ));

        // **Ohne diesen Vorgang gilt es und der Server-Block kennt es nicht.**
        // Dieselbe Falle wie bei einer Bestellung (`docs/32 §8`): Der Block
        // entsteht bei `web.site.apply`, und welches Zertifikat er ausliefert,
        // steht seit dem zweiten Wurf in seinen Argumenten.
        $tenancy->withoutRestriction(function () use ($web, $domain): void {
            $web->apply($domain, 'Server-Block mit hochgeladenem Zertifikat für '.$domain->name);
        });

        $this->line('  Der Server-Block wird neu geschrieben.');

        return self::SUCCESS;
    }

    /**
     * Die Namen aus der Antwort des Agenten, als saubere Liste.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    private function names(array $result): array
    {
        $value = $result['names'] ?? null;
        $names = [];

        if (is_array($value)) {
            foreach ($value as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Der Inhalt einer Datei, die der Betreiber genannt hat.
     *
     * Gelesen wird als das Konto des Panels und nicht als root: Wer eine Datei
     * hochlädt, die dieses Konto nicht lesen darf, bekommt es hier gesagt — und
     * nicht dadurch, dass ein Prozess mit Systemrechten sie für ihn öffnet.
     */
    private function contents(mixed $path, string $option): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! is_file($path)) {
            $this->error(sprintf('--%s: Diese Datei gibt es nicht: %s', $option, $path));

            return null;
        }

        /*
         * **„Nicht lesbar" ist ohne den Benutzer keine Auskunft.**
         *
         * `srvpanel` wechselt per `setpriv` auf den Dienstbenutzer, bevor
         * artisan startet — gelesen wird also **nicht** als root. Ein privater
         * Schlüssel, den ein Betreiber gerade mit `openssl req -keyout`
         * angelegt hat, gehört root und steht auf 0600; dieses Kommando kommt
         * nicht heran, und zwar immer.
         *
         * Ohne den Namen des Benutzers ist der naheliegende nächste Griff
         * `chmod 644` auf einen privaten Schlüssel. Deshalb steht er dabei, und
         * deshalb steht dabei, dass das Kommando nicht als root läuft.
         */
        if (! is_readable($path)) {
            $this->error(sprintf(
                '--%s: %s ist für den Benutzer %s nicht lesbar — dieses Kommando läuft nicht als root.',
                $option,
                $path,
                $this->serviceUser(),
            ));

            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || trim($contents) === '') {
            $this->error(sprintf('--%s: Diese Datei ist leer: %s', $option, $path));

            return null;
        }

        return $contents;
    }

    /**
     * Als wer dieses Kommando gerade liest.
     *
     * `posix_geteuid()` und nicht `get_current_user()`: Das zweite nennt den
     * Eigentümer der Skriptdatei und nicht den Benutzer des Prozesses — auf
     * einem Panel, das per `setpriv` wechselt, sind das zwei verschiedene
     * Antworten, und die falsche schickt den Betreiber in die Irre.
     */
    private function serviceUser(): string
    {
        if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
            return 'des Dienstes';
        }

        // `posix_getpwuid()` gibt `false`, wenn es den Eintrag nicht gibt —
        // sonst steht `name` darin, und zwar als Zeichenkette. Eine zweite
        // Prüfung darauf wäre ein Zweig, den nichts erreichen kann.
        $user = posix_getpwuid(posix_geteuid());

        return $user === false ? 'des Dienstes' : $user['name'];
    }

    private function storeAcme(AcmeSettings $settings, mixed $contact, mixed $directory): int
    {
        $values = [];

        if (is_string($contact)) {
            if (filter_var($contact, FILTER_VALIDATE_EMAIL) === false) {
                $this->error('Das ist keine Kontaktadresse: '.$contact);

                return self::FAILURE;
            }

            $values['contact'] = $contact;
        }

        if (is_string($directory)) {
            if (! in_array($directory, Directories::keys(), true)) {
                $this->error('Unbekannte Zertifizierungsstelle: '.$directory.
                    '. Möglich ist '.implode(' oder ', Directories::keys()).'.');

                return self::FAILURE;
            }

            $values['directory'] = $directory;
        }

        $settings->update($values);

        $this->info('Gespeichert.');
        $this->showAcme($settings);

        return self::SUCCESS;
    }

    /**
     * Was für ACME eingetragen ist.
     *
     * Die Zeile steht auch nach einem gewöhnlichen Lauf da, weil sie die Frage
     * beantwortet, die sonst niemand beantwortet: Warum bekommt eine Domain
     * kein Zertifikat? Ohne Kontaktadresse bestellt das Panel nichts, und das
     * ist von aussen nicht zu sehen — es passiert schlicht nichts.
     */
    private function showAcme(AcmeSettings $settings): void
    {
        $contact = $settings->contact();

        $this->line('  ACME-Kontakt: '.($contact ?? 'nicht eingetragen — es wird nichts bestellt'));
        $this->line('  Zertifizierungsstelle: '.$settings->directory().
            ($settings->staging() ? ' (Testbetrieb)' : ''));
    }
}
