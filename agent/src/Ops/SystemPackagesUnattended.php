<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\AptLock;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Guard;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Unattended;

/**
 * Die unbeaufsichtigten Updates schalten — und nachlesen, ob es angekommen ist.
 *
 * ## Zwei Einstellungen, verschieden scharf
 *
 * `docs/81 §3`, Frage 4, entschieden vom Betreiber am 24. August 2026:
 *
 * 1. **Paketlisten auffrischen: immer an.** Es ändert nichts am System und ist
 *    die Bedingung dafür, dass die Anzeige nicht lügt — eine Zahl, die drei
 *    Wochen alt ist, ist schlimmer als keine.
 * 2. **Unbeaufsichtigt installieren: der Schalter.** Voreingestellt aus.
 *
 * Beide stehen in **einer** Datei, und die schreibt diese Operation als Ganzes
 * neu. Ein Schalter, der zwei Dateien pflegte, hätte zwei Zustände, die
 * auseinanderlaufen können.
 *
 * ## Warum danach nachgelesen wird
 *
 * Weil das Schreiben nichts zusagt. `/etc/apt/apt.conf.d` wird nach ASCII
 * sortiert gelesen, die letzte Zuweisung gewinnt, und **Ziffern stehen vor
 * Buchstaben**: Gemessen am 26. August 2026 verliert eine Datei `99-probe`
 * gegen `docker-disable-periodic-update`, eine `zz-probe` gewinnt. Der Name
 * dieser Datei beginnt deshalb mit `zz` — und das ist ein Versuch und keine
 * Zusage.
 *
 * > **Ein Namensschema, das „zuletzt" bedeuten soll, bedeutet es nur, solange
 * > niemand einen Buchstaben davorschreibt.**
 *
 * Die Zusage ist das Nachlesen: `apt-config dump` nach dem Schreiben, und wenn
 * der wirksame Wert nicht der gewollte ist, **bricht die Operation ab** und
 * nennt die Dateien, die den Schlüssel ebenfalls setzen.
 *
 * > **Erfolg wird gelesen, nicht geglaubt.**
 *
 * ## Was hier ausdrücklich nicht steht
 *
 * **`Unattended-Upgrade::Allowed-Origins`.** Das Panel betreibt die Automatik
 * nicht, es konfiguriert die der Distribution — und deren Vorgabe ist breiter
 * als `-security` allein (gemessen: dazu die Release-Tasche und zwei
 * ESM-Herkünfte). Sie zu verengen wäre eine Richtlinienentscheidung im Namen
 * des Betreibers; sie zu **zeigen** ist die Auskunft, die er braucht, und das
 * tut `system.packages.list`.
 *
 * **Und kein automatischer Neustart.** `Automatic-Reboot` steht immer auf
 * `false`: Ein Hosting-Server, der nachts um drei von selbst neu startet, ist
 * ein Ausfall mit guter Absicht.
 */
final class SystemPackagesUnattended implements Op
{
    /** Wie lange das Nachinstallieren des Pakets laufen darf. */
    private const INSTALL_TIMEOUT = 900;

    public static function name(): string
    {
        return 'system.packages.unattended';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $an = Guard::enum(
            is_bool($args['enabled'] ?? null) ? ($args['enabled'] ? 'on' : 'off') : null,
            ['on', 'off'],
            'enabled',
        ) === 'on';

        /*
         * **Das Paket kommt zuerst und nur beim Einschalten.** Ohne
         * `unattended-upgrade` im Pfad tut die Automatik nichts, gleich wie die
         * Schalter stehen (`apt.systemd.daily`, Zeile 494). Beim Ausschalten
         * wird es **nicht** entfernt: Ein Schalter, der ein Paket deinstalliert,
         * nimmt einem Betreiber eine Entscheidung ab, die er nicht getroffen
         * hat.
         */
        if ($an) {
            $this->ensureInstalled($context);
        }

        $context->progress(60, 'Einstellung wird geschrieben');

        $this->write(Unattended::fragment($an));

        $context->progress(80, 'Es wird nachgelesen');

        $wirksam = $this->effective($context);

        /*
         * **Der Vergleich, wegen dessen es diese Operation gibt.** Steht nach
         * dem Schreiben etwas anderes da, hat eine fremde Datei das letzte
         * Wort — und der Betreiber erführe es sonst nie, weil das Schreiben
         * gelungen ist.
         */
        $stimmt = $wirksam['enabled']
            && $wirksam['lists_days'] > 0
            && ($an ? $wirksam['upgrade_days'] > 0 : $wirksam['upgrade_days'] === 0);

        if (! $stimmt) {
            throw AgentException::execFailed(sprintf(
                'Die Einstellung ist geschrieben und wirkt nicht. Gewollt war „%s", apt meldet: '
                .'Hauptschalter %s, Listen alle %d Tage, unbeaufsichtigt alle %d Tage. '
                .'Diese Dateien setzen den Hauptschalter, und die letzte gewinnt: %s',
                $an ? 'ein' : 'aus',
                $wirksam['enabled'] ? 'ein' : 'aus',
                $wirksam['lists_days'],
                $wirksam['upgrade_days'],
                implode(', ', $wirksam['setters']),
            ), ['setters' => $wirksam['setters']]);
        }

        $context->progress(100, $an ? 'eingeschaltet' : 'ausgeschaltet');

        /*
         * **Zurück kommt der wirksame Zustand und nicht der gewollte.** Beide
         * sind hier gleich — die Prüfung darüber lässt nichts anderes durch —,
         * und genau deshalb steht nur einer da: Zwei Felder, die dasselbe
         * bedeuten müssen, sind die Gelegenheit, an der sie es eines Tages
         * nicht mehr tun.
         */
        return ['file' => Unattended::FILE, ...$wirksam];
    }

