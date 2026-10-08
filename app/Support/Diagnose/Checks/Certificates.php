<?php

declare(strict_types=1);

namespace App\Support\Diagnose\Checks;

use App\Enums\CertificateSource;
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
use App\Support\Tls\CertificateRenewal;
use Illuminate\Support\Carbon;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * E · Zertifikate — `tls.file`, `tls.expiry` und `tls.wire` (`docs/98 §3 E`,
 * `docs/142`).
 *
 * ## Drei Fragen und nicht eine
 *
 * **An die Datei:** Liegt sie da, und steht jeder Name der Domain im
 * `subjectAltName`? Das beantwortet `acme.certificate.info` mit
 * `openssl_x509_parse`, wie `PanelTlsInfo` seit P4 — je Ablageort einmal, auch
 * wenn zehn Domains ihn teilen. **An die Zeit:** Wie lange gilt sie noch? Das
 * steht in derselben Antwort. **An die Leitung:** Liefert der Server sie für
 * diesen Namen auch aus? Das fragt {@see Wire} mit SNI; ohne SNI käme der
 * Vorgabeblock zurück und damit ein gültig aussehendes Zertifikat mit dem
 * falschen Namen (`docs/78`, `docs/81 §2.3o` M18).
 *
 * **Die Zeit ist eine eigene Frage seit B9** (`docs/142 §2`, Befund 3). Bis zum
 * 7. Oktober 2026 standen `expiring` und `expired` als Gründe unter
 * `tls.file`, und die Gründe dort schlossen einander aus: Ein Zertifikat, das
 * abläuft und einen Namen nicht deckt, hiess nur `name_mismatch`, und beim
 * Ablauf löste `expired` den Befund `expiring` ab — der Webhook bekam eine
 * Entwarnung in dem Augenblick, in dem es schlimmer wurde. Seitdem stehen
 * `expiring` und `expired` nebeneinander, wie die Vorwarnung des Platzes neben
 * „ausgeschöpft" (`docs/141 §0` Befund 7). Und nur diese Frage geht den Kunden
 * an; die übrigen beiden sind Zustände des Servers.
 *
 * **Die Leitung wird nur gefragt, wenn Datei und Zeit in Ordnung sind** — Frage
 * 3 in `docs/98 §9`, entschieden mit c. Ein abgelaufenes Zertifikat wird auch
 * über die Leitung abgelaufen ausgeliefert; zwei Befunde für eine Ursache wären
 * die Falle aus §4. Verglichen wird der **Fingerabdruck** und nicht das
 * Ablaufdatum: Zwei Zertifikate für denselben Namen tragen dasselbe Ablaufdatum,
 * sobald sie in derselben Stunde ausgestellt wurden. Und nicht die
 * Seriennummer — die ist nur je Aussteller eindeutig, und dieses Panel erzeugt
 * selbstsignierte Zertifikate (`docs/81 §2.3o` M23).
 *
 * ## Ab wann eines „demnächst" abläuft, hängt an seiner Herkunft
 *
 * **Eines von Let's Encrypt erneuert das Panel selbst**, ab
 * {@see CertificateRenewal::LEAD_DAYS} Tagen Restlaufzeit. Gewarnt wird erst
 * {@see self::RENEWAL_NIGHTS} Nächte danach, entschieden vom Betreiber am
 * 7. Oktober 2026 (`docs/142 §6`, Frage 2). Bei derselben Schwelle meldete
 * etwa jede zwölfte **gelungene** Erneuerung ein ablaufendes Zertifikat: Beide
 * Zeitgeber würfeln ihre Stunde jede Nacht neu, und lief die Diagnose zweimal
 * vor der Erneuerung, stand der Befund zwei Läufe lang da (`docs/142 §3` M1,
 * gerechnet in `tests/kundenmeldungen-rechnen.php`).
 *
 * > **Eine Warnung, die am selben Tag anschlägt wie ihre Abhilfe, meldet jedes
 * > Mal, wenn ihr Zeitgeber zuerst läuft.**
 *
 * **Ein hochgeladenes erneuert niemand**; es wird wie bisher ab
 * {@see self::EXPIRING_DAYS} Tagen gemeldet. Zwei Tage später zu warnen nähme
 * dem Kunden zwei Tage, in denen er ein neues besorgen kann, und gewönne
 * nichts.
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
    /**
     * Ab wann ein Zertifikat, das niemand erneuert, als „läuft demnächst ab"
     * gilt (`docs/98 §7`, Punkt 6).
     */
    public const EXPIRING_DAYS = 30;

    /**
     * Wie viele Nächte nach der fälligen Erneuerung ein Zertifikat von Let's
     * Encrypt als „läuft demnächst ab" gilt (`docs/142 §6`, Frage 2).
     *
     * **Zwei und nicht eine.** Der Zeitgeber der Erneuerung streut um eine
     * Stunde, der der Diagnose ebenso, und jede Nacht wird neu gewürfelt
     * (`FixedRandomDelay=no`, gemessen in `docs/909`). Eine Nacht Abstand
     * liesse die Diagnose noch vor dem ersten Versuch laufen, der nach der
     * Fälligkeit kommt. `CertificateCadenceTest` rechnet den Abstand aus den
     * beiden Units nach.
     */
    public const RENEWAL_NIGHTS = 2;

    /**
     * Der Satz der Zeit an einem Befund — in UTC und mit seiner Zone.
     *
     * Eine Stelle für das Schreiben in {@see self::expiry()} und das Lesen in
     * {@see self::validUntil()}; zwei Fassungen des Formats liefen
     * auseinander, und dann fände die Mail keinen Zeitpunkt mehr.
     */
    private const UNTIL = 'gültig bis %s UTC';

    /** Die Gründe, die diese Prüfung ausspricht — je Schlüssel. */
    public const REASONS = [
        'tls.file' => ['missing', 'name_mismatch', FindingCheck::UNREACHABLE],

        // Kein `unreachable`: Die Zeit steht in derselben Antwort wie die
        // Datei, und fehlt die Antwort, sagt `tls.file` es für beide.
        'tls.expiry' => ['expiring', 'expired'],

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
        return [FindingCheck::TlsFile, FindingCheck::TlsExpiry, FindingCheck::TlsWire];
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
            // Zeit und Leitung bleiben ungefragt und ihre alten Befunde stehen:
            // Sie sind nicht widerlegt, sondern ungeprüft. `tls.wire` kennt
            // kein `unreachable` — dass der Server nicht antwortet, wäre dort
            // ein gemessener Zustand —, und `tls.expiry` braucht keines,
            // weil die Zeit aus derselben Antwort käme.
            $log->unreachable(FindingCheck::TlsFile, array_column($rows, 'name'), $measuredAt, $e->getMessage());

            return;
        }

        $verdict = self::judge($rows, $infos, fn (string $name): ?string => $this->wire->fingerprint($name), $measuredAt);

        $log->replace(FindingCheck::TlsFile, $verdict['file'], $measuredAt);
        $log->replace(FindingCheck::TlsExpiry, $verdict['expiry'], $measuredAt);
        $log->replace(FindingCheck::TlsWire, $verdict['wire'], $measuredAt);
    }

    /**
     * Das Urteil über alle Domains — ohne Agent und ohne Netz.
     *
     * `$wire` wird **nur** für Domains gerufen, deren Datei und Zeit in Ordnung
     * sind; `CertificateVerdictTest` zählt die Aufrufe. Das ist Frage 3 aus
     * `docs/98 §9`: Ein abgelaufenes Zertifikat wird auch über die Leitung
     * abgelaufen ausgeliefert, und zwei Befunde für eine Ursache sind die Falle
     * aus §4.
     *
     * **Datei und Zeit werden dagegen beide gefragt**, auch wenn die erste
     * schon einen Befund hat: Ein Zertifikat, das einen Namen nicht deckt und
     * abläuft, sind zwei Auskünfte an zwei Empfänger. Nur `missing` lässt die
     * Zeit aus — ohne Datei gibt es keine.
     *
     * @param  list<array{name: string, names: list<string>, storage: null|string, renewed: bool}>  $rows
     * @param  array<string, array<string, mixed>>  $infos  je Ablageort die Antwort von `acme.certificate.info`
     * @param  callable(string): ?string  $wire
     * @return array{file: list<array{subject: string, reason: string, detail: null|string}>, expiry: list<array{subject: string, reason: string, detail: null|string}>, wire: list<array{subject: string, reason: string, detail: null|string}>}
     */
    public static function judge(array $rows, array $infos, callable $wire, Carbon $now): array
    {
        $file = [];
        $expiry = [];
        $served = [];

        foreach ($rows as $row) {
            if ($row['storage'] === null) {
                continue;
            }

            $info = $infos[$row['storage']] ?? null;
            $verdict = self::file($row['names'], $info);
            $zeit = self::expiry($info, $now, self::expiringDays($row['renewed']));

            if ($verdict !== null) {
                $file[] = ['subject' => $row['name']] + $verdict;
            }

            foreach ($zeit as $befund) {
                $expiry[] = ['subject' => $row['name']] + $befund;
            }

            if ($verdict !== null || $zeit !== []) {
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

        return ['file' => $file, 'expiry' => $expiry, 'wire' => $served];
    }

    /**
     * Ab wie vielen Tagen Restlaufzeit ein Zertifikat als „läuft demnächst
     * ab" gilt — je nachdem, ob das Panel es selbst erneuert.
     *
     * Der Grund für die beiden Zahlen steht im Kopf dieser Klasse.
     */
    public static function expiringDays(bool $renewed): int
    {
        return $renewed
            ? CertificateRenewal::LEAD_DAYS - self::RENEWAL_NIGHTS
            : self::EXPIRING_DAYS;
    }

    /**
     * Die Frage an die Datei: Liegt sie da, und deckt sie die Namen?
     *
     * Wie lange sie gilt, fragt {@see self::expiry()}.
     *
     * @param  list<string>  $names  die Namen, die die Domain bedient
     * @param  null|array<string, mixed>  $info  die Antwort von `acme.certificate.info`
     * @return null|array{reason: string, detail: null|string}
     */
    public static function file(array $names, ?array $info): ?array
    {
        if ($info === null || ($info['present'] ?? false) !== true) {
            $reason = $info['reason'] ?? null;

            return ['reason' => 'missing', 'detail' => is_string($reason) ? $reason : null];
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

        return null;
    }

    /**
     * Die Frage an die Zeit: Wie lange gilt das Zertifikat noch?
     *
     * **`expiring` bleibt neben `expired` stehen.** Ein abgelaufenes
     * Zertifikat hat beide Befunde, und die Mail nennt den schwereren. Löste
     * `expired` den anderen ab, meldete der Webhook „erledigt" für „läuft
     * demnächst ab" in dem Augenblick, in dem es schlimmer wird.
     *
     * **Der Zeitpunkt steht in UTC und mit seiner Zone**, wie seit A10. Diese
     * Funktion fragt keine Einstellung und keinen Speicher — sie rechnet, und
     * `tests/kundenmeldungen-rechnen.php` fährt sie ohne Framework. Die Mail an
     * den Kunden nennt die Anzeigezone; sie liest den Zeitpunkt am Zertifikat
     * und nicht aus diesem Satz.
     *
     * @param  null|array<string, mixed>  $info  die Antwort von `acme.certificate.info`
     * @param  int  $days  ab wie vielen Tagen Restlaufzeit gewarnt wird ({@see self::expiringDays()})
     * @return list<array{reason: string, detail: string}>
     */
    public static function expiry(?array $info, Carbon $now, int $days): array
    {
        if ($info === null || ($info['present'] ?? false) !== true) {
            // Ohne Datei keine Zeit — das sagt `missing` an der Datei.
            return [];
        }

        $validTo = (int) ($info['valid_to'] ?? 0);
        $until = sprintf(self::UNTIL, gmdate('Y-m-d H:i', $validTo));
        $befunde = [];

        if ($validTo <= $now->getTimestamp() + $days * 86400) {
            $befunde[] = ['reason' => 'expiring', 'detail' => $until];
        }

        if ($validTo <= $now->getTimestamp()) {
            $befunde[] = ['reason' => 'expired', 'detail' => $until];
        }

        return $befunde;
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
     * Der Zeitpunkt aus dem Satz, den {@see self::expiry()} an einen Befund
     * schreibt — `null`, wenn der Satz ein anderer ist.
     *
     * **Gelesen wird der Satz und nicht die Zeile in der Datenbank.** Er ist
     * das, was die Prüfung beurteilt hat: die Datei auf der Platte. Die Mail
     * an den Kunden nennt ihn in der Anzeigezone; nähme sie `not_after` aus
     * dem Bestand, stünde dort ein anderer Tag, sobald Datei und Zeile
     * auseinanderlaufen — und genau dann kommt die Mail.
     */
    public static function validUntil(?string $detail): ?Carbon
    {
        $muster = '/^'.str_replace('%s', '(\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2})', preg_quote(self::UNTIL, '/')).'$/uD';

        if ($detail === null || preg_match($muster, $detail, $treffer) !== 1) {
            return null;
        }

        try {
            $zeitpunkt = Carbon::createFromFormat('!Y-m-d H:i', $treffer[1], 'UTC');
        } catch (\Throwable) {
            return null;
        }

        // PHP rechnet einen 45. Tag in den nächsten Monat weiter, statt
        // abzulehnen — zurückgeschrieben muss derselbe Text herauskommen.
        return $zeitpunkt instanceof Carbon && $zeitpunkt->format('Y-m-d H:i') === $treffer[1] ? $zeitpunkt : null;
    }

    /**
     * Die lebenden Domains mit ihren Namen, dem gewählten Ablageort und ob das
     * Panel ihr Zertifikat selbst erneuert.
     *
     * Aliasse stehen nicht als eigene Zeile: Sie sind Namen ihrer Eltern
     * (`Domain::serverNames()`) und haben keinen eigenen Block.
     *
     * **Erneuert wird, was von Let's Encrypt kommt** — dieselbe Bedingung, mit
     * der {@see CertificateRenewal} seine Kandidaten wählt. Ob die nächste
     * Erneuerung gelingen kann, fragt diese Zeile nicht: Eine, die scheitert,
     * meldet sich nach zwei Nächten, und genau das soll sie.
     *
     * **Ohne Mandantenklammer, begründet:** Der Nachtlauf hat kein Konto und
     * fragt den ganzen Server — wie `CertificatePrune`, mit denselben Abfragen.
     *
     * @return list<array{name: string, names: list<string>, storage: null|string, renewed: bool}>
     */
    private function rows(): array
    {
        /** @var list<array{name: string, names: list<string>, storage: null|string, renewed: bool}> $rows */
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
                    'renewed' => $certificate?->source === CertificateSource::Acme,
                ];
            }
        });

        return $rows;
    }
}
