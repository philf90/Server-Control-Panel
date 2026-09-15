<?php

declare(strict_types=1);

/*
 * Die Messrunde vor P8 — Sicherungen und Wiederherstellung.
 *
 * **Warum das im Repo liegt und nicht im Sitzungsverlauf.** In `docs/45`,
 * `docs/48`, `docs/59` und `docs/84` steckte die Mehrheit der Befunde im
 * Prüfmittel und nicht im Prüfling. Seit `tests/bilder-messen.js` als geprüfte
 * Vorschrift danebenliegt, ist das Verhältnis gekippt (`docs/66`):
 *
 * > **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht
 * > noch einmal.**
 *
 * **Jede Messung nennt ihre Gegenprobe.** Eine Null ist nur dann eine Messung,
 * wenn daneben etwas anderes als Null steht. Wo eine Messung hier eine Grenze
 * bestätigt, steht daneben ein Fall, der sie reisst; wo sie ein „bleibt
 * erhalten" meldet, steht daneben ein Weg, der es verliert.
 *
 * **Jede Messung sagt, was sie NICHT sagt.** Der Abschnitt `nicht()` je Messung
 * ist kein Beiwerk — `docs/100` und `docs/113` sind an Sätzen teuer geworden,
 * die mehr behaupteten als der Prüfkörper hergab.
 *
 * Aufruf:
 *
 *     php tests/sicherung-messen.php            # alles, was hier geht
 *     php tests/sicherung-messen.php m1 m6      # nur einzelne
 *
 * **M3 und M5 brauchen Laravel.** Fehlt `vendor/autoload.php`, fallen sie mit
 * einem genannten Grund aus und melden sich nicht als Null — „nicht gemessen"
 * und „nichts gefunden" sind zwei Sätze. Der Weg zu `vendor/` steht in
 * `CLAUDE.md` unter „Diese Umgebung".
 *
 * **Was dieses Skript grundsätzlich nicht kann**, steht bei M2: Dieser
 * Container kann keine Quota erzwingen (der Kernel kennt das Format nicht, und
 * ein Dateisystem mit der ext4-eigenen Quota lässt sich gar nicht einhängen —
 * beides gemessen). Die Zustände mit erzwungener Quota gehören auf den Server.
 */

require __DIR__.'/../agent/src/autoload.php';

use App\Models\SystemUser;
use App\Support\Subscriptions\Lifecycle;
use Illuminate\Contracts\Console\Kernel;
use SrvPanel\Agent\Connection;
use SrvPanel\Agent\Diagnose\Statements;
use SrvPanel\Agent\Files\Packer;
use SrvPanel\Agent\Mounts;
use SrvPanel\Agent\Ops\SubscriptionUsage;
use SrvPanel\Agent\Site;
use SrvPanel\Agent\SiteTemplate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Wohin die Prüfkörper kommen — ausserhalb des Repos, damit kein Lauf ihn schmutzig lässt. */
const ARBEIT = '/tmp/sicherung-messen';

/** Der Prüfkörper in der Form eines Kundenauftritts: komprimierbar plus schon komprimiert. */
const TEXT_MB = 15;
const MEDIEN_MB = 45;

// ---------------------------------------------------------------- Gerüst ---

function kopf(string $nummer, string $frage): void
{
    printf("\n=== %s — %s\n", $nummer, $frage);
}

function zeile(string $was, string $wert): void
{
    printf("  %-34s %s\n", $was, $wert);
}

/**
 * Was die Messung darüber **nicht** sagt.
 *
 * Steht am Ende jeder Messung und nicht im Kopf: Wer die Zahlen abschreibt,
 * liest den Satz dann mit. `docs/78` hat eine Stufe daran verloren, dass eine
 * Grenze nur im Kopf stand.
 */
function nicht(string ...$saetze): void
{
    foreach ($saetze as $s) {
        printf("  %-34s %s\n", 'sagt NICHT', $s);
    }
}

function mb(int|float $bytes): string
{
    return number_format($bytes / 1048576, 1, ',', '.').' MiB';
}

// ------------------------------------------------------------ Prüfkörper ---

