<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Audit;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Was der Server von sich aus sichert (`docs/117 §6` Schritte 9 und 10).
 *
 * ## Warum das eine Seite ist und kein fester Wert
 *
 * Beide Schalter kosten etwas, und zwar Verschiedenes: Die Automatik kostet
 * Platz auf dem Datenträger, die Sicherung vor dem Rückbau kostet Zeit an einem
 * Griff, den jemand gerade eilig tut. Welcher Preis tragbar ist, weiss der
 * Betreiber und nicht dieses Panel.
 *
 * > **Ein Vorgabewert, den niemand überschreiben kann, ist keine Vorgabe,
 * > sondern eine Entscheidung ohne Entscheider.**
 *
 * ## `operate-server` und nicht `manage-settings`
 *
 * Dieselbe Fähigkeit wie bei `/settings/php` und `/settings/database`: Was den
 * Datenträger des Servers füllt und was beim Rückbau geschieht, gehört dem
 * Betreiber. Die Anzeigezeitzone gehört dem Administrator — das ist der
 * Unterschied, den A9 gezogen hat.
 *
 * ## Wie viele Stände bleiben, steht **nicht** hier
 *
 * Das ist ein Kontingent des Plans ({@see Quota::Backups}) und
 * je Abonnement übersteuerbar. Eine Zahl auf dieser Seite wäre eine zweite
 * Fassung derselben Regel — und die zweite ist die, die veraltet.
 */
final class BackupSettingsController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Audit $audit,
    ) {}

    public function show(): Response
    {
        return Inertia::render('Settings/Backups', [
            'backups' => $this->settings->backups(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'automatic' => ['required', 'boolean'],
            'before_removal' => ['required', 'boolean'],
        ]);

        /*
         * **`boolean` über einen Wert, der als JSON reist, und nicht über die
         * Adresse.** Ein `PUT` trägt seinen Rumpf als JSON, dort ist `false`
         * ein Wahrheitswert; in einer Adresse wäre daraus das Wort `"false"`
         * geworden, und Laravels Regel `boolean` nimmt kein Wort.
         *
         * > **Dieselbe Regel über einem Wert, der einmal als JSON und einmal
         * > als Zeichenkette reist, gilt nur einmal.** (`docs/66`)
         */
        $this->settings->saveBackups((bool) $data['automatic'], (bool) $data['before_removal']);

        $this->audit->record('settings.backups.saved', context: [
            'automatic' => (bool) $data['automatic'],
            'before_removal' => (bool) $data['before_removal'],
        ]);

        /*
         * Das Ziel steht da und nicht `back()`. `RedirectTargetTest`
         * besteht darauf, und der Grund ist bezahlt: `back()` liest die
         * vorige Seite aus der Sitzung, und die ist nach einer
         * Inertia-Navigation nicht zwangsläufig die, von der das Formular
         * kam.
         */
        return redirect()->route('settings.backups')->with('success', 'Einstellungen gespeichert.');
    }
}
