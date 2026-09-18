<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\Support\WithoutMarkupComments;
use Tests\TestCase;

/**
 * Jede Seite, die zu **einem** Abonnement gehört, ist von seiner Seite aus
 * erreichbar.
 *
 * ## Warum es diesen Wächter gibt
 *
 * Fünfmal hat der Betreiber denselben Fehler gemeldet, und jedes Mal beim
 * Benutzen: der Dateimanager (`docs/55` Befund 8), der SFTP-Zugang (`docs/59`
 * Befund 19), „Job anlegen" auf der Cronseite (`docs/64` Befund 13), das
 * Abzeichen an den Updates (`docs/907`) — und am 18. September 2026 die
 * Sicherungen (`docs/121 §9`, Befund 2). Er suchte „Jetzt sichern" auf der
 * Abonnementseite, wo `Dateien` und `SFTP-Zugang` stehen, und unter *Freigaben*
 * stand „Sicherungen anlegen — frei": die Zusage, dass es die Handlung gibt,
 * ohne einen Weg zu ihr.
 *
 * > **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten
 * > Merkmal wieder da, wenn die Behebung nicht die Regel wurde.**
 *
 * CLAUDE.md führt seitdem die Frage, die kein Test halten kann — *wo sucht
 * jemand diese Handlung* —, und das stimmt für die Frage im Allgemeinen. **Ein
 * Stück davon ist aber strukturell**, und genau das hält dieser Wächter: Eine
 * Route unter `/subscriptions/{id}/…` sagt selbst, dass sie zu *einem*
 * Abonnement gehört. Von dessen Seite muss ein Weg dorthin führen.
 *
 * > **Zwei Geschwister mit demselben Zuschnitt, von denen eines auf der Seite
 * > steht und das andere nicht, sind keine Entwurfsentscheidung — es ist eine
 * > vergessene Zeile.**
 *
 * ## Warum nur die oberste Ebene
 *
 * Gefragt wird nach **einem** Segment ohne weiteren Platzhalter — `…/files`,
 * nicht `…/files/edit`, und nicht `…/backups/{backup}/download`. Eine
 * Unterseite erreicht man über ihre Hauptseite, und ein Download ist kein Ort.
 * Die Grenze ist damit die Adresse und nicht mein Geschmack.
 *
 * ## Gemessen am Router und nicht an einer Liste
 *
 * Die Segmente kommen aus `Route::getRoutes()`. Eine Liste im Test wäre die
 * zweite Fassung der Routendatei, und die zweite veraltet — dann meldete dieser
 * Wächter für ein neues Merkmal „alles in Ordnung".
 *
 * ## Was er nicht hält
 *
 * Ob der Weg **auffällt**. Ein Knopf am Ende einer langen Seite erfüllt diese
 * Regel und wird trotzdem nicht gefunden; das ist die Frage aus CLAUDE.md, und
 * sie hängt daran, was ein Betrachter erwartet. Gehalten ist nur, dass es ihn
 * gibt.
 */
final class SubscriptionReachTest extends TestCase
{
    use WithoutMarkupComments;

    /**
     * Segmente, die **nicht** auf die Abonnementseite gehören — mit Grund.
     *
     * Leer, und das ist der Zustand vom 18. September 2026. Sie steht trotzdem
     * da: Ein künftiges Segment, das hier nicht hingehört, soll seinen Grund
     * **laut** hinschreiben müssen und nicht still fehlen dürfen.
     *
     * @var array<string, string>
     */
    private const OHNE_WEG = [];

    /**
     * Die Ausnahmen als Wert und nicht als Konstante.
     *
     * **Ein Umweg mit einem Grund.** PHPStan liest eine leere Konstante als
     * `array{}` und meldet jede Frage daran als „ist immer falsch" — richtig
     * für heute und falsch als Zusage: Die Liste ist leer und soll es nicht
     * bleiben müssen. Über eine Methode mit deklariertem Rückgabetyp bleibt die
     * Frage eine Frage.
     *
     * @return array<string, string>
     */
    private function ohneWeg(): array
    {
        return self::OHNE_WEG;
    }

