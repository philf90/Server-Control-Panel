<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Models\Account;
use App\Models\Announcement;
use App\Support\Audit\Audit;
use App\Support\Time\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Ankündigungen des Betreibers verwalten (A14, `docs/103 §5`).
 *
 * ## Hinter `operate-server` und nicht hinter `manage-settings`
 *
 * Der Plan hat das einmal andersherum gesagt, mit der Begründung „eine
 * Ankündigung dreht nichts am Server, sie ist Text in einer Tabelle". Sie ordnet
 * nach dem, was der Griff **anfasst**; `docs/20 §6.1` ordnet nach dem, was er
 * **bewirkt** — kritisch ist unter anderem, was „alle Kunden mitnimmt".
 *
 * Eine Ankündigung mit dem Publikum „Kunde" erscheint bei jedem Kunden, im Namen
 * des Betreibers, und eine Störung steht auf der Anmeldeseite vor jedem, der die
 * Adresse kennt.
 *
 * > **Eine Fähigkeit bemisst sich nicht daran, was ein Griff anfasst, sondern
 * > daran, wen er erreicht.**
 *
 * ## Zugleich der Ort des vollen Textes
 *
 * Der Streifen zeigt zwei Zeilen (`docs/81 §2.3q` M8); wer mehr will, kommt
 * hierher. Deshalb steht der Text hier **ungekürzt** und nicht noch einmal
 * geklammert.
 */
final class AnnouncementController extends Controller
{
    /**
     * Eine einzelne Ankündigung im vollen Wortlaut — für jeden, den sie erreicht.
     *
     * ## Warum es diese Seite gibt
     *
     * Der Streifen klammert bei zwei Zeilen; das sind bei 390 px rund 80 von
     * 500 Zeichen. `docs/103 §4.3` versprach dafür einen Verweis „auf die
     * Verwaltungsseite" — und die steht hinter `operate-server`. Kunde,
     * Administrator und der Unangemeldete auf der Anmeldeseite hätten dort
     * einen 403 bekommen, also genau die drei Gruppen, für die der Verweis da
     * war.
     *
     * > **Ein Verweis auf einen Ort, den der Leser nicht betreten darf, ist
     * > kein Weg zum Text — er ist eine zweite Sackgasse.**
     *
     * ## Wer was sieht, entscheidet dieselbe Stelle wie beim Streifen
     *
     * **Angemeldet**: was {@see Announcement::visibleTo()} liefert — Fenster
     * und Publikum. **Unangemeldet**: was
     * {@see Announcement::onLoginPage()} liefert, also Störungen im Fenster
     * und sonst nichts. Beides sind die Mengen, die der Leser als Streifen
     * ohnehin schon vor sich hat; diese Seite zeigt nur denselben Text
     * ungekürzt.
     *
     * > **Eine Leseseite, die eine eigene Frage stellt, ist eine zweite
     * > Fassung der Sichtbarkeitsregel — und die zweite ist die, die
     * > veraltet.**
     *
     * ## 404 und nicht 403
     *
     * Ein 403 bestätigte die Existenz. Wer eine Kennung durchprobiert, soll
     * nicht erfahren, dass es Ankündigung 7 gibt und sie ihn nur nichts
     * angeht.
     */
    public function show(Request $request, Announcement $announcement): Response
    {
        $konto = $request->user();

        $sichtbar = $konto instanceof Account
            ? Announcement::visibleTo($konto)
            : Announcement::onLoginPage();

        if (! $sichtbar->contains(static fn (Announcement $a): bool => $a->is($announcement))) {
            abort(404);
        }

        return Inertia::render('Announcements/Show', [
            'announcement' => [
                'rank' => $announcement->category->label(),
                'badge' => $announcement->category->badge(),
                'body' => $announcement->body,
            ],

            // Angemeldet führt der Weg zurück ins Panel, unangemeldet zur
            // Anmeldung — die einzige Seite, die der Leser dann kennt.
            'back' => $konto instanceof Account
                ? ['url' => route('overview'), 'label' => 'Zur Übersicht']
                : ['url' => route('login'), 'label' => 'Zur Anmeldung'],
        ]);
    }

