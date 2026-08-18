<?php

declare(strict_types=1);

/*
 * Die Sandbox aus P6 — gemessen statt gelesen.
 *
 * **Warum das hier steht und nicht in tests/Unit/.** Jeder Wächter zur Sandbox
 * liest Quelltext: dass nur eine Stelle `chroot` ruft, dass die Rechteabgabe in
 * der richtigen Reihenfolge steht, dass der Socket geschlossen wird. Das ist
 * ihre Aufgabe und ihre Grenze — **keiner von ihnen sagt, ob es auf dieser
 * Maschine funktioniert.**
 *
 * Genau dort liegt das Muster dieses Projekts: `docs/45` fand zwölf Fehler,
 * `docs/47` sechs, `docs/48` zwölf — und keinen davon ein Test.
 *
 * Die Messungen aus `docs/50` standen bis hierher in Wegwerfskripten. Nach der
 * Regel dieses Projekts ist das falsch:
 *
 * > **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile
 * > Anwendungscode ist.**
 *
 * **Es braucht root**, weil `chroot` und `setuid` root brauchen. In der CI
 * läuft es deshalb in der Regel nicht; es läuft im Entwicklungscontainer und
 * auf dem Zielserver, und dort ist es Punkt 1 des Zwischenabnahmelaufs
 * (`docs/52`).
 *
 * **Jede Messung hat ihre Gegenprobe daneben.** Ohne sie wäre jede Null ein
 * Beleg für nichts — `docs/50 §3` hat zweimal am eigenen Leib erlebt, dass ein
 * Angriff, der nicht trifft, den Angreifer misst und nicht die Abwehr.
 *
 *     sudo php tests/sandbox-messen.php
 */

require __DIR__.'/../agent/src/autoload.php';

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Files\Workspace;
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Journal;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Runner;
use SrvPanel\Agent\Sandbox;

const ABO = 'sandbox-messung.probe';
const NUTZER = 'p9998';

$wurzel = SubscriptionProvision::VHOSTS.'/'.ABO;
$aussen = '/tmp/sandbox-messung-aussen';

/**
 * Die Zählstände des Laufs.
 *
 * **Sie standen zuerst als `global`, und PHPStan hat das zu Recht gemeldet:**
 * Aus der Sicht des Hauptteils blieben beide auf `0`, weil ihre Veränderung in
 * Funktionen versteckt war — die Abfragen am Ende waren „immer falsch". Der
 * Analysator sah damit genau das, was auch ein Leser sieht: einen Wert, der
 * sich ändert, ohne dass die Stelle es zeigt.
 *
 * > **Ein Zustand, dessen Änderung man nicht sieht, ist einer, auf den man sich
 * > nicht verlassen kann** — und ein Abnahmeskript, dessen Zählstand
 * > verlorengeht, meldet Erfolg.
 */
final class Lauf
{
    /** Wie viele Befunde es gibt — jeder davon heisst: die Grenze hält nicht. */
    public static int $rot = 0;

    /** Wie viele Messungen ohne Gegenprobe blieben — kein Befund, aber auch kein Beleg. */
    public static int $stumm = 0;
}

/**
 * Verzeichnis und Verweis atomar tauschen — `renameat2(…, RENAME_EXCHANGE)`.
 *
 * **Zweimal gebraucht, deshalb einmal benannt.** Die Zahlen sind sonst
 * unlesbar: `316` ist der Systemaufruf auf x86_64, `-100` ist `AT_FDCWD`, `2`
 * ist `RENAME_EXCHANGE`. Der Tausch lässt den Namen nie verschwinden — genau
 * daran scheitert jede Prüfung, die zwischen Nachsehen und Zugriff liegt.
 *
 * **`call_user_func` und nicht `$ffi->syscall(...)`**, und das ist kein
 * Kunstgriff um den Analysator herum: `FFI` hat keine Methode `syscall`, sie
 * entsteht erst zur Laufzeit aus der `cdef`-Zeile. PHPStan meldet den direkten
 * Aufruf als `method.notFound` — zu Recht, denn statisch *gibt* es sie nicht.
 * Eine Unterdrückungsmarke stünde hier als erste im ganzen Repo; ein benannter
 * Aufruf sagt stattdessen, was passiert.
 *
 * **Und diese Marke lässt sich nicht einmal erwähnen.** Der erste Entwurf
 * dieses Absatzes nannte sie beim Namen — mit dem Klammeraffen davor —, und
 * PHPStan hat den Fliesstext als Anweisung gelesen: `ignore.parseError`,
 * nicht unterdrückbar. Dieselbe Familie wie das `%` in `crontab(5)` und das
 * `$` in einem PCRE-Muster:
 *
 * > **Ein Wort, das ein Parser als Anweisung liest, ist eine Anweisung — auch
 * > wenn es im Fliesstext steht.**
 */
