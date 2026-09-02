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
     * Die Endungen, die apt in {@see self::PARTS} überhaupt liest.
     *
     * **Gemessen und nicht nachgelesen** (27. August 2026, apt 2.8.3, mit
     * eigenem `Dir::Etc::sourceparts`): Von acht Dateien liest apt genau zwei —
     * `a.list` und `b.sources`. Die anderen sechs — `.sources.curtin.orig`,
     * `.list.bak`, `.txt`, `.disabled`, `.sources.disabled`, `.list.disabled` —
     * ignoriert es **stumm**: kein Wort auf stderr, kein Rückgabewert.
     *
     * Und die Gegenprobe sagt, dass die Endung entscheidet und nicht der
     * Inhalt: Dieselben Bytes noch einmal als `c.sources` abgelegt, und apt
     * holt drei Ziele statt zwei.
     *
     * > **Ein Prüfkörper, der nicht gelesen wird, kann auch kaputt sein — das
     * > sieht gleich aus.**
     *
     * **Der Anlass ist `docs/86`, Befund 13.** Auf `cloudsrv24` liegt seit der
     * Installation ein `ubuntu.sources.curtin.orig`. Der Filter war richtig und
     * von nichts gehalten: `SourceListTest` prüft das **Zerlegen** einer Datei
     * und nirgends, welche Dateien gelesen werden.
     *
     * > **Ein Filter, der stimmt, und ein Filter, den etwas hält, sehen heute
     * > gleich aus — und morgen nicht mehr.**
     *
     * @var list<string>
     */
    public const EXTENSIONS = ['list', 'sources'];

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
     * Die Adressen aus dem Feld `URIs:` einer deb822-Quelldatei.
     *
     * **Hier und nicht in `PhpVersions`, seit dem 1. September 2026.** Der
     * Leser ist seit A1 dort entstanden, weil PHP ihn zuerst brauchte — und er
     * hat mit PHP nichts zu tun. Als `PanelUpdate` dieselbe Frage für
     * {@see self::PANEL_SOURCE} stellen musste, hätte der Aufruf
     * `PhpVersions::sourceUris(Sources::PANEL_SOURCE)` gelautet: derselbe
     * Verweis-ohne-Bezug, den dieses Projekt an Kommentaren, Policies und
     * Zertifikaten schon sechsmal bezahlt hat.
     *
     * > **Ein Name, der den ersten Aufrufer nennt, wird beim zweiten zur
     * > falschen Auskunft.**
     *
     * `PhpVersions::sourceUris()` reicht seitdem hierher durch und bleibt der
     * Ort, an dem die **Vorgabedatei** von PHP steht —
     * `PhpSourceUriTest` hält weiter deren Naht zur Paketierung.
     *
     * ## Warum das kein deb822-Leser ist
     *
     * `docs/81 §2.1b` entscheidet ausdrücklich gegen einen. Gelesen wird **ein
     * Feld einer Datei, die das Panel selbst geschrieben hat**, und dafür
     * genügt die eine Eigenschaft, die deb822 zusichert: Ein fortgesetzter Wert
     * beginnt mit einem Leerzeichen. Ein `URIs:` am Zeilenanfang ist deshalb
     * immer ein Feldname und nie die Fortsetzung eines anderen.
     *
     * Mehrere Stanzas mit je eigenem `URIs:` kommen alle mit: Wer die Datei von
     * Hand erweitert hat, soll nicht die Hälfte der Antwort bekommen.
     *
     * **Leer heisst „keine eigene Quelle" und nicht „nicht nachgesehen".** Der
     * Aufrufer kann dann keine Quelle als schuldig benennen, und das ist
     * richtig so.
     *
     * @return list<string>
     */
    public static function uris(string $datei): array
    {
        if (! is_file($datei)) {
            return [];
        }

        $uris = [];

        foreach (explode("\n", (string) file_get_contents($datei)) as $zeile) {
            if (preg_match('/^URIs:\s*(.*?)\s*$/D', rtrim($zeile, "\r"), $treffer) !== 1) {
                continue;
            }

            // deb822 erlaubt mehrere Adressen in einem Feld, durch Leerraum
            // getrennt. Eine einzelne Zeichenkette zurückzugeben hiesse, bei
            // zweien die zweite zu verlieren.
            foreach (preg_split('/\s+/', $treffer[1]) ?: [] as $uri) {
                if ($uri !== '') {
                    $uris[] = $uri;
                }
            }
        }

        return array_values(array_unique($uris));
    }

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
     * Die Adressen der **eingeschalteten** Quellen einer Datei.
     *
     * ## Der Befund, gegen den es sie gibt
     *
     * **Der Fehler ist am Quelltext hergeleitet, die Wirkung dieses Lesers ist
     * gemessen** (`docs/96 §4b`). Der Prüfkörper hat den Zustand zweimal nicht
     * hergestellt (Befund 14) — `sed -i` meldet Erfolg, auch wenn sein Muster
     * nirgends passt, und die Quelldatei des Panels trägt keine
     * `Enabled:`-Zeile. Beim dritten Mal, mit belegtem Zustand, bricht das
     * Update ab.
     *
     * Die Herleitung steht: apt holt eine abgeschaltete Quelle
     * gar nicht erst, also erzeugt sie keinen Fehlschlag, {@see Apt::hitting()}
     * findet nichts — und die Simulation danach sieht mangels neuer Listen
     * nichts Anstehendes. Das Panel meldete dann „Es stand nichts an", während
     * seine eigene Quelle aus ist. **Beobachtet ist dieser Ablauf nicht.**
     *
     * > **Eine Quelle, die nicht gefragt wird, antwortet nicht falsch — sie
     * > fehlt, und das sieht aus wie Zustimmung.**
     *
     * ## Warum hier ein deb822-Leser steht und bei {@see self::uris()} nicht
     *
     * Dort ist die Frage „welche Adressen nennt diese Datei", und dafür genügt
     * der Anker am Zeilenanfang. `Enabled:` ist dagegen eine Eigenschaft
     * **einer Stanza** und ohne deren Grenzen nicht zu beantworten. Gelesen
     * wird deshalb über {@see self::stanzas()} — den gibt es seit P7b, und er
     * ist geprüft.
     *
     * **Leer heisst „keine Quelle in Kraft".** Ob die Datei fehlt, alle Stanzas
     * aus sind oder keine eine Adresse nennt, entscheidet der Aufrufer: Er
     * allein weiss, welche Meldung sein Leser braucht.
     *
     * @return list<string>
     */
    public static function enabledUris(string $datei): array
    {
        if (! is_file($datei)) {
            return [];
        }

        $uris = [];

        foreach (self::stanzas((string) file_get_contents($datei)) as $stanza) {
            if (! $stanza['enabled']) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($stanza['fields']['URIs'] ?? '')) ?: [] as $uri) {
                if ($uri !== '' && ! in_array($uri, $uris, true)) {
                    $uris[] = $uri;
                }
            }
        }

        return $uris;
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
     * ## Warum `realpath()` dafür nicht genügt
     *
     * **Weil es `false` gibt, wenn es die Datei nicht gibt** — und genau dann
     * ist die Frage am wichtigsten: Die eigene Quelldatei entsteht erst beim
     * Anlegen. Bis zum 26. August war die Zusage oben deshalb nur auf einem
     * Server wahr, auf dem die Datei schon lag.
     *
     * Gefunden hat das die CI, und **hier war der Fall grün** — weil eine
     * Messrunde Stunden vorher ein `srvpanel.sources` im Container liegen
     * gelassen hatte.
     *
     * > **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher liegt,
     * > misst die Umgebung mit.**
     *
     * {@see self::lexical()} führt die Schreibweisen deshalb ohne das
     * Dateisystem zusammen; `realpath()` bleibt daneben stehen und löst
     * zusätzlich Verweise auf, wenn es die Datei gibt.
     *
     * Die Grenze ist die Liste selbst, und die steht in {@see self::owned()}.
     */
    public static function isOwned(string $pfad): bool
    {
        $echt = realpath($pfad);
        $glatt = self::lexical($pfad);

        foreach (self::owned() as $eigen) {
            // **Drei Wege, und der mittlere ist der, der ohne Datei auskommt.**
            // Die Zeichenkette für den Regelfall, die lexikalische Glättung für
            // eine andere Schreibweise derselben Datei, `realpath()` für einen
            // Verweis — das letzte nur, wenn es die Datei schon gibt.
            if ($pfad === $eigen
                || $glatt === self::lexical($eigen)
                || ($echt !== false && $echt === realpath($eigen))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * `.` und `..` auflösen, ohne das Dateisystem zu fragen.
     *
     * **Ohne Dateisystem, und das ist der Zweck.** Eine Datei, die es noch
     * nicht gibt, hat keinen `realpath()` — sie ist trotzdem eine, die das
     * Panel anlegen dürfte.
     *
     * Ein `..` hinter einem symbolischen Verweis bedeutet lexikalisch etwas
     * anderes als im Dateisystem. Das ist hier hinnehmbar: Diese Funktion
     * entscheidet nichts allein — sie **erweitert** die Annahme neben dem
     * Zeichenkettenvergleich, und der Fehler fiele zur nachsichtigen Seite,
     * die `realpath()` daneben ohnehin schon hat.
     */
    private static function lexical(string $pfad): string
    {
        $teile = [];

        foreach (explode('/', $pfad) as $stueck) {
            if ($stueck === '' || $stueck === '.') {
                continue;
            }

            if ($stueck === '..') {
                array_pop($teile);

                continue;
            }

            $teile[] = $stueck;
        }

        return (str_starts_with($pfad, '/') ? '/' : '').implode('/', $teile);
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
     * Die Dateien, die apt liest — in derselben Reihenfolge.
     *
     * **`sources.list` gehört dazu, auch wenn es hier nur Kommentar ist.**
     * Gemessen auf diesem Abbild: vier Zeilen, alle Kommentar, null Einträge.
     * Auf einem älteren System steht dort der ganze Bestand, und wer die Datei
     * weglässt, zeigt dem Betreiber eine leere Liste für einen Server, der
     * Quellen hat.
     *
     * > **Eine Datei, die auf dem eigenen System leer ist, ist damit nicht
     * > überall leer.**
     *
     * **Die beiden Pfade sind Argumente und haben keine Vorgabe.** Sonst läse
     * ein Wächter, der sie vergisst, das echte `/etc/apt` der messenden
     * Maschine — und genau daran ist `SourceOwnershipTest` am 26. August in der
     * CI rot und hier grün gewesen.
     *
     * > **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher liegt,
     * > misst die Umgebung mit.**
     *
     * **Ein `glob` je Endung und kein `GLOB_BRACE`.** So ist
     * {@see self::EXTENSIONS} die einzige Stelle, an der die Endungen stehen —
     * und die Fahne fällt weg, die PHP nicht auf jeder Bauart hat: Wo sie
     * fehlt, gibt `glob()` `false` zurück, und daraus würde hier lautlos „gar
     * keine Quelle".
     *
     * @param  string  $main  Sonst {@see self::MAIN}
     * @param  string  $parts  Sonst {@see self::PARTS}
     * @return list<string>
     */
    public static function files(string $main, string $parts): array
    {
        $teile = [];

        foreach (self::EXTENSIONS as $endung) {
            $teile = [...$teile, ...(glob($parts.'/*.'.$endung) ?: [])];
        }

        // apt liest zuerst die Hauptdatei und dann das Verzeichnis in
        // Namensfolge. Die Pfade teilen sich ihr Verzeichnis, ein Vergleich
        // über den ganzen Pfad ist hier also einer über den Dateinamen.
        sort($teile);

        return array_values(array_filter(
            [$main, ...$teile],
            static fn (string $pfad): bool => is_file($pfad),
        ));
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
