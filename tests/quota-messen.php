<?php

declare(strict_types=1);

/*
 * Die volle Quota — Punkt 12 des Abnahmekriteriums (`docs/51 §4`, `docs/61 §8`).
 *
 * **Was hier gemessen wird und was nicht.** Der Punkt verlangt zweierlei: dass
 * ein Schreibvorgang bei erschöpftem Kontingent **fehlschlägt** und dass die
 * **Seite es sagt**. Dieses Skript misst die erste Hälfte — den Weg der
 * Operation. Die zweite braucht einen Browser und steht in `docs/61 §8`.
 *
 * **Der Satz, der diesen Punkt trägt, ist bisher nie gemessen worden.** In
 * {@see FilesWrite} steht seit P6, `file_put_contents` gebe bei voller Quota die
 * Zahl der geschriebenen Bytes zurück und nicht `false` — und darauf beruht die
 * ganze Prüfung: Wer nur auf `false` prüft, meldet Erfolg für eine
 * abgeschnittene Datei. Das ist Wissen aus zweiter Hand, und dieses Projekt hat
 * mit solchem Wissen schon eine Stufe verloren (`docs/37 §3`).
 *
 * > **Wissen aus zweiter Hand sieht aus wie Wissen.**
 *
 * Dieses Skript druckt deshalb, was der Aufruf **wirklich** zurückgegeben hat.
 *
 * **Es braucht root**, weil es ein Konto anlegt, eine Quota setzt und in die
 * Sandbox geht. Und es braucht ein Dateisystem, auf dem die Quota **läuft** —
 * nicht eines, dessen Mount-Optionen sie erlauben:
 *
 * > **Eine Option, die etwas erlaubt, ist nicht dasselbe wie ein Zustand, in dem
 * > es geschieht.** (`docs/41 §2.3`)
 *
 * Fehlt sie, endet der Lauf mit Rückgabewert 2 und **ohne Befund**. Das ist
 * kein Ergebnis, sondern seine Abwesenheit — ein Schreibvorgang, der nicht
 * scheitert, weil ihn nichts begrenzt, sagt über das Panel nichts.
 *
 *     sudo php tests/quota-messen.php
 */

require __DIR__.'/../agent/src/autoload.php';

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\DiskQuota;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Mounts;
use SrvPanel\Agent\Ops\FilesWrite;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Runner;

const ABO = 'quota-messung.probe';
const NUTZER = 'p9996';

/** Die enge Grenze, an der der Vorgang scheitern soll. */
const ENG_MB = 1;

/** Und die weite, unter der derselbe Vorgang gelingen muss. */
const WEIT_MB = 64;

$wurzel = SubscriptionProvision::VHOSTS.'/'.ABO;

final class Lauf
{
    /** Wie viele Befunde es gibt — jeder heisst: der Fehlerweg trägt nicht. */
    public static int $rot = 0;
}

function meldung(string $was, bool $ok, string $zusatz = ''): void
{
    if (! $ok) {
        Lauf::$rot++;
    }

    printf("  %-52s %s %s\n", $was, $ok ? 'ja  ' : 'NEIN', $zusatz);
}

function hinweis(string $was, string $wert): void
{
    printf("  %-52s      %s\n", $was, $wert);
}

/** Abbruch ohne Befund: Hier ist nichts zu messen, also wird nichts behauptet. */
function unmessbar(string $grund): never
{
    printf("\n  Hier ist Punkt 12 nicht messbar: %s\n", $grund);
    echo "  Das ist kein Befund. Ein Schreibvorgang, den nichts begrenzt,\n";
    echo "  sagt über den Fehlerweg des Panels nichts.\n\n";

    exit(2);
}

$journal = new Journal('/dev/null');
$kontext = new Context(new Runner($journal), $journal, static fn (array $zeile): null => null);

/**
 * Ein Programm des Systems aufrufen — und ein fehlendes nicht als Absturz enden
 * lassen.
 *
 * **Der erste Wurf dieses Skripts ist genau daran gestorben.** Ohne das Paket
 * `quota` wirft {@see Runner} „repquota ist auf diesem System nicht
 * installiert", und der Lauf endete mit einem Stapelabzug und Rückgabewert 255
 * — statt mit dem Satz, für den es {@see unmessbar()} gibt. Ein Riegel, der
 * selbst umfällt, ist keiner.
 *
 * @param  list<string>  $argumente
 */
