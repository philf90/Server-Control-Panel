<?php

declare(strict_types=1);

/**
 * Was eine API v1 an diesem Panel vorfindet — die Messrunde vor B7.
 *
 *     php tests/api-messen.php
 *
 * **Warum es diese Datei gibt.** `docs/128` M8 hat den Bestand gemessen und
 * ausdrücklich gesagt, was es *nicht* sagt: nichts über Tokens, nichts über
 * eine Route ohne `can:`, nichts über Ratenbegrenzung. Genau diese Lücken
 * misst dieses Skript — und es liegt im Repo, damit die nächste Fassung
 * dieselbe Frage nicht neu erfindet.
 *
 * **Jede Anfrage bekommt eine frische Anwendung, und das ist der Kern.**
 * `Tenancy` ist ein Singleton; zwei Anfragen in einem Prozess teilen sich
 * seinen Zustand, php-fpm tut das nicht. Der erste Anlauf dieser Runde hat
 * genau daran gemessen, was die vorige Anfrage hinterlassen hatte: Die
 * Gegenprobe „ohne Wache" gab **200**, weil die Klammer des Kunden noch stand.
 * Als *erste* Anfrage eines Prozesses gibt dieselbe Route **404**.
 *
 *   Ein Prüfstand, der mehrere Anfragen in einem Prozess fährt, misst den
 *   Zustand, den die vorige hinterlassen hat.
 *
 * **Jede Messung hat ihre Gegenprobe**, und eine Null ist nur dann eine
 * Messung, wenn daneben etwas anderes als Null steht.
 */
$REPO = dirname(__DIR__);
$ARBEIT = '/var/tmp/srvpanel-api-messung';
$DB = $ARBEIT.'/messung.sqlite';

@mkdir($ARBEIT, 0o755, true);
@unlink($DB);
touch($DB);

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$DB);
putenv('DB_FOREIGN_KEYS=true');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('CACHE_STORE=array');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $DB;
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';

require $REPO.'/vendor/autoload.php';

use App\Http\Middleware\ApplyTenancy;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

function titel(string $t): void
{
    printf("\n=== %s\n", $t);
}

function wert(string $k, string $v): void
{
    printf("  %-52s %s\n", $k, $v);
}

function satz(string $s): void
{
    printf("  %s\n", $s);
}

/**
 * Eine frische Anwendung — eine je Anfrage.
 *
 * @return array{0: Application, 1: HttpKernel}
 */
function frisch(string $repo): array
{
    $app = require $repo.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $kernel = $app->make(HttpKernel::class);

    return [$app, $kernel];
}

/**
 * Eine Anfrage durch den echten Kernel, in einer frischen Anwendung.
 *
 * @param  callable(): void  $routen
 * @param  array<string, string>  $kopf
 * @return array{status: int, typ: string, ziel: string, rumpf: string}
 */
function anfrage(string $repo, callable $routen, string $uri, array $kopf = []): array
{
    [$app, $kernel] = frisch($repo);

    $routen();

    $server = [];
    foreach ($kopf as $name => $inhalt) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $inhalt;
    }

    $antwort = $kernel->handle(Request::create($uri, 'GET', [], [], [], $server));

    $ergebnis = [
        'status' => $antwort->getStatusCode(),
        'typ' => (string) $antwort->headers->get('Content-Type'),
        'ziel' => (string) $antwort->headers->get('Location'),
        'rumpf' => substr((string) $antwort->getContent(), 0, 120),
    ];

    $kernel->terminate(Request::create($uri), $antwort);
    $app->flush();

    return $ergebnis;
}

// ─────────────────────────────────────────────────────────────────────────────
// Der Bestand, einmal aufgebaut. Danach liest jede Anfrage ihn aus der Datei.
// ─────────────────────────────────────────────────────────────────────────────
[$app, $kernel] = frisch($REPO);
$app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);

$eigen = Customer::factory()->create();
$fremd = Customer::factory()->create();
$eigenesAbo = Subscription::factory()->create(['customer_id' => $eigen->id]);
$fremdesAbo = Subscription::factory()->create(['customer_id' => $fremd->id]);
$konto = Account::factory()->customer($eigen)->create();