    public function test_every_page_of_a_subscription_is_reachable_from_it(): void
    {
        $segmente = $this->segmente();

        /*
         * **Die Untergrenze, und sie ist der Sinn der Zahl.** Greift der
         * Ausdruck über die Routen ins Leere, findet er null Segmente — und
         * eine leere Liste erfüllt jede Forderung darüber. Vier ist weit unter
         * dem Bestand und weit über dem, was ein kaputter Ausdruck liefert.
         *
         * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes
         * > als Null steht.**
         */
        $this->assertGreaterThanOrEqual(
            4,
            count($segmente),
            'Es werden kaum Seiten eines Abonnements gefunden — dann prüft dieser Test nichts.',
        );

        $seite = $this->withoutMarkupComments(
            (string) file_get_contents(base_path('resources/js/Pages/Subscriptions/Show.vue')),
        );

        $ohneWeg = [];

        foreach ($segmente as $segment => $name) {
            if (array_key_exists($segment, $this->ohneWeg())) {
                continue;
            }

            if (! str_contains($seite, '/subscriptions/${props.subscription.id}/'.$segment)) {
                $ohneWeg[$segment] = $name;
            }
        }

        $this->assertSame([], $ohneWeg, sprintf(
            "Zu diesen Seiten eines Abonnements führt von seiner Seite kein Weg:\n  %s\n\n"
            .'Sie gehören zu *einem* Abonnement — das sagt ihre Adresse —, und wer sie sucht, '
            .'sucht sie dort. Ein Menüpunkt beantwortet die andere Frage („wo sind meine …"), '
            .'nicht diese. Entweder kommt ein Verweis in die Knopfreihe der Seite, oder das '
            .'Segment steht mit seinem Grund in SubscriptionReachTest::OHNE_WEG.',
            implode("\n  ", array_map(
                static fn (string $segment, string $name): string => '/'.$segment.' ('.$name.')',
                array_keys($ohneWeg),
                $ohneWeg,
            )),
        ));
    }

    /**
     * Und die Gegenrichtung: Kein Eintrag in {@see self::OHNE_WEG} überlebt
     * seine Route.
     *
     * **So entsteht ein toter Eintrag wirklich** — bei einer Umbenennung trägt
     * man den neuen Namen nach, die erste Richtung ist wieder grün, und der
     * alte bleibt liegen. Danach deckt er ein Segment, das es gar nicht mehr
     * gibt, und beim nächsten gleichnamigen wäre die Regel still abgeschaltet.
     *
     * > **Ein Wächter, der eine Richtung prüft, hat über die andere nichts
     * > gesagt — und welche der beiden fehlt, sieht man erst, wenn man sie
     * > braucht.**
     */
    public function test_no_exemption_outlives_its_route(): void
    {
        $segmente = $this->segmente();
        $tot = [];

        foreach ($this->ohneWeg() as $segment => $grund) {
            if (! array_key_exists($segment, $segmente)) {
                $tot[] = $segment.': '.$grund;
            }
        }

        $this->assertSame([], $tot, sprintf(
            "Diese Ausnahmen nennen ein Segment, das es nicht mehr gibt:\n  %s",
            implode("\n  ", $tot),
        ));
    }

    /**
     * Die obersten Seiten eines Abonnements, aus dem Router.
     *
     * Ein Segment ohne weiteren Platzhalter und ohne zweiten Schrägstrich:
     * `subscriptions/{subscription}/files` zählt, `…/files/edit` und
     * `…/backups/{backup}/download` nicht.
     *
     * @return array<string, string> Segment → Routenname
     */
    private function segmente(): array
    {
        $gefunden = [];

        /*
         * **`getRoutes()` auf der Sammlung und nicht über sie iteriert.** Die
         * Schnittstelle `RouteCollectionInterface` sagt nicht, dass sie
         * iterierbar ist — der Ausdruck lief, und PHPStan wies ihn zu Recht ab.
         *
         * @var list<\Illuminate\Routing\Route> $alle
         */
        $alle = Route::getRoutes()->getRoutes();

        foreach ($alle as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (preg_match('#^subscriptions/\{subscription\}/([a-z][a-z-]*)$#', $route->uri(), $treffer) !== 1) {
                continue;
            }

            $gefunden[$treffer[1]] = (string) ($route->getName() ?? $route->uri());
        }

        ksort($gefunden);

        return $gefunden;
    }
}
