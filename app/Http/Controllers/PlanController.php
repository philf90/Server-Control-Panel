<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Audit\Audit;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
use App\Support\Tenancy\Tenancy;
use App\Support\Web\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pläne — die Vorlage, aus der jedes Abonnement seine Grenzen bekommt.
 *
 * **Eine Änderung hier wirkt sofort auf alle daran gebundenen Abonnements.**
 * Das ist der Sinn einer Vorlage und zugleich das, was sie gefährlich macht:
 * Wer die Datenbanken von fünf auf zwei setzt, tut das für jeden Kunden in
 * diesem Plan. Deshalb steht an jedem Plan, wie viele Abonnements daran
 * hängen, und deshalb steht die Zahl auch auf dem Formular — nicht nur in der
 * Liste, aus der man kommt.
 *
 * **Bestehende Kunden werden dabei nicht rückwirkend beschnitten.** Eine
 * gesenkte Grenze verbietet das Anlegen des nächsten Objekts; sie löscht keine
 * vorhandenen. Wer zwei Datenbanken über der neuen Grenze liegt, behält sie
 * und kann keine dritte anlegen. Alles andere wäre eine Datenlöschung durch
 * eine Formularänderung.
 */
final class PlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::query()
            ->withCount('subscriptions')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(Page::SIZE)
            ->withQueryString();

        return Inertia::render('Plans/Index', [
            'plans' => Page::from($plans, static fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'is_default' => (bool) $plan->is_default,
                'subscriptions' => (int) $plan->getAttribute('subscriptions_count'),

                // Nicht alle neun in der Liste — drei, an denen sich Pläne
                // tatsächlich unterscheiden. Der Rest steht im Formular.
                'summary' => [
                    ['label' => Quota::DiskMb->label(), 'value' => Quotas::format(Quota::DiskMb, $plan->quota(Quota::DiskMb))],
                    ['label' => Quota::Domains->label(), 'value' => Quotas::format(Quota::Domains, $plan->quota(Quota::Domains))],
                    ['label' => Quota::Databases->label(), 'value' => Quotas::format(Quota::Databases, $plan->quota(Quota::Databases))],
                ],
                'features' => array_values(array_map(
                    static fn (Feature $feature): string => $feature->short(),
                    array_filter(Feature::cases(), static fn (Feature $feature): bool => $plan->feature($feature)),
                )),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Plans/Form', [
            'plan' => null,
            'values' => [
                'name' => '',
                'description' => '',
                // Der erste Plan ist der Standardplan. Etwas anderes wäre
                // sinnlos: Ohne einen bekäme ein neues Abonnement keinen.
                'is_default' => Plan::query()->count() === 0,
                'quotas' => Quotas::defaults(),
                'features' => Quotas::featureDefaults(),
            ],
            'catalog' => Quotas::catalog(),
            'subscriptions' => 0,
            'withdrawn' => 0,
            'targets' => [],
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $data = $this->validated($request, null);

        $plan = DB::transaction(function () use ($data): Plan {
            $plan = Plan::query()->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'quotas' => Quotas::normalize($data['quotas']),
                'features' => Quotas::normalizeFeatures($data['features']),
                'is_default' => false,
            ]);

            $this->settleDefault($plan, (bool) ($data['is_default'] ?? false));

            return $plan;
        });

        $audit->success('plan.created', $plan, ['name' => $plan->name]);

        return redirect()->route('plans.index')->with('success', "Plan {$plan->name} angelegt.");
    }

    public function edit(Plan $plan, Tenancy $tenancy): Response
    {
        // Beide Zahlen mit denselben Augen wie `destroy()` — sonst zeigt das
        // Formular einen Knopf an, den der Aufruf danach abweist. Ein Knopf,
        // der an einer anderen Frage hängt als die Prüfung dahinter, ist in
        // diesem Projekt schon mehrfach teuer gewesen.
        [$bound, $withdrawn] = $tenancy->withoutRestriction(
            static fn (): array => [
                $plan->subscriptions()->count(),
                $plan->subscriptions()->onlyTrashed()->count(),
            ],
        );

        return Inertia::render('Plans/Form', [
            'plan' => ['id' => (int) $plan->id, 'name' => $plan->name],
            'values' => [
                'name' => $plan->name,
                'description' => $plan->description ?? '',
                'is_default' => (bool) $plan->is_default,

                // Durch die Normalisierung geschickt und nicht roh aus der
                // Spalte: Ein Plan aus einer älteren Fassung kann ein
                // Kontingent noch nicht kennen, das es inzwischen gibt. Das
                // Formular zeigt dann den Vorgabewert statt ein leeres Feld,
                // das beim Speichern als „unbegrenzt" ankäme.
                'quotas' => Quotas::normalize($plan->quotas ?? []),
                'features' => Quotas::normalizeFeatures($plan->features ?? []),
            ],
            'catalog' => Quotas::catalog(),
            'subscriptions' => $bound,
            'withdrawn' => $withdrawn,

            // Nur wenn wirklich Grabsteine da sind. Eine Liste, die immer
            // mitfährt, ist eine Auswahl, die die Seite immer bauen muss —
            // und ein Feld, das ohne Anlass dasteht, liest niemand.
            'targets' => $withdrawn === 0 ? [] : Plan::query()
                ->whereKeyNot($plan->getKey())
                ->orderBy('name')
                ->get()
                ->map(static fn (Plan $other): array => [
                    'id' => (int) $other->id,
                    'name' => $other->name,
                ])
                ->all(),
        ]);
    }

    public function update(Request $request, Plan $plan, Audit $audit): RedirectResponse
    {
        $data = $this->validated($request, $plan);

        $before = ['quotas' => $plan->quotas, 'features' => $plan->features];

        DB::transaction(function () use ($plan, $data): void {
            $plan->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'quotas' => Quotas::normalize($data['quotas']),
                'features' => Quotas::normalizeFeatures($data['features']),
            ]);

            $this->settleDefault($plan, (bool) ($data['is_default'] ?? false));
        });

        // Was sich geändert hat, steht im Protokoll — nicht der ganze Plan.
        // Ein Eintrag, der neun unveränderte Werte mitschleppt, ist beim
        // Nachlesen wertlos.
        $audit->success('plan.updated', $plan, [
            'name' => $plan->name,
            'changed' => $this->changes($before, $plan),
            'subscriptions' => $plan->subscriptions()->count(),
        ]);

        return redirect()->route('plans.index')->with('success', "Plan {$plan->name} gespeichert.");
    }

    /**
     * Einen Plan löschen.
     *
     * **Nur ohne gebundene Abonnements.** Der Fremdschlüssel steht auf
     * `restrictOnDelete` und würde es ohnehin abweisen — aber mit einer
     * Datenbankmeldung, die der Betreiber nicht deuten kann. Die Prüfung hier
     * ist dieselbe Aussage in verständlich.
     *
     * **Und sie zählt mit denselben Augen wie der Fremdschlüssel.** Das war
     * der Fehler, der bis August 2026 einen 500er warf: `Subscription` trägt
     * zwei Filter, die der Datenbank fremd sind — die Mandantenklammer und
     * `SoftDeletes`. Ein zurückgebautes Abonnement verschwindet damit aus
     * `$plan->subscriptions()`, hält aber seine Zeile und darin `plan_id`.
     * Das Panel zählte null, der Fremdschlüssel zählte eins, und `DELETE`
     * endete als SQLSTATE 23000. Gezählt wird deshalb ohne Klammer und mit
     * den Grabsteinen; `RestrictedDeleteTest` besteht darauf.
     *
     * **Zurückgebaute Abonnements werden übertragen, nicht abgewiesen.** Ihre
     * Zeilen bleiben liegen, damit ihr Systembenutzer nicht neu vergeben wird;
     * am Plan hängen sie nur, weil die Spalte einen verlangt. Eine Abweisung
     * hätte bedeutet, dass so ein Plan nie wieder verschwindet — und im Panel
     * gibt es keinen Weg, einen Grabstein loszuwerden. Wohin sie gehen, sagt
     * der Betreiber; still auf den Standardplan zu schieben wäre eine Änderung,
     * die niemand sieht und deshalb niemand prüft.
     *
     * War es der Standardplan, rückt der älteste verbliebene nach. Die
     * Alternative wäre, das Löschen zu verweigern, bis jemand anderswo einen
     * neuen Standard setzt — das ist ein Umweg für einen Zustand, den das
     * Panel selbst auflösen kann.
     */
    public function destroy(Request $request, Plan $plan, Audit $audit, Tenancy $tenancy): RedirectResponse
    {
        [$bound, $withdrawn] = $tenancy->withoutRestriction(
            static fn (): array => [
                $plan->subscriptions()->count(),
                $plan->subscriptions()->onlyTrashed()->count(),
            ],
        );

        if ($bound > 0) {
            $audit->denied('plan.deleted', $plan, ['reason' => 'gebundene Abonnements', 'subscriptions' => $bound]);

            throw ValidationException::withMessages([
                'plan' => $bound === 1
                    ? 'An diesem Plan hängt noch ein Abonnement. Es muss zuerst auf einen anderen Plan wechseln.'
                    : "An diesem Plan hängen noch {$bound} Abonnements. Sie müssen zuerst auf einen anderen Plan wechseln.",
            ]);
        }

        // **Ein Grabstein ist kein Kunde, und trotzdem hält er den Plan.**
        // Ein zurückgebautes Abonnement bleibt als Zeile liegen, damit sein
        // Systembenutzer nicht ein zweites Mal vergeben wird (siehe
        // `Lifecycle::nextSystemUser()`) — und diese Zeile zeigt weiter auf
        // ihren Plan. Sie muss also irgendwohin, bevor der Plan verschwinden
        // kann, und wohin, entscheidet der Betreiber.
        $target = $withdrawn > 0 ? $this->transferTarget($request, $plan, $withdrawn, $audit) : null;

        $name = $plan->name;
        $wasDefault = (bool) $plan->is_default;

        $successor = DB::transaction(function () use ($plan, $wasDefault, $target, $tenancy): ?Plan {
            if ($target !== null) {
                $this->carryOver($plan, $target, $tenancy);
            }

            $plan->delete();

            if (! $wasDefault) {
                return null;
            }

            $successor = Plan::query()->orderBy('id')->first();
            $successor?->update(['is_default' => true]);

            return $successor;
        });

        $audit->success('plan.deleted', context: [
            'name' => $name,
            'successor' => $successor?->name,
            'withdrawn' => $withdrawn,
            'transferred_to' => $target?->name,
        ]);

        // Zwei Sätze und nicht einer mit Nebensatz: Die Übertragung ist eine
        // eigene Tatsache, und der Betreiber soll sie noch lesen können, wenn
        // er die Meldung nur überfliegt.
        $message = "Plan {$name} gelöscht.";

        if ($target !== null) {
            $message .= $withdrawn === 1
                ? " Ein zurückgebautes Abonnement hängt jetzt an {$target->name}."
                : " {$withdrawn} zurückgebaute Abonnements hängen jetzt an {$target->name}.";
        }

        if ($successor !== null) {
            $message .= " {$successor->name} ist jetzt der Standardplan.";
        }

        return redirect()->route('plans.index')->with('success', $message);
    }

    /**
     * Wohin die Grabsteine sollen — gefragt und nicht angenommen.
     *
     * **Warum überhaupt gefragt wird.** Ein zurückgebautes Abonnement ist im
     * Panel unsichtbar; sein Plan wird nirgends angezeigt. Man könnte die
     * Zeilen also still auf irgendeinen Plan schieben, und niemand sähe einen
     * Unterschied. Genau das ist der Grund, es nicht zu tun: Eine Änderung, die
     * niemand sieht, ist eine, die niemand prüft. Der Betreiber nennt das Ziel,
     * und es steht danach im Protokoll.
     *
     * **Der Standardplan wird hier nicht als Vorgabe eingesetzt.** Ein Ziel,
     * das der Aufruf sich selbst aussucht, wäre wieder die stille Fassung —
     * nur mit einem plausibleren Namen.
     */
    private function transferTarget(Request $request, Plan $plan, int $withdrawn, Audit $audit): Plan
    {
        $others = Plan::query()->whereKeyNot($plan->getKey())->orderBy('name')->get();

        // Der einzige Fall, der auch mit Rückfrage nicht aufgeht: Es gibt
        // keinen zweiten Plan, an den die Zeilen könnten. Ein Abonnement ohne
        // Plan gibt es nicht — die Spalte ist nicht nullable, und „unbegrenzt"
        // wäre die Bedeutung, die ein leerer Plan bekäme.
        if ($others->isEmpty()) {
            $audit->denied('plan.deleted', $plan, ['reason' => 'kein Ziel für die Grabsteine', 'withdrawn' => $withdrawn]);

            throw ValidationException::withMessages([
                'plan' => 'An diesem Plan hängen noch zurückgebaute Abonnements, und es gibt keinen zweiten Plan, an den sie könnten. Legen Sie zuerst einen weiteren Plan an.',
            ]);
        }

        $target = $others->firstWhere('id', $request->integer('transfer_to'));

        if (! $target instanceof Plan) {
            $audit->denied('plan.deleted', $plan, ['reason' => 'kein Ziel genannt', 'withdrawn' => $withdrawn]);

            throw ValidationException::withMessages([
                'transfer_to' => $withdrawn === 1
                    ? 'An diesem Plan hängt noch ein zurückgebautes Abonnement. Es ist aus dem Panel verschwunden, seine Zeile bleibt aber liegen, damit sein Systembenutzer nicht neu vergeben wird. Bitte wählen Sie, an welchen Plan sie übergeht.'
                    : "An diesem Plan hängen noch {$withdrawn} zurückgebaute Abonnements. Sie sind aus dem Panel verschwunden, ihre Zeilen bleiben aber liegen, damit ihre Systembenutzer nicht neu vergeben werden. Bitte wählen Sie, an welchen Plan sie übergehen.",
            ]);
        }

        return $target;
    }

    /**
     * Die Grabsteine übertragen.
     *
     * `onlyTrashed()` und nicht `withTrashed()`: Lebende Abonnements sind an
     * dieser Stelle bereits ausgeschlossen, und ein Aufruf, der sie trotzdem
     * mitnähme, würde bei einem Fehler weiter oben stillschweigend Kunden
     * umhängen. Die engere Abfrage ist hier die Sicherung.
     *
     * Ohne Mandantenklammer aus demselben Grund wie beim Zählen — sonst trifft
     * das `UPDATE` je nach anfragendem Konto keine einzige Zeile, und das
     * `DELETE` danach liefe in denselben Fremdschlüssel wie zuvor.
     */
    private function carryOver(Plan $plan, Plan $target, Tenancy $tenancy): void
    {
        $tenancy->withoutRestriction(static fn (): int => Subscription::query()
            ->onlyTrashed()
            ->where('plan_id', $plan->getKey())
            ->update(['plan_id' => $target->getKey()]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('plans', 'name')->ignore($plan?->id),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_default' => ['required', 'boolean'],
            ...Quotas::rules(),
        ]);
    }

    /**
     * Die Marke „Standardplan" setzen — und sie genau einmal vergeben.
     *
     * **Das Abwählen an einem Plan tut nichts.** Es gäbe sonst einen Zustand
     * ohne Standardplan, und in dem bekäme ein neues Abonnement keinen Plan
     * zugewiesen — ein Fehler, der erst beim nächsten Anlegen auffiele und
     * nicht bei dem Häkchen, das ihn verursacht hat. Der Standard wechselt,
     * indem man ihn woanders setzt.
     */
    private function settleDefault(Plan $plan, bool $wanted): void
    {
        if (! $wanted) {
            return;
        }

        Plan::query()->whereKeyNot($plan->getKey())->update(['is_default' => false]);
        $plan->update(['is_default' => true]);
    }

    /**
     * Welche Kontingente und Freigaben sich geändert haben.
     *
     * @param  array{quotas: array<string, mixed>|null, features: array<string, mixed>|null}  $before
     * @return array<string, array{von: string, auf: string}>
     */
    private function changes(array $before, Plan $plan): array
    {
        $changed = [];

        foreach (Quota::cases() as $quota) {
            $old = ($before['quotas'] ?? [])[$quota->value] ?? null;
            $new = $plan->quota($quota);

            if ($old !== $new) {
                $changed[$quota->value] = [
                    'von' => Quotas::format($quota, $old),
                    'auf' => Quotas::format($quota, $new),
                ];
            }
        }

        foreach (Feature::cases() as $feature) {
            $old = (bool) (($before['features'] ?? [])[$feature->value] ?? false);
            $new = $plan->feature($feature);

            if ($old !== $new) {
                $changed[$feature->value] = [
                    'von' => $old ? 'frei' : 'gesperrt',
                    'auf' => $new ? 'frei' : 'gesperrt',
                ];
            }
        }

        return $changed;
    }
}