$KONTO = (int) $konto->id;
$EIGEN = (int) $eigenesAbo->id;
$FREMD = (int) $fremdesAbo->id;

$app->flush();

/** Wegwerf-Wache: ein Konto aus einer Kopfzeile, ohne Sitzung. */
final class ProbeGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = $request->header('X-Probe-Token');

        if ($id !== null) {
            $konto = Account::query()->find((int) $id);

            if ($konto !== null) {
                Auth::setUser($konto);
            }
        }

        return $next($request);
    }
}

$ladebeleg = anfrage($REPO, function (): void {
    Route::get('/api/ladebeleg', fn () => response()->json(['ok' => true]));
}, '/api/ladebeleg');

titel('A0 · Ladebeleg — der Prüfstand antwortet überhaupt');
wert('GET /api/ladebeleg', $ladebeleg['status'].'  '.$ladebeleg['rumpf']);
satz('Ohne diese Zeile ist jede Null darunter von „nichts gemessen" nicht zu trennen.');

// ─────────────────────────────────────────────────────────────────────────────
titel('A1 · Die Mittelschichtgruppen, am gebooteten Kernel');

[$app, $kernel] = frisch($REPO);
$gruppen = $app->make(Router::class)->getMiddlewareGroups();
foreach ($gruppen as $name => $liste) {
    wert('Gruppe '.$name, count($liste).' Einträge');
    foreach ($liste as $i => $m) {
        satz(sprintf('    %2d  %s', $i + 1, is_string($m) ? $m : gettype($m)));
    }
}
$app->flush();

satz('');
satz('Die Vorgabegruppe `api` trägt SubstituteBindings und sonst nichts —');
satz('die Bindung liefe damit vor jeder Klammer. Was das anrichtet: A3.');

// ─────────────────────────────────────────────────────────────────────────────
titel('A2 · Was `api/*` heute tut, ohne dass es eine API gibt');

$offen = anfrage($REPO, function (): void {
    Route::middleware('web')->get('/api/probe', fn () => response()->json(['ok' => true]));
}, '/api/probe');

$gast = anfrage($REPO, function (): void {
    Route::middleware(['web', 'auth'])->get('/api/gesichert', fn () => response()->json(['ok' => true]));
}, '/api/gesichert');

$apiWirft = anfrage($REPO, function (): void {
    Route::middleware('web')->get('/api/wirft', function (): never {
        throw new RuntimeException('Messkoerper');
    });
}, '/api/wirft');

$webWirft = anfrage($REPO, function (): void {
    Route::middleware('web')->get('/web/wirft', function (): never {
        throw new RuntimeException('Messkoerper');
    });
}, '/web/wirft');

wert('offen, ohne Wache', (string) $offen['status']);
wert('hinter `auth`, ohne Konto', $gast['status'].($gast['ziel'] !== '' ? ' -> '.$gast['ziel'] : '  (keine Weiterleitung)'));
wert('Ausnahme unter api/', $apiWirft['status'].'  '.$apiWirft['typ']);
wert('Gegenprobe · Ausnahme unter web/', $webWirft['status'].'  '.$webWirft['typ']);
satz('');
satz('`shouldRenderJsonWhen(api/*)` steht seit jeher in bootstrap/app.php — eine');
satz('Vorkehrung fuer ein Merkmal, das es nicht gibt. Gemessen tut sie, was eine');
satz('API braucht: 401 statt einer Weiterleitung, und JSON statt HTML.');

// ─────────────────────────────────────────────────────────────────────────────
titel('A3 · Traegt die Mandantenklammer eine Wache ohne Sitzung?');

$richtig = static function (): void {
    Route::middleware([
        ProbeGuard::class,
        ApplyTenancy::class,
        SubstituteBindings::class,
        'can:view,subscription',
    ])->get('/api/abo/{subscription}', fn (Subscription $subscription) => response()->json(['id' => $subscription->id]));
};

$falsch = static function (): void {
    Route::middleware([
        SubstituteBindings::class,
        ProbeGuard::class,
        ApplyTenancy::class,
        'can:view,subscription',
    ])->get('/api/abo/{subscription}', fn (Subscription $subscription) => response()->json(['id' => $subscription->id]));
};

