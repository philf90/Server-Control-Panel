<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AuditResult;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Support\Audit\AuditQuery;
use App\Support\Web\Page;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Das Protokoll: ansehen, filtern, ausgeben.
 *
 * Beide Wege — Liste und Export — gehen durch {@see AuditQuery} und damit
 * durch dieselbe Sichtbarkeit. Der naheliegende Fehler wäre eine sorgfältig
 * gefilterte Liste und ein Export, der „schnell noch" eine eigene Abfrage
 * baut; er fällt niemandem auf, weil beide für den Betreiber gleich aussehen.
 */
final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $this->account($request);
        $filters = $this->filters($request);

        $events = AuditQuery::newestFirst(
            AuditQuery::filter(AuditQuery::visibleTo($account), $filters)
        )->paginate(Page::SIZE)->withQueryString();

        return Inertia::render('Audit/Index', [
            'events' => Page::from($events, static fn (AuditEvent $event): array => AuditQuery::toArrayRow($event)),
            'filters' => $filters,
            'results' => collect(AuditResult::cases())
                ->map(static fn (AuditResult $r): array => ['value' => $r->value, 'label' => $r->label()])
                ->all(),
        ]);
    }

    /**
     * Der Export als CSV.
     *
     * **Gestreamt, nicht gesammelt.** Ein Protokoll wächst; ein Export, der
     * erst alles in ein Array legt, fällt irgendwann über das Speicherlimit —
     * und zwar an dem Tag, an dem jemand ihn wirklich braucht.
     *
     * **Gedeckelt, aber nicht stillschweigend.** Nach der Höchstzahl endet die
     * Datei mit einer Zeile, die das sagt. Eine Datei, die aussieht wie das
     * ganze Protokoll und es nicht ist, wäre die schlechtere Antwort auf
     * dieselbe Grenze.
     */
    public function export(Request $request): StreamedResponse
    {
        $account = $this->account($request);
        $filters = $this->filters($request);
        $limit = (int) config('srvpanel.audit.export_max', 50000);

        $query = AuditQuery::newestFirst(
            AuditQuery::filter(AuditQuery::visibleTo($account), $filters)
        );

        // Die Sitzung schließen, bevor der Strom beginnt — dieselbe Überlegung
        // wie bei der Live-Ausgabe eines Vorgangs.
        if ($request->hasSession()) {
            $request->session()->save();
        }

        $response = new StreamedResponse(function () use ($query, $limit): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Zeitpunkt', 'Aktion', 'Ergebnis', 'Konto', 'Im Kontext von',
                'Abonnement', 'Ziel', 'IP',
            ]);

            $written = 0;

            foreach ($query->lazy(500) as $event) {
                if ($written >= $limit) {
                    fputcsv($handle, [
                        sprintf('[Bei %d Zeilen abgeschnitten — bitte den Zeitraum eingrenzen.]', $limit),
                    ]);
                    break;
                }

                $row = AuditQuery::toArrayRow($event);

                fputcsv($handle, array_map(self::harmless(...), [
                    $row['created_at'],
                    $row['action'],
                    $row['result_label'],
                    $row['account_id'],
                    $row['acting_as_account_id'],
                    $row['subscription_id'],
                    $row['target'],
                    $row['ip_address'],
                ]));

                $written++;
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="protokoll-'.now()->format('Y-m-d-His').'.csv"',
        );

        return $response;
    }

    /**
     * Ein Feld so schreiben, dass eine Tabellenkalkulation es nicht ausführt.
     *
     * Beginnt ein Feld mit `=`, `+`, `-`, `@`, einem Tabulator oder einem
     * Wagenrücklauf, liest Excel es als Formel — und `=cmd|'/c calc'!A1` ist
     * keine erfundene Gefahr, sondern der Standardfall dieser Lücke. Im
     * Protokoll stehen Werte, die von außen kommen: die Adresse aus einem
     * fehlgeschlagenen Anmeldeversuch etwa. Ein vorangestelltes Hochkomma
     * macht daraus wieder Text.
     */
    private static function harmless(mixed $value): string
    {
        $text = (string) ($value ?? '');

        if ($text === '') {
            return '';
        }

        return in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$text
            : $text;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'result' => ['sometimes', 'nullable', 'string', 'max:32'],
            'ip' => ['sometimes', 'nullable', 'string', 'max:45'],
            'subscription_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        return array_filter($validated, static fn ($value): bool => $value !== null && $value !== '');
    }

    private function account(Request $request): Account
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 403);

        return $account;
    }
}
