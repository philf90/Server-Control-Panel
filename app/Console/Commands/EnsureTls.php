<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tls\AcmeSettings;
use App\Support\Tls\CertificateRenewal;
use Illuminate\Console\Command;
use SrvPanel\Agent\Acme\Directories;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

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
        {--directory= : Zertifizierungsstelle setzen: staging oder production}';

    protected $description = 'Prüft das Zertifikat der Oberfläche und erneuert es, bevor es abläuft';

    public function handle(Client $agent, AcmeSettings $settings, CertificateRenewal $renewal): int
    {
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
                ['source' => 'cli', 'command' => 'srvpanel:tls'],
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
        $this->renew($renewal);

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
