<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Names;
use SrvPanel\Agent\Op;

/**
 * Selbstsigniertes Zertifikat für die Panel-Oberfläche.
 *
 * Es gibt das Panel beim ersten Start ohne Zertifikat nicht: Eine Anmeldung
 * über eine ungesicherte Verbindung wäre ein Passwort im Klartext auf dem
 * Weg. Das selbstsignierte Zertifikat ist die Notlösung dafür, dass beim
 * ersten Start noch kein Name auf diesen Server zeigt — abgelöst wird es in
 * P4 durch ACME.
 *
 * Erzeugt wird es mit der openssl-Erweiterung von PHP und nicht mit dem
 * Programm `openssl`: ein Programm weniger auf der Positivliste, und der
 * private Schlüssel geht nie durch eine Kommandozeile.
 *
 * **Es trägt einen subjectAltName, und ohne den wäre es wertlos.** Bis August
 * 2026 stand der Name nur im CommonName. Chrome liest den seit 2017 nicht
 * mehr, Firefox und Safari ebenso wenig — der Browser meldete deshalb nicht
 * „unbekannter Aussteller", sondern „der Name passt nicht", und das
 * Zertifikat liess sich auch durch Aufnahme in den eigenen Speicher nicht
 * brauchbar machen. Dazu kommt: Nach der Einrichtung ruft man das Panel über
 * die **IP** auf, und die stand nirgends darin.
 *
 * **CA:FALSE.** Ein selbstsigniertes Zertifikat, das gleichzeitig eine
 * Zertifizierungsstelle sein darf, ist ein Generalschlüssel: Wer den privaten
 * Schlüssel dieses Servers erbeutet, kann damit Zertifikate für *jeden* Namen
 * ausstellen, die jede Maschine akzeptiert, die dieses eine Zertifikat einmal
 * aufgenommen hat. Der Preis dafür ist, dass die Aufnahme in den
 * Zertifikatsspeicher je nach Betriebssystem umständlicher ist — für eine
 * Übergangslösung bis P4 ist das die richtige Seite des Tauschs.
 */
final class PanelTls implements Op
{
    /** Wie lange ein neu ausgestelltes Zertifikat gilt. */
    public const DAYS = 397;

    /** Ab wann erneuert wird. */
    public const RENEW_BEFORE_DAYS = 30;

    /**
     * Das Verzeichnis, auf das der Server-Block zeigt.
     *
     * Es steht als Konstante da, weil {@see self::reload()} sie braucht: nginx
     * neu zu laden ergibt nur dann Sinn, wenn das eben geschriebene Zertifikat
     * auch das ist, das er ausliefert.
     */
    public const DIRECTORY = '/etc/srvpanel/tls';

    public function __construct(private readonly string $directory = self::DIRECTORY) {}

