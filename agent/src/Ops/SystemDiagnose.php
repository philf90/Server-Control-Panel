<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Acme\Store;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\Diagnose\Verdict;
use SrvPanel\Agent\DomainName;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Keys;
use SrvPanel\Agent\ManagedBlock;
use SrvPanel\Agent\Mounts;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Pg\Server;
use SrvPanel\Agent\Pg\Session;
use SrvPanel\Agent\PhpVersions;
use SrvPanel\Agent\PoolTemplate;
use SrvPanel\Agent\Result;
use SrvPanel\Agent\Runner;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use SrvPanel\Agent\Sources;
use SrvPanel\Agent\Ssh\SshdConfig;

/**
 * Die Diagnose des Bestands, soweit sie Systemrechte braucht (A10, `docs/98 §3`).
 *
 * ## Was sie prüft
 *
 * | Schlüssel | Frage |
 * |---|---|
 * | `web.config` | Lehnt `nginx -t` den laufenden Bestand ab? |
 * | `php.config` | Lehnt `php-fpmX -t` ihn ab — je installierter Version? |
 * | `ssh.config` | Lehnt `sshd -t` ihn ab? |
 * | `block.integrity` | Ist die **Form** der beiden verwalteten Bereiche heil? |
 * | `quota.state` | Wird die Quota unter `/var/www/vhosts` **erzwungen**? |
 * | `apt.key` | Gilt der Signaturschlüssel der eigenen Paketquelle noch? |
 *
 * Die übrigen Prüfungen aus `docs/98 §3` — Units, Zertifikate, Systembenutzer,
 * verwaiste Zeilen, die Dateien des Panels — fragen, was A2, P4 und `docs/35`
 * gebaut haben, und brauchen dafür keinen Agenten. Sie liegen im Panel.
 *
 * ## Die drei Regeln
 *
 * **1. Sie schreibt nichts.** Kein Block wird neu gesetzt, kein Verzeichnis
 * angelegt, kein Dienst angefasst — auch dann nicht, wenn es die Messung
 * erleichterte. `sshd -t` braucht `/run/sshd`, und {@see SftpAccess} legt es an;
 * diese Operation meldet stattdessen `unreachable`, wenn es fehlt. Ein
 * Diagnoselauf, der schreibt, ist der nächste Schreiber in derselben Datei
 * (`docs/98 §5`), und `DiagnoseWriteTest` hält das.
 *
 * **2. Kein Pfad und keine Unit kommen von aussen.** Übergeben wird eine Liste
 * von Schlüsseln aus {@see Verdict::REASONS}; alles andere steht hier.
 *
 * **3. Sie urteilt nicht — sie ruft und klebt.** Was eine Antwort bedeutet,
 * steht in {@see Verdict}, als reine Funktion über das {@see Result},
 * und ist dort ohne Server prüfbar.
 *
 * ## Die Form der Antwort
 *
 * Je Schlüssel eine Liste von Befunden — `subject`, `reason`, `detail` — in
 * der Form aus `docs/98 §2`. **Eine leere Liste heisst „geprüft, nichts
 * gefunden".** Konnte eine Prüfung nicht laufen, steht das als eigener Befund
 * mit dem Grund `unreachable` da und nicht als leere Liste: Eine leere Liste,
 * die zwei Dinge bedeuten kann, bedeutet keins von beiden (`docs/44`).
 *
 * `detail` trägt den **ungekürzten Wortlaut** des Werkzeugs; gekürzt wird im
 * Panel, vor der Spalte. Der Wortlaut wechselt mit jedem Lauf (Datum,
 * Prozessnummer — `docs/81 §2.3o` M9) und ist deshalb nie Teil der Kennung.
 */
final class SystemDiagnose implements Op
{
    /** @var list<string> */
    public const CHECKS = ['web.config', 'php.config', 'ssh.config', 'web.file', 'php.file', 'block.integrity', 'quota.state', 'apt.key'];

    /**
     * Wie viele Gegenstände ein Aufruf für `web.file` und `php.file` tragen darf.
     *
     * Wie {@see SshdConfig::MAX_ACCESSES} keine Kapazitätsaussage, sondern ein
     * Riegel: Was hier ankommt, wird Name für Name zu einem Pfad, der geöffnet
     * wird.
     */
    public const MAX_SUBJECTS = 2048;