/**
 * Baut den Baum, an dem M1 misst — und die Gegenprobe steckt in ihm selbst.
 *
 * **Zwei Hälften mit Absicht.** `text/` sind echte Dateien dieses Repos, also
 * das, was ein Auftritt an Quelltext trägt; `medien/` sind Zufallsbytes, der
 * Grenzfall „schon komprimiert", dem ein JPEG sehr nahe kommt. Ergäben beide
 * dasselbe Verhältnis, misst der Lauf nicht die Verdichtung, sondern das
 * Kopieren.
 *
 * @return array{0: int, 1: int} Dateien, rohe Bytes
 */
function baumBauen(string $ziel): array
{
    if (is_dir($ziel)) {
        return baumZaehlen($ziel);
    }

    @mkdir("$ziel/text", 0755, true);
    @mkdir("$ziel/medien", 0755, true);

    $repo = dirname(__DIR__);
    $quellen = [];

    foreach (['app', 'agent/src', 'resources/js', 'resources/css', 'docs'] as $unter) {
        if (! is_dir("$repo/$unter")) {
            continue;
        }

        $lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$repo/$unter", FilesystemIterator::SKIP_DOTS));

        foreach ($lauf as $datei) {
            if ($datei->isFile() && $datei->getSize() > 0 && $datei->getSize() < 2_000_000) {
                $quellen[] = $datei->getPathname();
            }
        }
    }

    sort($quellen);

    if ($quellen === []) {
        fwrite(STDERR, "Keine Quelldateien gefunden — der Prüfkörper wäre leer.\n");
        exit(2);
    }

    $n = 0;
    $geschrieben = 0;

    while ($geschrieben < TEXT_MB * 1048576) {
        $quelle = $quellen[$n % count($quellen)];
        $unter = sprintf('%s/text/%02d/%02d', $ziel, intdiv($n, 400), intdiv($n % 400, 20));

        if (! is_dir($unter)) {
            mkdir($unter, 0755, true);
        }

        $datei = sprintf('%s/%05d-%s', $unter, $n, basename($quelle));
        copy($quelle, $datei);
        $geschrieben += (int) filesize($datei);
        $n++;
    }

    // Deterministische Grössen zwischen 40 KB und 940 KB — die Streuung eines
    // Bilderordners, ohne dass zwei Läufe verschiedene Zahlen ergeben.
    $m = 0;
    $geschriebenM = 0;
    $zufall = fopen('/dev/urandom', 'rb');

    while ($geschriebenM < MEDIEN_MB * 1048576) {
        $unter = sprintf('%s/medien/%02d', $ziel, intdiv($m, 100));

        if (! is_dir($unter)) {
            mkdir($unter, 0755, true);
        }

        $groesse = 40_000 + ($m * 7919) % 900_000;
        file_put_contents(sprintf('%s/%05d.bin', $unter, $m), fread($zufall, $groesse));
        $geschriebenM += $groesse;
        $m++;
    }

    fclose($zufall);

    return baumZaehlen($ziel);
}

/** @return array{0: int, 1: int} */
function baumZaehlen(string $wurzel): array
{
    $dateien = 0;
    $bytes = 0;
    $lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));

    foreach ($lauf as $datei) {
        if ($datei->isFile()) {
            $dateien++;
            $bytes += $datei->getSize();
        }
    }

    return [$dateien, $bytes];
}

/** @return list<string> */
function dateienUnter(string $wurzel, bool $mitVerzeichnissen = false): array
{
    $treffer = [];
    $lauf = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS),
        $mitVerzeichnissen ? RecursiveIteratorIterator::SELF_FIRST : RecursiveIteratorIterator::LEAVES_ONLY,
    );

    foreach ($lauf as $eintrag) {
        $treffer[] = $eintrag->getPathname();
    }

    sort($treffer);

    return $treffer;
}

// ----------------------------------------------------------------- M1 ------

/**
 * Was eine Dateisicherung kostet — in Zeit, in Platz und im Speicher.
 *
 * **Der Speicher ist die Frage, nicht die Zeit.** `Db\Dump` begründet seit P5,
 * warum ein Dump nicht durch den Socket zurückgereicht wird: „der Weg, auf dem
 * der Agent den Speicher des Servers füllt". Für Dateien ist dieselbe Frage
 * offen, und sie entscheidet, ob der Agent überhaupt packen darf.
 */
