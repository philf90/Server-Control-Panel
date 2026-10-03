<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Support\Brand\Style;
use App\Support\Design\Contrast;

/**
 * Was der Betreiber am Aussehen des Panels ändern darf (B6, `docs/129 §9`).
 *
 * ## Vier Angaben, und die fünfte steht woanders
 *
 * Das Abnahmekriterium nennt **Logo, Farbe, Fusszeile und Absenderadresse**.
 * Die Absenderadresse gibt es seit P2 als {@see MailSettings::$from_address};
 * sie hier ein zweites Mal aufzunehmen hiesse, zwei Formulare für einen Wert
 * zu pflegen — und das zweite ist das, das veraltet. Die Markenseite verweist
 * darauf und schreibt sie nicht ab.
 *
 * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von
 * > beiden ist der Ort, an dem man nachsieht.**
 *
 * **Der Name kommt dazu, und das ist eine Ergänzung und kein Fund.** Ein Logo
 * ersetzt das Zeichen des Panels; stünde daneben weiter „SrvPanel", trüge die
 * Anmeldeseite zwei Marken. Das Kriterium nennt ihn nicht, und deshalb steht
 * hier, dass er dazugehört.
 *
 * ## Zwei Farben und nicht eine
 *
 * `CLAUDE.md` sagt es für jede Gestaltung dieses Panels: **Beide Themes
 * entstehen zusammen, nie eines nachträglich.** `app.css` hält sich daran —
 * der Akzent ist hell `#3730a3` und dunkel `#ff7fec`, zwei ganz verschiedene
 * Farben. Eine einzige Markenfarbe müsste auf beiden Gründen lesbar sein; das
 * ginge nur für ein schmales Band mittlerer Töne, und die Alternative wäre,
 * die zweite aus der ersten zu **erfinden**.
 *
 * > **Eine Farbe, die man aus einer anderen ableitet, ist eine Vermutung über
 * > einen Grund, den man nicht gemessen hat.**
 *
 * ## Und jede Farbe wird gerechnet
 *
 * `--accent` trägt in `app.css` sechzehn Verwendungen, davon sechs als
 * **Schriftfarbe**. Eine Markenfarbe, die dort nicht 4,5:1 erreicht, macht
 * Teile des Panels unlesbar — und zwar erst, nachdem sie gespeichert ist.
 * {@see self::verdictLight()} und {@see self::verdictDark()} rechnen das aus,
 * seit dem 3. Oktober 2026 auch auf den getönten Flächen
 * ({@see self::TINTS_LIGHT}); die Schwellen stehen in {@see Contrast} und
 * nicht hier.
 *
 * ## Ohne Eintrag gilt die Vorgabe
 *
 * Entschieden vom Betreiber am 3. Oktober 2026 im Lauf für B6
 * (`docs/140 §6d`): Wer Name oder Farbe leert, bekommt die Vorgabe und muss
 * sie nicht abschreiben. Bis dahin trugen die drei Felder `required`, im
 * Browser und an der Tür; in Punkt 9 des Laufs wurden die Vorgaben deshalb
 * von Hand eingetippt.
 *
 * **Abgelegt wird dafür keine Abschrift der Vorgabe, sondern keine Angabe**
 * ({@see self::toStored()}). Eine Abschrift wäre eine zweite Fassung der
 * Vorgabe: Ändert eine spätere Fassung des Panels sie, bliebe dieses Panel bei
 * der alten stehen, als hätte der Betreiber sie gewählt.
 *
 * > **Ein Wert, der der Vorgabe gleicht, ist keine eigene Angabe — wer ihn als
 * > Wert ablegt, hält eine zweite Fassung der Vorgabe, und die veraltet.**
 */
final class BrandSettings
{
    /** Wie das Panel heisst, solange der Betreiber nichts anderes sagt. */
    public const DEFAULT_NAME = 'SrvPanel';

    /**
     * Die Vorgabewerte des Akzents — dieselben zwei, die `app.css` führt.
     *
     * Sie stehen hier als Zahl und nicht als Verweis auf das Stylesheet: Diese
     * Klasse läuft im Server und liest kein CSS. Dass die beiden Paare
     * übereinstimmen, hält `BrandContrastTest`.
     */
    public const DEFAULT_ACCENT_LIGHT = '#3730a3';

    public const DEFAULT_ACCENT_DARK = '#ff7fec';

