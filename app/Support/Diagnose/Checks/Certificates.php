<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\DomainStatus;
use App\Enums\DomainType;
use App\Enums\FindingCheck;
use App\Enums\SubscriptionStatus;
use App\Models\Certificate;
use App\Models\Domain;
use App\Models\Subscription;
use App\Support\Diagnose\Check;
use App\Support\Diagnose\FindingLog;
use App\Support\Diagnose\Wire;
use App\Support\Tenancy\Tenancy;
use App\Support\Tls\CertificateChoice;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * E · Zertifikate — `tls.file` und `tls.wire` (`docs/98 §3 E`).
 *
 * ## Zwei Fragen und nicht eine
 *
 * **An die Datei:** gültig, nicht abgelaufen, und jeder Name der Domain steht
 * im `subjectAltName`. Das beantwortet `acme.certificate.info` mit
 * `openssl_x509_parse`, wie `PanelTlsInfo` seit P4 — je Ablageort einmal, auch
 * wenn zehn Domains ihn teilen. **An die Leitung:** liefert der Server es für
 * diesen Namen auch aus? Das fragt {@see Wire} mit SNI; ohne SNI käme der
 * Vorgabeblock zurück und damit ein gültig aussehendes Zertifikat mit dem
 * falschen Namen (`docs/78`, `docs/81 §2.3o` M18).
 *
 * **Die Leitung wird nur gefragt, wenn die Datei in Ordnung ist** — Frage 3 in
 * `docs/98 §9`, entschieden mit c. Ein abgelaufenes Zertifikat wird auch über
 * die Leitung abgelaufen ausgeliefert; zwei Befunde für eine Ursache wären die
 * Falle aus §4. Verglichen wird der **Fingerabdruck** und nicht das
 * Ablaufdatum: Zwei Zertifikate für denselben Namen tragen dasselbe Ablaufdatum,
 * sobald sie in derselben Stunde ausgestellt wurden. Und nicht die
 * Seriennummer — die ist nur je Aussteller eindeutig, und dieses Panel erzeugt
 * selbstsignierte Zertifikate (`docs/81 §2.3o` M23).
 *
 * ## Welches Zertifikat für eine Domain zählt
 *
 * Das, das ausgeliefert wird — {@see CertificateChoice::effective()}, dieselbe
 * Regel, nach der `web.site.apply` den Block baut. Eine zweite Auswahl hier
 * wäre die Fassung, die veraltet.
 *
 * **Eine Domain ohne gewähltes Zertifikat ist kein Befund.** Das ist der
 * Zustand, den die TLS-Seite zeigt und die Automatik behebt; auf einem
 * Abnahmeserver stehen Domains unter `.invalid`, die nie eines bekommen
 * können, und die jede Nacht zu melden wäre §4. `missing` heisst: Die gewählte
 * Zeile gibt es, ihre **Datei** nicht mehr — das ist ein Schaden am Bestand.
 *
 * ## Was diese Prüfung nicht kann
 *
 * Ob der Name im DNS auf diesen Server zeigt, ist P7. Wie lange ein stiller
 * Port die Frage an die Leitung hält, ist ungemessen (M19, `docs/98 §11`).
 */
final class Certificates implements Check
{
    /** Ab wann ein Zertifikat als „läuft demnächst ab" gilt (`docs/98 §7`, Punkt 6). */
    public const EXPIRING_DAYS = 30;

    /** Die Gründe, die diese Prüfung ausspricht — je Schlüssel. */
    public const REASONS = [
        'tls.file' => ['missing', 'expired', 'expiring', 'name_mismatch', FindingCheck::UNREACHABLE],

        // Kein `unreachable`: Dass der Server nicht antwortet, ist hier der
        // gemessene Zustand und keine ausgefallene Messung.
        'tls.wire' => ['not_served', 'no_answer'],
    ];

    public function __construct(
        private readonly Client $agent,
        private readonly CertificateChoice $choice,
        private readonly Wire $wire,
        private readonly Tenancy $tenancy,
    ) {}

    public function writes(): array
    {
        return [FindingCheck::TlsFile, FindingCheck::TlsWire];
    }

    public function run(Carbon $measuredAt, FindingLog $log): void
    {
        $rows = $this->rows();
        $storages = [];

        foreach ($rows as $row) {
            if ($row['storage'] !== null) {
                $storages[$row['storage']] = true;
            }
        }

        try {
            $infos = [];

            foreach (array_keys($storages) as $storage) {
                $infos[$storage] = $this->agent->call('acme.certificate.info', ['name' => $storage]);
            }
        } catch (AgentException $e) {
            // Die Leitung bleibt ungefragt und ihre alten Befunde stehen: Sie
            // sind nicht widerlegt, sondern ungeprüft. `tls.wire` kennt kein
            // `unreachable` — dass der Server nicht antwortet, wäre dort ein
            // gemessener Zustand.
            $log->unreachable(FindingCheck::TlsFile, array_column($rows, 'name'), $measuredAt, $e->getMessage());

            return;
        }

        $verdict = self::judge($rows, $infos, fn (string $name): ?string => $this->wire->fingerprint($name), $measuredAt);

        $log->replace(FindingCheck::TlsFile, $verdict['file'], $measuredAt);
        $log->replace(FindingCheck::TlsWire, $verdict['wire'], $measuredAt);
    }

