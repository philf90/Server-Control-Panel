<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Audit\Audit;
use App\Support\Plans\Feature;
use App\Support\Plans\Quota;
use App\Support\Plans\Quotas;
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
            ->get();

        return Inertia::render('Plans/Index', [
            'plans' => $plans->map(static fn (Plan $plan): array => [
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
            ])->all(),
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

    public function edit(Plan $plan): Response
    {
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
            'subscriptions' => $plan->subscriptions()->count(),
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
     * War es der Standardplan, rückt der älteste verbliebene nach. Die
     * Alternative wäre, das Löschen zu verweigern, bis jemand anderswo einen
     * neuen Standard setzt — das ist ein Umweg für einen Zustand, den das
     * Panel selbst auflösen kann.
     */
    public function destroy(Plan $plan, Audit $audit): RedirectResponse
    {
        $bound = $plan->subscriptions()->count();

        if ($bound > 0) {
            $audit->denied('plan.deleted', $plan, ['reason' => 'gebundene Abonnements', 'subscriptions' => $bound]);

            throw ValidationException::withMessages([
                'plan' => "An diesem Plan hängen noch {$bound} Abonnements. Sie müssen zuerst auf einen anderen Plan wechseln.",
            ]);
        }

        $name = $plan->name;
        $wasDefault = (bool) $plan->is_default;

        $successor = DB::transaction(function () use ($plan, $wasDefault): ?Plan {
            $plan->delete();

            if (! $wasDefault) {
                return null;
            }

            $successor = Plan::query()->orderBy('id')->first();
            $successor?->update(['is_default' => true]);

            return $successor;
        });

        $audit->success('plan.deleted', context: ['name' => $name, 'successor' => $successor?->name]);

        $message = $successor !== null
            ? "Plan {$name} gelöscht. {$successor->name} ist jetzt der Standardplan."
            : "Plan {$name} gelöscht.";

        return redirect()->route('plans.index')->with('success', $message);
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