    /**
     * Die Gründe, gegen die gerechnet wird — **alle** Flächen aus `app.css`.
     *
     * ## Die Anmeldeseite ist eine dritte Fläche, und sie ist die gemeinte
     *
     * `.signin` trägt seit „Kontor" einen **eigenen, vollständigen**
     * Markensatz: `--bg: #140823`, `--surface: #1a0b2e`, dazu eigene Text- und
     * Feldfarben, jede gegen diese Fläche gerechnet. Er gilt in beiden Themes,
     * weil er kein Theme ist, sondern eine Markenfläche.
     *
     * Wer die Markenfarbe nur gegen `:root` rechnete, hätte die eine Seite
     * ungemessen gelassen, die das Abnahmekriterium beim Namen nennt.
     *
     * > **Eine Messung, die die Flächen des Stylesheets aus dem Gedächtnis
     * > nimmt, misst die Flächen, an die man sich erinnert.**
     *
     * ## Und gemessen wird gegen die **ungünstigste**
     *
     * Ein dunkler Akzent hat auf `#fafafb` weniger Kontrast als auf `#ffffff`,
     * ein heller auf `#1a0b2e` weniger als auf `#0f1116`. Gegen die
     * freundlichste zu rechnen hiesse, eine Farbe zuzulassen, die auf der
     * Hälfte der Flächen durchfällt.
     *
     * Dass diese Listen mit `app.css` übereinstimmen, hält `BrandContrastTest`
     * — er liest die Flächen aus dem Stylesheet und nicht aus dieser Datei.
     *
     * @var list<string>
     */
    public const SURFACES_LIGHT = ['#ffffff', '#fafafb'];

    /** @var list<string> */
    public const SURFACES_DARK = ['#0f1116', '#14171d', '#1a0b2e'];

    /**
     * Die getönten Flächen, auf denen der Akzent ebenfalls Schrift trägt —
     * Farbe und Deckung je Zustand, wie `app.css` sie führt.
     *
     * ## Warum es sie gibt
     *
     * Bis zum 3. Oktober 2026 rechnete die Prüfung nur gegen die Flächen
     * darüber. Als Schrift steht der Akzent aber auch auf **getönten**
     * Flächen: auf seiner eigenen Tönung im aktiven Menüpunkt der Leiste, auf
     * aktiven Knöpfen und Zählmarken, als Verweis im Band eines Vorgangs — und
     * auf der Anmeldeseite, seit dort `--text-strong` die Markenfarbe trägt, in
     * der Überschrift einer Fehlermeldung. Gemessen mit dem dunkelsten Akzent,
     * den die alte Prüfung annahm (`#02925b`): der aktive Menüpunkt 4,14:1,
     * die Überschrift der Fehlermeldung 3,96:1 (`docs/140 §6c`).
     *
     * > **Eine Farbe, die auf jeder Fläche lesbar ist, ist es auf der Tönung
     * > darüber noch lange nicht — und die Tönung ist die Stelle, an der etwas
     * > hervorgehoben werden soll.**
     *
     * **Gerechnet wird jede Tönung über jedem Grund ihrer Fläche, nicht nur die
     * heutigen Orte.** Entschieden vom Betreiber am 3. Oktober 2026: Ein
     * Verweis, der morgen in eine Erfolgsmeldung kommt, soll nicht erst einen
     * Wächter brauchen, der ihn findet. Das kostet: Von den Tönen, die die alte
     * Prüfung annahm, weist diese hell 19,9 % und dunkel 22,9 % ab, alle knapp
     * über der alten Schwelle. Die ausgelieferten Farben bestehen.
     *
     * Dass diese Werte mit `app.css` übereinstimmen, hält `BrandContrastTest`,
     * in beide Richtungen: Eine Tönung, die das Stylesheet dazubekommt, und
     * eine, die es nicht mehr führt, sind beide ein Befund.
     *
     * @var array<string, array{string, float}>
     */
    public const TINTS_LIGHT = [
        'ok' => ['#076e54', 0.1],
        'warn' => ['#845306', 0.11],
        'critical' => ['#ab2b19', 0.09],
        'info' => ['#ff4696', 0.09],
    ];

    /** @var array<string, array{string, float}> */
    public const TINTS_DARK = [
        'ok' => ['#57c99c', 0.14],
        'warn' => ['#e2a94a', 0.14],
        'critical' => ['#f08a72', 0.14],
        'info' => ['#ff4696', 0.14],
    ];

