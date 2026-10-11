<?php

declare(strict_types=1);

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\WithoutPhpComments;

/**
 * Was die Anwendung zur Laufzeit aus ihrem eigenen Baum liest, liegt im Paket.
 *
 * ## Der Befund
 *
 * `docs/144 §0` Befund 4: `SpecController` liest `docs/openapi-v1.yaml` über
 * `base_path()`, und `packaging/build.sh` nahm `docs` nicht mit. Im Paket lag
 * die Beschreibung deshalb seit `0.9.0-rc.1` nicht, und ohne sie gibt
 * `/api/v1/openapi.yaml` 404, sogar mit leerem Text — gemessen im Container.
 * `OpenApiReachTest` hielt die Beschreibung in beide Richtungen gegen die
 * Routen — im Repo, wo sie lag.
 *
 * > **Eine Datei, die ein Wächter im Repo prüft, ist damit noch nicht auf dem
 * > Server.**
 *
 * Der Satz steht seit dem 15. August 2026 im Kopf von `build.sh`, damals über
 * `lang`, und behoben wurde jener Fall mit einem Eintrag in der Liste und
 * `ValidationLanguageTest::test_the_translations_are_shipped` — einem Wächter
 * über genau dieses eine Verzeichnis.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * ## Was hier gemessen wird
 *
 * Jeder Aufruf von `base_path()`, `resource_path()`, `lang_path()`,
 * `database_path()`, `public_path()`, `config_path()` und `app_path()` unter
 * `app/` mit einem Pfad: als Zeichenkette oder als Konstante derselben Klasse
 * (`self::PATH`). Der Pfad zeigt auf etwas, das es im Repo gibt, und er liegt
 * unter einem Eintrag der Positivliste in `build.sh`. Was sich nicht auflösen
 * lässt, ist ein Befund und kein Übergehen.
 *
 * `storage_path()` fehlt mit Absicht: `storage` gehört nicht in die Fassung,
 * das Paket legt einen Verweis nach `/var/lib/srvpanel/storage` (der Kopf von
 * `build.sh` und `packaging/nfpm.yaml` sagen warum).
 *
 * ## Was er nicht kann
 *
 * Ein Pfad aus `__DIR__` oder aus einer Variablen ist für ihn unsichtbar, und
 * der Agent liest seine Dateien nicht über Laravel. Gefragt ist, was `app/`
 * liest; heute ist das genau eine Datei.
 */
final class ShippedFileTest extends TestCase
{
    use WithoutPhpComments;

