<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugins\PluginDdlConnection;
use App\Services\Plugins\PluginDdlProvisioner;
use App\Services\Plugins\PluginSignatureVerifier;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\PluginDdlNoTransactionCase;
use ZipArchive;

/**
 * K5.12 — آیا provisioning واقعاً در مسیرِ سرتاسری انجام می‌شود؟
 *
 * ## چرا این تست جدا از `PluginPrefixGuardTest` است
 *
 * آن یکی روی سرویس‌ها آزمون می‌کرد و `ensureRole()` را **خودش** صدا می‌زد. اگر
 * هیچ‌کس در مسیرِ واقعیِ فعال‌سازی صدایش نزند، همهٔ آن ۲۱ تست سبز و بی‌اثر
 * می‌مانند.
 *
 * این را با mutation کشف کردم: نقش را حذف کردم و دیدم تست‌ها سبز می‌مانند چون
 * خودشان می‌سازندش. این تست همان چیزی را می‌سنجد که آن‌ها **نمی‌سنجند**: آیا
 * مسیرِ واقعی، خودش را آماده می‌کند یا نه.
 *
 * ## چرا `RefreshDatabase` ندارد
 *
 * اولش داشت و همه‌چیز قفل می‌کرد. علتش قابل حدس نبود: تراکنشِ بازِ تست قفلِ
 * سطری روی جدول‌های هسته می‌گیرد، و `grantMinimal()` که `REVOKE ... ON ALL
 * TABLES` می‌زند برای نوشتنِ `relacl` قفلِ انحصاری می‌خواهد. یعنی **هر دو
 * منتظرِ هم می‌ماند** و اجرا ۱۵ دقیقه طول می‌کشد.
 *
 * این فقط مشکلِ تست نیست: یعنی `grantMinimal()` نمی‌تواند داخلِ تراکنشِ باز
 * اجرا شود. در تولید هم پیش از هر تراکنشی صدا زده می‌شود، ولی این باید
 * **دانسته** باشد نه اینکه کسی بعداً provisioning را داخل یک تراکنش بگذارد و
 * سایت را قفل کند. پس نه `RefreshDatabase` و نه تراکنشِ باز.
 */
final class PluginDdlProvisioningFlowTest extends PluginDdlNoTransactionCase
{
    private PluginDdlProvisioner $provisioner;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();

