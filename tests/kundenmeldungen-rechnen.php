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
 * Bestand und nicht aus einer zweiten Fassung hier: {@see Certificates::file()}
 * entscheidet „läuft demnächst ab", {@see CertificateRenewal::due()} den
 * Zeitpunkt der Erneuerung und {@see Notices::HOLD_HOURS} die Haltezeit.
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
 * `$lag` verschiebt die Schwelle der Diagnose hinter die der Erneuerung:
 * gemeldet wird dann ab `30 − $lag` Tagen Restlaufzeit. `$broken` lässt die
 * Erneuerung nie gelingen — die Gegenprobe.
 *
 * @return array{twice: int, once: int}
 */
function renewal(int $tries, int $duration, int $lag, bool $broken): array
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

            // Valid_to <= (jetzt − Versatz) + 30 Tage heisst: gemeldet ab 30 − Versatz.
            $verdict = Certificates::file(['example.de'], $info, Carbon::createFromTimestamp($diagnose - $lag * DAY));
            $run = ($verdict['reason'] ?? null) === 'expiring' ? $run + 1 : 0;
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

foreach ([[5, 0, false], [60, 0, false], [300, 0, false], [60, 1, false], [60, 2, false], [60, 0, true], [60, 2, true]] as [$duration, $lag, $broken]) {
    $r = renewal($tries, $duration, $lag, $broken);
    printf(
        "  %-10s Dauer %3d s, gemeldet ab %2d Tagen:  zweimal hintereinander %6.2f %%  nur einmal %6.2f %%\n",
        $broken ? 'kaputt' : 'gesund',
        $duration,
        30 - $lag,
        100 * $r['twice'] / $tries,
        100 * $r['once'] / $tries,
    );
}

echo "\nM2 · Welchen Grund spricht Certificates::file() wann aus\n";

$notAfter = Carbon::parse('2026-11-22 11:00:17', 'UTC');
$info = ['present' => true, 'valid_to' => $notAfter->getTimestamp(), 'names' => ['example.de']];

foreach (['-35 days', '-29 days', '-1 hour', '+1 hour', '+3 days'] as $shift) {
    $verdict = Certificates::file(['example.de'], $info, $notAfter->copy()->modify($shift));
    printf("  %-9s  %s\n", $shift, $verdict === null ? 'kein Befund' : $verdict['reason']);
}

$verdict = Certificates::file(['example.de', 'www.example.de'], $info, $notAfter->copy()->modify('-10 days'));
printf("  läuft ab und deckt www.example.de nicht:  %s\n", $verdict['reason'] ?? 'kein Befund');

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