    /**
     * Die Anmeldeseite setzt zwei Zustände selbst, für ihren dunklen Grund
     * (`docs/139 §9`).
     *
     * @var array<string, array{string, float}>
     */
    public const TINTS_SIGNIN = [
        'warn' => ['#e2a94a', 0.14],
        'critical' => ['#f08a72', 0.14],
    ];

    /**
     * Die beiden Gründe der Anmeldeseite: um die Maske (`--bg`) und in ihr
     * (`--surface`). Der zweite steht auch in {@see self::SURFACES_DARK}. Der
     * erste ist dunkler als jede Fläche dort und entscheidet für den Akzent
     * selbst nie — für die Fehlermeldung, die auf ihm liegt, schon.
     *
     * @var list<string>
     */
    public const SIGNIN_GROUNDS = ['#140823', '#1a0b2e'];

    /** Wie die Meldung den Ort einer Tönung nennt. */
    private const STATES = [
        'ok' => 'einer Erfolgsmeldung',
        'warn' => 'einer Warnung',
        'critical' => 'einer Störung',
        'info' => 'eines Hinweises',
    ];

    private const SIGNIN_STATES = [
        'warn' => 'einer Warnung auf der Anmeldeseite',
        'critical' => 'einer Fehlermeldung auf der Anmeldeseite',
    ];

    public function __construct(
        public readonly string $name = self::DEFAULT_NAME,
        public readonly string $accent_light = self::DEFAULT_ACCENT_LIGHT,
        public readonly string $accent_dark = self::DEFAULT_ACCENT_DARK,
        public readonly string $footer = '',
        public readonly ?string $logo = null,
    ) {}

    /** @param  array<string, mixed>  $stored */
    public static function fromArray(array $stored): self
    {
        $logo = $stored['logo'] ?? null;

        return new self(
            name: self::text($stored['name'] ?? null, self::DEFAULT_NAME),
            // Eine unlesbare Farbe fällt auf die Vorgabe zurück und nicht auf
            // Schwarz: Was in der Ablage steht, ist geprüft worden; steht dort
            // Unsinn, ist die Ablage beschädigt, und dann ist die eingebaute
            // Farbe die einzige, von der man weiss, dass sie trägt.
            accent_light: self::colour($stored['accent_light'] ?? null, self::DEFAULT_ACCENT_LIGHT),
            accent_dark: self::colour($stored['accent_dark'] ?? null, self::DEFAULT_ACCENT_DARK),
            footer: trim((string) ($stored['footer'] ?? '')),
            logo: is_string($logo) && $logo !== '' ? $logo : null,
        );
    }

    /**
     * Was das Formular schickt — ein leeres Feld heisst „die Vorgabe".
     *
     * Gekürzt und kleingeschrieben wird hier und nicht im Controller: Ob ein
     * Wert der Vorgabe gleicht, fragt {@see self::own()}, und die Frage hat
     * nur dann eine Antwort, wenn `#3730A3` und `#3730a3` derselbe Wert sind.
     * Die Form der Farbe hat die Tür schon geprüft.
     *
     * @param  array<string, mixed>  $form
     */
    public static function fromForm(array $form, ?string $logo): self
    {
        return new self(
            name: self::text($form['name'] ?? null, self::DEFAULT_NAME),
            accent_light: strtolower(self::text($form['accent_light'] ?? null, self::DEFAULT_ACCENT_LIGHT)),
            accent_dark: strtolower(self::text($form['accent_dark'] ?? null, self::DEFAULT_ACCENT_DARK)),
            footer: trim((string) ($form['footer'] ?? '')),
            logo: $logo,
        );
    }

    /**
     * Was der Betreiber selbst eingetragen hat — `null` heisst „die Vorgabe".
     *
     * **Ein Wert, der der Vorgabe gleicht, ist keine eigene Angabe.** Wer
     * `#3730a3` eintippt, hat dasselbe gesagt wie ein leeres Feld, und die
     * Seite zeigt beides gleich: leer, mit der Vorgabe als Platzhalter. Die
     * Frage steht hier und nicht in jedem Leser, damit Ablage und Formular
     * dieselbe Antwort geben.
     *
     * @return array{name: ?string, accent_light: ?string, accent_dark: ?string}
     */
    public function own(): array
    {
        return [
            'name' => $this->name === self::DEFAULT_NAME ? null : $this->name,
            'accent_light' => $this->accent_light === self::DEFAULT_ACCENT_LIGHT ? null : $this->accent_light,
            'accent_dark' => $this->accent_dark === self::DEFAULT_ACCENT_DARK ? null : $this->accent_dark,
        ];
    }

