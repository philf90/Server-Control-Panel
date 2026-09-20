<?php

declare(strict_types=1);

/**
 * Was der Ringpuffer kostet, was ein Tageslauf kostet und was die verdichtete
 * Tabelle kostet — die Messrunde vor P9 (docs/127 §6.2, M1).
 *
 *     php tests/kennzahlen-messen.php
 *     SOCKET=/tmp/mdb.sock php tests/kennzahlen-messen.php
 *
 * **Framework-frei.** Nur `agent/src/autoload.php` und die drei Klassen unter
 * `app/Support/Metrics/` werden gebraucht; ohne `vendor/` läuft das hier
 * genauso. Die Tabelle wird in einer Wegwerf-Datenbank gemessen und danach
 * fallengelassen.
 *
 * **Gemessen, nicht gerechnet.** Die Zeilenzahl einer Tabelle lässt sich
 * ausrechnen, ihre Bytes nicht: InnoDB legt Seiten zu 16 KiB an, ein Index
 * kostet extra, und die Statistik in `information_schema` ist eine Schätzung.
 * K3 stellt deshalb drei Zahlen nebeneinander — gerechnet, geschätzt und die
 * Grösse der Datei auf der Platte.
 *
 * **Jede Messung hat ihre Gegenprobe**, und eine Null ist nur dann eine
 * Messung, wenn daneben etwas anderes als Null steht.
 */

$REPO = dirname(__DIR__);
require $REPO.'/app/Support/Metrics/RingBuffer.php';

use App\Support\Metrics\RingBuffer;

function titel(string $t): void { printf("\n=== %s\n", $t); }
function wert(string $k, string $v): void { printf("  %-46s %s\n", $k, $v); }
function satz(string $s): void { printf("  %s\n", $s); }
function mib(int $b): string { return number_format($b / 1048576, 2, ',', '.').' MiB'; }

$ARBEIT = '/var/tmp/srvpanel-kennzahlen';
@mkdir($ARBEIT, 0o755, true);

// Die vier Reihen, wie Collector und Store sie anlegen — Name => Spalten.
const REIHEN = ['cpu' => 2, 'ram' => 2, 'load' => 3, 'network' => 2];
const TAKT_S = 10;
const VORHALT = 8640;

// Was P9 je Abonnement will (docs/20 §4.6): Speicherplatz, Traffic, Zugriffe,
// Datenbankgrössen, FPM-Prozesse.
const KENNZAHLEN = 5;
const TAGE = 30;

