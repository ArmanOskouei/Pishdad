<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginIntegritySeal;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\PluginRouteTable;
use App\Services\Plugins\PluginTrustStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/**
 * K5.2-W / K0.4 — مهر یکپارچگی روی فایل‌های **استخراج‌شده**.
 *
 * سنجیده: مهر بعد از نصب زده می‌شود، یک بایتِ دستکاری آن را باطل می‌کند،
 * بازبینیِ دوباره روی نسخهٔ سالم سبز است، فهرست به ترتیبِ نوشتن وابسته نیست،
 * و نبودِ مهر روی یک نصبِ قدیمی **«تأییدنشده»** خوانده می‌شود نه «معتبر».
 *
 * سنجیده‌نشده: اینکه یک مهاجم با دسترسیِ کاملِ دیسک چه می‌تواند بکند. پاسخِ
 * صادقانه‌اش این است که می‌تواند فایل و مهر را پاک یا جایگزین کند، ولی **امضای
 * معتبر نمی‌سازد** چون کلید per-install در دیتابیس است. تست‌های «امضای جعلی»
 * و «مهرِ نسخهٔ دیگر» همین مرز را می‌سنجند.
 *
 * ⚠️ یادداشتِ ۶۰ ثانیه‌ایِ بازبینیِ سریع در `PluginIntegritySeal` مستند است و
 * اینجا آزموده نمی‌شود، چون یک حدّ کارایی است نه یک قراردادِ امنیتی. مرزِ
 * امنیتیِ واقعی «فعال‌سازی» است که همیشه هشِ کامل می‌گیرد.
 */
class PluginIntegritySealTest extends TestCase
{
    use RefreshDatabase;

    private PluginReleaseManager $releases;

    private PluginInstaller $installer;

    private PluginIntegritySeal $seal;

    private string $sandbox;

    private const SLUG = 'blog';

    /** slugِ مخصوصِ تستِ HTTP — ریشهٔ نصبِ واقعی را لمس می‌کند. */
    private const PROBE_SLUG = 'sealprobe';

    private const VERSION = '1.0.0';

    protected function setUp(): void
    {
        parent::setUp();

        $root = storage_path('framework/testing/plugin-seal/'.bin2hex(random_bytes(5)));
        mkdir($root, 0777, true);
        $this->sandbox = (string) realpath($root);

        $this->releases = new PluginReleaseManager($this->sandbox);
        $this->seal = new PluginIntegritySeal($this->releases, new PluginTrustStore);
        $this->installer = new PluginInstaller($this->releases, 0.25, $this->seal);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->sandbox);

        // ریشهٔ واقعیِ نصب هم باید پاک شود، وگرنه تستِ بعدی بسته‌ای مهرشده از
        // این تست پیدا می‌کند و سبز می‌ماند بی‌آنکه چیزی سنجیده باشد.
        app(PluginReleaseManager::class)->purge(self::PROBE_SLUG);
        (new PluginTrustStore)->forgetSealKeyFor(self::PROBE_SLUG);

