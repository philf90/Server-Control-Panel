<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Support\Diagnose\Checks\MaintenanceWindow;
use App\Support\Time\Clock;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Eine Ankündigung des Betreibers, die als Streifen ganz oben erscheint
 * (A14, `docs/103`).
 *
 * ## Das Fenster ist ein Filter beim Lesen
 *
 * `visible_from` und `visible_until` sind beide freilassbar; eine Ankündigung
 * ohne beide steht, bis jemand sie löscht. Ausgewertet wird beim **Lesen** —
 * es gibt keinen Zeitgeber, der etwas umschalten müsste.
 *
 * > **Ein Fenster, das beim Lesen ausgewertet wird, braucht keinen Zeitgeber.
 * > Eines, das beim Ablauf etwas verändern muss, braucht einen — und der kann
 * > ausfallen.**
 *
 * Das ist der Unterschied zu A12, wo genau diese Überlegung die Automatik
 * gestrichen hat (`docs/101 §2`).
 *
 * ## Verglichen wird in UTC
 *
 * Die Spalten liegen in UTC, `now()` liefert UTC (`app.timezone`), und
 * {@see Clock} dreht nur für die **Anzeige** und die **Eingabe**. Gemessen
 * (`docs/81 §2.3q` M7) mit der Anzeigezone auf `Europe/Berlin`:
 *
 * | jetzt | UTC-Vergleich | Ortszeit-Vergleich |
 * |---|---|---|
 * | 13:30 Ortszeit, **im** Fenster | ja | **nein** |
 * | 12:30, davor | nein | nein |
 * | 14:30, danach | nein | nein |
 *
 * > **Ein Fenster, dessen Filter in der Anzeigezone rechnet, ist genau während
 * > seiner eigenen Laufzeit unsichtbar und erscheint um den Versatz zu früh.**
 *
 * Die Gegenprobe schlägt in genau **einer** der drei Zeilen aus — der einzigen,
 * in der es zählt. Ein Prüfstand ohne Versatz sähe hier nichts.
 */
final class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Was der Betreiber höchstens schreiben darf.
     *
     * **Eine Grenze der Ablage und nicht des Aussehens** (`docs/81 §2.3q` M8):
     * Die Höhe des Streifens deckelt eine Zeilenklammer, weil eine Grenze in
     * Zeichen auf 390 px und 1440 px zwei verschiedene Grenzen ist. Ohne
     * *irgendeine* Grenze stünde hier trotzdem beliebig viel Text in einer
     * Spalte, die jeder Seitenaufruf liest.
     */
    public const BODY_MAX = 500;

    /** @var list<string> */
    protected $fillable = ['category', 'body', 'visible_from', 'visible_until', 'audiences'];

    /**
     * Die Ankündigungen, die dieses Konto gerade sehen soll.
     *
     * **Die eine Stelle, die das Fenster auswertet.** Sie wird von der
     * geteilten Nutzlast gerufen, also bei jedem Seitenaufruf — und dort
     * ausdrücklich als **Verschluss**, weil ein fertiger Wert in `share()` auch
     * bei einem partiellen Nachladen berechnet wird, das ihn gar nicht
     * mitschickt (`docs/81 §2.3q` M5).
     *
     * **Der Zeitpunkt ist ein Argument und nicht `now()`.** Derselbe Grund wie
     * bei {@see MaintenanceWindow}: Ein Prüfstand,
     * der gegen die Wanduhr misst, hat für denselben Bestand zwei Ergebnisse,
     * je nachdem, wann er läuft.
     *
     * @return Collection<int, self>
     */
    public static function visibleTo(?Account $account, ?Carbon $at = null): Collection
    {
        if (! $account instanceof Account) {
            return new Collection;
        }

        return self::query()
            ->where(self::inWindow($at ?? Carbon::now()))
            ->whereJsonContains('audiences', AnnouncementAudience::of($account)->value)
            ->get()
            ->sortByDesc(static fn (self $a): int => $a->category->rank())
            ->values();
    }

    /**
     * Die Fensterbedingung.
     *
     * Getrennt von {@see self::visibleTo()}, weil die Anmeldeseite dasselbe
     * Fenster braucht und ein **anderes** Publikum kennt — dort gibt es kein
     * angemeldetes Konto, sondern nur die Kategorie (`docs/103 §4.4`).
     *
     * @return \Closure(Builder<self>): void
     */
    public static function inWindow(Carbon $at): \Closure
    {
        return static function (Builder $query) use ($at): void {
            $query
                ->where(static fn (Builder $q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', $at))
                ->where(static fn (Builder $q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', $at));
        };
    }

    /**
     * Die Störungen, die ein Unangemeldeter auf der Anmeldeseite sieht.
     *
     * **Ohne `audiences`, und das ist Absicht.** Wer nicht angemeldet ist, hat
     * kein Publikum; die Grenze zieht hier die **Kategorie** und sonst nichts
     * (`docs/103 §2`, Entscheidung 4).
     *
     * > **Was auf der Anmeldeseite steht, steht vor jedem, der die Adresse
     * > kennt.** Deshalb steht hier genau eine Kategorie und keine Liste, die
     * > jemand später erweitert, ohne die Folge zu bedenken.
     *
     * @return Collection<int, self>
     */
    public static function onLoginPage(?Carbon $at = null): Collection
    {
        return self::query()
            ->where(self::inWindow($at ?? Carbon::now()))
            ->where('category', AnnouncementCategory::Incident->value)
            ->get();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'audiences' => 'array',
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }
}
