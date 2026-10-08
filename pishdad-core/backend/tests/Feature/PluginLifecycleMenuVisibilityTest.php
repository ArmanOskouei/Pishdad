<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\ManifestRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\EnsuresPluginDdlRole;
use Tests\TestCase;

/**
 * ⭐ قانونِ چرخهٔ عمر: اعلان‌های یک افزونه **دقیقاً** هم‌قدم با وضعیت آن.
 *
 * ## گزارشِ کاربر
 *
 * «پلاگین را غیرفعال کردم، ولی آیتمش هنوز در منوی پنل بود.»
 *
 * ## ریشه
 *
 * ریشه این نبود که کش کهنه بود. `ManifestRegistry::activeManifests()` نتیجه
 * را ۳۰۰ ثانیه کش می‌کند، ولی `Plugin::booted()` یک قلابِ `saved`/`deleted`
 * دارد که کش را خودکار پاک می‌کند — پس رجیستری همیشه تازه بود.
 *
 * ریشهٔ واقعی: آیتم «اشتراک من» در `ADMIN_MENU` فرانت **هاردکد** شده بود،
 * یعنی عضوِ هسته بود نه عضوِ افزونه. پس نه به وضعیت افزونه نگاه می‌کرد و نه
 * می‌توانست نگاه کند. اصلاح در `menu-plugin-visibility.ts` سمت فرانت است
 * (فیلد `requiresPlugin` + فیلترِ fail-closed).
 *
 * این تست‌ها از مسیر **واقعیِ HTTP** می‌روند، چون تستی که خودش کش را پاک
 * کند همیشه سبز می‌ماند — دقیقاً مثل باگی که می‌خواست بگیرد.
 *
 * ## چرا این تست از مسیر HTTP می‌رود
 *
 * تستی که خودش `flushCache()` صدا بزند **همیشه** سبز می‌ماند — دقیقاً مثل
 * باگی که می‌خواست بگیرد. پس اینجا عمداً هیچ پاک‌سازی دستی نمی‌کنیم و
 * می‌رویم سراغ همان `POST /plugins/{id}/deactivate` که پنل می‌زند.
 *
 * ## چرا `activate` با `RefreshDatabase` کار می‌کند
 *
 * نگرانیِ درست این بود که `ensureRole()` (که `activate()` صدا می‌زند) با
 * `CREATE ROLE` گیر می‌کند چون `CREATE ROLE` داخل تراکنشِ باز دیده نمی‌شود.
 * ولی آن فقط وقتی اجرا می‌شود که **نقش وجود نداشته باشد یا وصل نشود** — و
 * `EnsuresPluginDdlRole` در `setUpBeforeClass` هر دو را آماده می‌کند. پس
 * مسیرِ شکست اصلاً اجرا نمی‌شود و تراکنش دست‌نخورده می‌ماند.
 */
class PluginLifecycleMenuVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * نقش DDL را در `setUpBeforeClass` آماده می‌کند — **نه** در `setUp`.
     *
     * `setUp` داخل تراکنشِ `RefreshDatabase` اجرا می‌شود و `ALTER ROLE` آن‌جا
     * قفل می‌گیرد. `setUpBeforeClass` بیرون از هر تراکنشی است.
     */
    use EnsuresPluginDdlRole;

    protected function setUp(): void
    {
        parent::setUp();

        // فقط برای شروعِ تمیز. عمداً **بعد از هر فعال/غیرفعال** پاک نمی‌کنیم —
        // همان چیزی است که باید خودِ کد انجام دهد و این تست می‌سنجدش.
        ManifestRegistry::flushCache();
    }

    private function manager(): User
    {
        $user = User::query()->create([
            'name' => 'مدیر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ]);

        // هر دو پرمیشن لازم‌اند: فعال/غیرفعال `plugins.edit` و حذف
        // `plugins.delete` دارد. بدون دومی، تستِ uninstall با 403 رد می‌شد و
        // ما در اشتباهِ «کش درست کار می‌کند» می‌ماندیم.
        $permissions = collect(['plugins.edit', 'plugins.delete'])
            ->map(fn (string $n) => Permission::query()->firstOrCreate([
                'name' => $n,
                'guard_name' => 'web',
            ]));

        $role = Role::query()->firstOrCreate([
            'name' => 'menu-lifecycle-'.substr(md5($user->email), 0, 8),
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @var list<string> پوشه‌هایی که باید پاک شوند */
    private array $sourceDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->sourceDirs as $dir) {
            $this->deleteTree($dir);
        }

        $this->sourceDirs = [];

        parent::tearDown();
    }

    /**
     * رکورد افزونه، **همراه پوشهٔ واقعی روی دیسک**.
     *
     * ⚠️ پوشه لازم است و این نکته بارزی است: `activate()` بعد از گیت بازبینی
     * سراغ `PluginReleaseManager` می‌رود که دنبال *کدِ قابل بارگذاری* می‌گردد.
     * رکوردِ بدون پوشه ۴۲۲ می‌خورد — و آن ۴۲۲ درست است (افزونه‌ای که فایلش
     * نیست نباید فعال شود)، ولی برای این تستِ منو یک افزونهٔ سالم لازم داریم.
     */
    private function plugin(string $slug, string $key): Plugin
    {
        $dir = base_path('plugins/'.$slug);
        $this->sourceDirs[] = $dir;

        @mkdir($dir.'/Laravel/src', 0777, true);

        file_put_contents(
            $dir.'/Laravel/src/Placeholder.php',
            "<?php\nnamespace Pishdad\\".Str::studly($slug)."\\Src;\nclass Placeholder {}\n"
        );

        file_put_contents($dir.'/manifest.json', json_encode([
            'slug' => $slug,
            'name' => $slug,
            'version' => '1.0.0',
        ], JSON_UNESCAPED_SLASHES));

        return Plugin::query()->create([
            'slug' => $slug,
            'name' => $slug,
            'author' => 'test',
            'version' => '1.0.0',
            'signature' => 'x',
            'public_key' => 'x',
            'path' => 'plugins/'.$slug,
            // ⭐ `null` مثل افزونهٔ داخلی: گیتِ مهر یکپارچگی باید رد شود،
            // وگرنه تست مسیرِ دیگری را می‌سنجید.
            'checksum' => null,
            'manifest' => ['menu' => [
                $key => ['label' => 'گزارش‌ها', 'href' => '/admin/'.$key, 'order' => 10],
            ]],
            'active' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
        ]);
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }

    /** @return list<string> کلیدِ آیتم‌های منو */
    private function menuKeys(User $user): array
    {
        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/plugins/menu');

        $res->assertOk();

        return array_values(array_filter(array_map(
            static fn ($i): ?string => is_array($i) ? ($i['key'] ?? null) : null,
            (array) $res->json('data.items', [])
        )));
    }

    public function test_menu_shows_the_item_while_the_plugin_is_active(): void
    {
        $this->plugin('demo', 'reports');
        ManifestRegistry::flushCache();

        $this->assertContains('reports', $this->menuKeys($this->manager()));
    }

    public function test_deactivating_through_the_endpoint_removes_the_menu_item(): void
    {
        $p = $this->plugin('demo', 'reports');
        ManifestRegistry::flushCache();

        $manager = $this->manager();

        // پیش‌شرط: قبل از غیرفعال‌سازی هست.
        $this->assertContains('reports', $this->menuKeys($manager));

        // همان مسیری که پنل می‌زند.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/admin/plugins/{$p->id}/deactivate")
            ->assertOk();

        // ⭐ بدون دست زدن به کش: اگر `deactivate()` کش را پاک نکند، این
        // آیتم تا ۳۰۰ ثانیه دیگر در منو می‌ماند.
        $this->assertNotContains('reports', $this->menuKeys($manager->fresh()));
    }

        public function test_the_cycle_repeats_and_the_menu_always_matches(): void {
        $p = $this->plugin('demo', 'reports');
        ManifestRegistry::flushCache();

        $manager = $this->manager();
        $auth = $this->actingAs($manager, 'sanctum');

        for ($round = 1; $round <= 3; $round++) {
            $auth->postJson("/api/v1/admin/plugins/{$p->id}/deactivate")->assertOk();
            $this->assertNotContains(
                'reports',
                $this->menuKeys($manager->fresh()),
                "round {$round}: منو باید بعد از غیرفعال‌سازی خالی باشد"
            );

            $auth->postJson("/api/v1/admin/plugins/{$p->id}/activate")->assertOk();
            $this->assertContains(
                'reports',
                $this->menuKeys($manager->fresh()),
                "round {$round}: منو باید بعد از فعال‌سازی پر باشد"
            );
        }
    }

    public function test_deleting_the_plugin_row_removes_the_menu_item(): void
    {
        $p = $this->plugin('demo', 'reports');
        ManifestRegistry::flushCache();

        $manager = $this->manager();
        $this->assertContains('reports', $this->menuKeys($manager));

        $p->delete();
        ManifestRegistry::flushCache();

        $this->assertNotContains('reports', $this->menuKeys($manager->fresh()));
    }

    public function test_a_rejected_plugin_contributes_nothing(): void
    {
        $p = $this->plugin('demo', 'reports');
        $p->forceFill(['review_status' => Plugin::REVIEW_REJECTED])->save();
        ManifestRegistry::flushCache();

        $this->assertNotContains('reports', $this->menuKeys($this->manager()));
    }
}