        $kp = sodium_crypto_sign_keypair();
        config(['plugins.public_key' => base64_encode(sodium_crypto_sign_publickey($kp))]);
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($kp));

        $this->provisioner = app(PluginDdlProvisioner::class);

        $this->ensureGuardInstalled();
        $this->cleanSlate();
    }

    /**
     * پاک کردن ردِ اجراهای قبلی.
     *
     * این تست `RefreshDatabase` ندارد (دلیلش در docblock کلاس است)، پس رکوردِ
     * افزونه از اجرای قبلی می‌ماند و `upload` با «پلاگینی با این شناسه قبلاً نصب
     * شده» رد می‌شود. این خطا **بی‌ربط** به K5.12 است و تست را در جای اشتباه
     * قرمز می‌کرد.
     *
     * ضمناً جدول‌های ساخته‌شده و فایل نصب‌شده هم باید بروند، وگرنه نسخهٔ بعدی
     * روی نسخهٔ قبلی سوار می‌شود.
     */
    /**
     * پاک کردن ردِ اجراهای قبلی.
     *
     * این تست `RefreshDatabase` ندارد (دلیلش در docblock کلاس است)، پس رکوردِ
     * افزونه و **تاریخِ migration** از اجرای قبلی می‌مانند.
     *
     * تاریخ ماندنش خطرناک‌تر بود: `PluginMigrator` هر migration ثبت‌شده را
     * «اجرا شده» می‌داند و **skip** می‌کند — حتی وقتی جدولش روی دیسک نیست. پس
     * نصب دوباره ساکت شکست می‌خورد و گاردِ `missingTables` می‌گوید «بسته migration
     * ندارد»، در حالی که بسته دارد و قبلاً هم اجرا شده. تشخیصِ این سه حالت از هم
     * کارِ کاربر نیست.
     *
     * اگر روزی این تست `RefreshDatabase` گرفت، این پاک‌سازی باید برود — ولی آن
     * روز باید دلیلِ قفل شدن (`grantMinimal` و `REVOKE … ON ALL TABLES`) اول حل
     * شود.
     */
    private function cleanSlate(): void
    {
        Plugin::query()->where('slug', 'sampleplugin')->delete();
        DB::table('pishdad_plugin_migrations')->where('slug', 'sampleplugin')->delete();
        DB::table('plugin_table_prefixes')->where('slug', 'sampleplugin')->delete();

        $tables = DB::select(
            "select tablename from pg_tables where schemaname = current_schema() and tablename like 'sampleplugin\_%'"
        );

        foreach ($tables as $row) {
            DB::statement('drop table if exists "'.$row->tablename.'" cascade');
        }

        foreach (glob(storage_path('app/plugins/shared/sampleplugin*')) ?: [] as $file) {
            @unlink($file);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanSlate();

        // نقش را **پاک نمی‌کنیم** — `PluginDdlTestCase` آن را در
        // `setUpBeforeClass` می‌سازد و بین کلاس‌ها زنده نگه می‌دارد. پاک کردنش
        // اینجا باعث می‌شد تستِ بعدی نقش را با رمزِ نادرست دوباره بسازد.
        parent::tearDown();
    }

    private function ensureGuardInstalled(): void
    {
        if (DB::getSchemaBuilder()->hasTable('plugin_table_prefixes')) {
            return;
        }

        $migration = require database_path('migrations/2026_10_07_000001_guard_plugin_table_prefixes.php');
        $migration->up();
    }

    /**
     * مسیرِ واقعی: نصبِ افزونه باید نقش را بسازد.
     *
     * فرضِ تست: اگر مسیرِ نصب خودش `ensureRole()` را صدا نزند، افزونه فعال
     * می‌شود ولی `PluginMigrator` با `migration.ddl_unavailable` رد می‌کند — یعنی
     * نصبِ بدونِ DDL، و کاربر یک خطای گنگ دربارهٔ افزونه می‌بیند در حالی که
     * مشکل، پیکربندیِ سرور است.
     */
    public function test_installing_a_plugin_provisions_the_role(): void
    {
        $this->provisioner->dropRole();
        $this->assertFalse($this->provisioner->roleExists(), 'پیش‌فرضِ تست باید نبودِ نقش باشد.');

        // قبل از درخواست، وضعیت را ثبت می‌کنیم تا بعد مقایسه کنیم. اگر درخواست
        // خودش نقش را بسازد، این دو باید فرق کنند.
        $before = app(PluginDdlConnection::class)->isAvailable();

        $id = $this->installAndActivate(['notes']);

        $this->assertFalse($before, 'پیش از درخواست نباید نقشی در دسترس باشد.');

        $this->assertTrue(
            $this->provisioner->roleExists(),
            'مسیرِ نصب باید نقش DDL را می‌ساخت. اگر این قرمز شد یعنی '
            .'`ensureRole()` به هیچ مسیرِ واقعی‌ای وصل نیست.'
        );

        $this->assertTrue(
            app(PluginDdlConnection::class)->isAvailable(),
            'نقش ساخته شده ولی اتصال با رمزِ پیکربندی کار نمی‌کند.'
        );

        $this->assertGreaterThan(0, $id);
    }

    /**
     * اگر رمز در پیکربندی نباشد، نصب باید **با پیام درست** رد شود.
     *
     * بدون این، خطای رمز به شکل «افزونه مشکل دارد» درمی‌آید و مدیر سایت دنبالِ
     * بستهٔ ZIP می‌گردد در حالی که مشکل نبودِ یک متغیر محیطی است.
     */
    public function test_a_missing_password_is_reported_as_a_server_problem(): void
    {
        // `PLUGIN_DDL_PASSWORD` در `phpunit.agenta.xml` **با `force=true`** تعریف
        // شده، یعنی هر فراخوانیِ دوبارهٔ `putenv` نادیده گرفته می‌شود. پس خالی
        // کردنش از راه `putenv` کار نمی‌کند و باید از راه `config` باشد — که
        // `isAvailable()` هم از همان می‌خواند.
        config(['database.connections.plugin_ddl.password' => '']);

        $auth = $this->owner();
        $id = $this->upload($auth)->json('data.id');

        $this->approveAsOperator((int) $id);

        $response = $auth->postJson("/api/v1/admin/plugins/{$id}/activate");

        $this->assertStatus(422, $response);
        $this->assertSame('plugin.ddl_unavailable', $response->json('code'));
        $this->assertStringContainsString(
            'PLUGIN_DDL_PASSWORD',
            (string) $response->json('message'),
            'پیام باید اسمِ متغیرِ گمشده را بگوید، وگرنه مدیر سایت نمی‌داند دنبالِ چه بگردد.'
        );
    }

    /**
     * رجیستری باید **پیش از** اجرای migration پر باشد.
     *
     * ترتیب برعکس یعنی نگهبان رجیستریِ خالی می‌بیند و «هیچ چیز مجاز نیست» را
     * نتیجه می‌گیرد — یعنی نصبِ افزونه‌ای که کاملاً درست است شکست می‌خورد و
     * پیامش دربارهٔ افزونه است، نه دربارهٔ ترتیبِ کارِ ما.
     */
    public function test_the_registry_is_populated_and_the_table_is_created(): void
    {
        $this->installAndActivate(['notes']);

        $rows = DB::table('plugin_table_prefixes')
            ->where('slug', 'sampleplugin')
            ->pluck('table_name')
            ->all();

        $this->assertSame(['sampleplugin_notes'], $rows);

        $this->assertTrue(
            $this->tableExists('sampleplugin_notes'),
            'جدول باید با prefix ساخته شده باشد. اگر نیست، migration یا اجرا نشده '
            .'یا روی اتصالِ بی‌نگهبان رفته.'
        );
    }

    /**
     * uninstall باید رجیستری را پاک کند.
     *
     * بدون این، نام‌های یک افزونهٔ حذف‌شده بی‌صاحب می‌مانند و قاعده هر بار بازتر
     * می‌شود بی‌آنکه کسی بداند چرا.
     */
    public function test_uninstalling_clears_the_registry(): void
    {
        $auth = $this->owner();
        $id = $this->installAndActivate(['notes'], $auth);

        $this->assertGreaterThan(
            0,
            DB::table('plugin_table_prefixes')->where('slug', 'sampleplugin')->count(),
            'پیش‌فرضِ تست باید رجیستری پر باشد.'
        );

        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertOk();
        $this->assertSame(
            0,
            DB::table('plugin_table_prefixes')->where('slug', 'sampleplugin')->count(),
            'رجیستری باید پاک می‌شد.'
        );
    }

    // ── کمکی ───────────────────────────────────────────────────────────

    private function installAndActivate(array $tables, ?self $auth = null): int
    {
        $auth ??= $this->owner();

        $upload = $this->upload($auth, $tables);

        $this->assertStatus(201, $upload);

        $id = $upload->json('data.id');
        $this->assertNotNull($id, 'آپلود باید id برمی‌گرداند: '.$upload->getContent());

        $this->approveAsOperator((int) $id);

        $this->assertStatus(200, $auth->postJson("/api/v1/admin/plugins/{$id}/activate"));

        return (int) $id;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function upload(?self $auth = null, array $tables = ['notes']): TestResponse
    {
        $auth ??= $this->owner();

        $manifest = [
            'name' => 'نمونه',
            'slug' => 'sampleplugin',
            'version' => '1.0.0',
            'author' => 'نمونه',
            'description' => 'بستهٔ آزمایشی K5.12',
            'entry' => 'Laravel/src/Plugin.php',
            'api' => [
                'prefix' => 'api/v1/p/sampleplugin',
                'namespace' => 'Pishdad\\Plugins\\Sampleplugin',
                'routes' => [],
            ],
            'db' => ['tables' => array_map(
                static fn (string $t) => ['name' => $t],
                $tables,
            )],
        ];

        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $this->secretKey);

        $path = tempnam(sys_get_temp_dir(), 'k512').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Plugin.php', '<?php // stub');

        foreach ($tables as $table) {
            $zip->addFromString(
                'Laravel/database/migrations/2026_01_01_000001_create_'.$table.'.php',
                '<?php return new class extends \Illuminate\Database\Migrations\Migration {'
                .' public function up(): void { \Illuminate\Support\Facades\Schema::create(\''.$table.'\', function ($t) { $t->id(); }); }'
                .' public function down(): void { \Illuminate\Support\Facades\Schema::dropIfExists(\''.$table.'\'); } };',
            );
        }

        $zip->close();

        return $auth->post(
            '/api/v1/admin/plugins/upload',
            ['file' => new UploadedFile($path, 'plugin.zip', 'application/zip', null, true)],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * تأییدِ بازاری از راه اپراتور، سپس بازگشت به مالک.
     *
     * بازبینیِ افزونه در هسته است: `POST /api/v1/market/plugins/{id}/approve`
     * با `role:operator` ⇒ `ReviewService::approve()`.
     *
     * `actingAs` سراسری است، پس اگر به مالک برنگردیم، ادامهٔ تست با اپراتور
     * اجرا می‌شود و `activate` شکست می‌خورد با خطایی که ربطی به K5.12 ندارد —
     * یعنی تست در جای اشتباهی قرمز می‌شد.
     */
    private function approveAsOperator(int $id): void
    {
        $operator = User::query()->create([
            'name' => 'اپراتور',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'),
            'role' => 'operator',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$id}/approve")
            ->assertOk();

        $owner = User::query()->where('role', 'admin')->orderBy('id')->firstOrFail();
        $this->actingAs($owner->fresh(), 'sanctum');
    }

    /**
     * کاربرِ احرازشده برای این تست.
     *
     * `$this` برمی‌گرداند نه `User`، چون `post`/`postJson` متدِ `TestCase` است نه
     * مدل. نسخهٔ اول `User` برمی‌گرداند و `post` روی مدل گم می‌شد.
     *
     * @return $this
     */
    private function owner(): self
    {
        $this->seed(RolesPermissionsSeeder::class);

        $user = User::query()->create([
            'name' => 'مالک',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'),
            'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $this->actingAs($user->fresh(), 'sanctum');
    }

    private function tableExists(string $name): bool
    {
        $row = DB::selectOne(
            'select 1 as ok from pg_tables where schemaname = current_schema() and tablename = ?',
            [$name],
        );

        if ($row !== null) {
            DB::statement('drop table if exists "'.$name.'" cascade');
        }

        return $row !== null;
    }

    private function assertStatus(int $expected, $response): void
    {
        $this->assertSame(
            $expected,
            $response->status(),
            'وضعیت '.$expected.''."\n".'دریافتی: '.$response->status()."\n".$response->getContent()
        );
    }
}