    /**
     * Was in die Ablage geht: die eigenen Angaben, und für die Vorgabe `null`.
     *
     * **Nicht {@see self::toArray()}.** Das gibt aus, was gilt, mit der
     * Vorgabe aufgefüllt — und eine abgelegte Vorgabe wäre die zweite Fassung,
     * vor der der Kopf dieser Klasse warnt. {@see self::fromArray()} füllt
     * beim Lesen wieder auf, und zwar mit der Vorgabe der laufenden Fassung.
     *
     * Was vor `0.9.0-rc.12` gespeichert wurde, trägt die Vorgabe als Wert. Es
     * wird beim nächsten Speichern zu keiner Angabe. Ändert eine spätere
     * Fassung die Vorgabe, nimmt sie solche Zeilen mit einer Migration mit,
     * die die alte Vorgabe kennt — vorher lassen sie sich von einer eigenen
     * Angabe nicht unterscheiden.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return [...$this->own(), 'footer' => $this->footer, 'logo' => $this->logo];
    }

    /**
     * Die Vorgaben, wie das Formular sie als Platzhalter zeigt.
     *
     * @return array{name: string, accent_light: string, accent_dark: string}
     */
    public static function defaults(): array
    {
        return [
            'name' => self::DEFAULT_NAME,
            'accent_light' => self::DEFAULT_ACCENT_LIGHT,
            'accent_dark' => self::DEFAULT_ACCENT_DARK,
        ];
    }

    /**
     * Was gilt — jede Angabe, die fehlt, mit der Vorgabe aufgefüllt.
     *
     * Das druckt Block 1 des Laufs für B6 (`docs/140`); was abgelegt wird,
     * sagt {@see self::toStored()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'accent_light' => $this->accent_light,
            'accent_dark' => $this->accent_dark,
            'footer' => $this->footer,
            'logo' => $this->logo,
        ];
    }

    /**
     * Trägt eine Farbe auf **allen** Gründen, die man ihr gibt?
     *
     * Gemessen wird gegen die Schwelle für **Text**, weil der Akzent in
     * `app.css` als Schriftfarbe vorkommt — sechs der sechzehn Verwendungen.
     * Wer nur die Knopffläche im Blick hat, misst gegen 3:1 und übersieht sie.
     *
     * Zurück kommt das **schlechteste** Verhältnis samt dem Grund, an dem es
     * entsteht, und seinem Ort, wenn der Hexwert allein ihn nicht verrät: Eine
     * Zahl ohne ihren Gegenstand sagt dem Betreiber nicht, wo er nachsehen
     * soll. Eine Fläche aus `app.css` trägt keinen Ort, eine Tönung schon.
     *
     * Was das Panel beim Speichern fragt, steht in {@see self::verdictLight()}
     * und {@see self::verdictDark()}; diese Methode ist die Rechnung darunter.
     *
     * @param  list<string>|array<string, string|null>  $grounds  Hexwerte, oder Hexwert => Ort
     * @return array{ratio: float, passes: bool, surface: string, place: string|null}
     */
    public static function verdict(string $colour, array $grounds): array
    {
        $schlechteste = null;
        $ort = null;
        $wert = INF;

        foreach (array_is_list($grounds) ? array_fill_keys($grounds, null) : $grounds as $grund => $wo) {
            $verhaeltnis = Contrast::between($colour, (string) $grund);

            if ($verhaeltnis < $wert) {
                $wert = $verhaeltnis;
                $schlechteste = (string) $grund;
                $ort = $wo;
            }
        }

        return [
            'ratio' => round((float) $wert, 2),
            'passes' => $wert >= Contrast::TEXT,
            'surface' => (string) $schlechteste,
            'place' => $ort,
        ];
    }

    /**
     * Die Prüfung, die das Panel für den hellen Akzent fragt.
     *
     * @return array{ratio: float, passes: bool, surface: string, place: string|null}
     */
    public static function verdictLight(string $accent): array
    {
        return self::verdict($accent, self::groundsLight($accent));
    }

