<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

/**
 * Was diese Maschine über sich sagt, ohne dass es ein Recht bräuchte.
 *
 * Drei Fragen, alle ohne den Agenten zu beantworten: Ein Systembenutzer steht in
 * `/etc/passwd`, das für jeden lesbar ist; der Eigentümer eines Verzeichnisses
 * braucht nur das Suchrecht auf dem Weg dorthin, und `/var/www/vhosts` steht
 * auf `0755`, jede Abo-Wurzel auch (`SubscriptionProvision::tree()`); und
 * `/etc/cron.d` ist ein Verzeichnis, das jeder lesen darf. Deshalb laufen G und
 * H aus `docs/98 §3` im Panel (`docs/98 §8`, Schritt 5: „kein Systemrecht
 * nötig").
 *
 * **Als Schnittstelle, damit die Regel einen Wächter haben kann.** Die
 * Antworten hängen an der Maschine, auf der der Test läuft; ein Test, der
 * `posix_getpwnam` direkt fragt, misst den Container mit. {@see LocalHost}
 * fragt das System, die Wächter geben Antworten vor.
 */
interface Host
{
    /** Die uid eines Systembenutzers — `null`, wenn es ihn nicht gibt. */
    public function uidOf(string $user): ?int;

    /**
     * Die uid des Eigentümers — `null`, wenn der Pfad nicht da ist.
     *
     * Ein Pfad, den der Panelprozess nicht erreichen kann, sieht genauso aus
     * wie einer, den es nicht gibt; `stat` unterscheidet das nicht. Für die
     * Wurzeln lebender Abonnements ist das gleichgültig — sie stehen auf
     * `0755` —, und nur die werden gefragt.
     */
    public function ownerOf(string $path): ?int;

    /**
     * Die Cron-Dateien des Panels unter `/etc/cron.d`, als Pfade.
     *
     * @return list<string>
     */
    public function cronFiles(): array;
}
