<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Signaturschlüssel der Paketquellen — Fingerabdruck und Ablauf.
 *
 * **Warum es das gibt.** Ein abgelaufener Schlüssel bricht `apt-get update`,
 * und weil das mit `0` endet (M5, `docs/81 §2.1`), merkt es niemand — der
 * Server bleibt auf den alten Listen stehen und meldet „nichts zu tun".
 * `docs/81 §4` Punkt 4 verlangt deshalb, dass ein Schlüssel gemeldet wird,
 * **bevor** ein Lauf an ihm scheitert.
 *
 * **Getrennt vom Aufruf, wie {@see Packages} und {@see Sources}** — `Runner`
 * ist `final`, es gibt also keine Attrappe.
 *
 * ## Die Form, gemessen und nicht nachgelesen
 *
 * `gpg --show-keys --with-colons` schreibt je Schlüssel mehrere Zeilen:
 *
 *     pub:-:4096:1:8D81803C0EBFCD88:1487788586:::-:::escaESCA::::::23::0:
 *     fpr:::::::::9DC858229FC7DD38854AE2D88D81803C0EBFCD88:
 *     uid:-::::1487792064::B50C…::Docker Release (CE deb) <docker@docker.com>::…
 *     sub:-:4096:1:7EA0A9C3F273FCD8:1487788586::::::s::::::23:
 *     fpr:::::::::D3306A018370199E527AE7997EA0A9C3F273FCD8:
 *
 * **Feld 7 der `pub`-Zeile ist der Ablauf als Unixzeit; leer heisst „läuft nie
 * ab".** Gemessen am 26. August 2026 auf Debian 12 in der CI, an einem eigens
 * hergestellten Bund: eine Zeile mit leerem Feld 7, eine mit `1819259803`.
 *
 * **Und die `fpr`-Zeile gehört zur zuletzt gesehenen `pub` ODER `sub`.** Auf
 * dieser Maschine stehen 12 `fpr` bei 11 `pub` und 1 `sub` — wer „die
 * `fpr`-Zeile" nimmt, hängt einem Schlüssel den Fingerabdruck seines
 * Unterschlüssels an.
 *
 * > **Eine Zeile, die zur vorigen gehört, gehört nicht zur vorigen ihrer
 * > Art.**
 */
final class Keys
{
    /** Ab wann ein Ablauf gemeldet wird — `docs/81 §4` Punkt 4. */
    public const SOON_SECONDS = 30 * 86400;

    /**
     * Das Heimverzeichnis, das `gpg` bekommt.
     *
     * **Hier stand bis zum 3. September 2026 das Gegenteil dessen, was
     * `gpg` tut**, und der Satz hat die Prüfung `apt.key` von ihrem ersten
     * Tag an unbrauchbar gemacht: „`gpg` legt sein Heimverzeichnis an, auch
     * wenn es nur liest." Es legt es **nicht** an — es stirbt daran:
     *
     *     gpg: keyblock resource '…/gnupg/pubring.kbx': No such file or directory
     *     gpg: Fatal: /var/lib/srvpanel/gnupg: directory does not exist!
     *     rc=2
     *
     * Und diesen Ort legt niemand an: In der Paketierung steht er nicht.
     * Damit gab `Keys::inspect()` auf jedem Server `readable: false` zurück —
     * die Bestandsdiagnose meldete `apt.key` als **nicht gemessen**, und die
     * Quellenseite kannte zu keiner Quelle einen Schlüssel. Gefunden hat es
     * der Abnahmelauf (`docs/99`, Punkt 1, `cloudsrv24`, 0.7.3-rc.12).
     *
     * Die Messung, aus der der falsche Satz entstand, stimmte sogar — ein
     * fehlender `--homedir` scheitert mit `rc=2`. Nur der **Schluss** daraus
     * war verkehrt herum.
     *
     * > **Eine Messung und der Schluss daraus sind zwei Dinge — und
     * > aufgeschrieben wird der Schluss.**
     *
     * **Der Ort bleibt trotzdem stehen**, obwohl der Aufruf ihn seit den
     * Schaltern unten nicht mehr braucht: Sollte eine künftige Fassung von
     * `gpg` doch etwas ablegen wollen, legt sie es hier ab und nicht in
     * `/root/.gnupg`, wo es danach niemand erklären kann.
     */
    public const HOME = '/var/lib/srvpanel/gnupg';

