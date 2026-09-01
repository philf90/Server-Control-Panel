<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Operation;
use App\Support\Operations\Origin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Die Herkunft kommt an — gemessen an der Wirkung und nicht am Quelltext.
 *
 * **Warum es diesen Test zusätzlich gibt.** `OperationOriginTest` läuft ohne
 * Framework und hält, was sich am Text halten lässt: dass die Kopfzeile an
 * beiden Enden gleich heisst, dass `Origin::pfad()` richtig rechnet, dass die
 * Herkunft am Modell genommen wird. Keines davon belegt, dass am Ende
 * tatsächlich ein Wert in der Spalte steht.
 *
 * > **Ein Wächter über den Quelltext sagt, dass die Teile zusammenpassen. Dass
 * > sie zusammen etwas tun, sagt er nicht.**
 *
 * Und dieser Weg hat genau die Bauart, bei der das auseinanderfällt, ohne dass
 * es auffällt: Eine fehlende Herkunft sieht aus wie ein Vorgang der Automatik
 * — es fehlt kein Wert, es steht `null` da, und `null` ist ein gültiger
 * Zustand.
 */
final class OriginHeaderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Die Anfrage trägt die Kopfzeile, der Vorgang trägt die Herkunft.
     */
    public function test_the_header_reaches_the_column(): void
    {
        $this->anfrageMit('/updates?nur=sicherheit');

        $vorgang = Operation::factory()->create();

        $this->assertSame(
            '/updates?nur=sicherheit',
            $vorgang->origin,
            'Die Kopfzeile kommt nicht in der Spalte an — der Brotkrümel zeigt dann keinen Weg zurück.',
        );
    }

    /**
     * Ohne Kopfzeile bleibt die Spalte leer, und das ist die Wahrheit.
     *
     * Die Warteschlange und die Konsole setzen ohne Seite ab. Ein Wert, den man
     * dort erfände, sähe aus wie eine Auskunft.
     */
    public function test_without_the_header_the_column_stays_empty(): void
    {
        $this->anfrageMit(null);

        $this->assertNull(
            Operation::factory()->create()->origin,
            'Ein Vorgang ohne Seite bekommt eine Herkunft, die es nicht gibt.',
        );
    }

    /**
     * Ein Wert, der aus dem Panel herausführt, kommt nicht in die Spalte.
     *
     * **Die Wirkung derselben Regel, die `OperationOriginTest` an der reinen
     * Funktion misst.** Sie steht hier noch einmal, weil zwischen der Funktion
     * und der Spalte zwei Schichten liegen — das Lesen der Kopfzeile und das
     * Ereignis am Modell —, und eine davon könnte den Wert ungeprüft
     * durchreichen.
     */
    public function test_a_foreign_address_never_reaches_the_column(): void
    {
        $this->anfrageMit('/\\evil.example/x');

        $this->assertNull(
            Operation::factory()->create()->origin,
            'Eine Adresse ausserhalb des Panels steht in der Spalte — der Brotkrümel führte dorthin.',
        );
    }

    /**
     * Eine Herkunft, die schon dasteht, wird nicht überschrieben.
     *
     * `Operation::booted()` setzt sie nur, wenn sie leer ist. Ohne diese
     * Bedingung verlöre eine Stelle, die es besser weiss, ihre Angabe — und
     * zwar wortlos.
     */
    public function test_an_origin_that_is_already_set_survives(): void
    {
        $this->anfrageMit('/updates');

        $vorgang = Operation::factory()->create(['origin' => '/databases/22']);

        $this->assertSame('/databases/22', $vorgang->origin);
    }

    /**
     * Die laufende Anfrage durch eine mit (oder ohne) Kopfzeile ersetzen.
     *
     * **Über den Container und nicht über `->withHeader()`:** Der Vorgang
     * entsteht hier unmittelbar am Modell und nicht hinter einer Route. Ein
     * Weg über HTTP prüfte zusätzlich die Route und wäre bei deren Umbau rot,
     * ohne dass an der Herkunft etwas falsch wäre.
     */
    private function anfrageMit(?string $herkunft): void
    {
        $server = $herkunft === null
            ? []
            : ['HTTP_'.str_replace('-', '_', strtoupper(Origin::HEADER)) => $herkunft];

        $this->app->instance('request', Request::create('/x', 'POST', server: $server));
    }
}