function programm(Context $kontext, string $name, array $argumente, int $frist = 60): object
{
    try {
        return $kontext->stream($name, $argumente, $frist);
    } catch (AgentException $ausnahme) {
        unmessbar($ausnahme->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n1. Vorbedingung: läuft auf diesem Dateisystem überhaupt eine Quota?\n\n";

printf("  PHP %s, %s\n\n", PHP_VERSION, php_uname('s').' '.php_uname('r'));

if (posix_getuid() !== 0) {
    unmessbar('dieser Lauf braucht root');
}

$geraet = Mounts::deviceFor(SubscriptionProvision::VHOSTS);

if ($geraet === null) {
    unmessbar('kein Mount für '.SubscriptionProvision::VHOSTS.' gefunden');
}

hinweis('Gerät unter '.SubscriptionProvision::VHOSTS, $geraet);

/*
 * **Gefragt wird der Leseversuch und nicht die Optionszeile** — genau die
 * Unterscheidung, auf die `docs/41` einmal hereingefallen ist. `findmnt` sagte
 * `usrquota`, und `quotaon -p` sagte `is off`.
 */
$bericht = programm($kontext, 'repquota', ['-u', '-O', 'csv', $geraet], 120);

if (! $bericht->successful()) {
    unmessbar('repquota scheitert: '.(trim($bericht->stderr) !== '' ? trim($bericht->stderr) : 'ohne Meldung'));
}

meldung('repquota liest die Quota-Datei', true, sprintf('%d Zeilen', substr_count($bericht->stdout, "\n")));

// ─────────────────────────────────────────────────────────────────────────
echo "\n2. Ein Wegwerf-Abonnement mit einer engen Grenze\n\n";

exec('rm -rf '.escapeshellarg($wurzel));
exec('groupadd -f '.NUTZER.' 2>&1');

if (posix_getpwnam(NUTZER) === false) {
    exec('useradd --gid '.NUTZER.' --no-user-group --no-create-home --shell /usr/sbin/nologin '.NUTZER.' 2>&1');
}

mkdir($wurzel.'/httpdocs', 0o755, true);
exec('chown -R '.NUTZER.':'.NUTZER.' '.escapeshellarg($wurzel.'/httpdocs'));
chown($wurzel, 'root');
chgrp($wurzel, 'root');
chmod($wurzel, 0o755);

try {
    $eng = DiskQuota::apply($kontext, NUTZER, ENG_MB);
} catch (AgentException $ausnahme) {
    unmessbar($ausnahme->getMessage());
}

if (($eng['enforced'] ?? false) !== true) {
    unmessbar('setquota greift nicht: '.($eng['reason'] ?? 'ohne Grund'));
}

meldung(sprintf('die Grenze von %d MB gilt', ENG_MB), true);

// ─────────────────────────────────────────────────────────────────────────
echo "\n3. Der Fall: eine Datei, die nicht mehr hineinpasst\n\n";

/*
 * Zwei Mebibyte gegen ein Megabyte Kontingent. Mehr geht über diesen Weg nicht
 * — {@see FilesWrite::MAX_BYTES} deckelt bei 2 MiB, und das ist der Grund für
 * die enge Grenze: Der Prüfkörper muss unter die Obergrenze des Vorgangs passen
 * und über die des Kontingents.
 */
$inhalt = str_repeat('A', 2 * 1024 * 1024);
$ziel = '/httpdocs/zu-gross.bin';

$args = ['subscription' => ABO, 'user' => NUTZER, 'path' => $ziel, 'content' => $inhalt];

$fehler = null;

try {
    (new FilesWrite)->execute($args, $kontext);
} catch (AgentException $ausnahme) {
    $fehler = $ausnahme;
}

meldung('der Vorgang schlägt fehl', $fehler !== null,
    $fehler === null ? 'er hat Erfolg gemeldet' : '');

if ($fehler !== null) {
    hinweis('Meldung', '„'.$fehler->getMessage().'"');
    hinweis('Code', $fehler->errorCode);

    /*
     * **Das ist die Zahl, wegen der dieses Skript existiert.** Der Quelltext
     * sagt seit P6, `file_put_contents` liefere bei voller Quota die Zahl der
     * geschriebenen Bytes und nicht `false`. Gemessen war das nie.
     */
    $geschrieben = $fehler->details['written'] ?? null;
    $erwartet = $fehler->details['expected'] ?? null;

    hinweis('geschrieben / erwartet', sprintf('%s / %s', var_export($geschrieben, true), var_export($erwartet, true)));

    meldung(
        'die Meldung nennt das Kontingent',
        str_contains($fehler->getMessage(), 'Kontingent'),
        'sonst sucht der Kunde den Fehler bei sich',
    );

    meldung(
        'der Fehlerweg kennt den Umfang des Verlusts',
        is_int($geschrieben) && is_int($erwartet) && $erwartet === strlen($inhalt),
        'ohne beide Zahlen ist „unvollständig" eine Vermutung',
    );

    if (is_int($geschrieben) && $geschrieben > 0) {
        hinweis('welcher Zweig', 'kurze Zahl — der Quelltext hat recht');
    } elseif ($geschrieben === 0) {
        hinweis('welcher Zweig', 'false — der Quelltext nimmt den anderen Fall an');
    }
}

/*
 * **Und was liegen bleibt, gehört gemessen.** Geschrieben wird in eine
 * Nachbardatei und dann umbenannt; scheitert das Schreiben, muss die Nachbardatei
 * fort sein. Bliebe sie liegen, frässe jeder Fehlversuch dauerhaft am Kontingent
 * des Kunden — und niemand sähe, warum es voll ist.
 */
$reste = glob($wurzel.'/httpdocs/.srvpanel-*') ?: [];

meldung('kein halb geschriebener Rest bleibt liegen', $reste === [],
    $reste === [] ? '' : implode(', ', array_map(basename(...), $reste)));

meldung('die Zieldatei entsteht gar nicht erst', ! file_exists($wurzel.$ziel));

// ─────────────────────────────────────────────────────────────────────────
echo "\n4. Die Gegenprobe: derselbe Vorgang unter einer weiten Grenze\n\n";

/*
 * **Ohne diesen Abschnitt misst Abschnitt 3 nichts.** Ein Fehlschlag sähe
 * genauso aus, wenn der Pfad falsch wäre, das Verzeichnis fehlte oder der
 * Vorgang aus einem ganz anderen Grund nicht liefe.
 *
 * > **Eine Gegenprobe, die nicht treffen kann, ist keine.**
 */
try {
    $weit = DiskQuota::apply($kontext, NUTZER, WEIT_MB);
} catch (AgentException $ausnahme) {
    unmessbar($ausnahme->getMessage());
}

if (($weit['enforced'] ?? false) !== true) {
    unmessbar('die weite Grenze liess sich nicht setzen: '.($weit['reason'] ?? 'ohne Grund'));
}

$gelungen = false;

try {
    (new FilesWrite)->execute($args, $kontext);
    $gelungen = true;
} catch (AgentException $ausnahme) {
    hinweis('unerwartete Meldung', '„'.$ausnahme->getMessage().'"');
}

meldung(sprintf('unter %d MB gelingt derselbe Vorgang', WEIT_MB), $gelungen);

$angekommen = (int) @filesize($wurzel.$ziel);

meldung('und die Datei ist vollständig', $angekommen === strlen($inhalt),
    sprintf('%d von %d Byte', $angekommen, strlen($inhalt)));

// ─────────────────────────────────────────────────────────────────────────
exec('rm -rf '.escapeshellarg($wurzel));
programm($kontext, 'setquota', ['-u', NUTZER, '0', '0', '0', '0', $geraet]);
exec('userdel '.NUTZER.' 2>&1');
exec('groupdel '.NUTZER.' 2>&1');

echo "\n";

if (Lauf::$rot > 0) {
    printf("%d Befund(e). Der Fehlerweg trägt nicht.\n\n", Lauf::$rot);

    exit(1);
}

echo "Der Fehlerweg trägt — gemessen und gegengeprobt.\n";
echo "Was damit noch nicht gemessen ist: dass die Seite es sagt (docs/61 §8).\n\n";

exit(0);