    /**
     * Das Paket nachinstallieren, wenn es fehlt.
     *
     * **Ein ausdrücklicher Akt und kein stiller Nebeneffekt** (`docs/81 §3`,
     * Frage 4): `unattended-upgrades` ist auf keinem der vier Zielabbilder
     * vorinstalliert, das Einschalten ist also ein `apt-get install`. Deshalb
     * fragt diese Operation vorher die Sperre — sie fasst apt an.
     */
    private function ensureInstalled(Context $context): void
    {
        $paket = $context->runner->run(
            'dpkg-query',
            ['-W', '-f=${db:Status-Status}', SystemPackagesList::UNATTENDED_PACKAGE],
            15,
        );

        if (trim($paket->stdout) === 'installed') {
            return;
        }

        AptLock::ensureFree($context);

        $context->progress(20, 'unattended-upgrades wird installiert');

        $lauf = $context->stream('apt-get', [
            '-q', '-y',
            '-o', 'Dpkg::Use-Pty=0',
            '-o', 'Dpkg::Options::=--force-confold',
            'install', SystemPackagesList::UNATTENDED_PACKAGE,
        ], self::INSTALL_TIMEOUT);

        if (! $lauf->successful()) {
            throw AgentException::execFailed(
                'unattended-upgrades liess sich nicht installieren: '.$lauf->message(),
            );
        }
    }

    /**
     * Die Datei schreiben — daneben und dann umbenennen.
     *
     * Dieselbe Bauart wie in {@see SystemSourcesToggle}: `rename()` ist nur
     * innerhalb eines Dateisystems atomar, deshalb liegt die Wegwerfdatei im
     * selben Verzeichnis. Ein halb geschriebenes apt-Fragment bricht **jeden**
     * apt-Lauf dieses Servers.
     */
    private function write(string $inhalt): void
    {
        $temp = Unattended::FILE.'.srvpanel-neu';

        if (@file_put_contents($temp, $inhalt) === false || ! @chmod($temp, 0644)) {
            @unlink($temp);

            throw AgentException::execFailed('Die Einstellung liess sich nicht schreiben: '.Unattended::FILE);
        }

        if (! @rename($temp, Unattended::FILE)) {
            @unlink($temp);

            throw AgentException::execFailed('Die Einstellung liess sich nicht ablegen: '.Unattended::FILE);
        }
    }

    /**
     * Was apt danach sagt.
     *
     * @return array{enabled: bool, lists_days: int, upgrade_days: int, setters: list<string>}
     */
    private function effective(Context $context): array
    {
        $dump = $context->runner->run('apt-config', ['dump'], 30);

        if (! $dump->successful()) {
            throw AgentException::execFailed(
                'Der Zustand liess sich nach dem Schreiben nicht nachlesen: '.$dump->message(),
            );
        }

        $gelesen = Unattended::read($dump->stdout);
        $dateien = [];

        foreach (glob(SystemPackagesList::APT_CONF_DIR.'/*') ?: [] as $pfad) {
            if (is_file($pfad)) {
                $dateien[$pfad] = (string) @file_get_contents($pfad);
            }
        }

        return [
            'enabled' => Unattended::enabled($gelesen['values']),
            'lists_days' => Unattended::interval($gelesen['values'], Unattended::LISTS),
            'upgrade_days' => Unattended::interval($gelesen['values'], Unattended::UPGRADE),
            'setters' => Unattended::setters($dateien, Unattended::ENABLE),
        ];
    }
}
