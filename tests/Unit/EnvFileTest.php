<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SrvPanel\Agent\EnvFile;

final class EnvFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/srvpanel-env-'.bin2hex(random_bytes(6)).'.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_writes_and_reads_back(): void
    {
        (new EnvFile($this->path))->write(['APP_KEY' => 'base64:abc', 'DB_DATABASE' => 'srvpanel']);

        $values = (new EnvFile($this->path))->read();

        $this->assertSame('base64:abc', $values['APP_KEY']);
        $this->assertSame('srvpanel', $values['DB_DATABASE']);
    }

    public function test_is_not_readable_for_everyone(): void
    {
        (new EnvFile($this->path))->write(['APP_KEY' => 'geheim']);

        // In dieser Datei stehen Schlüssel und Datenbankpasswort. Ein Bit zu
        // viel in den Rechten, und jeder Systembenutzer liest sie.
        $this->assertSame(0o640, fileperms($this->path) & 0o777);
    }

    public function test_a_second_setup_keeps_the_existing_key(): void
    {
        $file = new EnvFile($this->path);
        $file->write(['APP_KEY' => 'erster-schluessel', 'DB_PASSWORD' => 'erstes-passwort']);

        $existing = $file->read();

        // So läuft der zweite Durchgang von panel.provision: Bestehendes wird
        // gelesen und mit den Vorgaben zusammengelegt, nicht ersetzt. Wechselte
        // der Schlüssel, wäre die Datenbank danach unlesbar.
        $file->write(array_merge($existing, ['APP_NAME' => 'SrvPanel']));

        $after = $file->read();

        $this->assertSame('erster-schluessel', $after['APP_KEY']);
        $this->assertSame('erstes-passwort', $after['DB_PASSWORD']);
        $this->assertSame('SrvPanel', $after['APP_NAME']);
    }

    public function test_ignores_comments_and_blank_lines(): void
    {
        file_put_contents($this->path, "# Kommentar\n\nAPP_KEY=abc\n  DB_HOST = 127.0.0.1 \nkaputt\n");

        $values = (new EnvFile($this->path))->read();

        $this->assertSame(['APP_KEY' => 'abc', 'DB_HOST' => '127.0.0.1'], $values);
    }

    public function test_missing_file_reads_as_empty(): void
    {
        $this->assertSame([], (new EnvFile($this->path))->read());
        $this->assertFalse((new EnvFile($this->path))->exists());
    }
}
