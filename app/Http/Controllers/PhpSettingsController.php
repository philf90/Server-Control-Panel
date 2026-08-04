<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\Settings\Settings;
use App\Support\Web\PhpSelection;
use Inertia\Inertia;
use Inertia\Response;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;
use SrvPanel\Agent\PhpVersions;

/**
 * Die PHP-Versionen des Servers — die eine Fläche des Betreibers dafür.
 *
 * **Installiert wird von hier, und nur von hier.** Die Knöpfe legen einen
 * Vorgang über den Aufgabenkatalog an (`php.version.install`,
 * `php.version.remove`); die Prüfung, wer das darf, steht an dessen Route und
 * nicht an dieser. Ein Kunde sieht diese Seite nicht — `can:manage-settings`,
 * dieselbe Schranke wie beim Mailversand und beim Zertifikat.
 *
 * **Der Agent wird beim Öffnen gefragt.** Was er sagt, landet zugleich im
 * Zwischenspeicher, aus dem die Domainformulare ihre Auswahl bauen: Diese
 * Seite ist der Ort, an dem sich beides von selbst auf denselben Stand bringt.
 * Antwortet er nicht, steht der letzte bekannte Stand da — mit dem Zeitpunkt,
 * damit niemand ihn für den heutigen hält.
 */
final class PhpSettingsController extends Controller
{
    public function show(Client $agent, PhpSelection $php, Settings $settings): Response
    {
        $live = null;
        $error = null;

        try {
            $answer = $agent->call('php.versions');

            $live = is_array($answer['versions'] ?? null) ? $answer['versions'] : null;

            if (is_array($answer['available'] ?? null)) {
                $php->remember(array_values(array_filter($answer['available'], is_string(...))));
            }
        } catch (AgentException $failure) {
            $error = $failure->getMessage();
        }

        return Inertia::render('Settings/Php', [
            'versions' => $live ?? $this->fromCache($php),
            'live' => $live !== null,
            'error' => $error,
            'checked_at' => $settings->phpVersionsCheckedAt(),

            // Wie viele Domains je Version laufen — die Zahl, die beim
            // Entfernen zählt. Sie kommt aus dem Bestand und nicht vom Agenten:
            // Der zählt Pool-Dateien, und die sagen nichts darüber, wessen
            // Website daran hängt.
            'usage' => $this->usage(),
        ]);
    }

    /**
     * Der letzte bekannte Stand, wenn der Agent nicht antwortet.
     *
     * Bewusst in derselben Form wie die Antwort des Agenten, nur ohne die
     * Angaben, die es ohne ihn nicht gibt. Zwei verschiedene Formen hiessen
     * eine Fallunterscheidung in der Oberfläche — und die wäre der Ort, an dem
     * „unbekannt" irgendwann wie „nicht installiert" aussieht.
     *
     * @return list<array<string, mixed>>
     */
    private function fromCache(PhpSelection $php): array
    {
        $installed = $php->installed();

        return array_map(static fn (string $version): array => [
            'version' => $version,
            'installed' => in_array($version, $installed, true),
            'unit' => PhpVersions::unit($version),
            'active' => null,
            'pools' => null,
            'release' => null,
        ], PhpVersions::CATALOG);
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        $counts = [];

        foreach (PhpVersions::CATALOG as $version) {
            $counts[$version] = Domain::query()->where('php_version', $version)->count();
        }

        return $counts;
    }
}
