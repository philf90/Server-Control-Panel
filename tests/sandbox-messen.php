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
use SrvPanel\Agent\Filesystem;
use SrvPanel\Agent\Ops\SubscriptionProvision;
use SrvPanel\Agent\Sandbox;

const ABO = 'sandbox-messung.probe';
const NUTZER = 'p9998';

$wurzel = SubscriptionProvision::VHOSTS.'/'.ABO;
$aussen = '/tmp/sandbox-messung-aussen';

$rot = 0;
$stumm = 0;

/** Ein Befund mit seiner Gegenprobe. */
function befund(string $was, bool $scharfHaelt, bool $stumpfTrifft, string $zahlen = ''): void
{
    global $rot, $stumm;

    if (! $stumpfTrifft) {
        // **Der wichtigste Zweig dieser Datei.** Trifft die Gegenprobe nicht,
        // ist nicht die Abwehr belegt — dann hat dieser Lauf nichts gemessen.
        $stumm++;
        printf("  %-46s OHNE MESSUNG  %s\n", $was, $zahlen);
        printf("  %-46s   die Gegenprobe trifft nicht: der Angreifer ist zu langsam,\n", '');
        printf("  %-46s   nicht die Abwehr gut.\n", '');

        return;
    }

    if (! $scharfHaelt) {
        $rot++;
        printf("  %-46s DURCHLAESSIG  %s\n", $was, $zahlen);

        return;
    }

    printf("  %-46s haelt         %s\n", $was, $zahlen);
}

function meldung(string $was, bool $ok, string $zusatz = ''): void
{
    global $rot;

    if (! $ok) {
        $rot++;
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
                    $ffi->syscall(316, -100, $dir, -100, $tausch, 2);
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
                    $ffi->syscall(316, -100, $wurzel.'/httpdocs/ziel', -100, $wurzel.'/httpdocs/.tausch', 2);
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

if ($stumm > 0) {
    printf("%d Messung(en) ohne Gegenprobe — darüber ist nichts gesagt.\n", $stumm);
}

if ($rot > 0) {
    printf("%d Befund(e). Die Grenze hält auf dieser Maschine nicht.\n\n", $rot);

    exit(1);
}

if ($stumm > 0) {
    echo "Kein Befund, aber der Lauf ist unvollständig.\n\n";

    exit(3);
}

echo "Die Grenze hält — auf dieser Maschine, gemessen und gegengeprobt.\n\n";

exit(0);