    /**
     * Wozu ein verwalteter Bereich gehört.
     *
     * Der Gegenstand eines Befundes bleibt der **Pfad** — den zeigt die Seite.
     * Die Rolle sagt dem Panel, welcher Sollzustand für diese Datei gilt, und
     * ist damit die Antwort auf eine Frage, die es sich sonst am Pfad
     * zusammenreimen müsste. `pg_hba.conf` liegt nicht überall gleich; ein
     * Vergleich am Dateinamen wäre eine Regel, die bei der ersten Distribution
     * mit anderem Ablageort still das Falsche tut.
     */
    public const ROLE_SSHD = 'sshd';

    public const ROLE_HBA = 'pg_hba';

    /** Der Gegenstand von `web.config` — die Datei, die `nginx -t` ohne `-c` liest. */
    public const NGINX_CONF = '/etc/nginx/nginx.conf';

    public function __construct(
        private readonly Session $session = new Session,
        private readonly Server $server = new Server,
        private readonly Store $store = new Store,
    ) {}

    public static function name(): string
    {
        return 'system.diagnose';
    }

    public static function mutating(): bool
    {
        return false;
    }

    /**
     * @return array{
     *     checks: array<string, list<array{subject: string, reason: string, detail: null|string}>>,
     *     managed: list<array{path: string, role: string, present: bool, lines: list<string>}>,
     * }
     */
    public function execute(array $args, Context $context): array
    {
        $wanted = $args['checks'] ?? self::CHECKS;

        if (! is_array($wanted) || $wanted === []) {
            throw AgentException::badRequest('checks muss eine Liste von Prüfungen sein.');
        }

        $checks = [];
        $managed = [];

        foreach (array_values(array_unique($wanted)) as $check) {
            $key = Guard::enum($check, self::CHECKS, 'checks');

            /*
             * **Diese eine Prüfung hat zwei Ausgänge, und deshalb steht sie
             * nicht im `match`.** Ihre Befunde sagen, ob die Marken heil sind;
             * ihre Zeilen sagen, was darin steht — und ob das richtig ist,
             * entscheidet der Sollzustand, den nur das Panel kennt. Beides in
             * einen Rückgabewert zu pressen hiesse, die Form aller anderen
             * Prüfungen für eine zu verbiegen.
             */
            if ($key === 'block.integrity') {
                [$checks[$key], $managed] = $this->blocks($context);

                continue;
            }

            $checks[$key] = match ($key) {
                'web.config' => $this->webConfig($context),
                'php.config' => $this->phpConfig($context),
                'ssh.config' => $this->sshConfig($context),
                'web.file' => $this->webFiles($args),
                'php.file' => $this->phpFiles($args),
                'quota.state' => $this->quota($context),
                'apt.key' => $this->aptKey($context),
                // Unerreichbar — Guard::enum() lässt nur CHECKS durch. Der Zweig
                // steht für den Typprüfer und für den Tag, an dem jemand CHECKS
                // um einen Schlüssel ergänzt und diesen match vergisst.
                default => throw AgentException::badRequest('Unbekannte Prüfung: '.$key),
            };
        }

        return ['checks' => $checks, 'managed' => $managed];
    }

    // ------------------------------------------------------------------ A

    /** @return list<array{subject: string, reason: string, detail: null|string}> */
    private function webConfig(Context $context): array
    {
        return $this->validator($context, 'nginx', ['-t'], self::NGINX_CONF);
    }