function tausche(object $ffi, string $eins, string $zwei): void
{
    call_user_func([$ffi, 'syscall'], 316, -100, $eins, -100, $zwei, 2);
}

/** Ein Befund mit seiner Gegenprobe. */
function befund(string $was, bool $scharfHaelt, bool $stumpfTrifft, string $zahlen = ''): void
{
    if (! $stumpfTrifft) {
        // **Der wichtigste Zweig dieser Datei.** Trifft die Gegenprobe nicht,
        // ist nicht die Abwehr belegt — dann hat dieser Lauf nichts gemessen.
        Lauf::$stumm++;
        printf("  %-46s OHNE MESSUNG  %s\n", $was, $zahlen);
        printf("  %-46s   die Gegenprobe trifft nicht: der Angreifer ist zu langsam,\n", '');
        printf("  %-46s   nicht die Abwehr gut.\n", '');

        return;
    }

    if (! $scharfHaelt) {
        Lauf::$rot++;
        printf("  %-46s DURCHLAESSIG  %s\n", $was, $zahlen);

        return;
    }

    printf("  %-46s haelt         %s\n", $was, $zahlen);
}

function meldung(string $was, bool $ok, string $zusatz = ''): void
{
    if (! $ok) {
        Lauf::$rot++;
    }

    printf("  %-46s %s %s\n", $was, $ok ? 'ja  ' : 'NEIN', $zusatz);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n1. Vorbedingung: gibt es auf dieser Plattform, worauf die Grenze steht?\n\n";

/*
 * **Das ist die Messung, die `docs/50 §8` offen gelassen hat.** Der Container
 * ist Ubuntu 24.04 mit PHP 8.4; die Zielplattformen sind Debian 12 (8.2),
 * Debian 13, Ubuntu 22.04 (8.1) und Ubuntu 24.04. Eine `disable_functions` in
 * einer distro-php.ini ist keine exotische Annahme — und fiele eine dieser
 * Funktionen aus, fiele nicht ein Detail aus, sondern die Grenze.
 */
$noetig = [
    'pcntl_fork', 'pcntl_waitpid', 'pcntl_wifsignaled', 'pcntl_wtermsig', 'pcntl_wexitstatus',
    'posix_initgroups', 'posix_setgid', 'posix_setuid', 'posix_geteuid', 'posix_getuid',
    'posix_getgroups', 'posix_getpwnam', 'chroot', 'stream_socket_pair',
];

$fehlend = array_values(array_filter($noetig, static fn (string $f): bool => ! function_exists($f)));

printf("  PHP %s, SAPI %s, %s\n", PHP_VERSION, PHP_SAPI, php_uname('s').' '.php_uname('r'));
meldung('alle vierzehn Funktionen vorhanden', $fehlend === [], $fehlend === [] ? '' : 'fehlt: '.implode(', ', $fehlend));
meldung('läuft als root', posix_geteuid() === 0);

if ($fehlend !== [] || posix_geteuid() !== 0) {
    echo "\n  Ohne diese Vorbedingungen misst der Rest nichts. Abbruch.\n\n";
    exit(2);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n2. Ein Wegwerf-Abonnement nach §4.5\n\n";

exec('rm -rf '.escapeshellarg($wurzel).' '.escapeshellarg($aussen));
exec('groupadd -f '.NUTZER.' 2>&1');

if (posix_getpwnam(NUTZER) === false) {
    exec('useradd --gid '.NUTZER.' --no-user-group --no-create-home --shell /usr/sbin/nologin '.NUTZER.' 2>&1');
}

mkdir($wurzel.'/httpdocs', 0o755, true);
mkdir($wurzel.'/conf', 0o755, true);
mkdir($aussen, 0o755, true);
file_put_contents($aussen.'/geheim.txt', "AUSSERHALB\n");
file_put_contents($wurzel.'/httpdocs/drin.txt', "innen\n");
file_put_contents($wurzel.'/conf/nur-root.conf', "root\n");
chmod($wurzel.'/conf/nur-root.conf', 0o640);
symlink('/etc/passwd', $wurzel.'/httpdocs/raus');
symlink($aussen, $wurzel.'/httpdocs/raus-dir');
exec('chown -R '.NUTZER.':'.NUTZER.' '.escapeshellarg($wurzel.'/httpdocs'));
chown($wurzel, 'root');
chgrp($wurzel, 'root');
chmod($wurzel, 0o755);

meldung('Wurzel root:root 0755, Inhalt dem Abo', fileowner($wurzel) === 0 && (fileperms($wurzel) & 0o777) === 0o755);

// ─────────────────────────────────────────────────────────────────────────
echo "\n3. Der Vorgang läuft ohne Rechte — der Beleg, ohne den alles darunter\n";
echo "   auch dann grün wäre, wenn gar nichts liefe.\n\n";

$beleg = Sandbox::run($wurzel, NUTZER, static fn (): array => [
    'uid' => posix_getuid(),
    'groups' => posix_getgroups(),
    'gelesen' => trim((string) @file_get_contents('/httpdocs/drin.txt')),
]);

meldung('uid ist nicht 0', $beleg['uid'] !== 0, 'uid='.$beleg['uid']);
meldung('keine Gruppe 0', ! in_array(0, $beleg['groups'], true), 'gruppen='.implode(',', $beleg['groups']));
meldung('eine gültige Datei wird gelesen', $beleg['gelesen'] === 'innen', '„'.$beleg['gelesen'].'"');

// ─────────────────────────────────────────────────────────────────────────
echo "\n4. Der Ausbruch — scharf gegen stumpf\n\n";

/*
 * Die stumpfe Fassung ist derselbe Zugriff **ohne** Sandbox, also als root und
 * ohne Chroot. Sie muss gelingen; täte sie es nicht, wäre die Null darüber
 * kein Beleg, sondern ein Tippfehler im Pfad.
 */
$scharf = static fn (string $pfad): ?string => Sandbox::run($wurzel, NUTZER, static function () use ($pfad): ?string {
    $inhalt = @file_get_contents($pfad);

    return $inhalt === false ? null : trim($inhalt);
});

$stumpf = static function (string $pfad): ?string {
    $inhalt = @file_get_contents($pfad);

    return $inhalt === false ? null : trim($inhalt);
};

/*
 * **Die Gegenprobe braucht je Fall ihren eigenen Pfad, und das war zuerst
 * falsch.** Der erste Entwurf hängte für alle vier dieselbe Wurzel davor —
 * für den absoluten Pfad ergab das `<wurzel>/etc/passwd`, also etwas, das es
 * nicht gibt. Die Gegenprobe konnte dort **von Bauart wegen** nicht treffen,
 * und die Abwehr sah dadurch gut aus, ohne geprüft zu sein. Gemeldet hat es
 * dieses Skript selbst, weil es die fehlende Gegenprobe nicht als Erfolg
 * zählt.
 *
 * > Eine Gegenprobe, die nicht treffen *kann*, ist keine.
 */
foreach ([
    ['Symlink auf /etc/passwd', '/httpdocs/raus', $wurzel.'/httpdocs/raus'],
    ['Symlink auf ein fremdes Verzeichnis', '/httpdocs/raus-dir/geheim.txt', $wurzel.'/httpdocs/raus-dir/geheim.txt'],
    ['..-Ausbruch', '/../../../../etc/passwd', $wurzel.'/../../../../etc/passwd'],
    ['absoluter Pfad', '/etc/passwd', '/etc/passwd'],
] as [$was, $imChroot, $draussen]) {
    befund($was, $scharf($imChroot) === null, $stumpf($draussen) !== null);
}

// `conf/` gehört root — hier hält kein Chroot, sondern das Dateisystem.
befund('conf/ (root:root 0640) lesen', $scharf('/conf/nur-root.conf') === null, $stumpf($wurzel.'/conf/nur-root.conf') !== null);

// ─────────────────────────────────────────────────────────────────────────
echo "\n4b. Welche der beiden Wände hält? — der Weg einer echten Operation\n\n";

/*
 * **Abschnitt 4 nimmt eine Abkürzung, und deshalb beantwortet er diese Frage
 * nicht.** Er ruft {@see Sandbox::run()} unmittelbar auf und reicht rohe Pfade
 * hinein. Eine `files.*`-Operation tut etwas anderes: Sie schickt den Pfad
 * zuerst durch {@see Workspace::path()} und die Arbeit dann durch
 * {@see Workspace::run()}. Zwischen Formular und Datei stehen also **zwei**
 * Wände — eine Prüfung in PHP und eine Schranke vom Kernel.
 *
 * **Am 18. August hat das eine ganze Messung gekostet.** Auf `cloudsrv24`
 * liefen `tests/stumpf.sh a` und `b` und danach dieser Prüfstand — und die
 * Ausgabe war dreimal Zeile für Zeile dieselbe. Die Eingriffe treffen
 * `Workspace`, und Abschnitt 4 kommt dort nicht vorbei. `stumpf.sh --pruefen`
 * meldete dabei völlig zu Recht „ist stumpf": Es hatte die Wand geöffnet und
 * nachgewiesen, dass sie offen ist.
 *
 * > **Ein Nachweis, dass der Eingriff wirkt, sagt nichts darüber, dass der
 * > Prüfkörper dort vorbeikommt.**
 *
 * Dieser Abschnitt geht den Weg der Operation. Damit unterscheiden sich die
 * drei Bauten:
 *
 * | Bau | erwartet |
 * |---|---|
 * | scharf | hält |
 * | stumpf-A (ohne Normalisierung, mit Chroot) | **hält weiter** — A ist keine Schranke |
 * | stumpf-B (mit Normalisierung, ohne Chroot) | **bricht aus** — B trägt |
 *
 * Die mittlere Zeile ist die eigentliche Aussage: Dass der Angriff auch ohne
 * die Normalisierung nichts erreicht, ist der Beleg dafür, dass nicht sie ihn
 * hält. Wer nur scharf misst, kann beide Wände nicht auseinanderhalten.
 */

$journal = new Journal('/dev/null');
$kontext = new Context(new Runner($journal), $journal, static fn (array $zeile): null => null);
$arbeitsplatz = Workspace::fromArgs(['subscription' => ABO, 'user' => NUTZER]);

/*
 * **Der Bau sagt sich selbst an, und das ist kein Schmuck.** Drei Läufe, deren
 * Ausgabe gleich aussieht, sind nicht auseinanderzuhalten — genau das ist am
 * 18. August passiert, mit drei Logs, in denen nichts stand, aus welchem Bau
 * sie kamen.
 *
 * > **Drei Läufe, die nicht sagen, aus welchem Bau sie kommen, sind ein Log
 * > dreimal.**
 *
 * Erkannt wird am Verhalten und nicht am Quelltext: Normalisiert `path()` noch?
 * Und läuft die Arbeit in einem eigenen Prozess, also hinter einem `fork`?
 */
$normalisiert = Workspace::path('/a/../b') === '/b';
$eigenerProzess = $arbeitsplatz->run($kontext, static fn (): int => getmypid()) !== getmypid();

$bau = match (true) {
    $normalisiert && $eigenerProzess => 'scharf',
    ! $normalisiert && $eigenerProzess => 'stumpf-A (ohne Normalisierung)',
    $normalisiert && ! $eigenerProzess => 'stumpf-B (ohne Chroot)',
    default => 'stumpf-A+B (beide Wände weg)',
};

printf("  %-46s %s\n", 'Bau', $bau);
printf("  %-46s %s / %s\n", '  Normalisierung / eigener Prozess',
    $normalisiert ? 'ja' : 'nein', $eigenerProzess ? 'ja' : 'nein');

$durchOperation = static function (string $roh) use ($arbeitsplatz, $kontext): ?string {
    try {
        $pfad = Workspace::path($roh);
    } catch (Throwable) {
        return null;
    }

    return $arbeitsplatz->run($kontext, static function () use ($pfad): ?string {
        $inhalt = @file_get_contents($pfad);

        return $inhalt === false ? null : trim($inhalt);
    });
};

$ausgebrochen = 0;

/*
 * **Je Fall sein eigener Pfad, sobald das Chroot fehlt** — dieselbe Falle wie
 * in Abschnitt 4, und beim ersten Wurf dieses Abschnitts prompt wieder
 * hineingelaufen: Ohne Chroot bezeichnet `/httpdocs/raus` nicht mehr den
 * Symlink des Abonnements, sondern einen Pfad an der Wurzel des Systems, den es
 * nicht gibt. Die Zeile las sich als „hält" und war ein Prüfkörper, der sein
 * Ziel verfehlt.
 *
 * > **Eine Gegenprobe, die nicht treffen kann, ist keine.**
 *
 * Die beiden Pfad-Angriffe brauchen keine zweite Fassung: `..` und der absolute
 * Pfad ergeben nach der Normalisierung ohnehin `/etc/passwd`, und das ist ohne
 * Chroot die echte Datei.
 */
foreach ([
    ['..-Ausbruch über die Operation', '/../../../../etc/passwd', null],
    ['absoluter Pfad über die Operation', '/etc/passwd', null],
    ['Symlink auf /etc/passwd über die Operation', '/httpdocs/raus', $wurzel.'/httpdocs/raus'],
] as [$was, $roh, $ohneChroot]) {
    $inhalt = $durchOperation($eigenerProzess ? $roh : ($ohneChroot ?? $roh));
    $traf = $inhalt !== null && str_contains($inhalt, 'root:');

    if ($traf) {
        $ausgebrochen++;
    }

    printf("  %-46s %s\n", $was, $traf ? 'AUSBRUCH' : 'haelt');
}

/*
 * **Und die Erwartung hängt am Bau.** Ein Ausbruch ist hier kein Fehler,
 * sondern in stumpf-B der Beleg; sein Ausbleiben wäre dort der Befund.
 */
match (true) {
    $bau === 'scharf' => meldung('scharf: kein Ausbruch', $ausgebrochen === 0),
    str_starts_with($bau, 'stumpf-A ') => meldung('stumpf-A: hält weiter, A ist keine Schranke', $ausgebrochen === 0),
    default => meldung('stumpf-B: bricht aus, B trägt', $ausgebrochen > 0, sprintf('%d von 3', $ausgebrochen)),
};

// ─────────────────────────────────────────────────────────────────────────
echo "\n5. Das Rennen — der Angreifer aus docs/50 §3\n\n";

/*
 * Vier Prozesse tauschen ein Verzeichnis und einen Verweis atomar
 * (`renameat2(RENAME_EXCHANGE)`). Der Tausch lässt den Namen nie verschwinden;
 * genau daran scheitert jede Prüfung, die zwischen Nachsehen und Zugriff liegt.
 *
 * **Ohne FFI gibt es `renameat2` nicht.** Fehlt es, fällt dieser Abschnitt auf
 * `unlink`/`symlink` zurück — der ist schlechter, und das steht dann auch da.
 */
$dir = $wurzel.'/httpdocs/dir';
$tausch = $wurzel.'/httpdocs/.tausch';
exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg($tausch));
mkdir($dir, 0o755);
file_put_contents($dir.'/ziel.txt', "innen\n");
symlink($aussen, $tausch);
exec('chown -R '.NUTZER.':'.NUTZER.' '.escapeshellarg($wurzel.'/httpdocs'));
file_put_contents($aussen.'/ziel.txt', "AUSSERHALB\n");

$atomar = class_exists('FFI');
printf("  Tauschverfahren: %s\n", $atomar ? 'renameat2(RENAME_EXCHANGE), atomar' : 'unlink/symlink — schwächer, FFI fehlt');

$angreifer = static function () use ($dir, $tausch, $atomar, $aussen): array {
    $kinder = [];

    for ($i = 0; $i < 4; $i++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            if ($atomar) {
                $ffi = FFI::cdef('long syscall(long number, ...);', 'libc.so.6');

                for (; ;) {
                    tausche($ffi, $dir, $tausch);
                }
            }

            for (; ;) {
                @exec('rm -rf '.escapeshellarg($dir));
                @symlink($aussen, $dir);
                @unlink($dir);
                @mkdir($dir, 0o755);
                @file_put_contents($dir.'/ziel.txt', "innen\n");
            }
        }

        $kinder[] = $pid;
    }

    return $kinder;
};

