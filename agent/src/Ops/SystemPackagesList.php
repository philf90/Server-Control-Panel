<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Packages;

/**
 * Was auf diesem Server aktualisierbar ist — und was ein Update nach sich zöge.
 *
 * **Zwei Läufe und nicht einer.** `dist-upgrade` sagt, was möglich ist;
 * `upgrade` sagt, was ohne Entfernen möglich ist. Die Zahl, die ein Betreiber
 * sehen muss, ist die Differenz — und sie steht in keinem der beiden Läufe
 * allein. Gemessen auf Ubuntu 22.04 am 26. August 2026: `upgrade` 2, mit einer
 * Sperrmarkierung 1, und `dist-upgrade` unverändert 2.
 *
 * **Diese Operation fasst apt an und fragt die Sperre trotzdem nicht** — die
 * einzige, für die das gilt. `AptLockReachTest::EXCEPTIONS` trägt sie mit dem
 * Grund, und der ist gemessen: `apt-get -s` läuft bei gehaltener
 * dpkg-Frontend-Sperre durch (rc 0, 145 `Inst`-Zeilen), ein echter Lauf nicht
 * (rc 100, „Could not get lock").
 *
 * > **Eine lesende Frage, die an einer Sperre scheitert, beantwortet sie nicht
 * > später, sondern gar nicht.** Ein Betreiber schlägt diese Liste am ehesten
 * > auf, während etwas läuft.
 *
 * **Gelesen wird über {@see Packages} und nicht hier.** `Runner` und `Context`
 * sind `final`, es gibt also keine Attrappe; stünde der Leser in dieser Klasse,
 * wäre er nur über einen echten Server prüfbar — also gar nicht.
 */
final class SystemPackagesList implements Op
{
    /** @var list<string> */
    public const DIST_UPGRADE = ['-s', 'dist-upgrade'];

    /** @var list<string> */
    public const UPGRADE = ['-s', 'upgrade'];

    /**
     * Wer `/run/reboot-required` anlegt.
     *
     * **Ohne dieses Paket ist die fehlende Datei keine Antwort.** Sie fehlt
     * dann, weil niemand sie schreibt — nicht, weil kein Neustart nötig wäre.
     * Auf jedem der vier Zielabbilder ist es nicht installiert (gemessen,
     * 26. August 2026), und das ist auf einem Server die Regel und nicht die
     * Ausnahme.
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
     * > tun".**
     */
    private const REBOOT_PROVIDER = 'update-notifier-common';

    private const REBOOT_FLAG = '/run/reboot-required';

    private const REBOOT_PACKAGES = '/run/reboot-required.pkgs';

    /**
     * Was ein dpkg-Lauf unter `/etc` zurücklassen kann.
     *
     * Eine solche Datei heisst: Der Betreiber hat eine Konfigurationsdatei
     * geändert, das Paket bringt eine neue mit, und beide liegen jetzt
     * nebeneinander. Wer das nicht sieht, fährt auf der alten weiter.
     *
     * @var list<string>
     */
    public const LEFTOVERS = ['.dpkg-dist', '.dpkg-new', '.ucf-dist'];

    /**
     * Tiefer wird unter `/etc` nicht gesucht.
     *
     * **Sechs, weil fünf die gemessene Tiefe ist.** Am 26. August 2026 über ein
     * `/etc` mit 349 Dateien ausgezählt — 68 auf Ebene 1, 145 auf 2, 80 auf 3,
     * 51 auf 4 und 5 auf Ebene 5 (`/etc/java-21-openjdk/security/policy/…`),
     * darüber keine. Die Grenze liegt also eine Ebene über dem tiefsten Pfad,
     * den ein echtes System hier hat.
     *
     * **Sie greift, und das ist gemessen und nicht gemeint**: Eine Datei auf
     * Ebene 7 kommt nicht in der Liste an, eine auf Ebene 6 schon.
     *
     * > **Eine Obergrenze, die über dem tatsächlichen Höchstwert liegt, ist
     * > keine.** Diese liegt knapp darüber — der Sinn ist, einen Verweisring
     * > oder ein irrtümlich unter `/etc` gehängtes Dateisystem zu begrenzen,
     * > nicht Dateien wegzulassen.
     */
    private const DEPTH = 6;

    public static function name(): string
    {
        return 'system.packages.list';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        $dist = $this->apt($context, self::DIST_UPGRADE);
        $plain = $this->apt($context, self::UPGRADE);

        return [
            ...Packages::read($dist, $plain),
            'reboot' => $this->reboot($context),
            'leftovers' => $this->leftovers(),
        ];
    }