function m1(?object $app = null): void
{
    kopf('M1', 'Was kostet eine Dateisicherung — Zeit, Platz, Speicher?');

    [$dateien, $roh] = baumBauen(ARBEIT.'/baum');
    zeile('Prüfkörper', sprintf('%d Dateien, %s', $dateien, mb($roh)));

    foreach (['text' => 'Quelltext', 'medien' => 'schon komprimiert', '' => 'beides'] as $halb => $was) {
        $quelle = ARBEIT.'/baum'.($halb === '' ? '' : "/$halb");
        $ziel = ARBEIT.'/'.($halb === '' ? 'ganz' : $halb).'.zip';
        @unlink($ziel);

        $liste = dateienUnter($quelle);
        $rohHalb = array_sum(array_map(fn (string $d): int => (int) filesize($d), $liste));
        $vor = memory_get_peak_usage(true);
        $t = hrtime(true);

        $zip = new ZipArchive;
        $zip->open($ziel, ZipArchive::CREATE | ZipArchive::EXCL);
        $schnitt = strlen($quelle) + 1;

        foreach ($liste as $d) {
            $zip->addFile($d, substr($d, $schnitt));
        }

        $zip->close();
        $dauer = (hrtime(true) - $t) / 1e9;

        zeile(sprintf('ZipArchive · %s', $was), sprintf(
            '%s → %s  Verhältnis %.3f  %.2fs  %.0f MB/s  Speicher +%s',
            mb($rohHalb), mb((int) filesize($ziel)), filesize($ziel) / $rohHalb,
            $dauer, $rohHalb / 1048576 / max($dauer, 0.001), mb(memory_get_peak_usage(true) - $vor),
        ));
    }

    nicht(
        'wie lange dasselbe auf einer langsamen Platte dauert',
        'was ein Auftritt mit 100 000 Dateien kostet — der Prüfkörper hat '.$dateien,
    );
}

/**
 * Welcher Schreiber trägt, was eine Wiederherstellung braucht.
 *
 * **Die Gegenprobe ist `tar` von aussen.** Trüge das auch nichts, läge der
 * Fehler in der Messung und nicht in den Schreibern.
 */
function m1b(?object $app = null): void
{
    kopf('M1b', 'Welches Archiv trägt Rechte, Eigentümer und Verweis?');

    $wurzel = ARBEIT.'/eigenschaften';
    exec('rm -rf '.escapeshellarg($wurzel));
    mkdir("$wurzel/quelle", 0755, true);

    file_put_contents("$wurzel/quelle/datei.txt", "inhalt\n");
    chmod("$wurzel/quelle/datei.txt", 0644);
    file_put_contents("$wurzel/quelle/privat.key", "geheim\n");
    chmod("$wurzel/quelle/privat.key", 0600);
    @chown("$wurzel/quelle/privat.key", 9501);
    @chgrp("$wurzel/quelle/privat.key", 9501);
    symlink('datei.txt', "$wurzel/quelle/verweis.txt");
    mkdir("$wurzel/quelle/unter", 0755);
    chmod("$wurzel/quelle/unter", 02750);
    file_put_contents("$wurzel/quelle/unter/tief.txt", "tief\n");

    // **Ein leeres Verzeichnis ist ein eigener Fall.** `tmp` und `mail` eines
    // Abonnements sind im Grundzustand leer; ein Archiv, das sie fallen lässt,
    // stellt ein Abonnement ohne sie wieder her — und der Kunde merkt es an
    // einem Schreibfehler und nicht an einer Meldung.
    mkdir("$wurzel/quelle/leer", 02700);

    $soll = bestand("$wurzel/quelle");
    zeile('Quelle', kurz($soll));

    $liste = dateienUnter("$wurzel/quelle", true);
    $schnitt = strlen("$wurzel/quelle") + 1;

    // (a) ZipArchive, so wie Packer es tut — Verweise ausgelassen.
    @unlink("$wurzel/a.zip");
    $zip = new ZipArchive;
    $zip->open("$wurzel/a.zip", ZipArchive::CREATE | ZipArchive::EXCL);

    foreach ($liste as $d) {
        $rel = substr($d, $schnitt);

        if (is_link($d)) {
            continue;
        }

        is_dir($d) ? $zip->addEmptyDir($rel) : $zip->addFile($d, $rel);
    }

    $zip->close();

    // (b) PharData — und ob das überhaupt geht, ist selbst eine Messung.
    @unlink("$wurzel/b.tar");
    $pharGeht = 'ja';

    try {
        $phar = new PharData("$wurzel/b.tar");
        $phar->buildFromDirectory("$wurzel/quelle");
        unset($phar);
    } catch (Throwable $fehler) {
        $pharGeht = 'ABGEWIESEN: '.$fehler->getMessage();
    }

    // (c) tar von aussen — die Gegenprobe.
    @unlink("$wurzel/c.tar");
    exec('tar -cf '.escapeshellarg("$wurzel/c.tar").' -C '.escapeshellarg("$wurzel/quelle").' .');

    zeile('phar.readonly', (string) ini_get('phar.readonly'));
    zeile('PharData schreiben', $pharGeht);

    foreach ([['a.zip', 'unzip -qq -o %s -d %s'], ['b.tar', 'tar -xf %s -C %s'], ['c.tar', 'tar -xf %s -C %s']] as [$archiv, $befehl]) {
        if (! is_file("$wurzel/$archiv")) {
            zeile($archiv, 'nicht entstanden');

            continue;
        }

        $aus = "$wurzel/aus-".str_replace('.', '-', $archiv);
        exec('rm -rf '.escapeshellarg($aus));
        mkdir($aus, 0755, true);
        exec(sprintf($befehl, escapeshellarg("$wurzel/$archiv"), escapeshellarg($aus)).' 2>/dev/null');

        $ist = bestand($aus);
        $gleich = array_keys(array_filter($soll, fn (string $w, string $p): bool => ($ist[$p] ?? '') === $w, ARRAY_FILTER_USE_BOTH));

        zeile($archiv.' zurück', sprintf('%d von %d gleich · %s', count($gleich), count($soll), kurz($ist)));
    }

    nicht(
        'ob `tar` je auf die Positivliste des Runners darf — das ist eine Entscheidung',
        'was ein Zip mit setExternalAttributes trüge; gemessen ist der Weg, den Packer geht',
    );
}

