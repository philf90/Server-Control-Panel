<?php

declare(strict_types=1);

/**
 * Drei Rechnungen vor B9 (`docs/142 §2`): Wann meldet die Diagnose ein
 * Zertifikat, das gerade erneuert wird, welchen Grund spricht sie beim Ablauf
 * aus, und wann geht eine Meldung über eine gescheiterte Sicherung hinaus?
 *
 *     php tests/kundenmeldungen-rechnen.php
 *
 * **Gerechnet und nicht auf dem Server gemessen.** Die Zeitgeber sind ein
 * Modell ihrer Units unter `packaging/systemd`: `OnCalendar=daily`, die
 * Streuung aus `RandomizedDelaySec`, je Nacht neu gewürfelt
 * (`FixedRandomDelay=no`, gemessen in `docs/909`). Die Regeln kommen aus dem
 * Bestand und nicht aus einer zweiten Fassung hier: {@see Certificates::expiry()}
 * entscheidet „läuft demnächst ab", {@see CertificateRenewal::due()} den
 * Zeitpunkt der Erneuerung und {@see Notices::HOLD_HOURS} die Haltezeit.
 *
 * **Seit dem Bau von B9 rechnet das Skript gegen den neuen Stand.** Die
 * Tabellen in `docs/142 §3` sind vom 7. Oktober 2026, vor dem Bau: M1 und M3
 * kommen genauso heraus, M2 nicht mehr — dort stand der Befund, den B9 behebt.
 * Die Ausgabe danach steht in `docs/142 §10`.
 *
 * **Die Laufzeit eines Zertifikats ist gemessen**, an einem echten von Let's
 * Encrypt auf `cloudsrv24` (`docs/78`): `notAfter` ist `notBefore` plus
 * 90 Tage minus eine Sekunde, und `notBefore` liegt in der Minute der
 * Ausstellung.
 *
 * **Jede Rechnung hat ihre Gegenprobe.** Eine Erneuerung, die nie gelingt,
 * muss in jedem Versuch zweimal hintereinander gesehen werden, und eine
 * Sicherung, die nie scheitert, in keinem. Steht dort etwas anderes, rechnet
 * das Modell falsch, und seine übrigen Zahlen bedeuten nichts.
 *
 * Braucht `vendor/autoload.php`: Carbon und die Klassen unter `app/`.
 */

use App\Support\Diagnose\Checks\Certificates;
use App\Support\Notify\Notices;
use App\Support\Tls\CertificateRenewal;
use Illuminate\Support\Carbon;

require dirname(__DIR__).'/vendor/autoload.php';

const DAY = 86400;

/** `RandomizedDelaySec=1h` aus `srvpanel-tls.timer` und `srvpanel-diagnose.timer`. */
const SPREAD_NIGHTLY = 3600;

/** `RandomizedDelaySec=2h` aus `srvpanel-backups.timer`. */
const SPREAD_BACKUPS = 7200;

/** Gemessen in `docs/78`: 90 Tage minus eine Sekunde. */
const VALIDITY = 90 * DAY - 1;

/**
 * Eine gesunde Erneuerung über acht Nächte — wie oft sieht die Diagnose
 * „läuft demnächst ab"?
 *
 * `$days` ist die Schwelle der Diagnose: gemeldet wird ab so vielen Tagen
 * Restlaufzeit. `$broken` lässt die Erneuerung nie gelingen — die Gegenprobe.
 *
 * @return array{twice: int, once: int}
 */
function renewal(int $tries, int $duration, int $days, bool $broken): array
{
    mt_srand(20261007);
    $twice = 0;
    $once = 0;

    for ($i = 0; $i < $tries; $i++) {
        // Nacht 0: die vorige Erneuerung, ausgestellt im Lauf von srvpanel-tls.
        $notAfter = mt_rand(0, SPREAD_NIGHTLY - 1) + $duration + VALIDITY;
        $due = CertificateRenewal::due(Carbon::createFromTimestamp($notAfter))?->getTimestamp() ?? PHP_INT_MAX;
        $installed = null;
        $run = 0;
        $longest = 0;

        foreach (range(59, 66) as $night) {
            $tls = $night * DAY + mt_rand(0, SPREAD_NIGHTLY - 1);
            $diagnose = $night * DAY + mt_rand(0, SPREAD_NIGHTLY - 1);

            if (! $broken && $installed === null && $due <= $tls) {
                $installed = $tls + $duration;
            }

            $validTo = $installed !== null && $installed <= $diagnose ? $installed + VALIDITY : $notAfter;
            $info = ['present' => true, 'valid_to' => $validTo, 'names' => ['example.de']];

            $verdict = Certificates::expiry($info, Carbon::createFromTimestamp($diagnose), $days);
            $run = in_array('expiring', array_column($verdict, 'reason'), true) ? $run + 1 : 0;
            $longest = max($longest, $run);
        }

        if ($longest >= 2) {
            $twice++;
        } elseif ($longest === 1) {
            $once++;
        }
    }

    return ['twice' => $twice, 'once' => $once];
}

