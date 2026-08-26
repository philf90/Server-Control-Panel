<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

/**
 * Die Paketquellen dieses Servers — was apt benutzt und was konfiguriert ist.
 *
 * **Getrennt vom Aufruf, aus demselben Grund wie {@see Packages}.** `Runner`
 * und `Context` sind `final`, es gibt also keine Attrappe; ohne diese Naht wäre
 * der Leser nur über einen echten Server prüfbar, und das heisst: gar nicht.
 *
 * ## Warum es zwei Sichten sind und nicht eine
 *
 * `apt-get indextargets` ist apts eigene aufgelöste Sicht. Sie beantwortet
 * „was benutzt apt" — und **nicht** „was ist konfiguriert": Eine abgeschaltete
 * Quelle ist dort schlicht fort, und eine, für die apt keinen Index geholt hat,
 * ebenfalls. Gemessen am 26. August 2026: `Enabled: no` an einer Stanza nimmt
 * ihre Ziele aus der Liste (18 → 17), die Datei bleibt liegen; die zwei PPAs
 * dieses Containers fehlen seit einem `apt-get update`, weil der Proxy sie mit
 * 403 abweist.
 *
 * > **Zwei Zustände, die von einer Seite gleich aussehen, brauchen die zweite
 * > Seite — nicht eine Vermutung.**
 *
 * ## Und warum sie sich trotzdem verbinden lassen
 *
 * Weil jeder Block ein `Sourcesentry: <datei>:<stanza>` trägt — gemessen an
 * allen 18, für `.sources` wie für `.list`. Der Plan behauptete das Gegenteil,
 * bis es jemand nachgesehen hat.
 *
 * **Die Zahl ist ein Stanza-Index und keine Zeilennummer.** In `ubuntu.sources`
 * stehen die beiden Stanzas auf Zeile 32 und 40 und heissen `:1` und `:2`. Und
 * sie ist stabil: Wird Stanza 1 abgeschaltet, bleibt die zweite `:2`.
 *
 * > **Eine Zahl hinter einem Doppelpunkt sieht aus wie eine Zeilennummer.**
 */
final class Sources
{
    /**
     * Wo die Quellen stehen.
     *
     * `sources.list` ist auf einem heutigen Ubuntu nur noch ein Hinweistext
     * (gemessen: 4 Zeilen, alle Kommentar) — leer ist es aber nicht überall,
     * und wer es weglässt, verschweigt auf einem älteren System die Hälfte.
     */
    public const MAIN = '/etc/apt/sources.list';

    public const PARTS = '/etc/apt/sources.list.d';

    /**
     * Die Quelldatei des Panels selbst.
     *
     * **Sie hat bis zum 26. August keine Konstante gehabt** — geschrieben wird
     * sie von `packaging/install.sh`, und der Pfad stand dort einmal und sonst
     * nirgends. `SourceOwnershipTest` hält beide Seiten zusammen; liefen sie
     * auseinander, liesse sich die eigene Quelle nicht mehr schalten, und der
     * Grund stünde in keiner Meldung.
     */
    public const PANEL_SOURCE = '/etc/apt/sources.list.d/srvpanel.sources';

    /**
     * Die Dateien, in die das Panel schreiben darf.
     *
     * **Der Hebel, um den es hier geht.** Wer eine Paketquelle kontrolliert,
     * kann ein Paket mit höherer Fassungsnummer ausliefern, das ein beliebiges
     * anderes ersetzt — `libc6`, `openssh-server`, `srvpanel` selbst. Eine
     * fremde Quelle zu schalten ist damit nicht eine Handlung neben den
     * anderen (`docs/81 §3`, Frage 1).
     *
     * **Zwei und nicht drei.** Der Plan nennt „Sury, PGDG, das eigene Repo";
     * PGDG gibt es in diesem Repo noch nicht — es käme als
     * `srvpanel-pgdg-source`, also als Freigabe und nicht als Textfeld. Steht
     * es einmal da, kommt es hier dazu und `SourceOwnershipTest` verlangt
     * seine Gegenstelle in der Paketierung.
     *
     * **Der Pfad von Sury kommt aus {@see PhpVersions::SOURCE_FILE}** und
     * nicht noch einmal ausgeschrieben — eine zweite Fassung wäre die, die
     * beim Umbenennen stehenbleibt.
     *
     * @return list<string>
     */
    public static function owned(): array
    {
        return [PhpVersions::SOURCE_FILE, self::PANEL_SOURCE];
    }