    public static function name(): string
    {
        return 'panel.tls.ensure';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $certificate = $this->directory.'/panel.crt';
        $key = $this->directory.'/panel.key';

        $names = Names::forThisHost();

        if (is_file($certificate) && is_file($key) && ($args['force'] ?? false) !== true) {
            $reason = $this->renewalReason((string) file_get_contents($certificate), $names);

            if ($reason === null) {
                return [
                    'certificate' => $certificate,
                    'key' => $key,
                    'created' => false,
                    'names' => $names,
                ];
            }

            $context->output('stdout', 'Zertifikat wird ersetzt: '.$reason);
        }

        if (! extension_loaded('openssl')) {
            throw AgentException::execFailed('Die PHP-Erweiterung openssl fehlt.');
        }

        $context->progress(20, 'Schlüsselpaar');
        [$certificateText, $keyText] = $this->issue($names);

        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o750, true) && ! is_dir($this->directory)) {
            throw AgentException::execFailed('Verzeichnis für Zertifikate ließ sich nicht anlegen.');
        }

        // Der Stand von vorher, damit der Rückweg offensteht. Ein Panel, das
        // sich mit dem eigenen Zertifikat aussperrt, wäre nur noch über SSH zu
        // retten — dieselbe Überlegung wie beim Server-Block.
        $before = [
            'certificate' => is_file($certificate) ? (string) file_get_contents($certificate) : null,
            'key' => is_file($key) ? (string) file_get_contents($key) : null,
        ];

        $context->progress(60, 'Zertifikat ablegen');
        $this->write($certificate, $key, $certificateText, $keyText);

        $context->progress(80, 'nginx');
        $reloaded = $this->reload($context, $before, $certificate, $key);

        $context->progress(100, 'fertig');

        return [
            'certificate' => $certificate,
            'key' => $key,
            'created' => true,
            'names' => $names,
            'reloaded' => $reloaded,
        ];
    }

    /**
     * Warum das vorhandene Zertifikat nicht bleiben kann — oder `null`.
     *
     * **Zwei Gründe, und der zweite ist der neue.** Der erste ist die
     * Restlaufzeit. Der zweite: Der Rechner heisst nicht mehr so wie damals.
     * Ein Zertifikat, das auf einen alten Hostnamen lautet, ist auf diesem
     * Server so brauchbar wie keines.
     *
     * **Eine geänderte IP allein erneuert nicht.** Sie wäre der dritte
     * naheliegende Grund und ein schlechter: Auf einem Server mit Docker oder
     * libvirt kommen und gehen Adressen, und jede Änderung ergäbe ein neues
     * Zertifikat samt neuer Browserwarnung. Wer eine neue Adresse abgedeckt
     * haben will, stellt im Panel neu aus — das ist ein Klick und eine
     * bewusste Entscheidung.
     *
     * @param  array{dns: list<string>, ip: list<string>}  $names
     */
    public function renewalReason(string $pem, array $names): ?string
    {
        $parsed = openssl_x509_parse($pem);

        if (! is_array($parsed)) {
            return 'es ließ sich nicht lesen';
        }

        $remaining = (int) ($parsed['validTo_time_t'] ?? 0) - time();

        if ($remaining <= self::RENEW_BEFORE_DAYS * 86400) {
            return $remaining <= 0
                ? 'es ist abgelaufen'
                : sprintf('es läuft in %d Tagen ab', (int) ceil($remaining / 86400));
        }

        $covered = Names::fromCertificate($parsed);
        $host = $names['dns'][0] ?? null;

        if ($host !== null && ! in_array($host, $covered['dns'], true)) {
            return sprintf('der Rechner heisst jetzt %s', $host);
        }

        return null;
    }

    /**
     * Schlüssel und Zertifikat erzeugen.
     *
     * **Die Erweiterungen brauchen eine Konfigurationsdatei.** Die
     * openssl-Erweiterung nimmt einen subjectAltName nicht als Parameter
     * entgegen; sie liest ihn aus einem Abschnitt einer `.cnf`. Die Datei
     * enthält ausschliesslich Namen — kein Schlüsselmaterial —, liegt im
     * Zertifikatsverzeichnis, das root allein gehört, und wird danach
     * gelöscht.
     *
     * @param  array{dns: list<string>, ip: list<string>}  $names
     * @return array{0: string, 1: string} Zertifikat und Schlüssel als PEM
     */
    private function issue(array $names): array
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($pair === false) {
            throw AgentException::execFailed('Schlüsselpaar ließ sich nicht erzeugen.');
        }

        $config = $this->writeConfig($names);

        try {
            $options = [
                'digest_alg' => 'sha256',
                'config' => $config,
                'req_extensions' => 'srvpanel',
                'x509_extensions' => 'srvpanel',
            ];

            $csr = openssl_csr_new([
                'commonName' => substr($names['dns'][0] ?? 'srvpanel', 0, 64),
                'organizationName' => 'SrvPanel',
            ], $pair, $options);

            if ($csr === false) {
                throw AgentException::execFailed('Zertifikatsanforderung ließ sich nicht erzeugen.');
            }

            /*
             * Eine zufällige Seriennummer statt der Vorgabe `0`.
             *
             * Zwei selbstsignierte Zertifikate desselben Rechners hätten sonst
             * denselben Aussteller *und* dieselbe Seriennummer — für einen
             * Zertifikatsspeicher sind das zwei Fassungen desselben
             * Zertifikats, und welche er nimmt, ist seine Sache.
             */
            $x509 = openssl_csr_sign($csr, null, $pair, self::DAYS, $options, random_int(1, PHP_INT_MAX));

            if ($x509 === false) {
                throw AgentException::execFailed('Zertifikat ließ sich nicht signieren.');
            }
        } finally {
            @unlink($config);
        }

        openssl_x509_export($x509, $certificateText);
        openssl_pkey_export($pair, $keyText);

        return [(string) $certificateText, (string) $keyText];
    }

    /**
     * Die Konfigurationsdatei mit den Erweiterungen.
     *
     * @param  array{dns: list<string>, ip: list<string>}  $names
     */
    private function writeConfig(array $names): string
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o750, true) && ! is_dir($this->directory)) {
            throw AgentException::execFailed('Verzeichnis für Zertifikate ließ sich nicht anlegen.');
        }

        $alt = [];
        $index = 0;

        foreach ($names['dns'] as $dns) {
            $alt[] = 'DNS.'.(++$index).' = '.$dns;
        }

        $index = 0;

        foreach ($names['ip'] as $ip) {
            $alt[] = 'IP.'.(++$index).' = '.$ip;
        }

        $text = "[req]\ndistinguished_name = dn\nprompt = no\n\n[dn]\nCN = srvpanel\n\n"
            ."[srvpanel]\n"
            // CA:FALSE — siehe die Klassenbeschreibung. `critical` ist keine
            // Zierde: Ohne die Kennzeichnung darf ein Programm die
            // Einschränkung überlesen.
            ."basicConstraints = critical, CA:FALSE\n"
            ."keyUsage = critical, digitalSignature, keyEncipherment\n"
            ."extendedKeyUsage = serverAuth\n"
            ."subjectAltName = @alt\n\n"
            ."[alt]\n".implode("\n", $alt)."\n";

        // Der Name ist zufällig: Das Verzeichnis gehört zwar root allein, aber
        // ein vorhersagbarer Pfad, den ein root-Prozess schreibt, ist eine
        // Angewohnheit, die anderswo teuer wird.
        $path = $this->directory.'/openssl-'.bin2hex(random_bytes(8)).'.cnf';

        if (@file_put_contents($path, $text) === false) {
            throw AgentException::execFailed('Konfiguration für openssl ließ sich nicht schreiben.');
        }

        chmod($path, 0o600);

        return $path;
    }

    private function write(string $certificate, string $key, string $certificateText, string $keyText): void
    {
        file_put_contents($certificate, $certificateText);
        chmod($certificate, 0o644);

        // Der private Schlüssel gehört root allein. nginx liest ihn als
        // Masterprozess, und der läuft als root — die Worker brauchen ihn nicht.
        $temp = $key.'.neu';
        file_put_contents($temp, $keyText);
        chmod($temp, 0o600);
        rename($temp, $key);
    }

    /**
     * nginx das neue Zertifikat übernehmen lassen.
     *
     * **Ohne diesen Schritt wäre die Erneuerung wirkungslos.** nginx liest
     * Zertifikat und Schlüssel beim Start und behält sie im Speicher; eine
     * neue Datei auf der Platte ändert nichts an dem, was ausgeliefert wird.
     * Ein Zertifikat, das erneuert ist und trotzdem abläuft, ist schlimmer als
     * eines, das nie erneuert wurde — niemand sieht danach noch hin.
     *
     * **Erst prüfen, dann übernehmen, sonst zurück.** Dieselbe Reihenfolge wie
     * beim Server-Block: `nginx -t` liest die Zertifikatsdateien mit und
     * merkt, wenn eine davon unbrauchbar ist.
     *
     * @param  array{certificate: string|null, key: string|null}  $before
     */
    private function reload(Context $context, array $before, string $certificate, string $key): bool
    {
        /*
         * **Nur, wenn das eben geschriebene Zertifikat auch das ist, das nginx
         * ausliefert.**
         *
         * Hier stand als einzige Bedingung „gibt es das Programm nginx" — und
         * das ist die falsche Frage. Diese Operation kann in ein anderes
         * Verzeichnis schreiben; der Server-Block zeigt aber fest auf
         * `/etc/srvpanel/tls`. Ein Reload wäre dann ein Eingriff in den
         * laufenden Webserver für ein Zertifikat, das er gar nicht kennt.
         *
         * Aufgefallen ist es in der CI, wo nginx auf dem Läufer installiert
         * ist: Der Test schreibt in ein temporäres Verzeichnis, und die
         * Operation prüfte daraufhin die Systemkonfiguration von nginx — als
         * unprivilegiertes Konto, mit `open() "/run/nginx.pid" failed`. Neun
         * Tests fielen um. Auf meiner Maschine gibt es nginx nicht, deshalb
         * lief es dort durch: genau die Sorte Bedingung, die zu wenig fragt
         * und trotzdem meistens stimmt.
         */
        if ($this->directory !== self::DIRECTORY || ! is_file('/usr/sbin/nginx')) {
            return false;
        }

        $check = $context->runner->run('nginx', ['-t'], 30);

        if (! $check->successful()) {
            $this->restore($before, $certificate, $key);

            throw AgentException::execFailed('nginx hat das neue Zertifikat abgelehnt: '.$check->message());
        }

        $reload = $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

        if (! $reload->successful()) {
            $this->restore($before, $certificate, $key);
            $context->runner->run('systemctl', ['reload-or-restart', 'nginx.service'], 60);

            throw AgentException::execFailed('nginx ließ sich nicht neu laden: '.$reload->message());
        }

        return true;
    }

    /** @param array{certificate: string|null, key: string|null} $before */
    private function restore(array $before, string $certificate, string $key): void
    {
        if ($before['certificate'] === null || $before['key'] === null) {
            @unlink($certificate);
            @unlink($key);

            return;
        }

        $this->write($certificate, $key, $before['certificate'], $before['key']);
    }
}