/**
 * Eine Sicherung, die `$failedNights` Nächte hintereinander scheitert — wann
 * geht die Meldung hinaus, wenn der Befund im Nachtlauf der Diagnose entsteht?
 *
 * Der Befund steht, solange die jüngste fertige Sicherung gescheitert ist.
 * Gemeldet wird im ersten Lauf mit `jetzt − first_seen ≥ $hold` — gleich nach
 * der Diagnose, in derselben Unit.
 *
 * @return array{sent: int, median: float, max: float}
 */
function backup(int $tries, int $duration, int $hold, int $failedNights): array
{
    mt_srand(20261007);
    $sent = 0;
    $delays = [];

    for ($i = 0; $i < $tries; $i++) {
        /** @var array<int, array{0: int, 1: bool}> $finished */
        $finished = [];

        foreach (range(-1, 6) as $night) {
            $finished[$night] = [$night * DAY + mt_rand(0, SPREAD_BACKUPS - 1) + $duration, $night >= 0 && $night < $failedNights];
        }

        $since = null;
        $notice = null;

        foreach (range(0, 6) as $night) {
            $diagnose = $night * DAY + mt_rand(0, SPREAD_NIGHTLY - 1);
            $latestFailed = null;
            $latestAt = PHP_INT_MIN;

            foreach ($finished as [$at, $failed]) {
                if ($at <= $diagnose && $at > $latestAt) {
                    $latestAt = $at;
                    $latestFailed = $failed;
                }
            }

            if ($latestFailed !== true) {
                $since = null;

                continue;
            }

            $since ??= $diagnose;

            if ($notice === null && $diagnose - $since >= $hold) {
                $notice = $diagnose;
            }
        }

        if ($notice !== null) {
            $sent++;
            $delays[] = ($notice - $finished[0][0]) / 3600;
        }
    }

    sort($delays);

    return [
        'sent' => $sent,
        'median' => $delays === [] ? 0.0 : $delays[intdiv(count($delays), 2)],
        'max' => $delays === [] ? 0.0 : $delays[count($delays) - 1],
    ];
}

$tries = 200_000;

echo "M1 · Eine Erneuerung von Let's Encrypt gegen die Diagnose ({$tries} Versuche)\n";

// 30 ist die Schwelle vor B9 und die eines hochgeladenen Zertifikats, die
// gebaute für Let's Encrypt kommt aus der Prüfung selbst.
$gebaut = Certificates::expiringDays(true);

foreach ([[5, 30, false], [60, 30, false], [300, 30, false], [60, 29, false], [60, $gebaut, false], [60, 30, true], [60, $gebaut, true]] as [$duration, $days, $broken]) {
    $r = renewal($tries, $duration, $days, $broken);
    printf(
        "  %-10s Dauer %3d s, gemeldet ab %2d Tagen:  zweimal hintereinander %6.2f %%  nur einmal %6.2f %%\n",
        $broken ? 'kaputt' : 'gesund',
        $duration,
        $days,
        100 * $r['twice'] / $tries,
        100 * $r['once'] / $tries,
    );
}

echo "\nM2 · Welche Gründe Certificates::file() und expiry() wann aussprechen\n";

$notAfter = Carbon::parse('2026-11-22 11:00:17', 'UTC');
$info = ['present' => true, 'valid_to' => $notAfter->getTimestamp(), 'names' => ['example.de']];

/** @param  list<array{reason: string, detail: string}>  $befunde */
$gruende = static fn (array $befunde): string => $befunde === [] ? 'kein Befund' : implode(' + ', array_column($befunde, 'reason'));

foreach (['-35 days', '-29 days', '-1 hour', '+1 hour', '+3 days'] as $shift) {
    $jetzt = $notAfter->copy()->modify($shift);
    printf(
        "  %-9s  hochgeladen: %-18s  Let's Encrypt: %s\n",
        $shift,
        $gruende(Certificates::expiry($info, $jetzt, Certificates::expiringDays(false))),
        $gruende(Certificates::expiry($info, $jetzt, Certificates::expiringDays(true))),
    );
}

$datei = Certificates::file(['example.de', 'www.example.de'], $info);
$zeit = Certificates::expiry($info, $notAfter->copy()->modify('-10 days'), Certificates::expiringDays(false));
printf(
    "  läuft ab und deckt www.example.de nicht:  Datei %s, Zeit %s\n",
    $datei['reason'] ?? 'kein Befund',
    $gruende($zeit),
);

$tries = 100_000;
$hold = Notices::HOLD_HOURS * 3600;

echo "\nM3 · Eine gescheiterte Sicherung gegen die Diagnose ({$tries} Versuche)\n";

foreach ([0, 1, 2, 7] as $failedNights) {
    foreach ([0, $hold] as $h) {
        $r = backup($tries, 60, $h, $failedNights);
        printf(
            "  %d %s gescheitert, Haltezeit %2d h:  gemeldet %6.2f %%  Verzug Median %4.1f h, höchstens %4.1f h\n",
            $failedNights,
            $failedNights === 1 ? 'Nacht' : 'Nächte',
            intdiv($h, 3600),
            100 * $r['sent'] / $tries,
            $r['median'],
            $r['max'],
        );
    }
}
