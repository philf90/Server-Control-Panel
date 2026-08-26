<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Sources;

/**
 * Eine Paketquelle ein- oder ausschalten.
 *
 * ## Warum nur die eigenen Dateien
 *
 * **Wer eine Paketquelle kontrolliert, kontrolliert jedes Paket.** Sie kann
 * eines mit höherer Fassungsnummer ausliefern, das ein beliebiges anderes
 * ersetzt — `libc6`, `openssh-server`, `srvpanel` selbst. Eine fremde Quelle
 * anzufassen ist damit nicht eine Handlung neben den anderen, sondern die, die
 * alle künftigen umfasst (`docs/81 §3`, Frage 1).
 *
 * Geschrieben wird deshalb nur in {@see Sources::owned()} — die Dateien, die
 * das Panel selbst angelegt hat. Der Pfad kommt aus der Liste des Kunden und
 * wird gegen sie geprüft, nicht gegen ein Muster.
 *
 * ## Warum danach nachgesehen wird
 *
 * **Weil eine kaputte Quelldatei nicht diese Quelle bricht, sondern apt.**
 * Gemessen am 26. August 2026: Eine einzige unlesbare `.sources`-Datei lässt
 * `apt-get indextargets` **und** `apt-get -s upgrade` mit `rc=100` und null
 * Zeilen enden — *„The list of sources could not be read."* Danach installiert
 * dieser Server keine PHP-Version mehr und kein PostgreSQL.
 *
 * > **Ein Fehler in einer Datei, den nur der nächste Leser bemerkt, ist beim
 * > Schreiben am billigsten.**
 *
 * **Und der Rückweg trägt hier**, anders als beim `sshd` aus `docs/59`: apt ist
 * kein Dienst, der an einer kaputten Datei stirbt — er scheitert beim nächsten
 * Aufruf. Ein `rename()` zurück stellt den Zustand vollständig her, und dass es
 * gewirkt hat, sagt derselbe apt-Aufruf.
 */
final class SystemSourcesToggle implements Op
{
    /** @var list<string> */
    public const PROBE = ['indextargets'];

    public static function name(): string
    {
        return 'system.sources.toggle';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $pfad = is_string($args['path'] ?? null) ? $args['path'] : '';
        $stanza = is_int($args['stanza'] ?? null) ? $args['stanza'] : 0;
        $enabled = (bool) ($args['enabled'] ?? false);

        if (! Sources::isOwned($pfad)) {
            throw AgentException::denied(
                'Diese Paketquelle hat das Panel nicht angelegt; sie lässt sich hier nicht schalten.',
            );
        }

        if (! is_file($pfad)) {
            throw new AgentException(AgentException::NOT_FOUND, 'Diese Quelldatei gibt es nicht.', ['path' => $pfad]);
        }

        /*
         * **Nur deb822.** Im Einzeiler-Format gibt es kein `Enabled:` — dort
         * schaltet das Kommentarzeichen ab, und das ist eine andere Bearbeitung
         * mit einer anderen Falle. Beide eigenen Dateien sind `.sources`; käme
         * eine `.list` dazu, soll sie hier abgewiesen werden und nicht
         * stillschweigend eine Zeile bekommen, die apt ignoriert.
         */
        if (str_ends_with($pfad, '.list')) {
            throw AgentException::badRequest(
                'Diese Datei ist im alten Einzeiler-Format; dort gibt es kein Enabled.',
                ['path' => $pfad],
            );
        }

        $vorher = (string) file_get_contents($pfad);
        $nachher = Sources::toggled($vorher, $stanza, $enabled);

        $this->write($pfad, $nachher);

        /*
         * **Erfolg wird gelesen, nicht geglaubt** (`docs/81 §7`, Punkt 6).
         * Zwei Fragen: Kann apt die Datei noch lesen, und steht die Stanza
         * jetzt so da, wie sie sollte? Die erste beantwortet apt, die zweite
         * die Datei selbst — eine Antwort für beide gäbe es nicht.
         */
        $probe = $context->runner->run('apt-get', self::PROBE, 60);

        if (! $probe->successful()) {
            $this->write($pfad, $vorher);

            throw AgentException::execFailed(
                'apt konnte die Quelldatei danach nicht mehr lesen; die Änderung ist zurückgenommen: '
                .$probe->message(),
                ['path' => $pfad],
            );
        }

        $gelesen = Sources::stanzas((string) file_get_contents($pfad));

        foreach ($gelesen as $eintrag) {
            if ($eintrag['stanza'] === $stanza && $eintrag['enabled'] === $enabled) {
                return ['path' => $pfad, 'stanza' => $stanza, 'enabled' => $enabled];
            }
        }

        $this->write($pfad, $vorher);

        throw AgentException::execFailed(
            'Die Quelle steht nach dem Schreiben nicht auf dem verlangten Wert; die Änderung ist zurückgenommen.',
            ['path' => $pfad, 'stanza' => $stanza],
        );
    }

    /**
     * Schreiben, ohne dass ein halber Stand liegenbleibt.
     *
     * **Erst daneben, dann umbenennen.** Ein `file_put_contents()` auf die
     * Zieldatei ist zwischen Kürzen und Schreiben ein Zustand, in dem apt eine
     * halbe Stanza liest — und dann liest es gar nichts mehr. Die Wegwerfdatei
     * liegt im selben Verzeichnis, weil `rename()` nur innerhalb eines
     * Dateisystems atomar ist.
     */
    private function write(string $pfad, string $inhalt): void
    {
        $neben = $pfad.'.srvpanel-neu';

        if (@file_put_contents($neben, $inhalt) === false) {
            throw AgentException::execFailed('Die Quelldatei liess sich nicht schreiben.', ['path' => $pfad]);
        }

        // Die Rechte der bestehenden Datei behalten — apt liest sie als root,
        // aber eine Quelldatei mit 0600 wäre eine Änderung, die niemand wollte.
        @chmod($neben, 0644);

        if (! @rename($neben, $pfad)) {
            @unlink($neben);

            throw AgentException::execFailed('Die Quelldatei liess sich nicht ersetzen.', ['path' => $pfad]);
        }
    }
}
