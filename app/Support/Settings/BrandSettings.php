<?php

declare(strict_types=1);

namespace App\Support\Settings;

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
 * {@see self::verdict()} rechnet das aus; die Schwellen stehen in
 * {@see Contrast} und nicht hier.
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

    /** @return array<string, mixed> */
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
     * Trägt eine Farbe auf **allen** Flächen ihres Themes?
     *
     * Gemessen wird gegen die Schwelle für **Text**, weil der Akzent in
     * `app.css` als Schriftfarbe vorkommt — sechs der sechzehn Verwendungen.
     * Wer nur die Knopffläche im Blick hat, misst gegen 3:1 und übersieht sie.
     *
     * Zurück kommt das **schlechteste** Verhältnis samt der Fläche, an der es
     * entsteht: Eine Zahl ohne ihren Gegenstand sagt dem Betreiber nicht, wo
     * er nachsehen soll.
     *
     * @param  list<string>  $surfaces
     * @return array{ratio: float, passes: bool, surface: string}
     */
    public static function verdict(string $colour, array $surfaces): array
    {
        $schlechteste = null;
        $wert = INF;

        foreach ($surfaces as $flaeche) {
            $verhaeltnis = Contrast::between($colour, $flaeche);

            if ($verhaeltnis < $wert) {
                $wert = $verhaeltnis;
                $schlechteste = $flaeche;
            }
        }

        return [
            'ratio' => round((float) $wert, 2),
            'passes' => $wert >= Contrast::TEXT,
            'surface' => (string) $schlechteste,
        ];
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