    /** Ein Helfer von Laravel => das Verzeichnis, auf das er zeigt. */
    private const HELPERS = [
        'base_path' => '',
        'resource_path' => 'resources/',
        'lang_path' => 'lang/',
        'database_path' => 'database/',
        'public_path' => 'public/',
        'config_path' => 'config/',
        'app_path' => 'app/',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Die Positivliste aus `build.sh`: die Wörter zwischen `for part in \` und
     * `do`.
     *
     * @return list<string>
     */
    private function parts(): array
    {
        $bau = (string) file_get_contents($this->root().'/packaging/build.sh');

        if (preg_match('/^for part in \\\\\n(.*?)\ndo$/ms', $bau, $liste) !== 1) {
            return [];
        }

        return preg_split('/\s+/', trim($liste[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Was `app/` über die Helfer liest: Pfad => Fundstellen, und was sich nicht
     * auflösen lässt.
     *
     * @return array{read: array<string, list<string>>, unresolved: list<string>}
     */
    private function reads(): array
    {
        $gelesen = [];
        $offen = [];

        $lauf = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app', FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $datei */
        foreach ($lauf as $datei) {
            if ($datei->getExtension() !== 'php') {
                continue;
            }

            $quelle = $this->withoutComments((string) file_get_contents($datei->getPathname()));
            $stelle = substr($datei->getPathname(), strlen($this->root()) + 1);
            $helfer = implode('|', array_keys(self::HELPERS));

            preg_match_all('/\b('.$helfer.')\(\s*([^()]*?)\s*\)/', $quelle, $aufrufe, PREG_SET_ORDER);

            foreach ($aufrufe as [, $name, $argument]) {
                if ($argument === '') {
                    continue;
                }

                $pfad = $this->resolve($quelle, $argument);

                if ($pfad === null) {
                    $offen[] = sprintf('%s: %s(%s)', $stelle, $name, $argument);

                    continue;
                }

                $gelesen[self::HELPERS[$name].ltrim($pfad, '/')][] = $stelle;
            }
        }

        return ['read' => $gelesen, 'unresolved' => $offen];
    }

    /** Eine Zeichenkette oder eine Konstante derselben Klasse — sonst `null`. */
    private function resolve(string $quelle, string $argument): ?string
    {
        if (preg_match("/^'([^']*)'$/", $argument, $wort) === 1) {
            return $wort[1];
        }

        if (preg_match('/^(?:self|static)::([A-Z_][A-Z0-9_]*)$/', $argument, $konstante) === 1
            && preg_match('/\bconst\s+'.$konstante[1]."\s*=\s*'([^']*)'\s*;/", $quelle, $wert) === 1) {
            return $wert[1];
        }

        return null;
    }

    public function test_the_readers_find_the_list_and_the_one_known_read(): void
    {
        $this->assertContains('lang', $this->parts(),
            'Die Positivliste aus build.sh wird nicht gefunden oder ist leer — dann ist jeder Pfad hier '.
            '„nicht im Paket", und der Fall darunter meldet alles.');

        /*
         * **Der Fall, für den es diesen Wächter gibt.** Findet der Leser ihn
         * nicht, geht sein Ausdruck an `base_path(self::PATH)` vorbei — und ein
         * leeres Ergebnis darunter wäre ein Freispruch.
         */
        $this->assertArrayHasKey('docs/openapi-v1.yaml', $this->reads()['read'],
            'SpecController liest docs/openapi-v1.yaml über base_path(self::PATH), und der Leser dieses '.
            'Wächters findet es nicht. Dann misst er an genau der Stelle nicht, an der der Befund war.');
    }

    public function test_every_file_the_app_reads_is_shipped(): void
    {
        $teile = $this->parts();
        $gelesen = $this->reads();
        $fehlt = [];

        foreach ($gelesen['read'] as $pfad => $stellen) {
            if (! file_exists($this->root().'/'.$pfad)) {
                $fehlt[] = sprintf('%s — gibt es im Repo nicht (gelesen von %s)', $pfad, implode(', ', array_unique($stellen)));

                continue;
            }

            $gedeckt = array_filter($teile, static fn (string $teil): bool => $pfad === $teil || str_starts_with($pfad, $teil.'/'));

            if ($gedeckt === []) {
                $fehlt[] = sprintf('%s — liegt unter keinem Eintrag der Positivliste (gelesen von %s)', $pfad, implode(', ', array_unique($stellen)));
            }
        }

        foreach ($gelesen['unresolved'] as $stelle) {
            $fehlt[] = $stelle.' — der Pfad lässt sich nicht auflösen; eine Zeichenkette oder eine Konstante derselben Klasse kann dieser Wächter lesen';
        }

        $this->assertSame([], $fehlt, sprintf(
            "Die Anwendung liest diese Dateien aus ihrem eigenen Baum, und das Paket bringt sie nicht mit:\n  %s\n\n".
            'packaging/build.sh baut eine Positivliste und überspringt jeden Eintrag, den es nicht findet. '.
            'Im Repo ist dann alles grün, und auf dem Server gibt es die Datei nicht — so fehlte '.
            'die Beschreibung von /api/v1/openapi.yaml dort seit 0.9.0-rc.1 (docs/144 §0 Befund 4).',
            implode("\n  ", $fehlt),
        ));
    }
}