        parent::tearDown();
    }

    // ── ۱) زدنِ مهر ─────────────────────────────────────────────────────────

    public function test_a_fresh_install_is_sealed_over_the_files_on_disk(): void
    {
        $installed = $this->install($this->packageZip());

        $this->assertTrue($installed['ok'], $installed['message']);

        $sealPath = $this->seal->sealPath(self::SLUG, (string) $installed['release']);

        // کنارِ پوشهٔ نسخه، **نه داخلش**. این همان «مهر، جزو بسته نیست» است؛
        // اگر روزی داخل بنشیند، `PluginInstallerTest` که شمارشِ دقیقِ درختِ
        // نسخه را می‌سنجد قرمز می‌شود.
        $this->assertFileExists($sealPath);
        $this->assertSame(
            dirname((string) $installed['path']),
            dirname($sealPath),
            'مهر باید کنارِ پوشهٔ نسخه بنشیند، نه داخلش.'
        );
        $this->assertNotContains($sealPath, $this->walk((string) $installed['path']));
    }

    public function test_the_seal_does_not_show_up_as_a_release(): void
    {
        $this->install($this->packageZip());

        $this->assertCount(1, $this->releases->releases(self::SLUG));
    }

    public function test_a_fresh_release_verifies_and_verifies_again(): void
    {
        $installed = $this->install($this->packageZip());
        $label = (string) $installed['release'];

        $first = $this->seal->verify(self::SLUG, $label);
        $second = $this->seal->verify(self::SLUG, $label);

        $this->assertTrue($first['ok'], $first['reason']);
        $this->assertSame('verified', $first['status']);
        $this->assertTrue($second['ok'], $second['reason']);
        $this->assertSame($first['digest'], $second['digest']);
    }

    // ── ۲) دستکاری ─────────────────────────────────────────────────────────

    public function test_tampering_one_extracted_byte_makes_the_seal_invalid(): void
    {
        $installed = $this->install($this->packageZip());
        $label = (string) $installed['release'];

        $this->assertTrue($this->seal->verify(self::SLUG, $label)['ok']);

        // **یک بایت** — نه یک فایل. شمارش و نام‌ها همان می‌ماند و تنها هش عوض
        // می‌شود، یعنی دقیقاً چیزی که فهرستِ قطعی باید بگیرد.
        $victim = (string) $installed['path'].'/Laravel/routes/api.php';
        file_put_contents($victim, file_get_contents($victim).' ');

        $verdict = $this->seal->verify(self::SLUG, $label);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('mismatch', $verdict['status']);
        $this->assertSame('seal.mismatch', $verdict['code']);
    }

    public function test_adding_a_file_to_a_sealed_release_breaks_the_seal(): void
    {
        $installed = $this->install($this->packageZip());
        $label = (string) $installed['release'];

        file_put_contents((string) $installed['path'].'/Laravel/src/Backdoor.php', '<?php // بد');

        $verdict = $this->seal->verify(self::SLUG, $label);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('mismatch', $verdict['status']);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $installed = $this->install($this->packageZip());
        $path = $this->seal->sealPath(self::SLUG, (string) $installed['release']);

        $body = json_decode((string) file_get_contents($path), true);

        // امضای درست‌طول ولی تصادفی: همان چیزی که یک مهاجم می‌نویسد.
        $body['signature'] = base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES));
        file_put_contents($path, (string) json_encode($body));

        $verdict = $this->seal->verify(self::SLUG, (string) $installed['release']);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('invalid_signature', $verdict['status']);
    }

    public function test_a_seal_copied_from_another_release_is_rejected(): void
    {
        $first = $this->install($this->packageZip());
        $second = $this->install($this->packageZip('2.0.0'), '2.0.0');

        copy(
            $this->seal->sealPath(self::SLUG, (string) $first['release']),
            $this->seal->sealPath(self::SLUG, (string) $second['release'])
        );

        $verdict = $this->seal->verify(self::SLUG, (string) $second['release']);

        $this->assertFalse($verdict['ok']);
        $this->assertSame('identity_mismatch', $verdict['status']);
    }

    // ── ۳) نصبِ قدیمی ──────────────────────────────────────────────────────

    public function test_a_legacy_release_without_a_seal_is_unverified_not_silently_ok(): void
    {
        $installed = $this->install($this->packageZip());
        $label = (string) $installed['release'];

        // دقیقاً چیزی که یک نصبِ پیش از K5.2-W روی دیسک دارد.
        unlink($this->seal->sealPath(self::SLUG, $label));

        $verdict = $this->seal->verify(self::SLUG, $label);

        $this->assertFalse($verdict['ok'], 'نبودِ مهر نباید «معتبر» خوانده شود.');
        $this->assertSame('unsealed', $verdict['status']);
        $this->assertSame('seal.unsealed', $verdict['code']);
        $this->assertNotSame('', $verdict['reason'], 'پیامِ «تأییدنشده» باید برسد.');
    }

    public function test_resealing_a_legacy_release_makes_it_verified(): void
    {
        $installed = $this->install($this->packageZip());
        $label = (string) $installed['release'];

        unlink($this->seal->sealPath(self::SLUG, $label));
        $this->assertFalse($this->seal->verify(self::SLUG, $label)['ok']);

        $resealed = $this->seal->reseal(self::SLUG, $label);

        $this->assertTrue($resealed['ok'], $resealed['message']);
        $this->assertTrue($this->seal->verify(self::SLUG, $label)['ok']);
    }

    // ── ۴) قطعی‌بودن ───────────────────────────────────────────────────────

    public function test_the_manifest_does_not_depend_on_write_order(): void
    {
        $a = $this->tree(['b.php' => 'two', 'a.php' => 'one', 'c/d.php' => 'three']);
        $b = $this->tree(['c/d.php' => 'three', 'a.php' => 'one', 'b.php' => 'two']);

        $this->assertSame($this->seal->manifestFor($a)['digest'], $this->seal->manifestFor($b)['digest']);

        $this->removeTree($a);
        $this->removeTree($b);
    }

    public function test_the_manifest_covers_the_manifest_file_itself(): void
    {
        $first = $this->install($this->packageZip());
        $second = $this->install($this->packageZip('2.0.0'), '2.0.0');

        // تنها تفاوتِ دو بسته `manifest.json` است (نسخه). اگر فهرست آن را
        // نمی‌دید، یک مهاجم می‌توانست مانیفستِ روی دیسک را عوض کند — مثلاً
        // مجوزها یا نقاط اتصال — و فهرست همان می‌ماند.
        $this->assertNotSame(
            $this->seal->manifestFor((string) $first['path'])['digest'],
            $this->seal->manifestFor((string) $second['path'])['digest']
        );
    }

    // ── ۵) دروازهٔ dispatch ────────────────────────────────────────────────

    public function test_dispatch_guard_allows_a_sealed_active_release(): void
    {
        $installed = $this->install($this->packageZip());
        $this->activate($this->zipPath);

        $guard = $this->seal->guardForDispatch(self::SLUG);

        $this->assertTrue($guard['ok'], $guard['reason']);
        $this->assertSame('verified', $guard['status']);
        $this->assertSame($installed['release'], $guard['release']);
    }

    public function test_dispatch_guard_blocks_a_tampered_active_release(): void
    {
        $installed = $this->install($this->packageZip());
        $this->activate($this->zipPath);

        file_put_contents((string) $installed['path'].'/Laravel/routes/api.php', '<?php // بد');

        $guard = $this->seal->guardForDispatch(self::SLUG);

        $this->assertFalse($guard['ok']);
        $this->assertSame('mismatch', $guard['status']);
    }

    public function test_dispatch_guard_still_serves_an_unsealed_legacy_release_but_says_so(): void
    {
        $installed = $this->install($this->packageZip());
        $this->activate($this->zipPath);

        unlink($this->seal->sealPath(self::SLUG, (string) $installed['release']));

        $guard = $this->seal->guardForDispatch(self::SLUG);

        // ⚠️ این «اجازه» است و عمدی: fail-closed اینجا یعنی هر ارتقای هسته
        // همهٔ نصب‌های موجود را می‌اندازد. مهم این است که برچسبِ وضعیت
        // **صادقانه** باشد: `unsealed`، نه `verified`.
        $this->assertTrue($guard['ok']);
        $this->assertSame('unsealed', $guard['status']);
        $this->assertNotSame('', $guard['reason']);
    }

    // ── ۶) دروازه روی HTTP واقعی ───────────────────────────────────────────

    /**
     * ⭐ تنها تستی که ثابت می‌کند `PluginRouter` **واقعاً** دروازه را صدا می‌زند.
     *
     * تست‌های بالا روی خودِ سرویس کار می‌کنند. اگر `PluginRouter` دروازه را
     * صدا نزند، همه‌شان سبز می‌مانند و بستهٔ دستکاری‌شده اجرا می‌شود — یعنی
     * دقیقاً همان «نصب شد ولی بی‌اثر بود»، این بار برای امنیت.
     *
     * اینجا برعکسِ بقیه، **ریشهٔ واقعیِ نصب** استفاده می‌شود (نه sandbox)، چون
     * کنترلر، `PluginIntegritySeal` را از container می‌گیرد و آن هم
     * `PluginReleaseManager` پیش‌فرض را. با sandbox، کنترلر اصلاً بسته را نمی‌دید
     * و تستِ سبز، هیچی را نمی‌سنجید.
     */
    public function test_http_dispatch_stops_when_the_active_release_was_tampered_with(): void
    {
        $this->serveProbePluginOverHttp();
        $releaseDir = $this->probeReleaseDir();

        $this->getJson('/api/v1/p/sealprobe/ping')->assertOk();

        file_put_contents($releaseDir.'/Laravel/routes/api.php', '<?php // بد');

        $response = $this->getJson('/api/v1/p/sealprobe/ping');

        $response->assertStatus(409);
        $this->assertSame('seal.mismatch', $response->json('code'));
        $this->assertStringContainsString('یکپارچگی', (string) $response->json('message'));

        // بازگرداندنِ فایل باید دوباره سبز کند — یعنی ۴۰۹ از وضعیتِ دیسک آمده،
        // نه از یک پرچمِ یک‌بار‌مصرف در حافظه.
        file_put_contents($releaseDir.'/Laravel/routes/api.php', '<?php');

        $this->getJson('/api/v1/p/sealprobe/ping')->assertOk();
    }

    // ── کمک‌کارها ───────────────────────────────────────────────────────────

    /**
     * یک بستهٔ واقعی روی ریشهٔ واقعیِ نصب، و یک رکوردِ فعال و تأییدشده.
     *
     * `PingController` عمداً در فایلِ بسته نوشته می‌شود تا از همان PSR-4 بارگذاری
     * شود — اگر کلاس در فرایند از قبل تعریف شده باشد، باز هم همین مسیر را
     * می‌رود و تفاوتی در نتیجه ندارد.
     */
    private function serveProbePluginOverHttp(): void
    {
        $zip = $this->probeZip();
        $digest = (string) hash_file('sha256', $zip);

        $result = app(PluginInstaller::class)->install($zip, self::PROBE_SLUG, '1.0.0', $digest);
        $this->assertTrue($result['ok'], $result['message']);

        app(PluginReleaseManager::class)->activate(self::PROBE_SLUG, '1.0.0', $digest);

        $user = User::query()->firstOrCreate(
            ['email' => 'seal-probe-owner@example.test'],
            ['name' => 'Probe Owner', 'password' => Hash::make('secret')]
        );

        Plugin::query()->create([
            'user_id' => $user->id,
            'name' => 'Seal Probe',
            'slug' => self::PROBE_SLUG,
            'version' => '1.0.0',
            'active' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
            'checksum' => $digest,
            'manifest' => [
                'slug' => self::PROBE_SLUG,
                'api' => [
                    'prefix' => self::PROBE_SLUG,
                    'routes' => [
                        [
                            'method' => 'get',
                            'path' => 'ping',
                            'handler' => 'Pishdad\\Plugins\\Sealprobe\\Http\\PingController@show',
                        ],
                    ],
                ],
            ],
        ]);

        PluginRouteTable::flush();
    }

    private function probeReleaseDir(): string
    {
        return (string) app(PluginReleaseManager::class)->currentDir(self::PROBE_SLUG);
    }

    private function probeZip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sealprobe').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ([
            'manifest.json' => (string) json_encode([
                'name' => 'پروبِ مهر',
                'slug' => self::PROBE_SLUG,
                'version' => '1.0.0',
            ], JSON_UNESCAPED_UNICODE),
            'Laravel/routes/api.php' => '<?php',
            'Laravel/src/Http/PingController.php' => <<<'PHP'
                <?php

                namespace Pishdad\Plugins\Sealprobe\Http;

                class PingController
                {
                    public function show(): array
                    {
                        return ['pong' => true];
                    }
                }

                PHP,
        ] as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function activate(string $zip): string
    {
        $this->releases->activate(self::SLUG, self::VERSION, (string) hash_file('sha256', $zip));

        return basename(str_replace('\\', '/', (string) $this->releases->currentDir(self::SLUG)));
    }

    /** @return array<string, mixed> */
    private function install(string $zip, string $version = self::VERSION): array
    {
        $this->zipPath = $zip;

        return $this->installer->install($zip, self::SLUG, $version, hash_file('sha256', $zip) ?: null);
    }

    private function packageZip(string $version = self::VERSION): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plugin-seal').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ([
            'manifest.json' => (string) json_encode([
                'name' => 'پلاگین بلاگ',
                'slug' => self::SLUG,
                'version' => $version,
            ], JSON_UNESCAPED_UNICODE),
            'Laravel/src/Models/Post.php' => '<?php // سالم',
            'Laravel/routes/api.php' => '<?php',
            'Laravel/config/plugin.php' => '<?php',
            'Next.js/panel/Widget.tsx' => 'export const Widget = () => null;',
        ] as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /** @param  array<string, string>  $files */
    private function tree(array $files): string
    {
        $root = $this->sandbox.'/tree-'.bin2hex(random_bytes(4));
        mkdir($root, 0777, true);

        foreach ($files as $name => $contents) {
            $path = $root.'/'.$name;

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return $root;
    }

    /** @return list<string> */
    private function walk(string $root): array
    {
        $out = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }

    private string $zipPath = '';
}
