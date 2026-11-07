<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Plans\Quota;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Ein Kontingent, das die Kundenansicht weglässt, begrenzt auch nichts.
 *
 * **Der Anlass:** Unter „Kontingente" stand für jeden Kunden „FTP-Konten: 5",
 * und FTP-Konten gibt es nicht — P6 hat einen SFTP-Zugang je Abonnement
 * gebaut. Gefunden beim Ausschreiben des Durchlaufs mit einer fremden Person
 * (`docs/145 §0`, Befund 9). Seitdem lässt die Kundenansicht weg, was
 * {@see Quota::limits()} verneint; was sie zeigt, misst `SubscriptionQuotaRowsTest`.
 *
 * **Hier steht die Gegenrichtung.** Fängt eines Tages jemand an, ein solches
 * Kontingent zu lesen, begrenzt es etwas — und dann gehört es wieder vor den
 * Kunden. Gesucht wird der Fall des Enums beim Namen, ausserhalb der
 * Planklassen: Der Wert `ftp_accounts` steht auch in `Permission`, als Recht
 * und nicht als Kontingent.
 */
final class QuotaLimitsTest extends TestCase
{
    public function test_ftp_accounts_limit_nothing_and_the_rest_does(): void
    {
        $this->assertFalse(Quota::FtpAccounts->limits());
        $this->assertTrue(Quota::DiskMb->limits(), 'Die Gegenprobe: Der Speicherplatz begrenzt etwas.');
        $this->assertTrue(Quota::CronJobs->limits(), 'Die Gegenprobe: Die Cronjobs begrenzen etwas.');
    }

    public function test_a_quota_that_limits_nothing_is_read_nowhere(): void
    {
        $root = dirname(__DIR__, 2);
        $gelesen = [];
        $geprueft = 0;

        foreach (Quota::cases() as $quota) {
            if ($quota->limits()) {
                continue;
            }

            $geprueft++;

            foreach (['app', 'agent/src'] as $directory) {
                /** @var SplFileInfo $file */
                foreach (new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS),
                ) as $file) {
                    $path = str_replace($root.'/', '', $file->getPathname());

                    if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($path, 'app/Support/Plans/')) {
                        continue;
                    }

                    if (preg_match('/\bQuota::'.$quota->name.'\b/', (string) file_get_contents($file->getPathname())) === 1) {
                        $gelesen[] = sprintf('Quota::%s — %s', $quota->name, $path);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $geprueft, 'Kein Kontingent ist als „begrenzt nichts" erklärt — dann prüft dieser Test nichts.');

        $this->assertSame([], $gelesen, sprintf(
            "Diese Kontingente gelten als „begrenzt nichts\" und werden doch gelesen:\n  %s\n\n".
            'Dann begrenzen sie etwas, und die Kundenansicht verschweigt eine Grenze. '.
            'Quota::limits() gehört angepasst.',
            implode("\n  ", $gelesen),
        ));
    }
}