    /**
     * Darf in diese Datei geschrieben werden?
     *
     * **`realpath()` erweitert die Annahme, es verengt sie nicht** — und hier
     * stand bis zum 26. August das Gegenteil: „ein symbolischer Verweis führt
     * sonst an der Liste vorbei". Gemessen ist es andersherum. Ein Verweis
     * **an** der eigenen Stelle wird vom Zeichenkettenvergleich ohnehin
     * angenommen, egal worauf er zeigt; `realpath()` fängt ihn nicht.
     *
     * > **Eine Auflösung, die zwei Schreibweisen zusammenführt, ist keine
     * > Prüfung — sie ist eine Nachsicht.**
     *
     * Was sie leistet: `/etc/apt/sources.list.d/./srvpanel.sources` und
     * `…/../sources.list.d/srvpanel.sources` sind dieselbe Datei und werden
     * auch so behandelt. Was sie **nicht** leisten muss: den Fall, dass jemand
     * die eigene Quelldatei durch einen Verweis ersetzt hat — dafür braucht es
     * root, und der Agent läuft ohnehin als root.
     *
     * Die Grenze ist die Liste selbst, und die steht in {@see self::owned()}.
     */
    public static function isOwned(string $pfad): bool
    {
        $echt = realpath($pfad);

        foreach (self::owned() as $eigen) {
            // **Beide Seiten aufgelöst, und die unaufgelöste als Rückfall.**
            // `realpath()` gibt `false`, wenn es die Datei nicht gibt — und
            // eine Datei, die es nicht gibt, ist trotzdem eine, die das Panel
            // anlegen dürfte.
            if ($pfad === $eigen || ($echt !== false && $echt === realpath($eigen))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Felder eines Ziels, die ein Betreiber liest.
     *
     * `indextargets` gibt je Block **29** Felder aus, die meisten davon über
     * Kompressionsverfahren und Zwischenspeicherung. Was hier steht, ist die
     * Auswahl, die auf eine Seite gehört.
     *
     * @var list<string>
     */
    public const FIELDS = ['Origin', 'Label', 'Suite', 'Codename', 'Component', 'Architecture', 'Base-URI', 'Trusted'];

    /**
     * `Feldname: Wert` — und der Anker am Zeilenanfang ist die Faltungsregel.
     *
     * **Eine Fortsetzungszeile beginnt mit einem Leerzeichen**, und darin
     * steht ein einzelner Punkt für die Leerzeile; so reist ein ganzer
     * PGP-Block in einem Feld. Weil dieser Ausdruck ein `[A-Za-z]` **am
     * Zeilenanfang** verlangt, kann keine gefaltete Zeile ein Feld werden —
     * die Faltung braucht keine eigene Prüfung.
     *
     * **Hier stand bis zum 26. August beides**, und der Bruch dazu hat nicht
     * gebissen: gemessen ohne die Prüfung grün, mit der Prüfung und gelöstem
     * Anker ebenfalls grün, erst ohne beides rot. Zwei Mechanismen für eine
     * Regel, von denen jeder für den anderen mitzahlt — und keiner von beiden
     * kann verrotten, ohne dass es auffällt.
     *
     * > **Eine Frage an die Vereinigung hält auch dann, wenn eine der Quellen
     * > blind ist — die andere zahlt für sie mit.**
     *
     * Wer den Anker lockert, um „auch eingerückte Felder" zu lesen, macht aus
     * `Comment:` mitten in einem Schlüsselblock ein Feld.
     */
    private const FIELD = '/^(?<name>[A-Za-z][A-Za-z0-9-]*):[ \t]*(?<value>.*)$/D';

    /**
     * Eine Zeile im alten Einzeiler-Format.
     *
     * Die Optionsklammer ist wahlfrei und trägt `signed-by=`, `arch=` und
     * anderes; danach kommen URI, Suite und die Komponenten.
     */
    private const ONELINE = '/^(?<type>deb|deb-src)(?:[ \t]+\[(?<options>[^\]]*)\])?[ \t]+(?<rest>\S.*)$/D';

    /**
     * Die Blöcke aus `apt-get indextargets`.
     *
     * Jeder Block ist eine Folge von Feldzeilen, getrennt durch eine Leerzeile.
     * Zurück kommt je Block **nur**, was auf eine Seite gehört — plus die
     * Herkunft aus `Sourcesentry`.
     *
     * @return list<array{file: null|string, stanza: null|int, fields: array<string, string>}>
     */
    public static function targets(string $ausgabe): array
    {
        $ziele = [];

        foreach (preg_split('/\R[ \t]*\R/', trim($ausgabe)) ?: [] as $block) {
            if (trim($block) === '') {
                continue;
            }

            $felder = [];
            $herkunft = null;

            foreach (preg_split('/\R/', $block) ?: [] as $zeile) {
                if (preg_match(self::FIELD, $zeile, $treffer) !== 1) {
                    continue;
                }

                if ($treffer['name'] === 'Sourcesentry') {
                    $herkunft = $treffer['value'];

                    continue;
                }

                if (in_array($treffer['name'], self::FIELDS, true)) {
                    $felder[$treffer['name']] = $treffer['value'];
                }
            }

            if ($felder === [] && $herkunft === null) {
                continue;
            }

            [$datei, $stanza] = self::entry($herkunft);

            $ziele[] = ['file' => $datei, 'stanza' => $stanza, 'fields' => $felder];
        }

        return $ziele;
    }

    /**
     * `<datei>:<stanza>` auseinandernehmen.
     *
     * **Getrennt wird am letzten Doppelpunkt und nicht am ersten** — ein
     * Dateiname darf welche enthalten. Und die Zahl muss eine sein: Steht dort
     * etwas anderes, ist die Herkunft unbekannt und nicht Stanza 0.
     *
     * @return array{0: null|string, 1: null|int}
     */
    private static function entry(?string $herkunft): array
    {
        if ($herkunft === null || trim($herkunft) === '') {
            return [null, null];
        }

        $herkunft = trim($herkunft);
        $doppel = strrpos($herkunft, ':');

        if ($doppel === false) {
            return [$herkunft, null];
        }

        $zahl = substr($herkunft, $doppel + 1);

        if ($zahl === '' || ltrim($zahl, '0123456789') !== '') {
            return [$herkunft, null];
        }

        return [substr($herkunft, 0, $doppel), (int) $zahl];
    }

    /**
     * Die Stanzas einer deb822-Datei, in apts Zählung.
     *
     * **Gezählt wird ein Block genau dann, wenn er eine Feldzeile trägt.**
     * Kommentare zählen nicht — `ubuntu.sources` beginnt mit 31 Kommentarzeilen,
     * und die erste Feld-Stanza ist trotzdem `:1`. Abgeschaltete Stanzas zählen
     * **mit**; sonst verschöbe sich der Index genau dann, wenn jemand eine
     * Quelle abschaltet, und der Verbund bräche in dem Fall, für den es ihn
     * gibt.
     *
     * @return list<array{stanza: int, fields: array<string, string>, enabled: bool, block: string}>
     */
    public static function stanzas(string $inhalt): array
    {
        $stanzas = [];
        $nummer = 0;

        foreach (preg_split('/\R[ \t]*\R/', $inhalt) ?: [] as $block) {
            $felder = self::fields($block);

            if ($felder === []) {
                continue;
            }

            $nummer++;

            $stanzas[] = [
                'stanza' => $nummer,
                'fields' => $felder,
                'enabled' => self::enabled($felder),

                // Der Rohblock reist mit, damit der Schlüsselleser die Faltung
                // von `Signed-By:` bekommt — sie steht in keinem Feldwert.
                'block' => $block,
            ];
        }

        return $stanzas;
    }

    /**
     * Die gefalteten Zeilen eines Feldes — für `Signed-By:` mit eingebettetem
     * Block.
     *
     * **Sie werden gebraucht und nicht nur übersprungen.** {@see self::fields()}
     * wirft sie weg, weil ein Feldwert hier eine Zeile ist; für den Schlüssel
     * ist der gefaltete Block aber der ganze Inhalt, und `gpg` liest ihn über
     * stdin ({@see Keys::unfold()}).
     *
     * @return list<string>
     */
    public static function folded(string $block, string $feld): array
    {
        $gefaltet = [];
        $drin = false;

        foreach (preg_split('/\R/', $block) ?: [] as $zeile) {
            if (preg_match(self::FIELD, $zeile, $treffer) === 1) {
                $drin = $treffer['name'] === $feld;

                continue;
            }

            // Eine Fortsetzungszeile beginnt mit einem Leerzeichen — und nur
            // solange wir im gesuchten Feld stehen.
            if ($drin && preg_match('/^[ \t]/', $zeile) === 1) {
                $gefaltet[] = $zeile;
            }
        }

        return $gefaltet;
    }

    /**
     * Die Felder eines deb822-Blocks, Faltung aufgelöst.
     *
     * **Eine Fortsetzungszeile ist keine Feldzeile**, und das entscheidet der
     * Anker in {@see self::FIELD} — nicht eine Prüfung hier. Ohne ihn läse ein
     * Ausdruck mitten in einem PGP-Block weiter, und `Comment: …` sähe dort
     * aus wie ein Feld.
     *
     * @return array<string, string>
     */
    private static function fields(string $block): array
    {
        $felder = [];

        foreach (preg_split('/\R/', $block) ?: [] as $zeile) {
            if (trim($zeile) === '' || str_starts_with(ltrim($zeile), '#')) {
                continue;
            }

            if (preg_match(self::FIELD, $zeile, $treffer) === 1) {
                $felder[$treffer['name']] = $treffer['value'];
            }
        }

        return $felder;
    }

    /**
     * Ist diese Stanza eingeschaltet?
     *
     * **Fehlt das Feld, ist sie es** — apts Vorgabe. Und der Wert wird gegen
     * `no` geprüft und nicht gegen `yes`: Sonst hielte ein Tippfehler
     * (`Enabled: yess`) die Quelle für abgeschaltet, und der Betreiber suchte
     * den Fehler an der falschen Stelle.
     *
     * @param  array<string, string>  $felder
     */
    private static function enabled(array $felder): bool
    {
        $wert = strtolower(trim($felder['Enabled'] ?? ''));

        return ! in_array($wert, ['no', 'false', '0'], true);
    }

    /**
     * Eine Stanza ein- oder ausschalten — als Text, nicht als Datei.
     *
     * **Getrennt vom Schreiben, damit es prüfbar ist.** Was hier passiert, ist
     * die ganze Regel; das Schreiben ist ein `rename()`.
     *
     * **Gearbeitet wird am Rohtext und nicht an den gelesenen Feldern.** Eine
     * Stanza trägt einen gefalteten PGP-Block über vierzig Zeilen, und ein
     * Neuschreiben aus `fields()` verlöre ihn — dort steht der Wert einer
     * Faltung nicht.
     *
     * > **Wer eine Datei aus dem liest, was er von ihr braucht, schreibt weg,
     * > was er nicht gelesen hat.**
     *
     * Ein vorhandenes `Enabled:` wird ersetzt, sonst kommt die Zeile **hinter
     * die erste Feldzeile** der Stanza. Nicht ans Ende: Dort könnte eine
     * Fortsetzungszeile stehen, und eine Zeile dahinter sähe zwar wie ein Feld
     * aus, stünde aber optisch mitten im Schlüssel.
     *
     * @throws AgentException wenn es die Stanza nicht gibt
     */
    public static function toggled(string $inhalt, int $stanza, bool $enabled): string
    {
        $bloecke = preg_split('/(\R[ \t]*\R)/', $inhalt, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $nummer = 0;
        $getroffen = false;

        foreach ($bloecke as $i => $block) {
            // Die Trenner stehen an den ungeraden Stellen und bleiben, wie sie
            // sind — sonst verschiebt jedes Schalten die ganze Datei.
            if ($i % 2 === 1 || self::fields($block) === []) {
                continue;
            }

            $nummer++;

            if ($nummer !== $stanza) {
                continue;
            }

            $bloecke[$i] = self::withEnabled($block, $enabled);
            $getroffen = true;
        }

        if (! $getroffen) {
            throw AgentException::badRequest('Diesen Eintrag gibt es in der Datei nicht.', ['stanza' => $stanza]);
        }

        return implode('', $bloecke);
    }

    /** Die `Enabled:`-Zeile eines Blocks setzen oder einfügen. */
    private static function withEnabled(string $block, bool $enabled): string
    {
        $wert = $enabled ? 'yes' : 'no';
        $zeilen = preg_split('/\R/', $block) ?: [];

        foreach ($zeilen as $i => $zeile) {
            if (preg_match(self::FIELD, $zeile, $treffer) === 1 && $treffer['name'] === 'Enabled') {
                $zeilen[$i] = 'Enabled: '.$wert;

                return implode("\n", $zeilen);
            }
        }

        foreach ($zeilen as $i => $zeile) {
            if (preg_match(self::FIELD, $zeile) === 1) {
                array_splice($zeilen, $i + 1, 0, ['Enabled: '.$wert]);

                return implode("\n", $zeilen);
            }
        }

        return $block;
    }

    /**
     * Die Einträge einer `.list`-Datei, in apts Zählung.
     *
     * Eine auskommentierte Zeile ist **kein** Eintrag: Im Einzeiler-Format gibt
     * es kein `Enabled:`, das Abschalten ist das Kommentarzeichen — und apt
     * zählt nur, was es liest.
     *
     * @return list<array{stanza: int, fields: array<string, string>, enabled: bool, block: string}>
     */
    public static function oneliners(string $inhalt): array
    {
        $eintraege = [];
        $nummer = 0;

        foreach (preg_split('/\R/', $inhalt) ?: [] as $zeile) {
            if (preg_match(self::ONELINE, trim($zeile), $treffer) !== 1) {
                continue;
            }

            $nummer++;
            $teile = preg_split('/[ \t]+/', trim($treffer['rest'])) ?: [];

            $eintraege[] = [
                'stanza' => $nummer,
                'fields' => array_filter([
                    'Types' => $treffer['type'],
                    'URIs' => array_shift($teile) ?? '',
                    'Suites' => array_shift($teile) ?? '',
                    'Components' => implode(' ', $teile),
                    // Kein `??`: Hinter `options` steht `rest`, also ist die
                    // Gruppe auch dann gesetzt (auf `''`), wenn die
                    // Optionsklammer fehlt — dieselbe PCRE-Regel wie bei
                    // {@see Packages::inst()}, dort an `old` gemessen.
                    'Signed-By' => self::option($treffer['options'], 'signed-by'),
                ], static fn (string $w): bool => $w !== ''),
                'enabled' => true,

                // Ein Einzeiler hat keine Faltung — `signed-by=` ist ein Pfad.
                'block' => '',
            ];
        }

        return $eintraege;
    }

    /** Ein Wert aus der Optionsklammer eines Einzeilers, `''` wenn er fehlt. */
    private static function option(string $optionen, string $name): string
    {
        foreach (preg_split('/[ \t]+/', trim($optionen)) ?: [] as $paar) {
            [$schluessel, $wert] = array_pad(explode('=', $paar, 2), 2, '');

            if (strtolower($schluessel) === $name) {
                return $wert;
            }
        }

        return '';
    }

    /**
     * Woher der Schlüssel dieser Stanza kommt — und ob er in der Datei steht.
     *
     * **Drei Formen, alle drei gemessen** (26. August 2026, in einem einzigen
     * `/etc/apt/sources.list.d`):
     *
     *     Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
     *     Signed-By:
     *      -----BEGIN PGP PUBLIC KEY BLOCK-----
     *     Signed-By: -----BEGIN PGP PUBLIC KEY BLOCK-----
     *      .
     *
     * Ein Leser, der „nicht leer heisst Pfad" annimmt, hält bei der dritten
     * Form den Anfang des Blocks für einen Dateinamen und meldet dem Betreiber
     * eine Datei, die es nicht gibt.
     *
     * > **Ein Wert, der auch leer sein darf, unterscheidet sich nicht dadurch
     * > von einem Pfad, dass er nicht leer ist.**
     *
     * @param  array<string, string>  $felder
     * @return array{kind: string, path: null|string}
     */
    public static function key(array $felder): array
    {
        $wert = trim($felder['Signed-By'] ?? '');

        if ($wert === '') {
            // Leer heisst hier zweierlei: kein Feld, oder ein Feld, dessen
            // Wert gefaltet darunter steht. Beides ist „kein Pfad", und mehr
            // braucht die Anzeige an dieser Stelle nicht.
            return ['kind' => isset($felder['Signed-By']) ? 'embedded' : 'none', 'path' => null];
        }

        if (str_starts_with($wert, '-----BEGIN')) {
            return ['kind' => 'embedded', 'path' => null];
        }

        return ['kind' => 'path', 'path' => $wert];
    }
}