    /**
     * Der Aufruf, der wirklich nur liest.
     *
     * `--show-keys` ist die lesende Frage, und trotzdem legt `gpg` neben ihr
     * seinen Hausrat an: in ein frisches Heimverzeichnis geschrieben werden
     * `pubring.kbx` **und** `trustdb.gpg` (gemessen, gpg 2.4.4).
     *
     * > **Ein Programm, das seinen Gegenstand nur liest, schreibt trotzdem —
     * > sein Arbeitsverzeichnis legt es beim ersten Mal an.**
     *
     * Zwei Schalter nehmen beides weg, und sie sind einzeln gemessen:
     * `--no-keyring` lässt `pubring.kbx` weg, `--trust-model always` die
     * `trustdb.gpg`. Zusammen schreibt der Aufruf **nichts** und braucht
     * **kein** Heimverzeichnis — auch dann nicht, wenn `--homedir` auf einen
     * Pfad zeigt, den es nicht gibt.
     *
     * Gemessen am 3. September 2026 gegen
     * `packaging/srvpanel-archive-keyring.gpg`, also gegen genau den Bund,
     * den `Signed-By` auf einem Server nennt:
     *
     * | | Heimverzeichnis fehlt | Heimverzeichnis da |
     * |---|---|---|
     * | ohne die Schalter | `rc=2`, nichts gelesen | `rc=0`, legt zwei Dateien an |
     * | mit den Schaltern | `rc=0`, ein Schlüssel | `rc=0`, legt nichts an |
     *
     * **Am Urteil ändern sie nichts**, und auch das ist gemessen: Die
     * Ausgabe mit und ohne die beiden Schalter ist Zeile für Zeile
     * identisch. `--show-keys` prüft keine Signatur, und
     * {@see self::read()} liest Kennung, Fingerabdruck und Ablauf — an
     * keiner Stelle die Gültigkeitsspalte, auf die ein Vertrauensmodell
     * wirkt.
     *
     * @var list<string>
     */
    public const ARGUMENTS = ['--batch', '--no-tty', '--show-keys', '--with-colons', '--no-keyring', '--trust-model', 'always'];

    /** `pub` und `sub` — beide führen einen Fingerabdruck. */
    private const KEY_LINE = '/^(?<art>pub|sub):[^:]*:[^:]*:[^:]*:(?<keyid>[^:]*):(?<created>[^:]*):(?<expires>[^:]*):/';

    private const FPR_LINE = '/^fpr:(?:[^:]*:){8}(?<fingerprint>[0-9A-Fa-f]+):/';

    private const UID_LINE = '/^uid:(?:[^:]*:){8}(?<uid>[^:]*):/';

    /**
     * Was `gpg --with-colons` sagt, als Auskunft.
     *
     * **Unterschlüssel kommen nicht zurück.** Sie laufen eigenständig ab, und
     * ein Betreiber, der „drei Schlüssel, zwei laufen bald aus" liest, zählt
     * Dinge, die er in `Signed-By:` nie gesehen hat. Gezählt werden die
     * Hauptschlüssel; ihre `sub`-Zeilen dienen hier nur dazu, den falschen
     * Fingerabdruck **nicht** zu nehmen.
     *
     * @return list<array{fingerprint: null|string, keyid: string, created: null|int, expires: null|int, uid: null|string}>
     */
    public static function read(string $ausgabe): array
    {
        $schluessel = [];
        $offen = null;

        foreach (preg_split('/\R/', $ausgabe) ?: [] as $zeile) {
            if (preg_match(self::KEY_LINE, $zeile, $treffer) === 1) {
                if ($offen !== null) {
                    $schluessel[] = $offen;
                    $offen = null;
                }

                if ($treffer['art'] === 'pub') {
                    $offen = [
                        'fingerprint' => null,
                        'keyid' => $treffer['keyid'],
                        'created' => self::zahl($treffer['created']),
                        'expires' => self::zahl($treffer['expires']),
                        'uid' => null,
                    ];
                }

                continue;
            }

            if ($offen === null) {
                continue;
            }

            /*
             * **Nur der Fingerabdruck hinter der `pub`-Zeile** — und das
             * entscheidet allein die Zeile oben, die `$offen` bei jeder `sub`
             * schliesst. Danach fällt jede weitere `fpr` in das `continue`
             * darüber.
             *
             * **Hier standen bis zum 26. August drei Fassungen derselben
             * Regel**, und zwei Eingriffe nacheinander bissen nicht. Gemessen,
             * einzeln und zusammen: die Prüfung auf die Art der letzten Zeile
             * allein — grün. Ein `$offen['fingerprint'] === null` daneben
             * allein — grün. **Erst ohne beide rot.** Jede zahlte für die
             * andere mit, und keine hätte verrotten können, ohne dass es
             * auffällt.
             *
             * > **Ein Eingriff, der nicht beisst, sagt entweder etwas über den
             * > Wächter oder etwas über die Regel.**
             *
             * Dasselbe wie beim Feldanker in {@see Sources::FIELD}, und in
             * derselben Runde zum dritten Mal.
             */
            if (preg_match(self::FPR_LINE, $zeile, $treffer) === 1) {
                $offen['fingerprint'] = strtoupper($treffer['fingerprint']);

                continue;
            }

            if ($offen['uid'] === null && preg_match(self::UID_LINE, $zeile, $treffer) === 1) {
                $offen['uid'] = $treffer['uid'] === '' ? null : self::entschluesselt($treffer['uid']);
            }
        }

        if ($offen !== null) {
            $schluessel[] = $offen;
        }

        return $schluessel;
    }