    /**
     * Je installierter Version einmal — `php-fpm` ohne Zahl gibt es auf
     * Debian nicht ({@see PhpVersions::binary()}).
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function phpConfig(Context $context): array
    {
        $findings = [];

        foreach (PhpVersions::available() as $version) {
            // Der Gegenstand ist die Datei, die `php-fpmX -t` ohne `-y` liest.
            // Zusammengesetzt und nicht erfragt — als **Beschriftung** und nicht
            // als Pfad, der geöffnet wird: Geprüft wird über den Prüfer.
            $subject = PhpVersions::PHP_ROOT.'/'.$version.'/fpm/php-fpm.conf';

            foreach ($this->validator($context, PhpVersions::program($version), ['-t'], $subject) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * `sshd -t` — mit der einen Vorbedingung, die diese Operation nicht herstellt.
     *
     * Ohne `/run/sshd` meldet `sshd -t` `rc=255` auf einer heilen Datei
     * (`docs/81 §2.3o` M1). {@see SftpAccess::ensureRuntime()} legt das
     * Verzeichnis an, weil es schreiben darf; hier wird es nicht angelegt,
     * sondern gemeldet — als „nicht gemessen" und nicht als Fehler der Datei.
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function sshConfig(Context $context): array
    {
        if (! is_dir(SftpAccess::RUNTIME)) {
            return [$this->finding(SshdConfig::FILE, Verdict::UNREACHABLE, sprintf(
                '%s fehlt; ohne dieses Verzeichnis prüft sshd -t nichts. Der Diagnoselauf legt es nicht an.',
                SftpAccess::RUNTIME,
            ))];
        }

        return $this->validator($context, 'sshd', ['-t'], SshdConfig::FILE);
    }

    /**
     * Einen Prüfer rufen und sein Urteil in einen Befund übersetzen.
     *
     * **Ein fehlendes Programm ist „nicht gemessen".** `nginx` steht auf der
     * Positivliste, aber nicht jeder Server hat es — und ein Prüfer, den es
     * nicht gibt, hat die Datei nicht abgelehnt.
     *
     * @param  list<string>  $arguments
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function validator(Context $context, string $program, array $arguments, string $subject): array
    {
        if (! is_executable(Runner::programs()[$program] ?? '')) {
            return [$this->finding($subject, Verdict::UNREACHABLE, $program.' ist nicht installiert.')];
        }

        try {
            $result = $context->runner->run($program, $arguments, 30);
        } catch (AgentException $error) {
            return [$this->finding($subject, Verdict::UNREACHABLE, $error->getMessage())];
        }

        $reason = Verdict::validator($result);

        return $reason === null ? [] : [$this->finding($subject, $reason, $result->message())];
    }

    // ------------------------------------------------------------------ B

    /**
     * Die Vhost-Dateien der genannten Domains — gegen die Zusagen der Vorlage.
     *
     * **Die Domains kommen vom Panel, die Pfade nicht.** Aus jedem Namen wird
     * über {@see Site::CONF_DIR} der Pfad, den `web.site.apply` geschrieben
     * hat; ein Name geht durch {@see DomainName::normalize()} wie überall.
     * Nur so lässt sich „die Datei fehlt" überhaupt melden — wer das
     * Verzeichnis abliest, sieht eine fehlende Datei nicht.
     *
     * **Gefragt wird an den Anfang einer Anweisung** ({@see Statements}), nicht
     * an die Zeichenkette: `nginx -t` lässt ein fehlendes Semikolon in zwei
     * von vier Formen mit `rc=0` durch, und `grep` fände die verschluckte
     * Anweisung noch (`docs/81 §2.3o` M3, M21).
     *
     * @param  array<string, mixed>  $args
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function webFiles(array $args): array
    {
        $findings = [];

        foreach ($this->subjects($args['domains'] ?? null, 'domains') as $raw) {
            [$name, $form, $certificate] = $this->webSubject($raw);

            $domain = DomainName::normalize($name);
            $content = $this->readOwn(Site::CONF_DIR.'/'.$domain.'.conf');

            /*
             * **Ob ein Zertifikat ausgeliefert wird, entscheidet dieselbe
             * Frage wie beim Schreiben** — {@see Store::existing()}, und nicht
             * das Panel: Es kennt die Zuordnung, aber nicht, ob die Datei
             * daliegt. Sagte das Panel „mit TLS" und der Block hätte keinen
             * 443er, meldete die Diagnose jede Nacht vier fehlende
             * `ssl_`-Anweisungen an einer heilen Domain.
             */
            $tls = $certificate !== null && $this->store->existing($certificate) !== null;

            /*
             * **Die Zusage kommt aus der Form und nicht aus dem Inhalt.** Wer
             * sie aus `$content` ableitete — etwa „steht `fastcgi_pass` drin,
             * dann ist es eine PHP-Domain" —, verlöre sie mit dem Schaden:
             * Genau die verschluckte Anweisung fiele aus der Erwartung heraus,
             * und der Befund bliebe aus.
             *
             * > **Eine Zusage, die aus dem Prüfling abgeleitet wird, schrumpft
             * > mit seinem Schaden.**
             */
            $promised = SiteTemplate::promised($form, $tls);