    /**
     * Ein `apt-get -s`-Lauf, dessen Fehlschlag nicht als „nichts zu tun"
     * durchgeht.
     *
     * **Hier reicht der Rückgabewert, und bei `apt-get update` reichte er
     * nicht** — der Unterschied ist gemessen und keine Auslegung. `update`
     * fragt „habe ich danach einen benutzbaren Zustand", und den hat es auch
     * mit toten Quellen: rc 0, die alten Listen bleiben liegen (M5,
     * `docs/81 §2.1`). Ein `-s dist-upgrade` beantwortet dagegen eine Frage,
     * die scheitern kann, und trägt das Scheitern:
     *
     *     eine tote Quelle                rc 0   · 145 Inst · stderr leer
     *     eine unerfüllbare Fassung       rc 100 ·   0 Inst · „E: Version … not found"
     *     die dpkg-Sperre gehalten        rc 0   · 145 Inst
     *
     * Gemessen am 26. August 2026 gegen apt 2.8.3.
     *
     * Die erste Zeile ist dabei die lehrreiche: Eine tote Quelle **fällt hier
     * nicht auf**, weil `-s` die Listen nicht erneuert, sondern liest. Die
     * Antwort ist dann so alt wie die Listen — richtig, aber nicht frisch.
     * Wer Frische zusagen will, braucht `Apt::refresh()` davor und dessen
     * Leser; diese Operation sagt sie nicht zu.
     *
     * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts
     * > zu tun".**
     *
     * @param  list<string>  $argumente
     */
    private function apt(Context $context, array $argumente): string
    {
        $lauf = $context->runner->run('apt-get', $argumente, 120);

        if (! $lauf->successful()) {
            throw AgentException::execFailed(
                'Der Paketstand liess sich nicht ermitteln: '.$lauf->message(),
                ['arguments' => $argumente, 'code' => $lauf->code],
            );
        }

        return $lauf->stdout;
    }

    /**
     * Ist ein Neustart nötig — ja, nein, oder weiss dieser Server nicht?
     *
     * Drei Zustände und nicht zwei. `null` heisst „nicht feststellbar", und das
     * ist eine Auskunft: Der Betreiber weiss dann, dass er selbst nachsehen
     * muss, statt sich auf ein „nein" zu verlassen, das keines ist.
     *
     * @return array{required: null|bool, packages: list<string>}
     */
    private function reboot(Context $context): array
    {
        if (is_file(self::REBOOT_FLAG)) {
            return ['required' => true, 'packages' => $this->rebootPackages()];
        }

        // Der Rückgabewert von `dpkg-query` wird nicht angesehen: Er ist 1,
        // sobald das Paket unbekannt ist — also genau im gefragten Fall.
        $status = $context->runner->run(
            'dpkg-query',
            ['-W', '-f=${db:Status-Status}', self::REBOOT_PROVIDER],
            15,
        );

        return [
            'required' => trim($status->stdout) === 'installed' ? false : null,
            'packages' => [],
        ];
    }

    /** @return list<string> */
    private function rebootPackages(): array
    {
        $roh = @file_get_contents(self::REBOOT_PACKAGES);

        if ($roh === false) {
            return [];
        }

        $namen = array_filter(array_map('trim', preg_split('/\R/', $roh) ?: []));

        return array_values(array_unique($namen));
    }

    /**
     * Die zurückgelassenen Konfigurationsdateien unter `/etc`.
     *
     * **Symbolischen Verweisen wird nicht gefolgt.** Ein Verweis unter `/etc`
     * kann auf `/` zeigen, und ein Lauf über die ganze Platte für eine Liste,
     * die eine Seite anzeigt, ist kein Merkmal, sondern ein Ausfall.
     *
     * @return list<string>
     */
    private function leftovers(): array
    {
        $gefunden = [];

        $verzeichnis = new \RecursiveDirectoryIterator(
            '/etc',
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
        );

        $lauf = new \RecursiveIteratorIterator(
            $verzeichnis,
            \RecursiveIteratorIterator::LEAVES_ONLY,

            // Ein `/etc`, in dem ein Verzeichnis nicht lesbar ist, darf die
            // ganze Antwort nicht kosten.
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        $lauf->setMaxDepth(self::DEPTH);

        foreach ($lauf as $pfad) {
            $pfad = (string) $pfad;

            if (is_link($pfad)) {
                continue;
            }

            foreach (self::LEFTOVERS as $endung) {
                if (str_ends_with($pfad, $endung)) {
                    $gefunden[] = $pfad;

                    break;
                }
            }
        }

        sort($gefunden);

        return $gefunden;
    }
}