    /**
     * Läuft dieser Schlüssel bald ab — oder ist er schon abgelaufen?
     *
     * **Drei Zustände und nicht zwei.** `null` heisst „läuft nie ab", und das
     * ist etwas anderes als „läuft in fünf Jahren ab": Beides ist heute
     * unbedenklich, aber nur das zweite wird es irgendwann nicht mehr sein.
     *
     * @return 'expired'|'never'|'ok'|'soon'
     */
    public static function state(?int $expires, int $jetzt): string
    {
        if ($expires === null) {
            return 'never';
        }

        if ($expires <= $jetzt) {
            return 'expired';
        }

        return $expires - $jetzt < self::SOON_SECONDS ? 'soon' : 'ok';
    }

    /**
     * Der Schlüssel einer Quelle — Art, Ablauf, Lesbarkeit.
     *
     * **Bis zum 2. September 2026 war das eine private Methode von
     * `SystemSourcesList`.** A10 braucht dieselbe Frage für die eigene
     * Paketquelle (`apt.key`, `docs/98 §3 I`), und eine zweite Fassung wäre
     * die, die veraltet. Hier steht sie einmal; beide rufen sie.
     *
     * **Zwei Wege in dasselbe `gpg`.** Ein Pfad geht als Argument hinein, ein
     * eingebetteter Block über stdin — beide gemessen (`docs/81 §2.3b`, Q7).
     * Der Aufruf ist derselbe und steht als {@see self::ARGUMENTS} einmal da.
     *
     * **Ein Fehlschlag ist kein leeres Ergebnis.** Ein Pfad, den es nicht gibt,
     * endet mit `rc=2` — und „keine Schlüssel gefunden" sähe aus wie „dieser
     * Quelle fehlt der Schlüssel", was etwas ganz anderes heisst. Er kommt
     * deshalb als `readable: false` zurück und nicht als leere Liste.
     *
     * > **Eine leere Liste, die „nicht nachgesehen" bedeutet, sieht aus wie
     * > „nichts da".**
     *
     * @param  array<string, string>  $felder
     * @return array{kind: string, path: null|string, keys: list<array{fingerprint: null|string, keyid: string, created: null|int, expires: null|int, uid: null|string, state: string}>, readable: bool}
     */
    public static function inspect(Context $context, array $felder, string $block): array
    {
        $art = Sources::key($felder);

        if ($art['kind'] === 'none') {
            return $art + ['keys' => [], 'readable' => true];
        }

        $lauf = $art['kind'] === 'path'
            ? $context->runner->run('gpg', [...self::ARGUMENTS, '--homedir', self::HOME, (string) $art['path']], 20)
            : $context->runner->run(
                'gpg',
                [...self::ARGUMENTS, '--homedir', self::HOME],
                20,
                input: self::unfold($felder['Signed-By'] ?? '', Sources::folded($block, 'Signed-By')),
            );

        if (! $lauf->successful()) {
            return $art + ['keys' => [], 'readable' => false];
        }

        $jetzt = time();
        $schluessel = [];

        foreach (self::read($lauf->stdout) as $eine) {
            $schluessel[] = $eine + ['state' => self::state($eine['expires'], $jetzt)];
        }

        return $art + ['keys' => $schluessel, 'readable' => true];
    }

    /**
     * Einen eingebetteten `Signed-By:`-Block auffalten.
     *
     * **Drei Formen, alle drei gemessen** (`docs/81 §2.3b`, Q7): ein Pfad, ein
     * leerer Wert mit gefaltetem Block darunter, und der Blockanfang **in
     * derselben Zeile**. Aufgefaltet wird nach deb822: Jede Fortsetzungszeile
     * beginnt mit einem Leerzeichen, und ein einzelner Punkt darin steht für
     * die Leerzeile.
     *
     * Zurück kommt, was `gpg` über stdin lesen kann — gemessen: rc 0, eine
     * `pub`- und eine `fpr`-Zeile.
     *
     * @param  list<string>  $gefaltet  Die Zeilen unter dem Feld, mit ihrem führenden Leerzeichen
     */
    public static function unfold(string $wert, array $gefaltet): string
    {
        $zeilen = [];

        if (trim($wert) !== '') {
            $zeilen[] = trim($wert);
        }

        foreach ($gefaltet as $zeile) {
            $ohne = preg_replace('/^[ \t]/', '', $zeile) ?? $zeile;
            $zeilen[] = $ohne === '.' ? '' : $ohne;
        }

        return implode("\n", $zeilen)."\n";
    }

    /** Ein leeres Feld ist keine Null. */
    private static function zahl(string $roh): ?int
    {
        return $roh === '' || ltrim($roh, '0123456789') !== '' ? null : (int) $roh;
    }

    /**
     * `--with-colons` maskiert in der `uid` mit `\x3a` und Verwandten.
     *
     * Ohne diese Rückwandlung liest ein Betreiber `Docker Release (CE deb)
     * \x3cdocker@docker.com\x3e` — richtig kodiert und trotzdem falsch
     * angezeigt.
     */
    private static function entschluesselt(string $roh): string
    {
        return (string) preg_replace_callback(
            '/\\\\x([0-9a-fA-F]{2})/',
            static fn (array $t): string => chr((int) hexdec($t[1])),
            $roh,
        );
    }
}
