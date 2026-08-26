<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Was die Automatik von apt **wirklich** tut — nicht, was in unserer Datei
 * steht.
 *
 * ## Der Befund, der diese Klasse trägt (M8, `docs/81 §2.1`)
 *
 * > **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen
 * > Zustand.**
 *
 * `/etc/apt/apt.conf.d` wird nach **ASCII** sortiert gelesen, und die letzte
 * Zuweisung gewinnt. Gemessen am 26. August 2026 in diesem Container:
 *
 *     99-probe  →  APT::Periodic::Enable "0"   (verloren)
 *     zz-probe  →  APT::Periodic::Enable "7"   (gewonnen)
 *     ohne      →  APT::Periodic::Enable "0"   (Gegenprobe)
 *
 * Der Gewinner ist `docker-disable-periodic-update` — ein **fremdes** Paket.
 * Und die Zahlen sagen, warum ein `99`-Präfix nicht genügt: Ziffern stehen im
 * ASCII vor Buchstaben, eine Datei mit einem Namen aus Buchstaben schlägt also
 * jede numerierte.
 *
 * > **Ein Namensschema, das „zuletzt" bedeuten soll, bedeutet es nur, solange
 * > niemand einen Buchstaben davorschreibt.**
 *
 * Deshalb wird hier **gefragt statt gerechnet**: `apt-config dump` ist apts
 * eigene aufgelöste Sicht, so wie `apt-get indextargets` es für die Quellen
 * ist. Diese Klasse liest sie und rechnet die Auflösung nicht nach.
 *
 * ## Die fünf Teile des wirksamen Zustands
 *
 * Gemessen an `/usr/lib/apt/apt.systemd.daily` und nicht nachgelesen:
 *
 * | Teil | Wo | Was gemessen ist |
 * |---|---|---|
 * | Das Paket | `unattended-upgrade` im Pfad | Zeile 494: ohne das Programm läuft nichts |
 * | Der Hauptschalter | `APT::Periodic::Enable` | Zeile 356–360: `0` ⇒ `exit 0`, sonst nichts |
 * | Listen auffrischen | `APT::Periodic::Update-Package-Lists` | Abstand in Tagen; `0` heisst nie |
 * | Unbeaufsichtigt | `APT::Periodic::Unattended-Upgrade` | dito |
 * | Die Zeitgeber | `apt-daily.timer`, `apt-daily-upgrade.timer` | sie stossen das Skript überhaupt erst an |
 *
 * **Und der Hauptschalter hat eine Vorgabe, die man raten kann und dann falsch
 * hat.** In `apt.systemd.daily` steht `AutoAptEnable=1  # default is yes` —
 * eine **fehlende** Zeile heisst „an" und nicht „aus". Ein Leser, der aus dem
 * Fehlen auf „aus" schlösse, meldete eine abgeschaltete Automatik auf jedem
 * frisch aufgesetzten Server.
 *
 * > **Eine Vorgabe, die nirgends steht, steht im Programm — und nur dort.**
 */
final class Unattended
{
    /**
     * Die Datei, die das Panel schreibt.
     *
     * **`zz-` und nicht `99-`**, aus der Messung oben: Ein numerierter Name
     * verliert gegen jeden, der mit einem Buchstaben beginnt. Das ist der
     * Versuch, das letzte Wort zu haben — die **Zusage** ist es nicht, dafür
     * gibt es das Nachlesen.
     */
    public const FILE = '/etc/apt/apt.conf.d/zz-srvpanel-unattended';

    /** Der Hauptschalter: `0` beendet den täglichen Lauf sofort. */
    public const ENABLE = 'APT::Periodic::Enable';

    /** Abstand in Tagen, in dem die Paketlisten aufgefrischt werden. */
    public const LISTS = 'APT::Periodic::Update-Package-Lists';

    /** Abstand in Tagen, in dem unbeaufsichtigt installiert wird. */
    public const UPGRADE = 'APT::Periodic::Unattended-Upgrade';

    /** Ob die Automatik von sich aus neu startet — bleibt `false` (`docs/81 §3`). */
    public const REBOOT = 'Unattended-Upgrade::Automatic-Reboot';

    /** Woraus die Automatik nimmt. Das Panel setzt es nicht; es zeigt es. */
    public const ORIGINS = 'Unattended-Upgrade::Allowed-Origins';

    /** Wo `apt.systemd.daily` festhält, wann es zuletzt etwas getan hat. */
    public const STAMPS = [
        'lists' => '/var/lib/apt/periodic/update-stamp',
        'upgrade' => '/var/lib/apt/periodic/upgrade-stamp',
    ];

    /** Die beiden Zeitgeber, die das Skript überhaupt anstossen. */
    public const TIMERS = ['apt-daily.timer', 'apt-daily-upgrade.timer'];

    /**
     * Eine Zeile von `apt-config dump`.
     *
     *     APT::Periodic::Enable "0";
     *     Unattended-Upgrade::Allowed-Origins:: "${distro_id}:${distro_codename}";
     *
     * **Das doppelte `::` am Ende ist ein Listeneintrag** und kein Tippfehler:
     * So schreibt apt eine Liste, und `Allowed-Origins` ist eine (gemessen:
     * vier Einträge nach der Installation). Der Ausdruck nimmt beides und
     * merkt sich, was davon eine Liste war.
     */
    private const LINE = '/^(?<key>[A-Za-z][A-Za-z0-9:_-]*?)(?<list>::)? "(?<value>.*)";$/D';

