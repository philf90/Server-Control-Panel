<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use SrvPanel\Agent\Acme\CertificateName;
use SrvPanel\Agent\Acme\Trust;
use SrvPanel\Agent\Ops\SubscriptionProvision;

/**
 * Eine Website, wie der Agent sie kennt — und die **einzige** Stelle, an der
 * aus ihren Angaben Pfade werden.
 *
 * **Warum das eine eigene Klasse ist.** Zu einer Domain gehören sechs Pfade:
 * die Konfigurationsdatei, die Include-Datei, das DocumentRoot, das
 * Protokollverzeichnis, der FPM-Sockel und die Wurzel des Abonnements. Stünden
 * sie in den drei Operationen `apply`, `remove` und `state` jeweils neu
 * zusammengesetzt, gäbe es drei Gelegenheiten, einen davon anders zu bilden —
 * und die Operation, die entfernt, wäre die schlechteste dafür: Sie löscht
 * dann etwas anderes, als die Operation angelegt hat.
 *
 * **Kein Pfad kommt von aussen.** Übergeben werden Name, Systembenutzer,
 * Domain und ein *relatives* DocumentRoot; alles Weitere entsteht hier. Das
 * ist dieselbe Entscheidung wie in {@see SubscriptionProvision}, wo sie im
 * Klassenkommentar als die wichtigste der Datei steht.
 */
final class Site
{
    /** Hier liegen die Server-Blöcke der Kundenwebsites. */
    public const CONF_DIR = '/etc/nginx/srvpanel.d';

    /**
     * Die eine Zeile, die das Verzeichnis einbindet.
     *
     * Sie liegt in `conf.d`, weil nginx dieses Verzeichnis von sich aus liest.
     * Ein eigenes Verzeichnis daneben hält die Server-Blöcke des Panels
     * beisammen: Was dort liegt, gehört srvpanel, und was nicht, wird nicht
     * angefasst.
     */
    public const INCLUDE_FILE = '/etc/nginx/conf.d/srvpanel-sites.conf';

    /** Mehr Aliasse hat keine Domain, und `server_name` bliebe lesbar. */
    public const MAX_ALIASES = 20;

    /**
     * @param  list<string>  $aliases
     * @param  array<string, string>  $phpSettings
     * @param  list<string>  $directives
     */
    private function __construct(
        public readonly string $subscription,
        public readonly string $user,
        public readonly string $domain,
        public readonly array $aliases,
        public readonly ?string $documentRoot,
        public readonly ?string $phpVersion,
        public readonly array $phpSettings,
        public readonly array $directives,
        public readonly ?string $redirectTarget,
        public readonly int $redirectCode,
        public readonly bool $suspended,
        public readonly bool $hsts,
        public readonly ?string $certificate,

        /**
         * Die voraussichtliche Endzeit der Wartung — als **Text für die
         * Seite** und nicht als Schalter.
         *
         * Ob Wartung ist, entscheidet die Flagdatei bei jeder Anfrage
         * ({@see Maintenance::FLAG}). Der Block weiss nur, was er sagen soll,
         * falls sie es ist — deshalb steht hier kein Wahrheitswert.
         */
        public readonly ?string $maintenanceUntil,
    ) {}

    /**
     * Aus den Argumenten einer Operation — jede Angabe geprüft.
     *
     * @param  array<string, mixed>  $args
     */
    public static function fromArgs(array $args): self
    {
        $subscription = SubscriptionProvision::subscriptionName($args['subscription'] ?? null);
        $user = SubscriptionProvision::systemUser($args['user'] ?? null);
        $domain = DomainName::normalize($args['domain'] ?? null);

        $redirect = self::redirect($args['redirect_target'] ?? null);
        $documentRoot = $redirect === null
            ? DocumentRoot::normalize($args['document_root'] ?? null)
            : null;

        // Ohne eigene Dateien kein Handler: Eine Weiterleitung beantwortet
        // nginx selbst und sucht nie eine Datei.
        $phpVersion = ($redirect === null && ($args['php_version'] ?? null) !== null)
            ? PhpVersions::normalize($args['php_version'])
            : null;

        return new self(
            subscription: $subscription,
            user: $user,
            domain: $domain,
            aliases: self::aliases($args['aliases'] ?? null, $domain),
            documentRoot: $documentRoot,
            phpVersion: $phpVersion,
            phpSettings: $phpVersion === null ? [] : PhpSettings::check($args['php_settings'] ?? null),
            directives: Directives::check($args['directives'] ?? null),
            redirectTarget: $redirect,
            redirectCode: self::redirectCode($args['redirect_code'] ?? null),
            suspended: (bool) ($args['suspended'] ?? false),
            maintenanceUntil: Maintenance::until($args['maintenance_until'] ?? null),

            /*
             * **Eine Erlaubnis, keine Anweisung.** Ob ein Jahr erzwungenes
             * HTTPS gewollt ist, weiss nur das Panel: Es kennt die
             * Zertifizierungsstelle und weiss, ob gerade der Testbetrieb
             * läuft, dessen Wurzel kein Browser kennt. Ob das Zertifikat es
             * hergibt, weiss nur der Agent, denn nur er liest die Datei —
             * beide Bedingungen zusammen stehen in {@see Trust::hsts()}.
             */
            hsts: (bool) ($args['hsts'] ?? false),

            /*
             * **Welches Zertifikat dieser Block ausliefert, sagt das Panel.**
             *
             * Bis zum zweiten Wurf von P4 hat der Agent es abgeleitet: Er sah
             * unter dem Namen der Domain nach und nahm, was dort lag. Für ein
             * Zertifikat, das genau diese eine Domain deckt, stimmt das — und
             * für nichts sonst. Ein Platzhalter liegt unter keinem der Namen,
             * die er deckt, und ein hochgeladenes Zertifikat für drei Domains
             * höchstens unter einer davon.
             *
             * Damit gäbe es zwei Wahrheiten zu einer Frage: die Zuordnung in
             * der Datenbank des Panels und der Verzeichnisname auf dem Server.
             * Das ist wörtlich das Muster, an dem dieses Projekt sechsmal
             * verloren hat (`docs/34 §2.1`).
             *
             * **Ein Name, kein Pfad.** Was hier hereinkommt, ist der Schlüssel
             * im Ablageort; den Pfad baut weiterhin {@see Store}. Ein Pfad aus
             * der Anwendung wäre eine Fernsteuerung dafür, welche Datei nginx
             * als Schlüssel liest.
             */
            certificate: ($args['certificate'] ?? null) === null
                ? null
                : CertificateName::normalize($args['certificate'], 'certificate'),
        );
    }