$kopf = ['X-Probe-Token' => (string) $KONTO];

wert('Klammer vor Bindung · eigenes Abo', (string) anfrage($REPO, $richtig, '/api/abo/'.$EIGEN, $kopf)['status']);
wert('Klammer vor Bindung · fremdes Abo', (string) anfrage($REPO, $richtig, '/api/abo/'.$FREMD, $kopf)['status']);
wert('Klammer vor Bindung · ID, die es nicht gibt', (string) anfrage($REPO, $richtig, '/api/abo/999999', $kopf)['status']);
wert('Gegenprobe · ohne Wache, eigenes Abo', (string) anfrage($REPO, $richtig, '/api/abo/'.$EIGEN)['status']);
satz('');
wert('Bindung vor Klammer · eigenes Abo', (string) anfrage($REPO, $falsch, '/api/abo/'.$EIGEN, $kopf)['status']);
wert('Bindung vor Klammer · fremdes Abo', (string) anfrage($REPO, $falsch, '/api/abo/'.$FREMD, $kopf)['status']);

// ─────────────────────────────────────────────────────────────────────────────
titel('A4 · Die Liste, wenn die Klammer nie gesetzt wurde');

$liste = static function (): void {
    Route::middleware([
        ProbeGuard::class,
        ApplyTenancy::class,
    ])->get('/api/liste', fn () => response()->json(Subscription::query()->pluck('id')->all()));
};

$mit = anfrage($REPO, $liste, '/api/liste', $kopf);
$ohne = anfrage($REPO, $liste, '/api/liste');

wert('mit Wache', $mit['status'].'  '.$mit['rumpf']);
wert('ohne Wache', $ohne['status'].'  '.$ohne['rumpf']);
satz('');
satz('`docs/128` M8 sagt es voraus: Ein Kunde ohne Abonnements und eine Route, die');
satz('den Mandanten zu setzen vergisst, sehen von aussen gleich aus — 200 und eine');
satz('leere Liste. Die Bindung faellt auf, die Liste nicht.');

// ─────────────────────────────────────────────────────────────────────────────
titel('A5 · Was die Pruefung eines Tokens je Anfrage kostet');

$klartext = str_repeat('a', 48);

$t = microtime(true);
for ($i = 0; $i < 10000; $i++) {
    hash('sha256', $klartext);
}
$sha = (microtime(true) - $t) / 10000 * 1000;

$hash = password_hash($klartext, PASSWORD_BCRYPT, ['cost' => 12]);
$t = microtime(true);
for ($i = 0; $i < 10; $i++) {
    password_verify($klartext, $hash);
}
$bcrypt = (microtime(true) - $t) / 10 * 1000;

wert('sha256 je Pruefung', sprintf('%.5f ms', $sha));
wert('bcrypt(cost 12) je Pruefung', sprintf('%.2f ms', $bcrypt));
wert('Faktor', sprintf('%.0f', $bcrypt / max($sha, 1e-9)));
satz('');
satz('Ein Token ist 48 Zeichen aus einem Zufallsgenerator und kein Passwort eines');
satz('Menschen; der Arbeitsfaktor von bcrypt schuetzt eine Entropie, die hier nicht');
satz('fehlt. Gemessen kostet er dafuer je Anfrage mehr als der ganze Rest.');

// ─────────────────────────────────────────────────────────────────────────────
titel('A6 · Der Grundzustand der Klammer, ohne jede Anfrage');

[$app, $kernel] = frisch($REPO);
$tenancy = $app->make(Tenancy::class);
wert('frisch · unrestricted()', $tenancy->unrestricted() ? 'true' : 'false');
wert('frisch · isSet()', $tenancy->isSet() ? 'true' : 'false');
wert('frisch · Subscription::count()', (string) Subscription::query()->count());
$tenancy->allowAll();
wert('Gegenprobe · nach allowAll()', (string) Subscription::query()->count());
$app->flush();

satz('');
satz('Die Null ist eine Messung, weil daneben eine Zwei steht.');

printf("\n");