/** @return array<string,string> */
function bestand(string $wurzel): array
{
    $r = [];

    foreach (['datei.txt', 'privat.key', 'verweis.txt', 'unter', 'leer'] as $p) {
        $v = "$wurzel/$p";

        if (is_link($v)) {
            $r[$p] = 'verweis';

            continue;
        }

        if (! file_exists($v)) {
            $r[$p] = 'fehlt';

            continue;
        }

        $st = lstat($v);
        $r[$p] = sprintf('%04o/%d', $st['mode'] & 07777, $st['uid']);
    }

    return $r;
}

/** @param array<string,string> $b */
function kurz(array $b): string
{
    $teile = [];

    foreach ($b as $p => $w) {
        $teile[] = substr($p, 0, 7).'='.$w;
    }

    return implode(' ', $teile);
}

// ----------------------------------------------------------------- M2 ------

/**
 * Zählt eine Sicherung gegen die Quota des Kunden?
 *
 * **Gefragt wird nicht der Pfad, sondern der Eigentümer** — und das ist keine
 * Auslegung, sondern folgt aus {@see SubscriptionUsage}:
 * Sie liest `repquota` über das Dateisystem, das `/var/www/vhosts` trägt, und
 * gibt aus, was der Form `pNNNN` entspricht. Ein Verzeichnis kommt darin nicht
 * vor.
 */
function m2(?object $app = null): void
{
    kopf('M2', 'Zählt eine Sicherung gegen die Quota des Kunden?');

    $vhosts = '/var/www/vhosts';
    $dumps = '/var/lib/srvpanel/dumps';

    foreach ([$vhosts, $dumps] as $p) {
        zeile($p, is_dir($p)
            ? sprintf('Gerät %s · Nummer %d', var_export(Mounts::deviceFor($p), true), stat($p)['dev'])
            : 'gibt es hier nicht — auf dem Server nachmessen');
    }

    if (is_dir($vhosts) && is_dir($dumps)) {
        zeile('dasselbe Dateisystem?', stat($vhosts)['dev'] === stat($dumps)['dev'] ? 'JA — dann schützt der Pfad nichts' : 'nein');
    }

    // Gegenprobe: Kann diese Frage überhaupt zwei Antworten geben?
    $anders = array_values(array_filter(['/dev/shm', '/proc'], 'is_dir'));

    if ($anders !== [] && is_dir($vhosts)) {
        zeile('Gegenprobe '.$anders[0], stat($anders[0])['dev'] === stat($vhosts)['dev']
            ? 'GLEICH — dann misst die Frage nichts'
            : sprintf('Nummer %d, also verschieden', stat($anders[0])['dev']));
    }

    zeile('quotaon vorhanden', (string) (shell_exec('command -v quotaon 2>/dev/null') ?: '—'));

    nicht(
        'ob die Quota greift — dieser Container kann sie nicht erzwingen (Kernel kennt das Format nicht)',
        'wie `/var` auf cloudsrv24 eingehängt ist; ein eigenes Dateisystem änderte die Antwort',
    );
}