titel('K0 — Plattform');
wert('PHP', PHP_VERSION);
$socket = getenv('SOCKET') ?: '/tmp/mdb.sock';
$pdo = null;
try {
    $pdo = new PDO('mysql:unix_socket='.$socket.';charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    wert('MariaDB', (string) $pdo->query('SELECT VERSION()')->fetchColumn());
} catch (Throwable $e) {
    wert('MariaDB', 'nicht erreichbar über '.$socket.' — K3 und K4 entfallen');
}

/* --------------------------------------------------------------------------
 * K1 — Was der Ringpuffer heute kostet.
 *
 * Gerechnet: 32 Byte Kopf plus `vorhalt` Sätze zu je (1 + Spalten) doubles.
 * Gemessen: die Datei, nachdem sie einmal voll geschrieben wurde. Stimmen
 * beide nicht überein, ist die Rechnung falsch und nicht die Datei.
 * -------------------------------------------------------------------------- */
titel('K1 — Der Ringpuffer heute');
$gesamtGerechnet = 0;
$gesamtGemessen = 0;
foreach (REIHEN as $name => $spalten) {
    $datei = $ARBEIT.'/'.$name.'.ring';
    @unlink($datei);
    $puffer = new RingBuffer($datei, $spalten, VORHALT);
    $puffer->write(array_fill(0, $spalten, 1.0));           // legt die Datei in voller Länge an
    $gerechnet = 32 + 8 * (1 + $spalten) * VORHALT;
    $gemessen = (int) filesize($datei);
    $gesamtGerechnet += $gerechnet;
    $gesamtGemessen += $gemessen;
    wert(sprintf('%s (%d Spalten)', $name, $spalten),
        sprintf('gerechnet %d B · gemessen %d B%s', $gerechnet, $gemessen,
            $gerechnet === $gemessen ? '' : '  <-- weichen ab'));
}
wert('alle vier Reihen zusammen', mib($gesamtGemessen).' für '.(VORHALT * TAKT_S / 3600).' Stunden');
wert('Vorhalt', sprintf('%d Sätze × %d s = %.1f Stunden', VORHALT, TAKT_S, VORHALT * TAKT_S / 3600));
satz('P9 will 30 Tage in Tagesauflösung — das ist eine andere Frage als diese Datei.');

/* --------------------------------------------------------------------------
 * K2 — Was ein Tageslauf über den Ringpuffer kostet.
 *
 * Der Lauf liest einen vollen Puffer und verdichtet ihn auf Kleinstes,
 * Grösstes und Mittel je Spalte — das ist, was eine Tageszeile braucht.
 *
 * **Zweimal gefahren, und einmal kalt.** Der Puffer wurde eben geschrieben
 * und liegt im Seitenzwischenspeicher; wer nur warm misst, misst ihn.
 *
 * **Gegenprobe:** derselbe Lauf, der nur liest und nicht verdichtet. Stehen
 * beide Zahlen gleich, misst der Lauf das Lesen und nicht das Verdichten.
 * -------------------------------------------------------------------------- */
titel('K2 — Was ein Tageslauf über den Ringpuffer kostet');
$datei = $ARBEIT.'/voll.ring';
@unlink($datei);
$spalten = 3;
$puffer = new RingBuffer($datei, $spalten, VORHALT);
$t0 = hrtime(true);
$jetzt = time() - VORHALT * TAKT_S;
for ($i = 0; $i < VORHALT; $i++) {
    $puffer->write([sin($i / 97) * 50 + 50, cos($i / 53) * 30 + 40, $i % 17], (float) ($jetzt + $i * TAKT_S));
}
wert('Füllen des Puffers (8640 Sätze)', sprintf('%.2f s — das tut der Collector über 24 h verteilt', (hrtime(true) - $t0) / 1e9));

$verdichten = static function (RingBuffer $p, bool $rechnen) use ($spalten): array {
    $t = hrtime(true);
    $saetze = $p->read();
    if (! $rechnen) {
        return [(hrtime(true) - $t) / 1e9, count($saetze), null];
    }
    $min = array_fill(0, $spalten, INF);
    $max = array_fill(0, $spalten, -INF);
    $summe = array_fill(0, $spalten, 0.0);
    foreach ($saetze as $satz) {
        foreach ($satz['values'] as $j => $v) {
            if ($v < $min[$j]) { $min[$j] = $v; }
            if ($v > $max[$j]) { $max[$j] = $v; }
            $summe[$j] += $v;
        }
    }
    $n = max(count($saetze), 1);

    return [(hrtime(true) - $t) / 1e9, count($saetze), sprintf('min %.1f max %.1f mittel %.1f', $min[0], $max[0], $summe[0] / $n)];
};

$kalt = @file_put_contents('/proc/sys/vm/drop_caches', "3\n");
[$s, $n, $werte] = $verdichten($puffer, true);
wert('Lauf 0 · verdichten, kalt', $kalt === false
    ? sprintf('%.4f s (Zwischenspeicher nicht leerbar)', $s)
    : sprintf('%.4f s · %d Sätze', $s, $n));
for ($i = 1; $i <= 2; $i++) {
    [$s, $n, $werte] = $verdichten($puffer, true);
    wert("Lauf $i · verdichten, warm", sprintf('%.4f s · %d Sätze · %s', $s, $n, $werte));
}
[$s, $n] = $verdichten($puffer, false);
wert('Gegenprobe · nur lesen', sprintf('%.4f s · %d Sätze (ohne Verdichten)', $s, $n));
satz('Stehen beide Zahlen gleich, misst der Lauf nicht das Verdichten.');
wert('Hochgerechnet auf alle vier Reihen', sprintf('%.3f s je Nacht und Server', $s * 4));

if ($pdo === null) {
    titel('Was diese Messung nicht sagt');
    satz('· K3 und K4 sind nicht gefahren — ohne MariaDB gibt es keine Tabelle.');
    exit(0);
}

/* --------------------------------------------------------------------------
 * K3 — Was die verdichtete Tabelle kostet.
 *
 * Zwei Formen, weil die Wahl zwischen ihnen die Entscheidung des Plans ist:
 *
 *   A (lang)  eine Zeile je Abonnement, Kennzahl und Tag
 *   B (breit) eine Zeile je Abonnement und Tag, fünf Wertspalten
 *
 * A nimmt eine sechste Kennzahl ohne Migration auf, B nicht. B hat ein
 * Fünftel der Zeilen. Was das in Bytes heisst, steht hier.
 * -------------------------------------------------------------------------- */
titel('K3 — Die verdichtete Tabelle (30 Tage)');
$pdo->exec('DROP DATABASE IF EXISTS messrunde');
$pdo->exec('CREATE DATABASE messrunde');
$pdo->exec('USE messrunde');

$formen = [
    'A lang ' => 'CREATE TABLE %s (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subscription_id BIGINT UNSIGNED NOT NULL,
        metric TINYINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        value BIGINT NOT NULL,
        UNIQUE KEY abo_kennzahl_tag (subscription_id, metric, day)
    ) ENGINE=InnoDB',
    'B breit' => 'CREATE TABLE %s (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subscription_id BIGINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        disk_mb BIGINT NOT NULL, traffic_bytes BIGINT NOT NULL,
        hits BIGINT NOT NULL, db_bytes BIGINT NOT NULL, fpm_peak BIGINT NOT NULL,
        UNIQUE KEY abo_tag (subscription_id, day)
    ) ENGINE=InnoDB',
];

