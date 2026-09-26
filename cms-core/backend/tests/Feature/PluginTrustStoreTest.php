<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\Setting;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Services\Plugins\PluginTrustStore;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * K0.4 / K5.9 — کلید مهر یکپارچگی per-install.
 *
 * ریشهٔ B19: `ensureSealKey()` کلید را با `Cache::forever` می‌نوشت. یک
 * `cache:clear` یا flush ریدایس کلید را بی‌صدا نابود می‌کرد و آن‌وقت همهٔ
 * بسته‌های قبلی «مهرشان نامعتبر» می‌شد بدون اینکه کسی بفهمد چرا. تست‌های
 * زیر دقیقاً همین را قفل می‌کنند: کلید باید از هر مسیرِ کش جان سالم به در ببرد.
 *
 * نام `group`/`key` در جدول `settings` اینجا عمداً **با رشتهٔ ثابت** نوشته
 * شده، نه با ثابتِ کلاس. تغییر نام این تنظیم یعنی کلیدِ نصب‌های موجود
 * دیگر پیدا نمی‌شود — دقیقاً همان بی‌صدا شدنی که این فایل برای جلوگیری از
 * آن نوشته شده.
 */
class PluginTrustStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $secretKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        // کلید از خود `keypair()` ساخته می‌شود، نه از `random_bytes` با طول
        // ثابت. چون `ext-sodium` اینجا نصب نیست و polyfill، اندازهٔ secret
        // را متفاوت اعلام می‌کند — `random_bytes(32)` کلیدی می‌دهد که این
        // runtime آن را نمی‌پذیرد.
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($pair));
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    private function store(): PluginTrustStore
    {
        return app(PluginTrustStore::class);
    }

    private function plugin(string $slug = 'acme-blog'): Plugin
    {
        return Plugin::query()->create([
            'user_id' => null,
            'name' => 'Blog',
            'slug' => $slug,
            'version' => '1.0.0',
            'active' => false,
            'system' => false,
            'source' => Plugin::SOURCE_LOCAL,
            'review_status' => Plugin::REVIEW_PENDING,
        ]);
    }

    /**
     * شنوندهٔ لاگ می‌گذارد و ظرفش را برمی‌گرداند. `ArrayObject` لازم است چون
     * آرایه با مقدار کپی می‌شود و چیزی که برگردانده می‌شود هنوز خالی است.
     *
     * @return ArrayObject<int, string> هر خط لاگ، به‌همراه context، به شکل متن
     */
    private function captureLog(): ArrayObject
    {
        $lines = new ArrayObject;

        Log::listen(function (MessageLogged $event) use ($lines): void {
            $lines[] = $event->level.' '.$event->message.' '
                .(string) json_encode($event->context, JSON_UNESCAPED_UNICODE);
        });

        return $lines;
    }

    /**
     * خط‌هایی از لاگ که کلید مهر در آن‌ها لو رفته است.
     *
     * @param  ArrayObject<int, string>  $lines
     * @return list<string>
     */
    private function logLinesMentioning(string $secret, ArrayObject $lines): array
    {
        $hits = [];

        foreach ($lines as $line) {
            if (str_contains($line, $secret)) {
                $hits[] = $line;
            }
        }

        return $hits;
    }

    public function test_seal_key_survives_a_cache_flush(): void
    {
        $key = $this->store()->ensureSealKey('acme-blog');

        Cache::flush();
        Cache::flush();

        $this->assertSame(
            $key,
            $this->store()->ensureSealKey('acme-blog'),
            'flush کش نباید کلید مهر را عوض کند؛ وگرنه مهرهای قبلی بی‌صدا نامعتبر می‌شوند.'
        );
    }

    public function test_cache_flush_does_not_remove_the_persisted_key(): void
    {
        $key = $this->store()->ensureSealKey('acme-blog');

        Cache::flush();

        $row = Setting::query()
            ->where('group', 'plugin')
            ->where('key', 'seal_key_acme-blog')
            ->first();

        $this->assertNotNull($row, 'کلید مهر باید خارج از کش، در جدول settings بماند.');
        $this->assertSame($key, $row->value['secret'] ?? null);
    }

    public function test_ensure_seal_key_is_idempotent(): void
    {
        $first = $this->store()->ensureSealKey('acme-blog');
        $second = $this->store()->ensureSealKey('acme-blog');

        $this->assertSame($first, $second);
        $this->assertSame(
            1,
            Setting::query()->where('group', 'plugin')->where('key', 'seal_key_acme-blog')->count(),
            'دو فراخوانی نباید دو رکورد بسازند.'
        );
    }

    public function test_generated_seal_key_is_a_usable_ed25519_secret_key(): void
    {
        $key = $this->store()->ensureSealKey('acme-blog');

        $raw = base64_decode($key, true);
        $this->assertIsString($raw);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($raw));
        $this->assertSame(
            SODIUM_CRYPTO_SIGN_BYTES,
            strlen(sodium_crypto_sign_detached('integrity-payload', $raw)),
            'کلید باید واقعاً بتواند مهر بزند، وگرنه در فاز ۱ هیچ مهری ساخته نمی‌شود.'
        );
    }

    public function test_a_wrong_length_seal_key_is_rebuilt(): void
    {
        $truncated = base64_encode(substr(random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES), 0, 16));
        Setting::set('plugin', 'seal_key_acme-blog', ['secret' => $truncated]);

        $key = $this->store()->ensureSealKey('acme-blog');

        $this->assertNotSame($truncated, $key, 'کلید ناقص باید بازسازی شود، نه اینکه همان بماند.');
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($key, true)));
    }

    public function test_a_non_base64_seal_key_is_rebuilt(): void
    {
        Setting::set('plugin', 'seal_key_acme-blog', ['secret' => 'این-کلید-نیست']);

        $key = $this->store()->ensureSealKey('acme-blog');

        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($key, true)));
    }

    public function test_a_seal_key_row_without_secret_is_rebuilt(): void
    {
        Setting::set('plugin', 'seal_key_acme-blog', ['note' => 'دستکاری‌شده']);

        $key = $this->store()->ensureSealKey('acme-blog');

        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($key, true)));
    }

    public function test_seal_keys_are_isolated_per_plugin(): void
    {
        $blog = $this->store()->ensureSealKey('acme-blog');
        $shop = $this->store()->ensureSealKey('acme-shop');

        $this->assertNotSame($blog, $shop, 'هر پلاگین مهر جدا دارد؛ وگرنه uninstall یکی دیگری را باطل می‌کند.');
        $this->assertSame($blog, $this->store()->ensureSealKey('acme-blog'));
        $this->assertSame($shop, $this->store()->ensureSealKey('acme-shop'));
    }

    public function test_a_blank_slug_is_rejected(): void
    {
        // بدون این محافظ، همهٔ slugهای تهی به یک ردیف مشترک می‌ریختند و یک
        // پلاگین می‌توانست مهرِ پلاگین دیگر را جعل کند.
        $this->expectException(InvalidArgumentException::class);

        $this->store()->ensureSealKey('   ');
    }

    public function test_seal_key_never_reaches_the_log(): void
    {
        $lines = $this->captureLog();

        $key = $this->store()->ensureSealKey('acme-blog');

        $this->assertSame([], $this->logLinesMentioning($key, $lines), 'ساخت کلید نباید چیزی لاگ کند.');
    }

    public function test_rebuilt_seal_key_never_reaches_the_log(): void
    {
        Setting::set('plugin', 'seal_key_acme-blog', ['secret' => 'کلید-خراب']);

        $lines = $this->captureLog();

        $key = $this->store()->ensureSealKey('acme-blog');

        $this->assertNotEmpty(
            $lines,
            'بازسازی کلید خراب باید ثبت شود، وگرنه خرابی بی‌صدا می‌ماند.'
        );
        $this->assertSame([], $this->logLinesMentioning($key, $lines));
    }

    public function test_ensure_seal_key_for_matches_the_slug_based_key(): void
    {
        $plugin = $this->plugin('acme-blog');

        $this->assertSame(
            $this->store()->ensureSealKey('acme-blog'),
            $this->store()->ensureSealKeyFor($plugin)
        );
    }

    public function test_seal_key_is_not_written_onto_the_plugin_row(): void
    {
        $plugin = $this->plugin('acme-blog');

        $key = $this->store()->ensureSealKeyFor($plugin);

        $attributes = (string) json_encode($plugin->fresh()->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(
            $key,
            $attributes,
            'کلید مهر نباید روی رکورد پلاگین بنشیند؛ آن رکورد در پاسخ API برگردانده می‌شود.'
        );
    }

    public function test_publisher_signature_verification_still_works(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);

        $verifier = app(PluginSignatureVerifier::class);
        $manifest = ['slug' => 'acme-blog', 'name' => 'Blog', 'version' => '1.0.0'];
        $manifest['signature'] = $verifier->sign($manifest, base64_encode(sodium_crypto_sign_secretkey($pair)));

        $result = $this->store()->verifyPublisherSignature($manifest);

        $this->assertTrue($result['valid'], $result['reason']);
        $this->assertSame('', $result['reason']);
    }
}