// ----------------------------------------------------------------- M4 ------

/**
 * Was die Leitung zum Agenten trägt — und wo ein Verzeichnis sie reisst.
 *
 * **Die Zahl daneben ist `Packer::MAX_ENTRIES`.** Ein Archiv nimmt 20 000
 * Einträge; ein Verzeichnis, das dieselben Einträge beschreibt, passt nicht
 * durch dieselbe Leitung. Das ist derselbe Fehler wie bei
 * `FilesRead::MAX_BYTES` gegen `REQUEST_MAX` (`docs/62` Punkt 12b), eine Stufe
 * weiter draussen:
 *
 * > **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**
 */
function m4(?object $app = null): void
{
    kopf('M4', 'Passt ein Verzeichnis der Sicherung durch die Leitung zum Agenten?');

    zeile('REQUEST_MAX', number_format(Connection::REQUEST_MAX).' B');
    zeile('CONTENT_MAX', number_format(Connection::CONTENT_MAX).' B');

    baumBauen(ARBEIT.'/baum');
    $eintraege = [];
    $wurzel = ARBEIT.'/baum';
    $schnitt = strlen($wurzel) + 1;

    foreach (dateienUnter($wurzel, true) as $p) {
        $st = lstat($p);
        // Genau das, was ZipArchive und PharData nach M1b verlieren.
        $eintraege[] = ['p' => substr($p, $schnitt), 'm' => $st['mode'] & 07777, 'u' => $st['uid'], 'g' => $st['gid'], 's' => $st['size']];
    }

    $json = (string) json_encode($eintraege, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $jeEintrag = strlen($json) / max(count($eintraege), 1);

    zeile('Prüfkörper', sprintf('%d Einträge → %s B JSON (%.1f B je Eintrag)', count($eintraege), number_format(strlen($json)), $jeEintrag));
    zeile('passt in eine Zeile', strlen($json) <= Connection::CONTENT_MAX ? 'ja' : 'NEIN');
    zeile('Schluss bei rund', number_format((int) (Connection::CONTENT_MAX / $jeEintrag)).' Einträgen');
    zeile('Packer::MAX_ENTRIES', number_format(Packer::MAX_ENTRIES).' — das Archiv nimmt mehr, als die Leitung beschreibt');

    // Gegenprobe: eine Zeile, die die Grenze wirklich reisst.
    $viel = array_merge(...array_fill(0, 20, $eintraege));
    $j2 = (string) json_encode($viel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    zeile('Gegenprobe '.number_format(count($viel)).' Einträge', strlen($j2) <= Connection::CONTENT_MAX
        ? 'passt — dann misst die Grenze nichts'
        : sprintf('%s B, REISST die Grenze', number_format(strlen($j2))));

    nicht(
        'wie gross ein Verzeichnis mit längeren Pfaden wird — der Prüfkörper hat kurze',
        'was ein Verzeichnis IM Archiv kostet; gemessen ist der Weg durch den Socket',
    );
}

// ----------------------------------------------------------------- M6 ------

/**
 * Beschreibung oder erzeugte Datei?
 *
 * **Der entscheidende Fall ist die wörtlich zurückgespielte Datei.** A10 hält
 * jede Vhost-Datei je Form gegen {@see SiteTemplate::PROMISED_BY_FORM}; eine
 * Datei aus einer älteren Fassung führt eine Anweisung weniger, und der
 * Nachtlauf meldet sie. Gemessen wird das hier an der echten Vorlage und am
 * echten Leser — nicht an einer nachgebauten Liste.
 */
function m6(?object $app = null): void
{
    kopf('M6', 'Speichert eine Sicherung die Beschreibung oder die erzeugte Datei?');

    $grund = [
        'subscription' => 'kunde-shop', 'user' => 'p1000', 'domain' => 'shop.example',
        'aliases' => ['www.shop.example'], 'document_root' => 'httpdocs',
        'php_version' => null, 'php_settings' => [], 'directives' => [],
        'redirect_target' => null, 'redirect_code' => 302,
        'suspended' => false, 'hsts' => false, 'certificate' => null,
    ];

    $bloecke = [];

    foreach ([
        'php' => ['php_version' => '8.3'],
        'static' => [],
        'redirect' => ['redirect_target' => 'https://ziel.example'],
        'suspended' => ['suspended' => true],
    ] as $name => $extra) {
        $site = Site::fromArgs(array_merge($grund, $extra));
        $form = SiteTemplate::formOf($site);
        $block = SiteTemplate::render($site);
        $bloecke[$name] = [$form, $block];

        $fehlt = array_values(array_diff(SiteTemplate::promised($form, false), Statements::heads($block)));
        zeile("Form $name", sprintf('%d B, %d Zeilen, %d Anweisungen, fehlt: %s',
            strlen($block), substr_count($block, "\n"), count(Statements::heads($block)), $fehlt === [] ? '—' : implode(',', $fehlt)));
    }

    [$form, $block] = $bloecke['php'];
    $alt = (string) preg_replace('/^[ \t]*client_max_body_size[^\n]*\n/m', '', $block);
    $fehlt = array_values(array_diff(SiteTemplate::promised($form, false), Statements::heads($alt)));

    zeile('wörtlich zurückgespielt (älter)', sprintf('%d → %d B · Diagnose meldet: %s', strlen($block), strlen($alt), $fehlt === [] ? 'NICHTS' : implode(',', $fehlt)));
    zeile('Gegenprobe unverändert', array_diff(SiteTemplate::promised($form, false), Statements::heads($block)) === [] ? 'meldet nichts (richtig)' : 'meldet etwas (kaputt)');

    nicht(
        'wie oft sich eine Vorlage wirklich ändert — gemessen ist die Wirkung, nicht die Häufigkeit',
        'was für pg_hba.conf und die Cron-Dateien gilt; dort schreibt derselbe verwaltete Bereich',
    );
}

/**
 * Wie ein langer Lauf seinen Ausgang meldet.
 *
 * **Die Frage aus `docs/115 §6.1` Punkt 7 lautete, ob Form A trägt** — also
 * `AwaitDispatchedRun`, die Nachlese für einen Lauf, der nur abgesetzt wird.
 * Gemessen wird hier die Vorfrage: Es gibt einen Fortschrittskanal, und die
 * nächste Verwandte einer Dateisicherung benutzt ihn bereits. Form A ist für
 * Läufe da, die das Panel **überleben** müssen — nicht für lange.
 */
function m7(?object $app = null): void
{
    kopf('M7', 'Wie meldet ein langer Lauf seinen Ausgang?');

    $repo = dirname(__DIR__);
    $job = (string) file_get_contents($repo.'/app/Jobs/RunAgentOperation.php');

    preg_match('/public int \$timeout = (\d+);/', $job, $t);
    preg_match('/public int \$tries = (\d+);/', $job, $v);

    $grenze = (int) ($t[1] ?? 0);
    zeile('RunAgentOperation::$timeout', $grenze.' s');
    zeile('RunAgentOperation::$tries', (string) ($v[1] ?? '?'));

    // Der Fortschrittskanal: gibt es ihn, und wer benutzt ihn?
    $nutzer = glob($repo.'/agent/src/Ops/*.php') ?: [];
    $mitFortschritt = array_values(array_filter($nutzer, static fn (string $d): bool => str_contains((string) file_get_contents($d), '->progress(')));
    zeile('Ops mit Fortschritt', sprintf('%d von %d', count($mitFortschritt), count($nutzer)));
    zeile('darunter DbDumpCreate', in_array($repo.'/agent/src/Ops/DbDumpCreate.php', $mitFortschritt, true)
        ? 'ja — die nächste Verwandte meldet schon Fortschritt'
        : 'NEIN (dann trägt der Vergleich nicht)');

    // Gegenprobe: eine Operation, die keinen Fortschritt meldet. Wären es alle,
    // sagte die Zahl oben nichts.
    zeile('Gegenprobe · AgentPing', in_array($repo.'/agent/src/Ops/AgentPing.php', $mitFortschritt, true)
        ? 'meldet auch Fortschritt — dann zählt die Zahl nur Dateien'
        : 'meldet keinen — die Zahl unterscheidet also');

    // Und was die Grenze in Bytes bedeutet, mit dem Durchsatz aus M1.
    foreach ([19, 31, 39] as $mbs) {
        zeile(sprintf('bei %d MB/s reicht %d s für', $mbs, $grenze), number_format($grenze * $mbs / 1024, 1, ',', '.').' GiB');
    }

    nicht(
        'ob `retry_after` (90 s in config/queue.php) einem Lauf von 1800 s in die Quere kommt — ungemessen',
        'wie fein ein Fortschritt sein muss, damit ihn jemand liest',
    );
}

// ------------------------------------------------------------ M3 und M5 ----

/** Braucht Laravel — fällt sonst mit genanntem Grund aus statt mit einer Null. */
function laravel(): ?object
{
    $autoload = dirname(__DIR__).'/vendor/autoload.php';

    if (! is_file($autoload)) {
        return null;
    }

    require_once $autoload;
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

/**
 * Der Weg zum Kunden — strömt er, oder füllt er den Speicher?
 *
 * **Die Gegenprobe gehört in denselben Lauf.** Ein Speicherzuwachs von 0 sagt
 * nur dann etwas, wenn dieselbe Messung daneben einen Zuwachs sehen kann. Der
 * erste Anlauf dieser Messung hat `ob_start()` benutzt und damit gemessen, was
 * der Prüfstand puffert — nicht, was die Antwort tut.
 */
function m3(?object $app): void
{
    kopf('M3', 'Wie kommt die Datei zum Kunden — strömt sie?');

    if ($app === null) {
        zeile('ausgefallen', 'vendor/autoload.php fehlt (nicht: es gibt nichts zu messen)');

        return;
    }

    $S = ARBEIT.'/m3';
    @mkdir($S, 0755, true);

    foreach (['klein.bin' => 1048576, 'gross.bin' => 512 * 1048576] as $name => $groesse) {
        if (! is_file("$S/$name") || filesize("$S/$name") !== $groesse) {
            exec(sprintf('head -c %d /dev/urandom > %s', $groesse, escapeshellarg("$S/$name")));
        }
    }

    $aus = fopen('/dev/null', 'wb');

    foreach (['klein.bin', 'gross.bin'] as $name) {
        $vor = memory_get_peak_usage(true);
        $t = hrtime(true);

        $antwort = new BinaryFileResponse("$S/$name");
        $antwort->setContentDisposition('attachment', $name);

        /*
         * **Der Puffer bekommt eine Stückgrösse, und das ist die Messung.**
         * Ein `ob_start()` ohne sie sammelt die ganze Antwort im Speicher —
         * der erste Anlauf dieser Messung ist genau daran mit „Allowed memory
         * size exhausted" gestorben, und das war ein Befund am Prüfstand und
         * nicht an der Antwort.
         *
         * > **Ein Prüfkörper, der seinen Gegenstand beim Messen verändert,
         * > meldet den Unterschied als Fehler des Gemessenen.**
         */
        ob_start(static function (string $stueck) use ($aus): string {
            fwrite($aus, $stueck);

            return '';
        }, 65536);
        $antwort->sendContent();
        ob_end_clean();

        $dauer = (hrtime(true) - $t) / 1e9;
        zeile($name, sprintf('%s · %.2fs · Speicher +%s', mb((int) filesize("$S/$name")), $dauer, mb(memory_get_peak_usage(true) - $vor)));
    }

    fclose($aus);

    $vor = memory_get_peak_usage(true);
    $ballast = str_repeat('x', 100 * 1048576);
    zeile('Gegenprobe 100 MiB im Speicher', sprintf('+%s — die Messung kann Zuwachs sehen', mb(memory_get_peak_usage(true) - $vor)));
    unset($ballast);

    nicht(
        'wie php-fpm puffert — hier ist output_buffering '.var_export(ini_get('output_buffering'), true),
        'ob nginx die FastCGI-Antwort auf die Platte puffert; das entscheidet der Server',
    );
}

/**
 * Bekommt ein wiederhergestelltes Abonnement seinen Systembenutzer zurück?
 *
 * **Die Messung, die die Form der Wiederherstellung entscheidet.** Sie schreibt
 * in `system_users` und gehört deshalb auf eine Wegwerf-Datenbank; sie räumt
 * hinterher auf, was sie angelegt hat.
 */
function m5(?object $app): void
{
    kopf('M5', 'Bekommt ein wiederhergestelltes Abonnement seinen Systembenutzer zurück?');

    if ($app === null) {
        zeile('ausgefallen', 'vendor/autoload.php fehlt (nicht: es gibt nichts zu messen)');

        return;
    }

    $leben = $app->make(Lifecycle::class);
    $marke = 'messrunde-p8-'.bin2hex(random_bytes(3));

    $eins = $leben->claim($marke);
    $zeileEins = SystemUser::query()->where('number', (int) ltrim($eins, 'p'))->first();

    // Der Rückbau löscht das Abonnement hart; die Reservierung bleibt stehen —
    // Lifecycle::withdraw() fasst system_users nicht an.
    $zwei = $leben->claim($marke);
    $zeileZwei = SystemUser::query()->where('number', (int) ltrim($zwei, 'p'))->first();

    zeile('vorher / nachher', sprintf('%s / %s   %s', $eins, $zwei, $eins === $zwei ? 'zurückbekommen' : 'NEU'));
    zeile('db_prefix vorher / nachher', sprintf('%s / %s   %s', $zeileEins->db_prefix, $zeileZwei->db_prefix,
        $zeileEins->db_prefix === $zeileZwei->db_prefix ? 'gleich' : 'VERSCHIEDEN'));

    // Gegenprobe 1: Steht die alte Reservierung noch da? Ohne sie wäre der neue
    // Name aus einem anderen Grund neu, und die Messung sagte nichts.
    $nochDa = SystemUser::query()->where('number', (int) ltrim($eins, 'p'))->exists();
    zeile('Gegenprobe · alte Reservierung', $nochDa ? 'steht da — deshalb ist der Name vergeben' : 'FORT (dann misst der Lauf etwas anderes)');

    // Gegenprobe 2: Gibt claim() je zweimal dasselbe zurück?
    $a = $leben->claim($marke.'-a');
    $b = $leben->claim($marke.'-b');
    zeile('Gegenprobe · zwei frische Namen', sprintf('%s / %s  %s', $a, $b, $a === $b ? 'GLEICH (kaputt)' : 'verschieden'));

    // Und die Frage dahinter: Gibt es überhaupt einen Leser, der nach dem
    // Namen des Abonnements fragt? Ohne einen solchen gibt es keinen Weg zurück.
    $leser = (string) shell_exec('grep -rn "SystemUser::" '.escapeshellarg(dirname(__DIR__).'/app').' --include=*.php 2>/dev/null | grep -c "where(.subscription." || true');
    zeile('Leser nach subscription', trim($leser) === '0' ? 'keiner — es gibt keinen Weg zurück' : trim($leser).' — dann gäbe es einen');

    SystemUser::query()->where('subscription', 'like', $marke.'%')->delete();
    zeile('aufgeräumt', 'die vier Zeilen dieser Messung sind fort');

    nicht(
        'ob eine Wiederherstellung den Namen zurückgeben SOLL — das ist eine Entscheidung',
        'was auf dem Dateisystem liegenbleibt; das misst der Abnahmelauf auf dem Server',
    );
}

// ---------------------------------------------------------------- Lauf -----

@mkdir(ARBEIT, 0755, true);

$gewaehlt = array_slice($argv, 1);
$alle = ['m1' => 'm1', 'm1b' => 'm1b', 'm2' => 'm2', 'm4' => 'm4', 'm6' => 'm6', 'm7' => 'm7', 'm3' => 'm3', 'm5' => 'm5'];
$laufen = $gewaehlt === [] ? array_keys($alle) : array_values(array_intersect(array_keys($alle), $gewaehlt));

printf("Messrunde vor P8 — %s\n", date('c'));
printf("Prüfkörper unter %s (bleibt liegen; er ist teuer zu bauen)\n", ARBEIT);

$app = null;

if (array_intersect($laufen, ['m3', 'm5']) !== []) {
    $app = laravel();
}

foreach ($laufen as $name) {
    $alle[$name]($app);
}

printf("\nFertig. Wegräumen: rm -rf %s\n", ARBEIT);
