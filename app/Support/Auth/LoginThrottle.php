<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Ratenbegrenzung der Anmeldung, je IP und je Konto (§6.4).
 *
 * **Die ansteigende Sperre.** Nach einer Handvoll Fehlversuchen wird gewartet,
 * und die Wartezeit wächst mit jedem weiteren Versuch: eine Minute, zwei,
 * fünf, fünfzehn, eine Stunde. Ein Mensch, der sein Passwort vertippt, merkt
 * davon nichts; ein Programm, das eine Liste durchprobiert, kommt nach dem
 * dritten Anlauf praktisch zum Stillstand.
 *
 * **Warum die Sperre je Konto schwächer ist als die je IP.** Beides zu zählen
 * ist richtig, aber die Kontosperre hat eine unangenehme Kehrseite: Wer die
 * Adresse eines Betreibers kennt, könnte ihn durch absichtliche Fehlversuche
 * dauerhaft aussperren — von jeder beliebigen IP aus, ohne je ein Passwort zu
 * erraten. Aus einer Schutzmaßnahme wird dann eine Angriffsfläche.
 *
 * Deshalb sind die Grenzen unterschiedlich gewählt: Die IP-Sperre steigt bis
 * zu einer Stunde, die Kontosperre ist bei fünf Minuten gedeckelt. Ein
 * Angreifer, der ein Konto blockieren will, erreicht damit höchstens eine
 * Verzögerung von fünf Minuten je Welle — und die IP, von der er es versucht,
 * ist längst gesperrt. Wer das Konto wirklich übernehmen will, scheitert an
 * der IP-Sperre; wer nur stören will, richtet wenig aus.
 */
final class LoginThrottle
{
    /** Ab so vielen Fehlversuchen greift die Sperre. */
    private const FREE_ATTEMPTS = 5;

    /** Wartezeiten in Sekunden, je weiterem Fehlversuch nach den freien. */
    private const IP_DELAYS = [60, 120, 300, 900, 3600];

    private const ACCOUNT_DELAYS = [60, 120, 300];

    /** Nach dieser Zeit ohne Fehlversuch beginnt die Zählung von vorn. */
    private const WINDOW = 3600;

    public function __construct(private readonly Cache $cache) {}

    /**
     * Wie lange muss noch gewartet werden? 0 heißt: Versuch erlaubt.
     */
    public function secondsUntilAllowed(string $ip, string $email): int
    {
        return max(
            $this->remainingFor($this->ipKey($ip)),
            $this->remainingFor($this->accountKey($email)),
        );
    }

    public function tooManyAttempts(string $ip, string $email): bool
    {
        return $this->secondsUntilAllowed($ip, $email) > 0;
    }

    /**
     * Einen Fehlversuch vermerken.
     *
     * Die Sperre wird beim Vermerken gesetzt, nicht beim Prüfen — sonst
     * könnte ein Angreifer, der viele Anfragen gleichzeitig schickt, die
     * Prüfung passieren, bevor die erste Antwort geschrieben ist.
     */
    public function recordFailure(string $ip, string $email): void
    {
        $this->bump($this->ipKey($ip), self::IP_DELAYS);
        $this->bump($this->accountKey($email), self::ACCOUNT_DELAYS);
    }

    /** Eine gelungene Anmeldung räumt beide Zähler ab. */
    public function clear(string $ip, string $email): void
    {
        foreach ([$this->ipKey($ip), $this->accountKey($email)] as $key) {
            $this->cache->forget($key.':count');
            $this->cache->forget($key.':until');
        }
    }

    /**
     * @param  list<int>  $delays
     */
    private function bump(string $key, array $delays): void
    {
        $count = ((int) $this->cache->get($key.':count', 0)) + 1;
        $this->cache->put($key.':count', $count, self::WINDOW);

        $over = $count - self::FREE_ATTEMPTS;

        if ($over <= 0) {
            return;
        }

        $delay = $delays[min($over, count($delays)) - 1];
        $this->cache->put($key.':until', time() + $delay, $delay);
    }

    private function remainingFor(string $key): int
    {
        $until = (int) $this->cache->get($key.':until', 0);

        return max(0, $until - time());
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.hash('sha256', $ip);
    }

    /**
     * Die Adresse wird gehasht und kleingeschrieben abgelegt.
     *
     * Kleingeschrieben, damit „Admin@…" und „admin@…" denselben Zähler
     * treffen — sonst wäre die Kontosperre mit einer Großschreibung umgangen.
     * Gehasht, weil im Cache sonst eine Liste der Adressen läge, unter denen
     * jemand Anmeldeversuche unternommen hat.
     */
    private function accountKey(string $email): string
    {
        return 'login:account:'.hash('sha256', Str::lower(trim($email)));
    }
}
