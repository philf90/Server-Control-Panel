<?php

declare(strict_types=1);

namespace App\Support\Diagnose;

use SrvPanel\Agent\Cron\CronFile;

/**
 * {@see Host} für die Maschine, auf der das Panel läuft.
 *
 * Nichts hier schreibt, und nichts hier braucht mehr als der Panelprozess
 * ohnehin hat. Das Muster der Cron-Dateien kommt von dort, wo sie geschrieben
 * werden ({@see CronFile}); ein zweites `srvpanel-` in dieser Klasse wäre
 * dieselbe Regel an zwei Orten.
 */
final class LocalHost implements Host
{
    public function uidOf(string $user): ?int
    {
        $entry = posix_getpwnam($user);

        return $entry === false ? null : (int) $entry['uid'];
    }

    public function ownerOf(string $path): ?int
    {
        $owner = @fileowner($path);

        return $owner === false ? null : $owner;
    }

    public function cronFiles(): array
    {
        $files = glob(CronFile::DIR.'/'.CronFile::PREFIX.'*');

        return $files === false ? [] : $files;
    }
}