    /**
     * Die Prüfung, die das Panel für den dunklen Akzent fragt — er gilt auch
     * auf der Leiste und auf der Anmeldeseite.
     *
     * @return array{ratio: float, passes: bool, surface: string, place: string|null}
     */
    public static function verdictDark(string $accent): array
    {
        return self::verdict($accent, self::groundsDark($accent));
    }

    /**
     * Jeder Grund, auf dem der helle Akzent Schrift tragen kann: die beiden
     * Flächen, seine eigene Tönung darüber und die Tönung jedes Zustands.
     *
     * @return array<string, string|null>
     */
    public static function groundsLight(string $accent): array
    {
        $gruende = array_fill_keys(self::SURFACES_LIGHT, null);

        foreach (self::SURFACES_LIGHT as $grund) {
            $gruende += [Contrast::over($accent, Style::SURFACE_ALPHA_LIGHT, $grund) => 'ihrer eigenen Tönung, wie in einem aktiven Knopf'];
            $gruende += self::tinted(self::TINTS_LIGHT, $grund, self::STATES);
        }

        return $gruende;
    }

    /**
     * Jeder Grund, auf dem der dunkle Akzent Schrift tragen kann: die Flächen
     * des dunklen Themas, der Leiste und der Anmeldeseite, seine eigene Tönung
     * über jeder davon, die Tönung jedes Zustands im dunklen Thema und die
     * beiden Zustände, die die Anmeldeseite selbst setzt.
     *
     * Die Zustände des Themas liegen auf seinen beiden Gründen und nicht auf
     * der Leiste: Dort steht keine Meldung.
     *
     * @return array<string, string|null>
     */
    public static function groundsDark(string $accent): array
    {
        $gruende = array_fill_keys(self::SURFACES_DARK, null);

        foreach (array_unique([...self::SURFACES_DARK, ...self::SIGNIN_GROUNDS]) as $grund) {
            $gruende += [Contrast::over($accent, Style::SURFACE_ALPHA_DARK, $grund) => $grund === self::SURFACES_DARK[2]
                ? 'ihrer eigenen Tönung, wie im aktiven Menüpunkt der Leiste'
                : 'ihrer eigenen Tönung, wie in einem aktiven Knopf'];
        }

        foreach ([self::SURFACES_DARK[0], self::SURFACES_DARK[1]] as $grund) {
            $gruende += self::tinted(self::TINTS_DARK, $grund, self::STATES);
        }

        foreach (self::SIGNIN_GROUNDS as $grund) {
            $gruende += self::tinted(self::TINTS_SIGNIN, $grund, self::SIGNIN_STATES);
        }

        return $gruende;
    }

    /**
     * Die Schriftfarbe auf der Akzentfläche — gerechnet, nicht geraten.
     *
     * Der Hauptknopf steht auf `--accent`; was darauf steht, muss lesbar sein.
     * „Heller Grund, dunkle Schrift" stimmt meistens und bei den Tönen
     * dazwischen nicht — und genau die wählt jemand, der eine Markenfarbe
     * eingibt.
     */
    public function accentOn(string $accent): string
    {
        return Contrast::readableOn($accent, '#ffffff', '#0f1116');
    }

    /** Steht etwas anderes da als die Auslieferung? */
    public function customised(): bool
    {
        return $this->name !== self::DEFAULT_NAME
            || $this->accent_light !== self::DEFAULT_ACCENT_LIGHT
            || $this->accent_dark !== self::DEFAULT_ACCENT_DARK
            || $this->footer !== ''
            || $this->logo !== null;
    }

    /**
     * Die Tönung jedes Zustands über einem Grund, mit ihrem Ort.
     *
     * @param  array<string, array{string, float}>  $toene
     * @param  array<string, string>  $namen
     * @return array<string, string>
     */
    private static function tinted(array $toene, string $grund, array $namen): array
    {
        $aus = [];

        foreach ($toene as $zustand => [$farbe, $deckung]) {
            $aus += [Contrast::over($farbe, $deckung, $grund) => 'der Tönung '.($namen[$zustand] ?? $zustand)];
        }

        return $aus;
    }

    private static function text(mixed $value, string $fallback): string
    {
        $wert = trim((string) (is_string($value) ? $value : ''));

        return $wert === '' ? $fallback : $wert;
    }

    private static function colour(mixed $value, string $fallback): string
    {
        return is_string($value) && Contrast::isColour($value) ? strtolower($value) : $fallback;
    }
}