    /** Die Wurzel des Abonnements — dieselbe wie in `subscription.provision`. */
    public function subscriptionRoot(): string
    {
        return SubscriptionProvision::VHOSTS.'/'.$this->subscription;
    }

    public function documentRootPath(): ?string
    {
        return $this->documentRoot === null
            ? null
            : $this->subscriptionRoot().'/'.$this->documentRoot;
    }

    /** Das Protokollverzeichnis dieser Domain (§4.5: `logs/<domain>/`). */
    public function logDir(): string
    {
        return $this->subscriptionRoot().'/logs/'.$this->domain;
    }

    public function accessLog(): string
    {
        return $this->logDir().'/access.log';
    }

    public function errorLog(): string
    {
        return $this->logDir().'/error.log';
    }

    /**
     * Die Datei mit den eigenen Direktiven.
     *
     * Sie liegt in `conf/` des Abonnements — §4.5 nennt das Verzeichnis
     * „generierte Includes" und hält es genau dafür frei. `root:root 0755`:
     * Der Kunde darf sie lesen, aber nicht schreiben. Läge sie in einem
     * Verzeichnis, in das er schreiben kann, wäre die ganze Positivliste aus
     * {@see Directives} umsonst — er schriebe hinein, was er wollte.
     */
    public function includeFile(): string
    {
        return $this->subscriptionRoot().'/conf/'.$this->domain.'.include';
    }

    public function confFile(): string
    {
        return self::CONF_DIR.'/'.$this->domain.'.conf';
    }

    public function socket(): ?string
    {
        return $this->phpVersion === null
            ? null
            : PhpVersions::socket($this->phpVersion, $this->user);
    }

    /**
     * Alle Namen, unter denen diese Website antwortet.
     *
     * @return list<string>
     */
    public function serverNames(): array
    {
        return array_merge([$this->domain], $this->aliases);
    }

    /**
     * Die Aliasse — geprüft wie die Domain selbst.
     *
     * @return list<string>
     */
    private static function aliases(mixed $value, string $domain): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw AgentException::badRequest('Aliasse müssen eine Liste sein.', ['aliases' => 'kein Array']);
        }

        if (count($value) > self::MAX_ALIASES) {
            throw AgentException::badRequest(
                sprintf('Höchstens %d Aliasse je Domain.', self::MAX_ALIASES),
                ['aliases' => count($value)],
            );
        }

        $names = [];

        foreach (array_values($value) as $index => $alias) {
            $name = DomainName::normalize($alias, 'aliases['.$index.']');

            // Der eigene Name doppelt in `server_name` ist für nginx eine
            // Warnung („conflicting server name") und für uns ein Zeichen,
            // dass jemand die Liste ungefiltert übergeben hat.
            if ($name !== $domain && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Das Ziel einer Weiterleitung.
     *
     * Es steht in einer `return`-Anweisung und damit in einer Zeile, die nginx
     * liest — deshalb wird es nicht als Zeichenkette geprüft, sondern
     * zerlegt: Schema, Rechnername, Pfad. Der Rechnername geht durch dieselbe
     * Prüfung wie jede Domain, der Pfad lässt nur Zeichen zu, die in einer
     * Adresse vorkommen. Kein Anführungszeichen, kein Semikolon, kein
     * Zeilenumbruch, kein `$` — letzteres, weil nginx darin eine Variable
     * sähe.
     */
    private static function redirect(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $target = trim(Guard::string($value, 'redirect_target'));

        if (strlen($target) > 255) {
            throw AgentException::badRequest('Weiterleitungsziel zu lang.', ['redirect_target' => strlen($target)]);
        }

        if (preg_match('#^(https?)://([^/]+)(/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*)?$#D', $target, $match) !== 1) {
            throw AgentException::badRequest('Unzulässiges Weiterleitungsziel.', ['redirect_target' => $target]);
        }

        $host = DomainName::normalize($match[2], 'redirect_target');
        $path = $match[3] ?? '';

        // `$` ist im Muster oben ausgeschlossen; die Bedingung bleibt als
        // zweite Schranke stehen, falls das Muster je erweitert wird.
        if (str_contains($path, '$')) {
            throw AgentException::badRequest('Unzulässiges Weiterleitungsziel.', ['redirect_target' => $target]);
        }

        return $match[1].'://'.$host.$path;
    }

    private static function redirectCode(mixed $value): int
    {
        if ($value === null) {
            return 302;
        }

        if (! is_int($value) || ! in_array($value, [301, 302], true)) {
            throw AgentException::badRequest('Weiterleitung ist 301 oder 302.', ['redirect_code' => $value]);
        }

        return $value;
    }
}