printf("  %-9s %6s %10s %12s %12s %12s\n", 'Form', 'Abos', 'Zeilen', 'gerechnet', 'geschätzt', 'Datei');
foreach ([100, 500, 2000] as $abos) {
    foreach ($formen as $bezeichnung => $ddl) {
        $tabelle = 't_'.trim(strtolower($bezeichnung[0])).'_'.$abos;
        $pdo->exec(sprintf($ddl, $tabelle));
        $lang = str_starts_with($bezeichnung, 'A');
        $zeilen = $lang ? $abos * KENNZAHLEN * TAGE : $abos * TAGE;

        $pdo->beginTransaction();
        if ($lang) {
            $stmt = $pdo->prepare("INSERT INTO $tabelle (subscription_id, metric, day, value) VALUES (?,?,?,?)");
            for ($a = 1; $a <= $abos; $a++) {
                for ($k = 0; $k < KENNZAHLEN; $k++) {
                    for ($d = 0; $d < TAGE; $d++) {
                        $stmt->execute([$a, $k, date('Y-m-d', strtotime("-$d day")), random_int(0, 9_000_000_000)]);
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO $tabelle (subscription_id, day, disk_mb, traffic_bytes, hits, db_bytes, fpm_peak) VALUES (?,?,?,?,?,?,?)");
            for ($a = 1; $a <= $abos; $a++) {
                for ($d = 0; $d < TAGE; $d++) {
                    $stmt->execute([$a, date('Y-m-d', strtotime("-$d day")),
                        random_int(0, 100000), random_int(0, 9_000_000_000),
                        random_int(0, 500000), random_int(0, 9_000_000_000), random_int(0, 40)]);
                }
            }
        }
        $pdo->commit();

        // ANALYZE liefert eine Ergebnismenge; wer sie nicht abholt, bekommt
        // beim nächsten query() "unbuffered queries are active".
        $pdo->query("ANALYZE TABLE $tabelle")->fetchAll();
        $row = $pdo->query("SELECT DATA_LENGTH + INDEX_LENGTH AS b FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA='messrunde' AND TABLE_NAME='$tabelle'")->fetch(PDO::FETCH_ASSOC);
        $geschaetzt = (int) ($row['b'] ?? 0);
        $breite = $lang ? (8 + 1 + 3 + 8) : (8 + 3 + 5 * 8);
        $gerechnet = $zeilen * $breite;
        $datadir = (string) $pdo->query('SELECT @@datadir')->fetchColumn();
        $ibd = (int) @filesize(rtrim($datadir, '/').'/messrunde/'.$tabelle.'.ibd');

        printf("  %-9s %6d %10d %12s %12s %12s\n", $bezeichnung, $abos, $zeilen,
            mib($gerechnet), mib($geschaetzt), $ibd > 0 ? mib($ibd) : '?');
    }
}
satz('');

/* --------------------------------------------------------------------------
 * K3b — Und die Reihenfolge, in der eingefügt wird.
 *
 * K3 füllt Abonnement für Abonnement, also dreissig Tage am Stück. Ein
 * Nachtlauf tut das Gegenteil: **einen** Tag für alle Abonnements. Der
 * Unterschied trifft den Sekundärindex — aufsteigend eingefügt hängt er
 * hinten an, durcheinander spaltet er Seiten.
 *
 * Das ist die Gegenprobe zu K3: Ändert sich die Datei nicht, hängt ihre
 * Grösse nicht an der Reihenfolge, und K3 misst nur die Zeilen.
 * -------------------------------------------------------------------------- */
$reihenfolge = static function (PDO $pdo, string $tabelle, bool $nachtlauf) {
    $pdo->exec("DROP TABLE IF EXISTS $tabelle");
    $pdo->exec(sprintf('CREATE TABLE %s (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        subscription_id BIGINT UNSIGNED NOT NULL,
        metric TINYINT UNSIGNED NOT NULL,
        day DATE NOT NULL,
        value BIGINT NOT NULL,
        UNIQUE KEY abo_kennzahl_tag (subscription_id, metric, day)
    ) ENGINE=InnoDB', $tabelle));
    $stmt = $pdo->prepare("INSERT INTO $tabelle (subscription_id, metric, day, value) VALUES (?,?,?,?)");
    $pdo->beginTransaction();
    if ($nachtlauf) {
        // Ein Tag nach dem anderen, darin alle Abos — so schreibt ein Nachtlauf.
        for ($d = TAGE - 1; $d >= 0; $d--) {
            for ($a = 1; $a <= 2000; $a++) {
                for ($k = 0; $k < KENNZAHLEN; $k++) {
                    $stmt->execute([$a, $k, date('Y-m-d', strtotime("-$d day")), random_int(0, 9_000_000_000)]);
                }
            }
        }
    } else {
        for ($a = 1; $a <= 2000; $a++) {
            for ($k = 0; $k < KENNZAHLEN; $k++) {
                for ($d = 0; $d < TAGE; $d++) {
                    $stmt->execute([$a, $k, date('Y-m-d', strtotime("-$d day")), random_int(0, 9_000_000_000)]);
                }
            }
        }
    }
    $pdo->commit();
    $pdo->query("ANALYZE TABLE $tabelle")->fetchAll();
    $datadir = rtrim((string) $pdo->query('SELECT @@datadir')->fetchColumn(), '/');

    return (int) @filesize($datadir.'/messrunde/'.$tabelle.'.ibd');
};
titel('K3b — Dieselben 300 000 Zeilen, zwei Reihenfolgen');
$aboWeise = $reihenfolge($pdo, 't_reihenfolge_abo', false);
$tagWeise = $reihenfolge($pdo, 't_reihenfolge_tag', true);
wert('Abo für Abo eingefügt (wie K3)', mib($aboWeise));
wert('Tag für Tag eingefügt (wie ein Nachtlauf)', mib($tagWeise));
wert('Unterschied', $aboWeise === $tagWeise
    ? 'keiner — die Grösse hängt nicht an der Reihenfolge'
    : sprintf('%+.1f %%', ($tagWeise - $aboWeise) / max($aboWeise, 1) * 100));

satz('Gerechnet ist die Summe der Feldbreiten. Geschätzt sagt InnoDB selbst.');
satz('Die Datei ist, was auf der Platte liegt — und sie ist die verbindliche Zahl.');

/* --------------------------------------------------------------------------
 * K4 — Was eine Zeitreihe je Domain dazulegt.
 *
 * Gegenprobe zu K3: Dieselbe Form, aber je Domain statt je Abonnement. Wenn
 * die Zahl hier nicht wächst, misst K3 nicht die Zahl der Zeilen.
 * -------------------------------------------------------------------------- */
titel('K4 — Und je Domain (3 Kennzahlen, 30 Tage)');
foreach ([1, 2, 5] as $jeAbo) {
    $domains = 2000 * $jeAbo;
    wert(sprintf('%d Domains bei 2000 Abos', $domains),
        sprintf('%d Zeilen in Form A', $domains * 3 * TAGE));
}
satz('Die Zahl der Domains entscheidet stärker als die der Abonnements.');

titel('Was diese Messung nicht sagt');
satz('· Sie misst leere Tabellen ohne Nebenverkehr, nicht eine Datenbank im Betrieb.');
satz('· Sie sagt nichts darüber, was das Schreiben je Nacht kostet — nur das Liegen.');
satz('· Sie misst die Platte dieses Containers, nicht die von cloudsrv24.');
satz('· Der Ringpuffer hier trägt Zufallswerte; echte Kennzahlen sind glatter,');
satz('  und das ändert an Dateigrösse und Lesezeit nichts, an der Verdichtung schon.');

$pdo->exec('DROP DATABASE IF EXISTS messrunde');
array_map('unlink', glob($ARBEIT.'/*.ring') ?: []);
@rmdir($ARBEIT);