$stoppen = static function (array $kinder): void {
    foreach ($kinder as $pid) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
};

$runden = 30000;

// Stumpf: die Prüfung, die bis P6 galt.
$kinder = $angreifer();
$stumpfTreffer = 0;

for ($i = 0; $i < $runden; $i++) {
    clearstatcache(true);

    if (is_link($dir) || @realpath($dir) !== $dir) {
        continue;
    }

    if (trim((string) @file_get_contents($dir.'/ziel.txt')) === 'AUSSERHALB') {
        $stumpfTreffer++;
    }
}

$stoppen($kinder);

// Scharf: dieselbe Arbeit in der Sandbox.
$kinder = $angreifer();
$scharfTreffer = Sandbox::run($wurzel, NUTZER, static function () use ($runden): int {
    $treffer = 0;

    for ($i = 0; $i < $runden; $i++) {
        clearstatcache(true);

        if (trim((string) @file_get_contents('/httpdocs/dir/ziel.txt')) === 'AUSSERHALB') {
            $treffer++;
        }
    }

    return $treffer;
});
$stoppen($kinder);

befund(
    'Tausch während des Zugriffs',
    $scharfTreffer === 0,
    $stumpfTreffer > 0,
    sprintf('scharf %d, stumpf %d von %d', $scharfTreffer, $stumpfTreffer, $runden),
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n6. Der Rückbau trifft nichts ausserhalb\n\n";

/*
 * Ein Durchgang bietet dem Angreifer genau einen Versuch, und das Fenster je
 * Knoten ist Mikrosekunden lang — deshalb viele Durchgänge. `docs/50` hat
 * genau hier zuerst null gemessen und nichts belegt.
 */
/*
 * **Die Gegenprobe läuft, bis sie trifft — und nicht eine feste Rundenzahl.**
 *
 * Der Treffer ist selten (ein Durchgang bietet dem Angreifer genau einen
 * Versuch, und das Fenster je Knoten ist Mikrosekunden lang). Mit sechzig
 * Durchgängen meldete dieses Skript deshalb mal vier Treffer und mal keinen —
 * und im zweiten Fall zu Recht „ohne Messung". Ein Tor, das bei jedem zweiten
 * Lauf sagt, es habe nichts gemessen, ist kein Tor.
 *
 * Gezählt wird deshalb, **wie viele Durchgänge die Gegenprobe gebraucht hat**,
 * und die scharfe Fassung bekommt danach mindestens ebenso viele. Bleibt die
 * Gegenprobe auch nach der Obergrenze stumm, sagt der Lauf das — dann ist
 * diese Maschine zu langsam für diesen Angriff, und das ist eine Auskunft und
 * kein Erfolg.
 */
$obergrenze = 400;

$durchgang = static function (string $art) use ($wurzel, $aussen, $atomar, $stoppen): bool {
    exec('rm -rf '.escapeshellarg($aussen));
    mkdir($aussen.'/heilig', 0o755, true);

    for ($i = 0; $i < 30; $i++) {
        file_put_contents($aussen.'/heilig/d'.$i.'.txt', 'x');
    }

    exec('rm -rf '.escapeshellarg($wurzel.'/httpdocs'));
    mkdir($wurzel.'/httpdocs/ziel', 0o755, true);

    for ($j = 0; $j < 8; $j++) {
        file_put_contents($wurzel.'/httpdocs/ziel/f'.$j, 'x');
    }

    @symlink($aussen, $wurzel.'/httpdocs/.tausch');
    exec('chown -R '.NUTZER.':'.NUTZER.' '.escapeshellarg($wurzel.'/httpdocs'));

    $kinder = [];

    for ($k = 0; $k < 4; $k++) {
        $pid = pcntl_fork();

        if ($pid === 0) {
            if ($atomar) {
                $ffi = FFI::cdef('long syscall(long number, ...);', 'libc.so.6');

                for (; ;) {
                    tausche($ffi, $wurzel.'/httpdocs/ziel', $wurzel.'/httpdocs/.tausch');
                }
            }

            for (; ;) {
                usleep(50);
            }
        }

        $kinder[] = $pid;
    }

    usleep(2000);

    if ($art === 'stumpf') {
        Filesystem::removeTree($wurzel.'/httpdocs');
    } else {
        Filesystem::purgeContents($wurzel, NUTZER);
    }

    $stoppen($kinder);

    return count(glob($aussen.'/heilig/*.txt') ?: []) < 30;
};

$stumpfRunden = 0;
$stumpfTreffer = 0;

while ($stumpfRunden < $obergrenze && $stumpfTreffer === 0) {
    $stumpfRunden++;

    if ($durchgang('stumpf')) {
        $stumpfTreffer++;
    }
}

$scharfTreffer = 0;

for ($z = 0; $z < max($stumpfRunden, 60); $z++) {
    if ($durchgang('scharf')) {
        $scharfTreffer++;
    }
}

$verlust = ['stumpf' => $stumpfTreffer, 'scharf' => $scharfTreffer];

befund(
    'Rückbau gegen den Tausch',
    $verlust['scharf'] === 0,
    $verlust['stumpf'] > 0,
    sprintf(
        'scharf %d von %d, stumpf %d nach %d Durchgängen',
        $verlust['scharf'],
        max($stumpfRunden, 60),
        $verlust['stumpf'],
        $stumpfRunden,
    ),
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n7. Die Wurzel selbst wird nicht angenommen\n\n";

foreach ([
    ['ausserhalb der Vhost-Wurzel', '/etc', NUTZER],
    ['die Vhost-Wurzel selbst', SubscriptionProvision::VHOSTS, NUTZER],
    ['ein Systembenutzer, den es nicht gibt', $wurzel, 'p9997'],
] as [$was, $pfad, $nutzer]) {
    $abgewiesen = false;

    try {
        Sandbox::run($pfad, $nutzer, static fn (): string => 'nie');
    } catch (AgentException) {
        $abgewiesen = true;
    }

    meldung($was.' abgewiesen', $abgewiesen);
}

// ─────────────────────────────────────────────────────────────────────────
exec('rm -rf '.escapeshellarg($wurzel).' '.escapeshellarg($aussen));
exec('userdel '.NUTZER.' 2>&1');
exec('groupdel '.NUTZER.' 2>&1');

echo "\n";

if (Lauf::$stumm > 0) {
    printf("%d Messung(en) ohne Gegenprobe — darüber ist nichts gesagt.\n", Lauf::$stumm);
}

if (Lauf::$rot > 0) {
    printf("%d Befund(e). Die Grenze hält auf dieser Maschine nicht.\n\n", Lauf::$rot);

    exit(1);
}

if (Lauf::$stumm > 0) {
    echo "Kein Befund, aber der Lauf ist unvollständig.\n\n";

    exit(3);
}

echo "Die Grenze hält — auf dieser Maschine, gemessen und gegengeprobt.\n\n";

exit(0);