    /**
     * Das Urteil über alle Domains — ohne Agent und ohne Netz.
     *
     * `$wire` wird **nur** für Domains gerufen, deren Datei in Ordnung ist;
     * `CertificateVerdictTest` zählt die Aufrufe. Das ist Frage 3 aus
     * `docs/98 §9`: Ein abgelaufenes Zertifikat wird auch über die Leitung
     * abgelaufen ausgeliefert, und zwei Befunde für eine Ursache sind die Falle
     * aus §4.
     *
     * @param  list<array{name: string, names: list<string>, storage: null|string}>  $rows
     * @param  array<string, array<string, mixed>>  $infos  je Ablageort die Antwort von `acme.certificate.info`
     * @param  callable(string): ?string  $wire
     * @return array{file: list<array{subject: string, reason: string, detail: null|string}>, wire: list<array{subject: string, reason: string, detail: null|string}>}
     */
    public static function judge(array $rows, array $infos, callable $wire, Carbon $now): array
    {
        $file = [];
        $served = [];

        foreach ($rows as $row) {
            if ($row['storage'] === null) {
                continue;
            }

            $info = $infos[$row['storage']] ?? null;
            $verdict = self::file($row['names'], $info, $now);

            if ($verdict !== null) {
                $file[] = ['subject' => $row['name']] + $verdict;

                continue;
            }

            $stored = $info['fingerprint'] ?? null;

            if (! is_string($stored) || $stored === '') {
                // Ein Programmierfehler und keine Messung: Panel und Agent kommen
                // aus einem Paket. Lieber der Abbruch als ein `not_served` für
                // jede Domain — das wäre eine falsche Auskunft und kein Ausfall.
                throw new \UnexpectedValueException(sprintf(
                    'acme.certificate.info nennt für %s keinen Fingerabdruck — ohne ihn ist die Leitung nicht zu vergleichen.',
                    $row['storage'],
                ));
            }

            $verdict = self::wire($stored, $wire($row['name']));

            if ($verdict !== null) {
                $served[] = ['subject' => $row['name']] + $verdict;
            }
        }

        return ['file' => $file, 'wire' => $served];
    }

    /**
     * Die Frage an die Datei.
     *
     * Reihenfolge: fehlt, abgelaufen, falscher Name, läuft ab. Ein Zertifikat,
     * das den Namen nicht deckt, ist schwerer als eines, das demnächst abläuft
     * — der Browser lehnt es **jetzt** ab.
     *
     * @param  list<string>  $names  die Namen, die die Domain bedient
     * @param  null|array<string, mixed>  $info  die Antwort von `acme.certificate.info`
     * @return null|array{reason: string, detail: null|string}
     */
    public static function file(array $names, ?array $info, Carbon $now): ?array
    {
        if ($info === null || ($info['present'] ?? false) !== true) {
            $reason = $info['reason'] ?? null;

            return ['reason' => 'missing', 'detail' => is_string($reason) ? $reason : null];
        }

        $validTo = (int) ($info['valid_to'] ?? 0);
        $until = 'gültig bis '.gmdate('Y-m-d H:i', $validTo).' UTC';

        if ($validTo <= $now->getTimestamp()) {
            return ['reason' => 'expired', 'detail' => $until];
        }

        $covered = [];

        foreach (is_array($info['names'] ?? null) ? $info['names'] : [] as $covering) {
            if (is_string($covering)) {
                $covered[] = $covering;
            }
        }

        foreach ($names as $name) {
            if (! Certificate::nameCovers($covered, $name)) {
                return [
                    'reason' => 'name_mismatch',
                    'detail' => sprintf('%s steht nicht im Zertifikat (%s)', $name, implode(', ', $covered)),
                ];
            }
        }

        if ($validTo <= $now->getTimestamp() + self::EXPIRING_DAYS * 86400) {
            return ['reason' => 'expiring', 'detail' => $until];
        }

        return null;
    }

    /**
     * Die Frage an die Leitung — der Vergleich, nachdem sie gestellt ist.
     *
     * @return null|array{reason: string, detail: null|string}
     */
    public static function wire(string $stored, ?string $served): ?array
    {
        if ($served === null) {
            // Kein Text als `detail`: Ein Port ohne TLS meldet einen Fehlschlag
            // mit **leerer** Meldung (`docs/81 §2.3o` M23), und eine leere
            // Zeile ist keine Auskunft.
            return ['reason' => 'no_answer', 'detail' => null];
        }

        if (strcasecmp($stored, $served) !== 0) {
            return ['reason' => 'not_served', 'detail' => sprintf('abgelegt %s, ausgeliefert %s', $stored, $served)];
        }

        return null;
    }

    /**
     * Die lebenden Domains mit ihren Namen und dem gewählten Ablageort.
     *
     * Aliasse stehen nicht als eigene Zeile: Sie sind Namen ihrer Eltern
     * (`Domain::serverNames()`) und haben keinen eigenen Block.
     *
     * **Ohne Mandantenklammer, begründet:** Der Nachtlauf hat kein Konto und
     * fragt den ganzen Server — wie `CertificatePrune`, mit denselben Abfragen.
     *
     * @return list<array{name: string, names: list<string>, storage: null|string}>
     */
    private function rows(): array
    {
        /** @var list<array{name: string, names: list<string>, storage: null|string}> $rows */
        $rows = [];

        $this->tenancy->withoutRestriction(function () use (&$rows): void {
            $usable = array_fill_keys(
                Subscription::query()->whereIn('status', SubscriptionStatus::usableValues())->pluck('id')->all(),
                true,
            );

            $domains = Domain::query()
                ->withoutGlobalScopes()
                ->with('children')
                ->where('status', DomainStatus::Active->value)
                ->where('type', '!=', DomainType::Alias->value)
                ->orderBy('id')
                ->get();

            /** @var Domain $domain */
            foreach ($domains as $domain) {
                if (! isset($usable[(int) $domain->subscription_id])) {
                    continue;
                }

                $certificate = $this->choice->effective($domain);

                $rows[] = [
                    'name' => (string) $domain->name,
                    'names' => $domain->serverNames(),
                    'storage' => $certificate?->storage_name,
                ];
            }
        });

        return $rows;
    }
}
