<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\Apt;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Packages;
use SrvPanel\Agent\Unattended;

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

    /**
     * Das Paket, ohne das nichts unbeaufsichtigt läuft.
     *
     * `apt.systemd.daily` prüft in Zeile 494 `command -v unattended-upgrade`
     * — ist es nicht da, geschieht nichts, gleich wie die Schalter stehen.
     * Auf keinem der vier Zielabbilder ist es vorinstalliert (M8).
     */
    public const UNATTENDED_PACKAGE = 'unattended-upgrades';

    /** Wo die Fragmente liegen, aus denen apt seine Einstellungen auflöst. */
    public const APT_CONF_DIR = '/etc/apt/apt.conf.d';

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
        /*
         * **Gefragt wird über dieselbe transiente Unit, in der auch
         * eingespielt wird** (`docs/86`, Befund 6). Unmittelbar aus dem
         * Agenten gefragt, antwortet apt anders: Seine Härtung legt einen
         * Mount-Namensraum an, darin meldet `ischroot` rc=0, und in einem
         * chroot hält Ubuntu keine phasenverzögerten Pakete zurück. Gemessen
         * auf `cloudsrv24`: elf Zeilen hier, vier dort — die Seite zeigte elf,
         * und `apt-run all` spielte vier ein.
         *
         * > **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen
         * > und nicht eine.**
         */
        $laeufe = Apt::simulate($context, SystemPackagesUpgrade::RUNNER);

        return [
            ...Packages::read($laeufe['dist-upgrade'], $laeufe['upgrade']),
            'reboot' => $this->reboot($context),
            'leftovers' => $this->leftovers(),
            'unattended' => $this->unattended($context),
        ];
    }

    /**
     * Der **wirksame** Zustand der Automatik — nicht der unserer Datei.
     *
     * **Hier und nicht in einer eigenen Operation**, weil die Seite beides
     * zusammen zeigt und der Griff billig ist: ein `apt-config dump`, ein
     * `dpkg-query`, zwei `stat`. Die beiden `apt-get -s` darüber kosten ein
     * Vielfaches.
     *
     * **Gefragt wird apt und nicht unsere Datei** (`docs/81 §7`, Falle 7).
     * Gemessen am 26. August 2026 in diesem Container: `20auto-upgrades` sagt
     * für beide Teilschalter `1`, und die Automatik ist trotzdem **aus** —
     * `docker-disable-periodic-update` setzt den Hauptschalter auf `0`, und
     * `apt.systemd.daily` steigt daran in Zeile 358 aus.
     *
     * > **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen
     * > Zustand.**
     *
     * @return array<string, mixed>
     */
    private function unattended(Context $context): array
    {
        $dump = $context->runner->run('apt-config', ['dump'], 30);

        if (! $dump->successful()) {
            /*
             * **Keine Behauptung, wenn die Frage nicht durchkam.** Ein
             * `installed: false` an dieser Stelle sähe aus wie „die Automatik
             * ist aus" — das ist der Fehler, den `kernelStale()` seit P7
             * vermeidet und den M7 für den Neustart noch einmal aufgeschrieben
             * hat.
             */
            return ['readable' => false, 'error' => $dump->message()];
        }

        $gelesen = Unattended::read($dump->stdout);
        $werte = $gelesen['values'];

        // Der Rückgabewert wird nicht angesehen: Er ist 1, sobald das Paket
        // unbekannt ist — also genau im gefragten Fall. Dieselbe Überlegung
        // wie bei `update-notifier-common` eine Methode weiter oben.
        $paket = $context->runner->run(
            'dpkg-query',
            ['-W', '-f=${db:Status-Status}', self::UNATTENDED_PACKAGE],
            15,
        );

        return [
            'readable' => true,
            'installed' => trim($paket->stdout) === 'installed',
            'enabled' => Unattended::enabled($werte),
            'lists_days' => Unattended::interval($werte, Unattended::LISTS),
            'upgrade_days' => Unattended::interval($werte, Unattended::UPGRADE),
            'automatic_reboot' => ($werte[Unattended::REBOOT] ?? 'false') !== 'false',
            'origins' => $gelesen['lists'][Unattended::ORIGINS] ?? [],
            'managed' => is_file(Unattended::FILE),
            'setters' => Unattended::setters($this->aptConfigFiles(), Unattended::ENABLE),
            'last' => $this->stamps(),
        ];
    }

    /**
     * Die Fragmente unter `apt.conf.d`, Pfad zu Inhalt.
     *
     * Nur für die **Erklärung**, welche Datei einen Schlüssel setzt — der Wert
     * kommt aus `apt-config dump`.
     *
     * @return array<string,string>
     */
    private function aptConfigFiles(): array
    {
        $dateien = [];

        foreach (glob(self::APT_CONF_DIR.'/*') ?: [] as $pfad) {
            if (is_file($pfad)) {
                $dateien[$pfad] = (string) @file_get_contents($pfad);
            }
        }

        return $dateien;
    }

    /**
     * Wann die Automatik zuletzt etwas getan hat.
     *
     * `apt.systemd.daily` berührt je Teil eine Datei unter
     * `/var/lib/apt/periodic` (Zeilen 450 und 493); mehr als ihr Änderungsdatum
     * steht darin nicht. `null` heisst „noch nie" — und das ist auf einem
     * frisch aufgesetzten Server die Wahrheit und kein Fehler.
     *
     * @return array<string, null|int>
     */
    private function stamps(): array
    {
        $stand = [];

        foreach (Unattended::STAMPS as $name => $pfad) {
            $zeit = @filemtime($pfad);
            $stand[$name] = $zeit === false ? null : $zeit;
        }

        return $stand;
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