            $verdict = Verdict::file($content, $content === null ? [] : Statements::lostInNginx($content, $promised));

            if ($verdict !== null) {
                $findings[] = $this->finding($domain, $verdict['reason'], $verdict['detail']);
            }
        }

        return $findings;
    }

    /**
     * Name, Form und Zertifikatsname eines Gegenstands von `web.file`.
     *
     * **Ein blosser Name bleibt zulässig** und fällt auf die Schnittmenge
     * zurück ({@see SiteTemplate::promised()}). Das ist keine Bequemlichkeit:
     * Ein Panel, das die Form nicht mitschickt, soll eine kleinere Zusage
     * bekommen und nicht einen Abbruch der ganzen Prüfung.
     *
     * Eine Form, die es nicht gibt, ist dagegen ein Fehler des Aufrufers und
     * wird abgewiesen — sonst hiesse „falsch geschrieben" dasselbe wie „nicht
     * mitgeschickt", und der Unterschied ist eine kleinere Zusage.
     *
     * @return array{0: string, 1: null|string, 2: null|string}
     */
    private function webSubject(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [(string) $raw, null, null];
        }

        $name = $raw['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw AgentException::badRequest('domains: jeder Eintrag nennt name.');
        }

        $form = $raw['form'] ?? null;

        if ($form !== null && ! isset(SiteTemplate::PROMISED_BY_FORM[$form])) {
            throw AgentException::badRequest(sprintf('domains: %s ist keine Form dieser Vorlage.', is_string($form) ? $form : gettype($form)));
        }

        $certificate = $raw['certificate'] ?? null;

        if ($certificate !== null && ! is_string($certificate)) {
            throw AgentException::badRequest('domains: certificate ist ein Name oder nichts.');
        }

        return [$name, $form, $certificate === '' ? null : $certificate];
    }

    /**
     * Die Pool-Dateien — je Abonnement und Version, gegen die Abschottung.
     *
     * Die Pool-Datei ist INI und kein nginx: Dort fehlt kein Semikolon, dort
     * fehlt eine Zeile. Der Gegenstand ist der Pfad und nicht die Domain, weil
     * ein Pool zum Abonnement gehört und nicht zu einer seiner Domains.
     *
     * **Der Benutzer wird hier nicht geprüft, weil `poolFile()` es tut** — über
     * `SubscriptionProvision::systemUser()`, dieselbe Frage wie beim Anlegen.
     * Ein Name, der kein `p` mit vier bis neun Ziffern ist, wird nie zu einem
     * Pfad; die Version geht vorher durch `PhpVersions::normalize()`.
     *
     * @param  array<string, mixed>  $args
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function phpFiles(array $args): array
    {
        $findings = [];

        foreach ($this->subjects($args['pools'] ?? null, 'pools') as $pool) {
            if (! is_array($pool)) {
                throw AgentException::badRequest('pools: jeder Eintrag nennt version und user.');
            }

            $path = PhpVersions::poolFile(PhpVersions::normalize($pool['version'] ?? null), (string) ($pool['user'] ?? ''));
            $content = $this->readOwn($path);
            $verdict = Verdict::file($content, $content === null ? [] : Statements::lostInIni($content, PoolTemplate::PROMISED));

            if ($verdict !== null) {
                $findings[] = $this->finding($path, $verdict['reason'], $verdict['detail']);
            }
        }

        return $findings;
    }

    /**
     * Eine Liste von Gegenständen aus den Argumenten — gedeckelt.
     *
     * @return list<mixed>
     */
    private function subjects(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw AgentException::badRequest($field.' muss eine Liste sein.');
        }

        if (count($value) > self::MAX_SUBJECTS) {
            throw AgentException::badRequest(sprintf('%s: zu viele Einträge (%d, erlaubt sind %d).', $field, count($value), self::MAX_SUBJECTS));
        }

        return array_values($value);
    }

    /**
     * Eine eigene Datei des Panels lesen — `null`, wenn sie nicht da ist.
     *
     * Ohne Sperre: Diese Dateien schreibt nur das Panel, und zwar ganz oder
     * gar nicht. Ein Lauf, der genau in den Augenblick eines Schreibvorgangs
     * fällt, liest den alten oder den neuen Stand — nie einen halben.
     */
    private function readOwn(string $path): ?string
    {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    // ------------------------------------------------------------------ C

    /**
     * Die Form der beiden verwalteten Bereiche — und nur die Form.
     *
     * Es sind zwei Dateien und nicht fünf (`docs/81 §2.3o` M13). Ob eine Zeile
     * fremd ist oder fehlt, weiss nur das Panel; hier wird gefragt, ob die
     * Marken stehen und ob es genau ein Paar ist ({@see ManagedBlock::inspect()}).
     *
     * **Gelesen wird unter der Sperre**, wie jede Leserin dieser Klasse — die
     * Sperre liegt neben der Datei und schreibt nichts hinein.
     *
     * @return array{
     *     0: list<array{subject: string, reason: string, detail: null|string}>,
     *     1: list<array{path: string, role: string, present: bool, lines: list<string>}>,
     * }
     */
    private function blocks(Context $context): array
    {
        $findings = [];
        $managed = [];

        foreach ($this->managedFiles($context) as [$path, $role, $error]) {
            if ($error !== null) {
                $findings[] = $this->finding($path, Verdict::UNREACHABLE, $error);

                continue;
            }

            try {
                $inspected = ManagedBlock::locked($path, static fn (): array => ManagedBlock::inspect(ManagedBlock::read($path)));
            } catch (AgentException $failure) {
                $findings[] = $this->finding($path, Verdict::UNREACHABLE, $failure->getMessage());

                continue;
            }

            $reason = Verdict::block($inspected['state']);

            if ($reason !== null) {
                $findings[] = $this->finding($path, $reason, sprintf(
                    'BEGIN in Zeile %s, END in Zeile %s.',
                    $inspected['begin_lines'] === [] ? '–' : implode(', ', $inspected['begin_lines']),
                    $inspected['end_line'] === null ? '–' : (string) $inspected['end_line'],
                ));
            }

            /*
             * **Die Zeilen gehen mit, und nur die gelesenen.**
             *
             * Was in den Marken steht, ist erst dann ein Befund, wenn jemand
             * weiss, was dort stehen *sollte* — und das weiss nur das Panel
             * (`docs/98 §3 C` Frage 3). Der Agent liest die Zeilen ohnehin;
             * sie wegzuwerfen zwänge das Panel, die Datei ein zweites Mal
             * lesen zu lassen, und `pg_hba.conf` ist `0640 postgres:postgres`.
             *
             * **Eine Datei, die nicht zu lesen war, steht hier nicht.** Sonst
             * läse das Panel eine leere Zeilenliste als „der Block ist leer"
             * und meldete jede Regel des Bestands als fehlend — aus „nicht
             * gemessen" würde ein Befund.
             */
            $managed[] = [
                'path' => $path,
                'role' => $role,
                'present' => $inspected['state'] !== 'absent',
                'lines' => $inspected['lines'],
            ];
        }

        return [$findings, $managed];
    }

    /**
     * Die Dateien mit einem verwalteten Bereich — je Pfad `null` oder der Grund,
     * warum er nicht zu haben war.
     *
     * `sshd_config` liegt fest ({@see SshdConfig::FILE}). `pg_hba.conf` wird
     * **erfragt und nicht gebaut** ({@see Server::hbaFile()}) — und ohne
     * laufenden Cluster gar nicht: Ein Server ohne PostgreSQL hat keinen
     * Gegenstand, über den etwas zu sagen wäre, und bekäme sonst jede Nacht
     * ein `unreachable` für eine Datei, die es zu Recht nicht gibt.
     *
     * **Eine Liste und keine Zuordnung Pfad → Fehler.** Seit Schritt 5b trägt
     * jeder Eintrag seine Rolle mit, damit das Panel den Sollzustand nicht am
     * Dateinamen erraten muss; ein Pfad als Schlüssel liesse dafür keinen
     * Platz, ohne eine zweite Liste danebenzustellen.
     *
     * @return list<array{0: string, 1: string, 2: null|string}> je Datei Pfad, Rolle und der Grund, warum sie nicht zu haben war
     */
    private function managedFiles(Context $context): array
    {
        $files = [[SshdConfig::FILE, self::ROLE_SSHD, null]];

        if ($this->server->primaryCluster($context) === null) {
            return $files;
        }

        try {
            $files[] = [$this->server->hbaFile($context, $this->session), self::ROLE_HBA, null];
        } catch (AgentException $error) {
            $files[] = ['pg_hba.conf', self::ROLE_HBA, $error->getMessage()];
        }

        return $files;
    }

    // ------------------------------------------------------------------ F

    /**
     * Beide Werkzeuge, ein Urteil ({@see Verdict::quota()}).
     *
     * Der Gegenstand ist das Verzeichnis, das das Panel meint, und nicht das
     * Gerät darunter: Der Betreiber sucht `/var/www/vhosts` und nicht
     * `/dev/vda3`. Das Gerät steht im Wortlaut.
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function quota(Context $context): array
    {
        $subject = SubscriptionProvision::VHOSTS;
        $device = Mounts::deviceFor($subject);

        if ($device === null) {
            return [$this->finding($subject, Verdict::UNREACHABLE, 'kein Mount für '.$subject.' gefunden')];
        }

        try {
            $quotaon = $context->runner->run('quotaon', ['-p', $device], 30);
            $repquota = $context->runner->run('repquota', ['-u', $device], 120);
        } catch (AgentException $error) {
            return [$this->finding($subject, Verdict::UNREACHABLE, $error->getMessage())];
        }

        $reason = Verdict::quota($quotaon, $repquota);

        if ($reason === null) {
            return [];
        }

        return [$this->finding($subject, $reason, trim(sprintf(
            "%s\nquotaon -p: %s\nrepquota: rc=%d %s",
            $device,
            $quotaon->message(),
            $repquota->code,
            trim($repquota->stderr),
        )))];
    }

    // ------------------------------------------------------------------ I

    /**
     * Der Signaturschlüssel der eigenen Paketquelle.
     *
     * Gefragt wird die erste Stanza von {@see Sources::PANEL_SOURCE} — die
     * Datei, die `srvpanel setup` schreibt. Der Weg zum Schlüssel ist derselbe
     * wie auf der Quellenseite ({@see Keys::inspect()}); eine zweite Fassung
     * wäre die, die veraltet.
     *
     * @return list<array{subject: string, reason: string, detail: null|string}>
     */
    private function aptKey(Context $context): array
    {
        $content = @file_get_contents(Sources::PANEL_SOURCE);

        if ($content === false) {
            return [$this->finding(Sources::PANEL_SOURCE, 'missing', 'Die Quelldatei des Panels liegt nicht da.')];
        }

        $stanza = Sources::stanzas($content)[0] ?? null;

        if ($stanza === null) {
            return [$this->finding(Sources::PANEL_SOURCE, 'missing', 'Die Quelldatei des Panels enthält keine Stanza.')];
        }

        try {
            $key = Keys::inspect($context, $stanza['fields'], $stanza['block']);
        } catch (AgentException $error) {
            return [$this->finding(Sources::PANEL_SOURCE, Verdict::UNREACHABLE, $error->getMessage())];
        }

        $subject = $key['kind'] === 'path' ? (string) $key['path'] : Sources::PANEL_SOURCE;

        if ($key['kind'] === 'none') {
            return [$this->finding($subject, 'missing', 'Die Stanza trägt kein Signed-By.')];
        }

        if (! $key['readable']) {
            return [$this->finding($subject, Verdict::UNREACHABLE, 'gpg konnte den Schlüssel nicht lesen.')];
        }

        $reason = Verdict::key($key['keys'], time());

        if ($reason === null) {
            return [];
        }

        $detail = implode("\n", array_map(
            static fn (array $one): string => sprintf(
                '%s %s%s',
                $one['fingerprint'] ?? $one['keyid'],
                $one['state'],
                $one['expires'] === null ? '' : ' bis '.gmdate('Y-m-d', $one['expires']),
            ),
            $key['keys'],
        ));

        return [$this->finding($subject, $reason, $detail)];
    }

    /** @return array{subject: string, reason: string, detail: null|string} */
    private function finding(string $subject, string $reason, ?string $detail): array
    {
        return ['subject' => $subject, 'reason' => $reason, 'detail' => $detail === null || trim($detail) === '' ? null : $detail];
    }
}
