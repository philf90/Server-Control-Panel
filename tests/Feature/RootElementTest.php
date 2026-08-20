<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Das ausgelieferte Dokument trägt die Kennung `app` genau einmal.
 *
 * ## Warum dieser Wächter der stärkere von zweien ist
 *
 * Sein Zwilling `Tests\Unit\RootElementTest` liest die Vorlage und weiß, wonach
 * er sucht: `@inertia` einmal, `@inertiaHead` im Kopf. Dieser hier weiß gar
 * nichts über Direktiven — er zählt, was beim Kunden ankommt.
 *
 * > **Ein Wächter über die Absicht findet, was jemand falsch geschrieben hat.
 * > Ein Wächter über das Ergebnis findet auch, was niemand geschrieben hat.**
 *
 * Befund 17 aus `docs/64` ist genau so entstanden: Niemand hat „zwei
 * Wurzelelemente" geschrieben. Geschrieben wurde `@inertia` statt
 * `@inertiaHead`, und das zweite Element war die **Folge**. Ein Wächter, der
 * nur nach der Ursache sucht, kennt immer nur die Ursachen, an die jemand
 * gedacht hat.
 *
 * ## Warum eine Kennung überhaupt einmalig sein muss
 *
 * Weil alles, was auf sie zeigt, sich das erste Element nimmt und je nach Weg
 * ein anderes findet: `document.getElementById`, ein `label[for]`, ein
 * `aria-labelledby`, ein Sprungziel `#app`. Im Fall von Befund 17 hing die
 * ganze Anwendung im Element aus dem Kopf, während das gemeinte aus dem Rumpf
 * leer stehenblieb.
 */
final class RootElementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Auf einer Seite ohne Anmeldung steht die Kennung einmal.
     */
    public function test_the_delivered_document_carries_one_root_element(): void
    {
        $this->assertRootElementIsUnique((string) $this->get('/login')->getContent(), '/login');
    }

    /**
     * Und auf einer Seite mit Anmeldung ebenso.
     *
     * **Zwei Seiten und nicht eine**, weil die Vorlage je nach Konto andere
     * Zweige nimmt: Theme, Dichte und der Hinweis auf die Kundensicht hängen
     * alle an `auth()->user()`. Ein Wurzelelement, das nur einem der beiden
     * Zweige zuviel wäre, fiele bei einer einzigen Seite nicht auf.
     */
    public function test_it_holds_for_a_signed_in_account(): void
    {
        $admin = Account::factory()->admin()->create();

        $this->assertRootElementIsUnique(
            (string) $this->actingAs($admin)->get('/')->getContent(),
            '/',
        );
    }

    /**
     * Die Zusage: genau ein Element mit `id="app"`, und es liegt im Rumpf.
     */
    private function assertRootElementIsUnique(string $html, string $wo): void
    {
        $anzahl = preg_match_all('/\sid="app"/', $html);

        $this->assertSame(
            1,
            $anzahl,
            sprintf(
                "Das Dokument unter %s trägt die Kennung `app` %dmal, erwartet ist einmal.\n\n".
                'Eine Kennung ist im Dokument einmalig, sonst ist sie keine. Was auf sie zeigt, '.
                'nimmt sich das erste Element und findet je nach Weg ein anderes.'."\n\n".
                'Der häufigste Grund ist `@inertia` im Kopf statt `@inertiaHead` (docs/64, '.
                'Befund 17): Die Direktive setzt ein `<div>`, im `<head>` ist das nicht erlaubt, '.
                'und der Parser schliesst den Kopf an dieser Stelle.',
                $wo,
                $anzahl,
            ),
        );

        $rumpf = strpos($html, '<body');

        $this->assertNotFalse($rumpf, 'Das Dokument unter '.$wo.' hat kein `<body`.');

        $this->assertGreaterThan(
            $rumpf,
            (int) strpos($html, ' id="app"'),
            'Das Wurzelelement steht vor `<body`. Damit hängt die Anwendung in etwas, das in den '.
            'Kopf gehört — und alles, was im Kopf danach käme, läge im Rumpf und wäre wirkungslos.',
        );
    }
}