    public function index(): Response
    {
        return Inertia::render('Announcements/Index', [
            'announcements' => Announcement::query()
                ->orderByDesc('id')
                ->get()
                ->map(fn (Announcement $a): array => $this->row($a))
                ->all(),

            /*
             * Die Zone steht neben den Zeiten und nicht nur in der Überschrift.
             *
             * Dieselbe Entscheidung wie bei A12 (`docs/102 §3b`): Ein Zeitpunkt
             * ohne seine Zone wird still falsch, sobald jemand die
             * Anzeigezeitzone ändert oder in einer anderen sitzt.
             */
            'zone' => Clock::label(),

            'categories' => array_map(
                static fn (AnnouncementCategory $c): array => ['value' => $c->value, 'label' => $c->label()],
                AnnouncementCategory::cases(),
            ),
            'audiences' => array_map(
                static fn (AnnouncementAudience $a): array => ['value' => $a->value, 'label' => $a->label()],
                AnnouncementAudience::cases(),
            ),
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $daten = $this->pruefen($request);

        $ankuendigung = Announcement::create($daten);

        $audit->success('announcement.create', context: [
            'id' => $ankuendigung->id,
            'category' => $ankuendigung->category->value,
            'audiences' => $ankuendigung->audiences,
        ]);

        return redirect()->route('announcements')
            ->with('success', 'Die Ankündigung steht.');
    }

    public function destroy(Announcement $announcement, Audit $audit): RedirectResponse
    {
        $audit->success('announcement.remove', context: [
            'id' => $announcement->id,
            'category' => $announcement->category->value,
        ]);

        $announcement->delete();

        return redirect()->route('announcements')
            ->with('success', 'Die Ankündigung ist entfernt.');
    }

    /**
     * Die Eingabe prüfen und in die abgelegte Form drehen.
     *
     * **Zwei Felder je Zeitpunkt und nicht eines, und das ist bezahlt.**
     * `docs/102 §2`: Ein Textfeld für `Y-m-d H:i` mit `inputmode="numeric"` war
     * auf dem iPhone nicht ausfüllbar, weil die Zifferntastatur weder
     * Bindestrich noch Doppelpunkt noch Leerzeichen hergibt.
     *
     * > **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon nicht
     * > tippbar.**
     *
     * **Zusammengesetzt wird hier und nicht in der Seite:** Das Format, das
     * abgelegt wird, ist eine Eigenschaft dieser Grenze, und die Seite hätte
     * sonst eine zweite Fassung davon.
     *
     * @return array<string, mixed>
     */
    private function pruefen(Request $request): array
    {
        $daten = $request->validate([
            'category' => ['required', Rule::in(array_column(AnnouncementCategory::cases(), 'value'))],
            'body' => ['required', 'string', 'max:'.Announcement::BODY_MAX],

            'visible_from_date' => ['nullable', 'required_with:visible_from_time', 'date_format:Y-m-d'],
            'visible_from_time' => ['nullable', 'required_with:visible_from_date', 'date_format:H:i'],
            'visible_until_date' => ['nullable', 'required_with:visible_until_time', 'date_format:Y-m-d'],
            'visible_until_time' => ['nullable', 'required_with:visible_until_date', 'date_format:H:i'],

            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*' => [Rule::in(AnnouncementAudience::values())],
        ], [
            'visible_from_date.date_format' => 'Das Datum muss die Form JJJJ-MM-TT haben.',
            'visible_from_time.date_format' => 'Die Uhrzeit muss die Form HH:MM haben.',
            'visible_until_date.date_format' => 'Das Datum muss die Form JJJJ-MM-TT haben.',
            'visible_until_time.date_format' => 'Die Uhrzeit muss die Form HH:MM haben.',
            'audiences.required' => 'Eine Ankündigung ohne Publikum sieht niemand.',
            'audiences.min' => 'Eine Ankündigung ohne Publikum sieht niemand.',
        ]);

        return [
            'category' => $daten['category'],
            'body' => $daten['body'],
            'visible_from' => $this->zeitpunkt($daten['visible_from_date'] ?? null, $daten['visible_from_time'] ?? null),
            'visible_until' => $this->zeitpunkt($daten['visible_until_date'] ?? null, $daten['visible_until_time'] ?? null),
            'audiences' => array_values($daten['audiences']),
        ];
    }

    /** Aus Datum und Uhrzeit der Anzeigezone ein abgelegtes UTC — oder nichts. */
    private function zeitpunkt(mixed $datum, mixed $zeit): ?string
    {
        if (! is_string($datum) || ! is_string($zeit) || $datum === '' || $zeit === '') {
            return null;
        }

        return Clock::minuteToUtc($datum.' '.$zeit);
    }

    /**
     * Eine Zeile für die Liste.
     *
     * **Der Zustand wird gerechnet und nicht gespeichert** — er folgt
     * vollständig aus dem Fenster, und eine Spalte daneben wäre die zweite
     * Fassung derselben Regel.
     *
     * @return array<string, mixed>
     */
    private function row(Announcement $a): array
    {
        $jetzt = now();
        $von = $a->visible_from;
        $bis = $a->visible_until;

        return [
            'id' => $a->id,
            'category' => $a->category->value,
            'rank' => $a->category->label(),
            'badge' => $a->category->badge(),
            'body' => $a->body,
            'from' => Clock::minute($von?->utc()->format('Y-m-d H:i:s')),
            'until' => Clock::minute($bis?->utc()->format('Y-m-d H:i:s')),
            'audiences' => array_map(
                static fn (string $v): string => AnnouncementAudience::from($v)->label(),
                $a->audiences ?? [],
            ),
            'state' => match (true) {
                $von !== null && $jetzt->lt($von) => 'wartet',
                $bis !== null && $jetzt->gt($bis) => 'abgelaufen',
                default => 'sichtbar',
            },
        ];
    }
}