    /**
     * Die aufgelöste Sicht von apt, als Wörterbuch.
     *
     * Listeneinträge sammeln sich unter ihrem Schlüssel; ein einfacher Wert
     * steht dort allein. Was mehrfach ohne `::` zugewiesen wird, behält die
     * **letzte** Zuweisung — so, wie apt es auflöst.
     *
     * @return array{values: array<string,string>, lists: array<string,list<string>>}
     */
    public static function read(string $dump): array
    {
        $values = [];
        $lists = [];

        foreach (preg_split('/\R/', $dump) ?: [] as $zeile) {
            if (preg_match(self::LINE, trim($zeile), $treffer) !== 1) {
                continue;
            }

            /*
             * **Kein `??`, und das ist keine Sparsamkeit, sondern PCRE.** Eine
             * Gruppe, die nicht mitspielt, fehlt nur dann im Ergebnis, wenn
             * nach ihr keine mitspielende mehr kommt. Hinter `list` steht
             * `value`, also ist `list` bei einer gewöhnlichen Zeile `''` und
             * nicht fort — dieselbe Messung wie bei `Packages::inst()`.
             */
            if ($treffer['list'] === '::') {
                $lists[$treffer['key']][] = $treffer['value'];

                continue;
            }

            $values[$treffer['key']] = $treffer['value'];
        }

        return ['values' => $values, 'lists' => $lists];
    }

    /**
     * Der Hauptschalter — mit der Vorgabe, die im Programm steht.
     *
     * **`null` gibt es hier nicht, und das ist die Aussage.** Eine fehlende
     * Zeile ist keine offene Frage: `apt.systemd.daily` setzt `AutoAptEnable=1`
     * und überschreibt es erst, wenn apt etwas liefert. Wer daraus „nicht
     * nachgesehen" machte, verwechselte eine gemessene Vorgabe mit einer
     * fehlenden Messung.
     *
     * @param  array<string,string>  $values
     */
    public static function enabled(array $values): bool
    {
        return ($values[self::ENABLE] ?? '1') !== '0';
    }

    /**
     * Ein Abstand in Tagen — `0` heisst nie.
     *
     * Gelesen wird als Zahl und nicht als Wahrheitswert: `apt.systemd.daily`
     * vergleicht ihn mit dem Alter des Zeitstempels, und eine `7` heisst
     * „wöchentlich". Ein Leser, der `"7"` für `true` nähme, verlöre genau die
     * Auskunft, die den Unterschied macht.
     *
     * @param  array<string,string>  $values
     */
    public static function interval(array $values, string $key): int
    {
        $roh = $values[$key] ?? '0';

        return is_numeric($roh) ? (int) $roh : 0;
    }

    /**
     * Welche Dateien diesen Schlüssel setzen — **zur Erklärung, nicht zur
     * Entscheidung.**
     *
     * Der Wert kommt aus `apt-config dump`; diese Liste sagt nur, wo er
     * herkommen **könnte**, damit die Seite den Betreiber zur richtigen Datei
     * schickt, wenn seine Einstellung nicht ankommt. Sie rechnet die Auflösung
     * ausdrücklich nicht nach — das wäre eine zweite Fassung von apt.
     *
     * > **Eine Erklärung, die man für die Antwort hält, ist die zweite
     * > Fassung.**
     *
     * @param  array<string,string>  $files  Pfad => Inhalt
     * @return list<string>
     */
    public static function setters(array $files, string $key): array
    {
        $muster = '/^\s*'.preg_quote($key, '/').'\s*(?:::)?\s*"/mi';
        $treffer = [];

        foreach ($files as $pfad => $inhalt) {
            if (preg_match($muster, $inhalt) === 1) {
                $treffer[] = $pfad;
            }
        }

        // Dieselbe Ordnung, in der apt liest: ASCII, Ziffern vor Buchstaben.
        // Der letzte Eintrag ist damit der, der gewinnt.
        sort($treffer, SORT_STRING);

        return $treffer;
    }

    /**
     * Was das Panel in seine Datei schreibt.
     *
     * **Zwei Einstellungen, verschieden scharf** (`docs/81 §3`, Frage 4): Die
     * Paketlisten aufzufrischen ändert nichts am System und ist die Bedingung
     * dafür, dass die Anzeige nicht lügt — es steht deshalb immer auf `1`,
     * gleich wie der Schalter steht. Unbeaufsichtigt zu **installieren** ist
     * die scharfe Hälfte und folgt dem Schalter.
     *
     * **Und `Automatic-Reboot` steht immer auf `false`.** Ein Hosting-Server,
     * der nachts um drei von selbst neu startet, ist ein Ausfall mit guter
     * Absicht; der Neustart wird angezeigt und von Hand ausgelöst.
     *
     * **Was hier ausdrücklich nicht steht: `Allowed-Origins`.** Das Panel
     * betreibt die Automatik nicht, es konfiguriert die der Distribution — und
     * deren Vorgabe ist breiter als `-security` allein (gemessen: dazu die
     * Release-Tasche und zwei ESM-Herkünfte). Sie zu verengen wäre eine
     * Richtlinienentscheidung im Namen des Betreibers; sie zu **zeigen** ist
     * die Auskunft, die er braucht.
     */
    public static function fragment(bool $upgrade): string
    {
        return implode("\n", [
            '// Diese Datei gehört dem Panel und wird bei jeder Änderung neu geschrieben.',
            '//',
            '// Der Name beginnt mit zz, weil apt seine Fragmente nach ASCII sortiert liest',
            '// und die letzte Zuweisung gewinnt — eine numerierte Datei verliert gegen jede,',
            '// die mit einem Buchstaben beginnt. Ob es gereicht hat, liest das Panel über',
            '// `apt-config dump` nach; auf den Namen allein verlässt es sich nicht.',
            '',
            self::ENABLE.' "1";',
            self::LISTS.' "1";',
            self::UPGRADE.' "'.($upgrade ? '1' : '0').'";',
            self::REBOOT.' "false";',
            '',
        ]);
    }
}
