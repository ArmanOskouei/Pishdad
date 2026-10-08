<?php

namespace Tests\Feature;

use App\Http\Controllers\Install\InstallController;
use Tests\TestCase;

/**
 * E51 — پیش‌فرض‌های داکرآگاهِ گام ۲.
 *
 * `127.0.0.1` داخلِ کانتینر یعنی «خودِ کانتینر»، پس فرمِ ازپیش‌پرشده همیشه
 * refused می‌گرفت. این تست‌ها قاعدهٔ «مقدارِ داده‌شده برنده است، وگرنه
 * پیش‌فرضِ محیط» را قفل می‌کنند. شاخه‌بندی روی `file_exists('/.dockerenv')`
 * است تا در هر محیطی (کانتینر یا میزبانِ توسعه) سبز بماند.
 */
class InstallDatabasePrefillTest extends TestCase
{
    /** @return array<string, string> */
    private function defaults(array $config = []): array
    {
        $method = new \ReflectionMethod(InstallController::class, 'withDatabaseDefaults');
        $method->setAccessible(true);

        return $method->invoke(new InstallController, $config);
    }

    public function test_given_values_always_win(): void
    {
        $given = [
            'host' => 'db.example.com',
            'port' => '5433',
            'database' => 'shop',
            'username' => 'shop',
            'password' => 's3cret',
        ];

        $this->assertSame($given, $this->defaults($given));
    }

    public function test_empty_values_fall_back_to_environment_defaults(): void
    {
        $out = $this->defaults([]);

        if (file_exists('/.dockerenv')) {
            $this->assertSame('db', $out['host']);
            $this->assertSame('cms', $out['database']);
            $this->assertSame('cms', $out['username']);
            $this->assertSame('cmssecret', $out['password']);
        } else {
            $this->assertSame('127.0.0.1', $out['host']);
            $this->assertSame('Pishdad', $out['database']);
            $this->assertSame('Pishdad', $out['username']);
            $this->assertNotSame('', $out['password']);
        }

        $this->assertSame('5432', $out['port']);
    }
}
