<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Admin\PluginController;
use App\Http\Middleware\GateDevModeUpload;
use App\Models\Plugin;
use App\Models\PublisherKey;
use App\Models\Setting;
use App\Models\SystemPlugin;
use App\Models\User;
use App\Services\Plugins\CoreRequirementChecker;
use App\Services\Plugins\DevMode;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginInstaller;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\PluginSettingsStore;
use App\Services\Plugins\PluginSignatureVerifier;
use App\Services\Settings\CachedSettings;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\PluginDdlNoTransactionCase;
use ZipArchive;

/**
 * تسک ۵.۴ — DoD: نصب امضاشده → فعال → rollback؛ امضای نامعتبر = 422. K5.5: بدون رجیستری هوک.
 *
 * ## ⚠️ چرا `RefreshDatabase` ندارد — K5.12
 *
 * `RefreshDatabase` `migrate:fresh` می‌کند و جدول‌ها را **دوباره می‌سازد**، که
 * `relacl` را پاک می‌کند. یعنی امتیازهایی که `PluginDdlTestCase` داده از بین
 * می‌رود و افزونه‌ای که واقعاً migration اجرا می‌کند با
 * `permission denied for table plugin_table_prefixes` رد می‌شود.
 *
 * یعنی **یک تست باید `RefreshDatabase` داشته باشد و دیگری نه**، بسته به اینکه
 * آیا واقعاً جدول می‌سازد:
 *  · تست‌هایی که فقط مسیر و امضا را می‌سنجند ⇒ تراکنش خوب است (ارزان و تمیز).
 *  · تستی که جدول می‌سازد ⇒ باید بدون تراکنش باشد، چون جدولِ ساخته‌شده در
 *    تراکنشِ تست برای اتصالِ جدا نامرئی است.
 *
 * این یک بده‌بستانِ آگاهانه است، نه سهل‌انگاری: جدول‌ها را `tearDown` پاک می‌کند.
 */
class PluginsTest extends PluginDdlNoTransactionCase
{
    private string $publicKey;

    private string $secretKey;

    /**
     * پاک‌سازیِ دستی، جایگزینِ `RefreshDatabase`.
     *
     * فهرست **صریح** است، چون دیتابیسِ تست مشترک است و یک الگوی کلی می‌تواند
     * جدولِ تستِ دیگری را ببرد.
     *
     * و پاک کردنِ رکوردِ افزونه **لازم** است: بدون `RefreshDatabase` هیچ چیز
     * برنمی‌گردد و تست بعدی با «پلاگینی با این شناسه قبلاً نصب شده» رد می‌شود.
     */
    protected function tearDown(): void
    {
        Plugin::query()->delete();
        DB::table('pishdad_plugin_migrations')->delete();
        DB::table('plugin_table_prefixes')->delete();
        Setting::query()->where('group', 'plugin')->delete();
        Setting::query()->where('key', 'like', 'seal_key_%')->delete();

        // E70: این کلاس `RefreshDatabase` ندارد (پایهٔ `PluginDdlNoTransactionCase`)،
        // پس هر چه تست‌ها `enable()` کنند در دیتابیسِ مشترکِ تست می‌ماند و تستِ
        // `ThemeMakeTest::fail_closed` (که باید حالت را بسته ببیند) در suite کامل
        // قرمز می‌شود. حالت توسعه‌دهنده را هم مثل بقیه صریح برمی‌گردانیم.
        Setting::query()->where('group', DevMode::GROUP)->where('key', DevMode::KEY)->delete();
        app(DevMode::class)->forget();

        // بدون این‌ها تست‌های بعدی با `unique violation` می‌میرند، چون
        // `RefreshDatabase` دیگر هیچ چیزی را برنمی‌گرداند.
        //
        // این را با اجرا پیدا شد: `test_revoked_publisher_key_fails_verification`
        // با `duplicate key value violates unique constraint
        // "publisher_keys_slug_unique"` قرمز شد.
        PublisherKey::query()->delete();
        DB::table('permissions')->where('name', 'like', 'plugin:%')->delete();
        DB::table('model_has_permissions')->whereIn(
            'permission_id',
            DB::table('permissions')->select('id')->where('name', 'like', 'plugin:%'),
        )->delete();
        DB::table('model_has_roles')->delete();
        DB::table('role_has_permissions')->delete();
        User::query()->where('email', 'like', '%@example.com')->delete();

        $rows = DB::select(
            "select tablename from pg_tables where schemaname = current_schema()
             and (tablename like 'tableplugin\_%' or tablename like 'sampleplugin\_%'
                  or tablename like 'pluginslug\_%' or tablename = 'posts')"
        );

        foreach ($rows as $row) {
            DB::statement('drop table if exists "'.$row->tablename.'" cascade');
        }

        $sequences = DB::select(
            "select c.relname as name from pg_class c
               join pg_namespace n on n.oid = c.relnamespace
              where c.relkind = 'S' and n.nspname = current_schema()
                and (c.relname like 'tableplugin\_%' or c.relname like 'sampleplugin\_%')"
        );

        foreach ($sequences as $row) {
            DB::statement('drop sequence if exists "'.$row->name.'" cascade');
        }

        foreach (glob(storage_path('app/plugins/*')) ?: [] as $dir) {
            if (is_dir($dir) && $this->isWithinTempRoot($dir)) {
                // `File` نه `removeTree` — متد دستی در `PluginMigratorTest` است و
                // اینجا وجود نداشت (`Call to undefined method removeTree()`).
                File::deleteDirectory($dir);
            }
        }

        foreach (glob(storage_path('app/plugins/shared/*')) ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** فقط پوشه‌هایی که خودِ این تست ساخته. */
    private function isWithinTempRoot(string $dir): bool
    {
        $name = basename($dir);

        foreach (['tableplugin', 'sampleplugin', 'sample-plugin', 'hooksplugin', 'posts'] as $slug) {
            if (str_starts_with($name, $slug)) {
                return true;
            }
        }

        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $kp = sodium_crypto_sign_keypair();
        $this->publicKey = base64_encode(sodium_crypto_sign_publickey($kp));
        $this->secretKey = base64_encode(sodium_crypto_sign_secretkey($kp));
        config(['plugins.public_key' => $this->publicKey]);
    }

    private function owner(): User
    {
        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $user->fresh();
    }

    /** ساخت ZIP پلاگین با مانیفست امضاشده/نشده/دست‌کاری‌شده. */
    /**
     * `$extra` فایل‌های اضافه به بسته می‌افزاید.
     *
     * لازم شد برای K5.6: migration باید **داخل ZIP** باشد تا `PluginInstaller`
     * آن را استخراج کند و `PluginMigrator` اجرایش کند. بدون این پارامتر، تست
     * فعال‌سازی نمی‌توانست اصلاً migration بسازد.
     *
     * @param  array<string, string>  $extra  مسیر ⇒ محتوا
     */
    /**
     * K5.2-W — این `$extra` قبلاً همین‌جا **دور ریخته می‌شد**.
     *
     * امضا `$extra` را می‌گرفت ولی `rawZip($manifest)` بدون آن صدا زده می‌شد، پس
     * هر تستی که `extra:` می‌داد یک ZIP بدون آن فایل‌ها می‌ساخت و بی‌صدا از کنارش
     * می‌گذشت. بدترین حالتش `test_a_migration_touching_an_undeclared_table` بود که
     * ادعایش «بدترین حالت ممکن» بود ولی migration بدافزاری‌اش هرگز داخل ZIP نرفت —
     * یعنی یک خاصیت امنیتی کاملاً بی‌تست. قبلاً هم سبز بود، ولی به دلیل اشتباه:
     * چون نسخه‌ای روی دیسک نبود، فعال‌سازی با `migration.no_release` رد می‌شد و
     * تست فکر می‌کرد دارد بررسی جدول اعلام‌نشده را می‌سنجد.
     */
    private function pluginZip(array $manifest, string $mode = 'signed', array $extra = []): UploadedFile
    {
        $verifier = app(PluginSignatureVerifier::class);

        if ($mode === 'signed') {
            $manifest['signature'] = $verifier->sign($manifest, $this->secretKey);
        } elseif ($mode === 'tampered') {
            $manifest['signature'] = $verifier->sign($manifest, $this->secretKey);
            $manifest['name'] .= ' دست‌کاری‌شده';
        }

        return $this->rawZip($manifest, $extra);
    }

    private function rawZip(array $manifest, array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            $this->fail('ساخت ZIP آزمایشی ناموفق بود.');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->addFromString('Laravel/src/Plugin.php', '<?php // stub');

        // نام متغیر عمداً `entry` است نه `path`: بالا‌تر `$path` نام فایل موقت
        // است و سایه کردنش یعنی `UploadedFile` یک مسیر بی‌ربط می‌گیرد.
        foreach ($extra as $entry => $body) {
            $zip->addFromString($entry, $body);
        }

        $zip->close();

        return new UploadedFile($path, 'plugin.zip', 'application/zip', null, true);
    }

    private function manifest(array $over = []): array
    {
        return array_merge([
            'name' => 'پلاگین نمونه', 'slug' => 'sample-plugin', 'version' => '1.0.0',
            'hooks' => ['dashboard.widget', ['hook' => 'ticket.created', 'handler' => 'NotifyAdmin']],
        ], $over);
    }

    /** ساخت ZIP امضاشده با کلیدی دلخواه (برای تست trust store چندناشره). */
    private function pluginZipSignedWith(array $manifest, string $secretKeyBase64): UploadedFile
    {
        $manifest['signature'] = app(PluginSignatureVerifier::class)->sign($manifest, $secretKeyBase64);

        return $this->rawZip($manifest);
    }

    public function test_upload_rejects_missing_or_invalid_signature(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'tampered')])
            ->assertStatus(422)->assertJsonPath('message', 'امضای پلاگین معتبر نیست.');

        $this->assertDatabaseCount('plugins', 0);
    }

    /**
     * B3 — middleware واقعاً روی route هست و توکن نامعتبر هم راهی باز نمی‌کند.
     *
     * این تست عمداً «یکپارچه» است نه واحد: هدفش این است که اگر کسی بعداً
     * `devmode.gate` را از آرایهٔ middleware هر دو route برداشت، این تست
     * قرمز شود. تست واحدِ `DevMode` چنین چیزی را نمی‌گیرد.
     */
    public function test_dev_mode_gate_is_wired_and_wrong_token_grants_nothing(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // حالت توسعه‌دهنده باز است، ولی توکن غلط است.
        app(DevMode::class)->enable('tok-right');
        app(DevMode::class)->forget();

        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-wrong')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $this->assertSame(0, Plugin::query()->count(), 'توکن نادرست نباید بستهٔ بی‌امضا را نصب کند.');

        // حالت توسعه‌دهنده بسته است، ولی توکن درست ارائه شده.
        app(DevMode::class)->disable();
        app(DevMode::class)->forget();

        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')])
            ->assertStatus(422);

        $this->assertSame(0, Plugin::query()->count(), 'توکن درست بدون حالت باز نباید چیزی را باز کند.');
    }

    /**
     * تنها مسیر مجاز: حالت باز + توکن درست.
     *
     * و نکتهٔ مهم این تست: حالت توسعه‌دهنده **تأیید نمی‌کند**. بسته با
     * وضعیت `pending` ثبت می‌شود، نه `approved` — چون `isReviewExempt()` فقط
     * سوپرادمین و اپراتور را از بازبینی معاف می‌کند. حالت توسعه‌دهنده
     * راه تست را باز می‌کند، نه راه انتشار.
     */
    public function test_dev_mode_allows_unsigned_upload_when_open_with_valid_token(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        app(DevMode::class)->enable('tok-right');
        app(DevMode::class)->forget();

        $res = $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->post('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest(), 'unsigned')]);

        $this->assertSame(201, $res->getStatusCode(), 'پاسخ: '.json_encode($res->json(), JSON_UNESCAPED_UNICODE));

        $id = $res->json('data.id');

        $this->assertNotNull($id);
        $this->assertDatabaseHas('plugins', ['id' => $id, 'review_status' => 'pending']);
    }

    /**
     * K5.9 — کلید مهر باید هنگام **نصب** ساخته شود.
     *
     * این تست از مسیر واقعی endpoint رد می‌شود، چون تست واحدِ `ensureSealKey`
     * فقط ثابت می‌کند متد کار می‌کند — نه اینکه کسی صدایش می‌زند. قبل از این
     * متد هیچ callerی نداشت و K0.4 عملاً پیاده نشده بود.
     */
    public function test_upload_creates_the_seal_key(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertStatus(201);

        $this->assertSame(
            1,
            Setting::query()->where('key', 'seal_key_sample-plugin')->count(),
            'نصب باید کلید مهر بسازد.'
        );
    }

    /**
     * حذف افزونه باید کلید مهر را هم پاک کند، وگرنه نصب دوبارهٔ همان slug به
     * کلید قبلی می‌خورد و مهرهایی که با آن زده شده بودند دوباره معتبر می‌شدند.
     */
    public function test_uninstall_removes_the_seal_key(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertStatus(201)->json('data.id');

        $this->assertSame(1, Setting::query()->where('key', 'seal_key_sample-plugin')->count());

        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertStatus(200);

        $this->assertSame(
            0,
            Setting::query()->where('key', 'seal_key_sample-plugin')->count(),
            'حذف افزونه باید کلید مهر را هم پاک کند.'
        );
    }

    public function test_signed_install_activate_then_upgrade(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertCreated()
            ->assertJsonPath('data.signature_valid', true)
            ->assertJsonPath('data.review_status', 'pending')
            ->json('data.id');

        // مورد ۶: فعال‌سازی قبل از تأیید اپراتور = 422.
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertStatus(422);

        $this->approveAsOperator($id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true);

        // K5.5: اعلام `hooks` نه رجیستری می‌سازد، نه در `present()` چیزی برمی‌گرداند.
        $show = $auth->getJson("/api/v1/admin/plugins/{$id}")->assertOk()->json('data');
        $this->assertEquals(64, strlen($show['checksum']));
        $this->assertArrayNotHasKey('hooks', $show);
        $this->assertArrayNotHasKey('hooks_count', $show);
        $this->assertFalse(Schema::hasTable('plugin_hooks'), 'جدول plugin_hooks باید حذف شده باشد.');

        // ارتقا به نسخه بالاتر + rollback نسخه قبلی ثبت می‌شود.
        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '2.0.0'])),
        ])->assertOk()
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonPath('data.previous_version', '1.0.0');

        // نسخه پایین‌تر رد می‌شود.
        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '1.5.0'])),
        ])->assertStatus(422);

        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertOk();

        // K7.8 — slug را **قبل** از uninstall می‌خوانیم، چون بعدش ردیف نیست.
        $slug = Plugin::query()->findOrFail($id)->slug;

        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertOk();

        // به‌جای «جدول خالی»، **فقط همان بسته** باید رفته باشد.
        //
        // assertion صریح بهتر است چون **دقیقاً** همان چیزی را می‌گوید که این
        // تست ادعا می‌کند: uninstall همان بسته را برداشت.
        $this->assertNotContains(
            $slug,
            $this->pluginSlugs(),
            'uninstall باید همان بسته را از جدول بردارد.',
        );
    }

/**
     * K5.0-W — فعال‌سازی/غیرفعال‌سازی باید **بارگذارِ کلاس** را هم بچرخاند.
     *
     * رگرسیونِ مشخص: `PluginAutoloader` فقط در `boot()` وصل بود و آن هم فقط
     * برای افزونهٔ داخلی. برای بستهٔ بازاری یعنی فعال‌سازی هیچ‌وقت کلاسی
     * بارگذاری نمی‌کرد، و «غیرفعال» هم فقط یک پرچم در DB بود.
     *
     * وضعیتِ همان نمونه‌ای را می‌سنجیم که `bootBundledPlugins()` می‌گیرد —
     * وگرنه یک نمونهٔ تازه، تست را بی‌معنا می‌کرد.
     */
    public function test_activate_and_deactivate_also_turn_the_class_autoloader(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $loader = app(PluginAutoloader::class);

        $slug = 'autoload-probe';

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => $slug])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator((int) $id);

        $this->assertFalse($loader->isRegistered($slug), 'قبل از فعال‌سازی نباید چیزی ثبت شده باشد.');

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true);

        $this->assertTrue(
            $loader->isRegistered($slug),
            'فعال‌سازی باید ریشهٔ PSR-4 بسته را ثبت کند، وگرنه هیچ کلاسِ افزونه‌ای در همان درخواست resolve نمی‌شود.'
        );

        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertFalse(
            $loader->isRegistered($slug),
            'غیرفعال‌سازی باید ثبت را بردارد؛ «غیرفعال» فقط روی DB نباید باشد.'
        );
    }

    /**
     * فعال‌سازی باید بلافاصله در رجیستری دیده شود، نه بعد از پایان TTL کش.
     * رگرسیون: کش `activeManifests()` اضافه شد و اگر flush نشود، پلاگین
     * تازه‌فعال‌شده ۵ دقیقه نامرئی می‌ماند.
     */
    public function test_activating_plugin_invalidates_manifest_cache(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'cache-probe',
                'permissions' => [['module' => 'probe', 'title_fa' => 'پروب', 'actions' => ['view']]],
            ])),
        ])->assertCreated()->json('data.id');

        // قبل از فعال‌سازی نباید در ماژول‌های پرمیشن باشد.
        ManifestRegistry::flushCache();
        $this->assertNotContains(
            'plugin:cache-probe:probe',
            array_column(ManifestRegistry::permissionModules(), 'name')
        );

        $this->approveAsOperator((int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        // بلافاصله بعد از فعال‌سازی باید دیده شود ⇒ کش باطل شده است.
        $this->assertContains(
            'plugin:cache-probe:probe',
            array_column(ManifestRegistry::permissionModules(), 'name')
        );
    }

    /** پلاگین در allowlist، حتی با مانیفست غیرسیستمی، محافظت می‌شود. */
    public function test_system_plugin_is_protected(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // فاز ۰: مانیفست دیگر نمی‌تواند پرچم system را اعطا کند. فقط allowlist.
        SystemPlugin::ensureMany(['core-plugin']);

$id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'core-plugin'])),
        ])->assertCreated()->json('data.id');

        // J7 — افزونهٔ سیستمی از صفِ بازبینی **خارج** است: `ReviewController`
        // تأیید دستیِ آن را رد می‌کند (۴۲۲)، چون وضعیتش را به‌روزرسانیِ هسته تعیین
        // می‌کند نه اپراتور. پس این تست باید وضعیت را **مستقیم** بگذارد.
        //
        // چرا این مهم است: قبلاً همین خط می‌خواست افزونهٔ سیستمی را از راهِ بازبینی
        // تأیید کند — و بدون guard، راهِ دور زدنِ حفاظتِ سیستمی بود. تستِ حفاظت
        // (خط پایین) برای رسیدن به فعال‌سازی مجبور بود از همان راهِ ناامن رد شود.
        //
        // نگهبانیِ خودِ این قاعده در `ReviewSystemGuardTest` است.
        Plugin::query()->whereKey($id)->update(['review_status' => Plugin::REVIEW_APPROVED]);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();
        $auth->postJson("/api/v1/admin/plugins/{$id}/deactivate")->assertStatus(422);
        $auth->postJson("/api/v1/admin/plugins/{$id}/uninstall")->assertStatus(422);
    }

    /**
     * L-B3/F0.3 — پلاگین سیستمی با کلید ناشر دیگر قابل تصاحب نیست.
     *
     * سناریو: قربانیِ سیستمی با ناشر A نصب شد (پین = A). مهاجم بسته‌ای با
     * همان slug و نسخهٔ بالاتر می‌سازد که با کلیدِ معتبرِ ناشر B امضا شده.
     * بدون گارد، `publisher_key_id` بازنویسی می‌شد و کد مهاجم زیر پرچم
     * حذف‌ناپذیر می‌نشست. حالا ۴۲۲ با کد `plugin.system_publisher_mismatch`،
     * نسخه عوض نمی‌شود و فایل تازه اصلاً روی دیسک نمی‌نشیند (گارد پیش از
     * storePackage است). ارتقای همان ناشر (A) همچنان راه است.
     */
    public function test_system_plugin_upgrade_with_another_publishers_key_is_refused(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $keyA = sodium_crypto_sign_keypair();
        $secretA = base64_encode(sodium_crypto_sign_secretkey($keyA));
        $publicA = base64_encode(sodium_crypto_sign_publickey($keyA));
        $fpA = PublisherKey::fingerprintOf($publicA);

        $keyB = sodium_crypto_sign_keypair();
        $secretB = base64_encode(sodium_crypto_sign_secretkey($keyB));
        $publicB = base64_encode(sodium_crypto_sign_publickey($keyB));
        $fpB = PublisherKey::fingerprintOf($publicB);

        foreach ([['ناشر الف', 'publisher-a', $publicA, $fpA], ['ناشر ب', 'publisher-b', $publicB, $fpB]] as [$name, $slug, $public, $fp]) {
            PublisherKey::query()->create([
                'name' => $name, 'slug' => $slug, 'public_key' => $public,
                'key_fingerprint' => $fp, 'status' => PublisherKey::STATUS_ACTIVE,
                'verified_at' => now(),
            ]);
        }

        // پین ران قبلی (کلید تصادفی) در DB مانده چون tearDown جدول
        // system_plugins را پاک نمی‌کند؛ اول پاک، بعد allowlist.
        SystemPlugin::query()->where('slug', 'pin-victim')->delete();
        SystemPlugin::flush();
        SystemPlugin::ensureMany(['pin-victim']);

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZipSignedWith(
                $this->manifest(['slug' => 'pin-victim', 'publisher' => ['key_id' => $fpA]]),
                $secretA
            ),
        ])->assertCreated()->json('data.id');

        $this->assertSame($fpA, SystemPlugin::pinnedFingerprint('pin-victim'), 'اولین ناشر معتبر باید پین شود.');

        $filesBefore = Storage::disk('local')->files('plugins/shared') ?: [];

        // `upgrade` روی route پشتِ `devmode.gate` است؛ بدون باز بودنِ حالت و
        // توکن، درخواست پیش از رسیدن به گارد پین ۴۰۴ می‌خورد و تست بی‌ربط
        // می‌شد. پس گیت را باز می‌کنیم تا واقعاً گارد pin-victim سنجیده شود.
        app(DevMode::class)->enable('tok-right');
        app(DevMode::class)->forget();

        // حمله: همان slug، نسخهٔ بالاتر، امضای معتبرِ ناشر دیگر.
        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
                'file' => $this->pluginZipSignedWith(
                    $this->manifest(['slug' => 'pin-victim', 'version' => '2.0.0', 'publisher' => ['key_id' => $fpB]]),
                    $secretB
                ),
            ])->assertStatus(422)->assertJsonPath('code', 'plugin.system_publisher_mismatch');

        $this->assertSame('1.0.0', Plugin::query()->findOrFail($id)->version, 'نسخه نباید عوض شده باشد.');
        $this->assertSame($fpA, Plugin::query()->findOrFail($id)->publisher_key_id, 'ناشر رکورد نباید بازنویسی شده باشد.');
        $this->assertSame(
            $filesBefore,
            Storage::disk('local')->files('plugins/shared') ?: [],
            'فایل تازه نباید روی دیسک نشسته باشد.'
        );

        // مسیر سالم: ارتقا با همان ناشرِ پین‌شده راه است.
        $auth->withHeader(GateDevModeUpload::HEADER, 'tok-right')
            ->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
                'file' => $this->pluginZipSignedWith(
                    $this->manifest(['slug' => 'pin-victim', 'version' => '2.0.0', 'publisher' => ['key_id' => $fpA]]),
                    $secretA
                ),
            ])->assertOk();

        $this->assertSame('2.0.0', Plugin::query()->findOrFail($id)->version);
    }

    /**
     * رگرسیون امنیتی فاز ۰: `system: true` در مانیفست نباید پلاگین را
     * غیرقابل‌حذف کند.
     *
     * با اضافه شدن لایهٔ اعتبارسنجی، این مانیفست **قبل از نصب** رد می‌شود چون
     * فیلد `system` اصلاً در مانیفست پذیرفته نیست. این از آنچه قبلاً انتظار
     * داشتیم قوی‌تر است: کاربر حتی نمی‌تواند چنین بسته‌ای نصب کند.
     */
    public function test_manifest_cannot_grant_system_flag(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'sneaky', 'system' => true])),
        ])->assertStatus(422)
            ->assertJsonPath('analysis.errors.0.code', 'manifest.system_forbidden');

        $this->assertDatabaseCount('plugins', 0);
        $this->assertFalse(SystemPlugin::allows('sneaky'));
    }

    /** سه مفهوم اعتماد باید در پاسخ API جدا و صریح باشند. */
    public function test_upload_reports_separated_trust_facts(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        // این مانیفست key_id ناشر ندارد، پس فقط با کلید config تأیید می‌شود.
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'trust-check'])),
        ])->assertCreated()
            ->assertJsonPath('data.source', 'local')
            // امضا راست است، ولی ناشر برای ما ناشناس است ⇒ publisher_verified=false.
            // این دقیقاً همان تفکیکی است که پیش از فاز ۰ گم بود.
            ->assertJsonPath('data.signature_valid', true)
            ->assertJsonPath('data.trust.publisher_verified', false)
            ->assertJsonPath('data.trust.review_approved', false)
            ->assertJsonPath('data.trust.badge', 'pending')
            // یکپارچگی تا فاز ۱ تأیید نمی‌شود ⇒ fail-closed.
            ->assertJsonPath('data.trust.integrity', 'unverified')
            ->assertJsonPath('data.is_system', false);
    }

    /** کلید ابطال‌شده نباید امضا را معتبر کند (fail-closed). */
    public function test_revoked_publisher_key_fails_verification(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $fingerprint = PublisherKey::fingerprintOf($public);

        PublisherKey::query()->create([
            'name' => 'ناشر ابطال‌شده',
            'slug' => 'revoked-publisher',
            'public_key' => $public,
            'key_fingerprint' => $fingerprint,
            'status' => PublisherKey::STATUS_REVOKED,
        ]);

        $auth = $this->actingAs($this->owner(), 'sanctum');
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZipSignedWith(
                $this->manifest(['slug' => 'from-revoked', 'publisher' => ['key_id' => $fingerprint]]),
                $secret
            ),
        ])->assertStatus(422);

        $this->assertDatabaseCount('plugins', 0);
    }

    /** کلید فعال در trust store باید پذیرفته و key_id ثبت شود. */
    public function test_active_publisher_key_in_trust_store_is_recorded(): void
    {
        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));
        $fingerprint = PublisherKey::fingerprintOf($public);

        PublisherKey::query()->create([
            'name' => 'ناشر معتبر',
            'slug' => 'trusted-publisher',
            'public_key' => $public,
            'key_fingerprint' => $fingerprint,
            'status' => PublisherKey::STATUS_ACTIVE,
            'verified_at' => now(),
        ]);

        $auth = $this->actingAs($this->owner(), 'sanctum');
        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZipSignedWith(
                $this->manifest(['slug' => 'from-trusted', 'publisher' => ['key_id' => $fingerprint]]),
                $secret
            ),
        ])->assertCreated()
            ->assertJsonPath('data.publisher_key_id', $fingerprint)
            ->assertJsonPath('data.publisher_verified', true)
            // تأیید اداری هنوز انجام نشده ⇒ تأییدنشده، نه تأییدشده.
            ->assertJsonPath('data.trust.review_approved', false);
    }

    /**
     * تأییدِ بازاری یک مورد به‌عنوان اپراتور و بازگشت به کاربر مالک (مورد ۶).
     *
     * بازبینیِ افزونه در **هسته** است، زیر پیشوندِ بازار و با همان الگوی
     * `role:operator` که صفِ قالب هم داشت: `POST /api/v1/market/plugins/{id}/approve`
     * ⇒ `ReviewService::approve()`. helper اینجا مستقیم سرویسِ هسته را از راه
     * endpoint واقعی‌اش صدا می‌زند — نه با دست‌زدن مستقیم به `review_status`،
     * چون تست‌ها دقیقاً دارند مسیرِ «آپلود ⇒ تأیید ⇒ فعال‌سازی» را می‌سنجند.
     */
    private function approveAsOperator(int $id): void
    {
        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/market/plugins/{$id}/approve")
            ->assertOk();

        // actingAs سراسری است؛ به اولین مالک برمی‌گردیم تا ادامه تست با مالک باشد.
        $owner = User::query()->where('role', 'admin')->orderBy('id')->firstOrFail();
        $this->actingAs($owner->fresh(), 'sanctum');
    }

    /** مشترک نصب: پلاگین نصب‌شده توسط یک مدیر برای همه مدیران قابل مشاهده است. */
    public function test_plugin_is_shared_across_managers(): void
    {
        $me = $this->owner();
        $id = $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->json('data.id');

        $stranger = User::query()->create([
            'name' => 'مدیر دوم', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Other!1234'), 'role' => 'admin',
        ]);
        $stranger->assignRole('owner');

        $this->actingAs($stranger->fresh(), 'sanctum')
            ->getJson("/api/v1/admin/plugins/{$id}")->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->actingAs($stranger->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/plugins')->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->approveAsOperator((int) $id);
        $this->actingAs($stranger->fresh(), 'sanctum')
            ->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertOk();
    }

    public function test_theme_with_invalid_signature_is_rejected(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $manifest = ['name' => 'قالب خراب', 'slug' => 'bad-theme', 'signature' => 'invalid'];
        $path = tempnam(sys_get_temp_dir(), 'thm').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        $zip->close();

        $auth->postJson('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($path, 'theme.zip', 'application/zip', null, true),
        ])->assertStatus(422);
    }

    // ── K3.0 / B25 — اجباری کردن `requires.core` ─────────────────────────────

    /** ادعای ناسازگار ⇒ نصب اصلاً انجام نمی‌شود: نه ردیف، نه فایل. */
    public function test_upload_rejects_plugin_requiring_a_newer_core(): void
    {
        Storage::fake('local');
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'future-plugin',
                'requires' => ['core' => '>=99.0.0'],
            ])),
        ])->assertStatus(422)
            ->assertJsonPath('requires.code', 'requires.core.unsatisfied')
            ->assertJsonPath('requires.required', '>=99.0.0')
            ->assertJsonPath('requires.current', CoreRequirementChecker::coreVersion());

        $this->assertDatabaseCount('plugins', 0);
        $this->assertSame([], Storage::disk('local')->files('plugins/shared') ?: []);
    }

    /**
     * اعلامِ ناخوانا هم باید رد شود. سکوت کردن یعنی «سازگار فرض شد» و دقیقاً همان
     * باگی است که `readManifest` (B9) به آن وابسته بود.
     */
    public function test_upload_rejects_unreadable_core_requirement(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        foreach (['totally-bogus', '1.4.0 || 2.0.0', ''] as $core) {
            $auth->postJson('/api/v1/admin/plugins/upload', [
                'file' => $this->pluginZip($this->manifest([
                    'slug' => 'garbled-requirement',
                    'requires' => ['core' => $core],
                ])),
            ])->assertStatus(422)->assertJsonPath('requires.code', 'requires.core.invalid');
        }

        $this->assertDatabaseCount('plugins', 0);
    }

    public function test_upload_accepts_plugin_whose_core_requirement_matches(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'compatible-plugin',
                'requires' => ['core' => '*'],
            ])),
        ])->assertCreated();
    }

    /** ارتقا به نسخه‌ای که با هسته نمی‌خواند ⇒ 422 و رکورد دست‌نخورده. */
    public function test_upgrade_rejects_incompatible_core_requirement(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'upgrade-core'])),
        ])->assertCreated()->json('data.id');

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'upgrade-core',
                'version' => '2.0.0',
                'requires' => ['core' => '>=99.0.0'],
            ])),
        ])->assertStatus(422)->assertJsonPath('requires.code', 'requires.core.unsatisfied');

        $this->assertDatabaseHas('plugins', ['id' => $id, 'version' => '1.0.0']);
    }

    // ── B9 — فقط مانیفست ریشهٔ ZIP ───────────────────────────────────────────

    /**
     * رگرسیون B9: `locateName('manifest.json', FL_NODIR)` روی **نام پایه** تطابق
     * می‌دهد، نه مسیر کامل — پس اولین `manifest.json` هر جای ZIP برنده می‌شود.
     *
     * اینجا تودرتو عمداً اول اضافه می‌شود تا فرق دو روش خواندن قابل تشخیص باشد.
     * مانیفست تودرتو همان چیزی است که `slug` و امضا و کل ردیف DB را تعیین می‌کند.
     */
    public function test_read_manifest_ignores_nested_manifest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Laravel/manifest.json', json_encode(
            ['name' => 'جاسوس', 'slug' => 'nested-imposter', 'version' => '9.9.9'],
            JSON_UNESCAPED_UNICODE
        ));
        $zip->addFromString('manifest.json', json_encode(
            ['name' => 'ریشه', 'slug' => 'root-one', 'version' => '1.0.0'],
            JSON_UNESCAPED_UNICODE
        ));
        $zip->close();

        // اطمینان از غیربنیان بودن تست: روش قدیمی واقعاً مانیفست تودرتو را
        // برمی‌گرداند، پس تست فقط با تغییر رفتار معنادار می‌شود.
        $probe = new ZipArchive;
        $probe->open($path);
        $this->assertSame(0, $probe->locateName('manifest.json', ZipArchive::FL_NODIR));
        $probe->close();

        $controller = app(PluginController::class);
        $method = new \ReflectionMethod($controller, 'readManifest');
        $method->setAccessible(true);

        $this->assertSame('root-one', $method->invoke($controller, $path)['slug'] ?? null);
    }

    /** لایهٔ دفاعی اول: validator بستهٔ دومنیفستی را اصلاً نصب نمی‌کند. */
    public function test_upload_rejects_package_with_nested_manifest(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $path = tempnam(sys_get_temp_dir(), 'plg').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Laravel/manifest.json', json_encode(
            ['name' => 'جاسوس', 'slug' => 'nested-imposter'], JSON_UNESCAPED_UNICODE
        ));
        $zip->addFromString('manifest.json', json_encode(
            $this->manifest(['slug' => 'root-one']), JSON_UNESCAPED_UNICODE
        ));
        $zip->close();

        $response = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => new UploadedFile($path, 'plugin.zip', 'application/zip', null, true),
        ])->assertStatus(422);

        $codes = array_column($response->json('analysis.errors') ?? [], 'code');
        $this->assertContains('manifest.misplaced', $codes);

        $this->assertDatabaseCount('plugins', 0);
    }

    // ── B11 — ترتیب ذخیره/به‌روزرسانی/حذف ────────────────────────────────────

    /**
     * رگرسیون B11: حذفِ زودهنگام + نوشتنِ ناموفق = پلاگین فعال با `path` که به
     * فایل ناموجود اشاره می‌کند.
     *
     * شکستِ نوشتن با یک دیسکِ ریشه‌ file-به‌جای-پوشه شبیه‌سازی می‌شود، چون
     * Flysystem برای «نشدن ساخت پوشه» exception می‌دهد نه `false`.
     */
    public function test_failed_upgrade_store_keeps_the_installed_version_intact(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'brick-me'])),
        ])->assertCreated()->json('data.id');

        $installed = Plugin::query()->findOrFail($id);
        $oldPath = $installed->path;
        $oldAbsolute = Storage::disk('local')->path($oldPath);
        $this->assertFileExists($oldAbsolute);

        $blocker = tempnam(sys_get_temp_dir(), 'blk');
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $blocker]]);
        Storage::forgetDisk('local');

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['slug' => 'brick-me', 'version' => '2.0.0'])),
        ])->assertStatus(422);

        $after = $installed->fresh();
        $this->assertSame('1.0.0', $after->version);
        $this->assertNull($after->previous_version);
        $this->assertSame($oldPath, $after->path);
        $this->assertFileExists($oldAbsolute, 'فایل قبلی نباید پیش از نوشتن موفق حذف می‌شد.');

        @unlink($blocker);
    }

    /**
     * WF-H17 — ارتقا باید تنظیمات افزونه را حفظ کند.
     *
     * تنظیمات در `settings` با `group = plugin:{slug}` ذخیره می‌شوند
     * (`PluginSettingsStore`)، پس با slug گره خورده‌اند نه با نسخه. `upgrade()`
     * هم جدول `settings` را پاک نمی‌کند. این تست همان قرارداد را روی مسیر
     * واقعیِ ارتقا قفل می‌کند تا اگر روزی کسی در `upgrade()` چیزی پاک کرد،
     * قرمز شود.
     */
    public function test_upgrade_preserves_plugin_settings(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $slug = 'settings-keeper';
        $settings = [
            'shop' => [
                'title_fa' => 'فروشگاه',
                'group' => 'عمومی',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'currency' => ['type' => 'enum', 'enum' => ['IRR', 'USD'], 'default' => 'IRR'],
                    ],
                ],
            ],
        ];

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => $slug, 'settings' => $settings])),
        ])->assertCreated()->json('data.id');

        PluginSettingsStore::write($slug, ['currency' => 'USD']);

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest([
                'slug' => $slug, 'version' => '2.0.0', 'settings' => $settings,
            ])),
        ])->assertOk()->assertJsonPath('data.version', '2.0.0');

        // کش را دور می‌زنیم تا واقعاً از دیتابیس بخوانیم، نه از مقدارِ قبلی.
        CachedSettings::forget('plugin', $slug);
        $this->assertSame('USD', PluginSettingsStore::read($slug)['currency'] ?? null);
    }

    /** مسیر سالم: فایل جدید هست، فایل قبلی پاک شده. */
    public function test_successful_upgrade_replaces_the_stored_file(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'swap-me'])),
        ])->assertCreated()->json('data.id');

        $oldPath = Plugin::query()->findOrFail($id)->path;
        $oldAbsolute = Storage::disk('local')->path($oldPath);

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['slug' => 'swap-me', 'version' => '2.0.0'])),
        ])->assertOk()->assertJsonPath('data.version', '2.0.0');

        $newPath = Plugin::query()->findOrFail($id)->path;
        $this->assertNotSame($oldPath, $newPath);
        $this->assertFileExists(Storage::disk('local')->path($newPath));
        $this->assertFileDoesNotExist($oldAbsolute, 'فایل نسخهٔ قبلی باید بعد از ثبت ردیف حذف شود.');
    }

    // ── B20 — پاسخ صادقانهٔ فعال‌سازی ────────────────────────────────────────

    /**
     * `hooks.not_yet_dispatched` هشدار است چون هوک‌ها اجرا نمی‌شوند؛ پس پاسخ
     * فعال‌سازی نباید طوری باشد که UI فکر کند هوک‌ها ثبت و اجرا شدند.
     *
     * K5.5 بعد از این تست آمد و «هنوز» را از متن برداشت: وعدهٔ ساخت dispatcher
     * لغو شد، پس متن باید بگوید فیلد بی‌اثر است نه اینکه فعلاً اجرا نمی‌شود.
     */
    public function test_activate_response_is_honest_about_hooks(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'hook-claimer'])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator((int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('hooks_dispatched', false);

        $warning = $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->json('warning');

        $this->assertIsString($warning);
        $this->assertStringContainsString('در قرارداد پلاگین نیست', $warning);
        $this->assertStringNotContainsString(
            'هنوز اجرا نمی‌شوند',
            $warning,
            'متن نباید وعدهٔ ساخت dispatcher بدهد؛ K5.5 آن را لغو کرد.'
        );
    }

    /** پلاگین بی‌هوک هشدار نمی‌گیرد — ولی `hooks_dispatched` همچنان صادقانه `false` است. */
    public function test_activate_without_hooks_has_no_warning(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'hookless', 'hooks' => []])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator((int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('hooks_dispatched', false)
            ->assertJsonPath('warning', null);
    }

    // ── K5.5 — هوک‌ها از قرارداد حذف شد ─────────────────────────────────────

    /**
     * قرارداد تازه: اعلام `hooks` نه رد می‌شود، نه چیزی می‌نویسد.
     *
     * مانیفست پیش‌فرض همین کلاس دو هوک اعلام می‌کند، پس این تست مسیر «هوک
     * اعلام کرده» را بدون دست‌زدن به fixture ثابت می‌کند: 201، سپس 200، و
     * هیچ جدولی در کار نیست.
     */
    public function test_declaring_hooks_is_neither_an_error_nor_a_row(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest(['slug' => 'still-declares-hooks'])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator((int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('hooks_dispatched', false);

        $this->assertFalse(
            Schema::hasTable('plugin_hooks'),
            'جدول باید حذف شده باشد، پس اعلام هوک چیزی برای نوشتن ندارد.'
        );
    }

    /**
     * مدل و رابطه هم با جدول باید رفته باشند — وگرنه یک `->hooks()` استفاده‌نشده
     * باقی می‌ماند که فقط در اولین فراخوانی می‌ترکد.
     */
    public function test_the_hook_model_and_relation_are_gone(): void
    {
        // `class_exists()` با autoload صدا می‌زند و روی فایل حذف‌شده خطای include
        // می‌دهد، پس وجودِ خودِ فایل بررسی می‌شود — همان چیزی که autoloader
        // هنوز به آن اشاره دارد.
        $this->assertFalse(
            is_file(app_path('Models/PluginHook.php')),
            'مدل PluginHook باید حذف شده باشد.'
        );
        $this->assertFalse(
            method_exists(Plugin::class, 'hooks'),
            'رابطهٔ hooks() باید حذف شده باشد.'
        );
    }

    /**
     * مهاجرت حذف باید برگشت‌پذیر باشد و `down()` همان شکل اصلی را بسازد.
     *
     * الگوی مهاجرت K7.5: خودِ فایل را `require` می‌کنیم و مستقیم صدا می‌زنیم.
     * DDL در PostgreSQL تراکنشی است، پس RefreshDatabase همه‌چیز را برمی‌گرداند.
     */
    public function test_the_drop_migration_round_trips_the_original_table_shape(): void
    {
        $this->assertFalse(Schema::hasTable('plugin_hooks'));

        $migration = require base_path('database/migrations/2026_10_06_000001_drop_plugin_hooks_table.php');

        $migration->down();
        $this->assertTrue(Schema::hasTable('plugin_hooks'));

        $this->assertSame(
            ['id', 'plugin_id', 'hook', 'handler', 'created_at', 'updated_at'],
            collect(Schema::getColumns('plugin_hooks'))->pluck('name')->all()
        );

        $foreign = collect(Schema::getForeignKeys('plugin_hooks'))->first();
        $this->assertSame('plugins', $foreign['foreign_table']);
        $this->assertSame(['plugin_id'], $foreign['columns']);
        $this->assertSame('cascade', strtolower((string) $foreign['on_delete']));

        $migration->up();
        $this->assertFalse(Schema::hasTable('plugin_hooks'));
    }

    /**
     * رگرسیون جابه‌جایی: `ensurePermissions` فقط از تهِ `syncHooks()` صدا زده
     * می‌شد، پس حذف آن متد می‌توانست پرمیشن‌های مانیفست را بی‌سروصدا از بین
     * ببرد. مسیر ارتقا هیچ تستی نداشت، پس اینجا ساخته شد.
     */
    public function test_upgrading_an_active_plugin_still_registers_new_manifest_permissions(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $id = $auth->postJson('/api/v1/admin/plugins/upload', [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'perm-drift',
                'permissions' => [['module' => 'alpha', 'title_fa' => 'آلفا', 'actions' => ['view']]],
            ])),
        ])->assertCreated()->json('data.id');

        $this->approveAsOperator((int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        $this->assertNotNull(
            Permission::findByName('plugin:perm-drift:alpha.view', 'web'),
            'فعال‌سازی باید پرمیشن نسخهٔ اول را بسازد.'
        );

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest([
                'slug' => 'perm-drift',
                'version' => '2.0.0',
                'permissions' => [
                    ['module' => 'alpha', 'title_fa' => 'آلفا', 'actions' => ['view']],
                    ['module' => 'beta', 'title_fa' => 'بتا', 'actions' => ['edit']],
                ],
            ])),
        ])->assertOk();

        $this->assertNotNull(
            Permission::findByName('plugin:perm-drift:beta.edit', 'web'),
            'ارتقای نسخهٔ فعال باید پرمیشن ماژول تازه را هم بسازد.'
        );
    }

    /**
     * K5.2-W — مسیر سرتاسری فعال‌سازی، حالا که اشاره‌گر نوشته می‌شود.
     *
     * این تست قبلاً عمداً tripwire بود: انتظار داشت فعال‌سازی با
     * `migration.no_release` رد شود، و کامنتش می‌گفت «اگر این تست قرمز شد یعنی
     * K5.2-W وصل شده». همین اتفاق افتاد، پس حالا ادعای **مثبت** را می‌سنجد:
     * فعال‌سازی باید موفق شود و جدول اعلام‌شده واقعاً ساخته شود.
     *
     * قبلاً این جدول ساخته نمی‌شد چون `PluginMigrator` نسخه را از `currentDir()`
     * پیدا می‌کرد و آن `null` بود — یعنی قابلیت اعلام `db.tables` عملاً هرگز
     * اجرا نمی‌شد. حالا باید اجرا شود، وگرنه فعال کردن افزونه‌ای که جدول‌هایش
     * ساخته نشده در اولین درخواست می‌میرد.
     *
     * اسلاگ `tableplugin` است نه پیش‌فرض fixture، چون قاعدهٔ `db.tables` اجازه
     * نمی‌دهد اسلاگی با خط تیره (`sample-plugin`) مالک جدول باشد: خط تیره در
     * شناسهٔ SQL معتبر نیست. این محدودیت عمدی است (سند معماری خط ۱۲۵).
     */
    public function test_activation_runs_migrations_and_creates_the_declared_table(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $zip = $this->pluginZip(
            $this->manifest(['slug' => 'tableplugin', 'db' => ['tables' => [['name' => 'notes']]]]),
            extra: [
                'Laravel/database/migrations/2026_01_01_000001_create_notes.php' => $this->migrationCreating('notes'),
            ]
        );

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $zip])
            ->assertCreated()->json('data.id');

        $this->assertTrue($this->installToDisk($zip, 'tableplugin'), 'نصب روی دیسک باید موفق باشد.');

        $this->approveAsOperator((int) $id);

        $activate = $auth->postJson("/api/v1/admin/plugins/{$id}/activate");

        $this->assertSame(
            200,
            $activate->status(),
            'فعال‌سازی باید موفق باشد: '.$activate->getContent()
        );

        $activate->assertJsonPath('data.active', true);

        $this->assertTrue(
            Schema::hasTable('tableplugin_notes'),
            'جدول اعلام‌شده باید بعد از فعال‌سازی ساخته شده باشد.'
        );

        $this->assertNotNull(
            app(PluginReleaseManager::class)->currentDir('tableplugin'),
            'اشاره‌گر نسخهٔ فعال باید بعد از فعال‌سازی نوشته شده باشد.'
        );
    }

    /**
     * اشاره‌گر نسخه فقط با **فعال‌سازی** نوشته می‌شود، نه با نصب.
     *
     * نصب روی دیسک یعنی «کد آماده است ولی هنوز اجرا نمی‌شود». اگر نصب به‌تنهایی
     * اشاره‌گر می‌نوشت، نسخه‌ای که هرگز فعال نشده به‌عنوان فعال گزارش می‌شد —
     * و `currentDir()` دقیقاً همان چیزی است که `PluginMigrator` برای یافتن فایل‌ها
     * به آن تکیه می‌کند، پس مرز «نصب شده» و «فعال است» محو می‌شد.
     */
    public function test_installing_alone_does_not_write_the_release_pointer(): void
    {
        $zip = $this->pluginZip($this->manifest(['slug' => 'pointergap']));

        $result = app(PluginInstaller::class)
            ->install($zip->getRealPath(), 'pointergap', '1.0.0');

        $this->assertTrue($result['ok'] ?? false, 'نصب باید موفق باشد.');

        $this->assertNull(
            app(PluginReleaseManager::class)->currentDir('pointergap'),
            'نصب نباید اشاره‌گر بنویسد — فقط فعال‌سازی این کار را می‌کند.'
        );
    }

    /**
     * K5.11 — ارتقا باید نسخهٔ جدید را واقعاً روی دیسک نصب کند.
     *
     * تا این‌جا `upgrade()` فقط ZIP تازه را ذخیره می‌کرد و رکورد DB را به‌روز
     * می‌کرد، بدون آنکه `PluginInstaller` را صدا بزند. نتیجه یک وضعیت متناقض بود:
     * DB می‌گفت ۲.۰.۰ ولی `currentDir()` نسخهٔ ۱.۰.۰ را برمی‌گرداند و
     * `PluginRouter` کد قدیم را اجرا می‌کرد — یعنی مدیر ارتقایی می‌دید که اصلاً
     * اتفاق نیفتاده بود.
     *
     * ادعای اصلی این تست بعد از ارتقا **دو نسخه روی دیسک** است: نسخهٔ تازه فعال،
     * و نسخهٔ قبلی که هنوز هست تا rollback واقعی باشد.
     */
    public function test_upgrade_installs_the_new_release_on_disk_and_keeps_the_previous_one(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $releases = app(PluginReleaseManager::class);

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertCreated()->json('data.id');
        $this->approveAsOperator((int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        $firstDir = $releases->currentDir('sample-plugin');
        $this->assertNotNull($firstDir, 'نسخهٔ ۱.۰.۰ باید فعال باشد.');

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '2.0.0'])),
        ])->assertOk()->assertJsonPath('data.version', '2.0.0');

        $secondDir = $releases->currentDir('sample-plugin');
        $this->assertNotNull($secondDir, 'اشاره‌گر باید نسخهٔ ۲.۰.۰ را نشان دهد.');
        $this->assertNotSame(
            $firstDir,
            $secondDir,
            'اشاره‌گر هنوز نسخهٔ قبلی را نشان می‌دهد ⇒ ارتقا فقط رکورد DB را عوض کرده و کد اجرا نمی‌شود.'
        );

        // آرتیفکت نسخهٔ قبلی باید روی دیسک مانده باشد — این همان چیزی است که
        // rollback واقعی را از یک رشتهٔ بی‌آرتیفکت جدا می‌کند.
        $this->assertDirectoryExists(
            $firstDir,
            'پوشهٔ نسخهٔ قبلی نباید حذف شود؛ `pointerBack()` روی همین حساب می‌کند.'
        );
        $this->assertSame(
            '2.0.0',
            Plugin::find($id)?->version,
            'رکورد DB باید با اشاره‌گر دیسک هم‌خوان باشد.'
        );
    }

    /**
     * K5.11 — اگر migration نسخهٔ جدید شکست بخورد، نسخهٔ قبلی باید دوباره فعال شود.
     *
     * این بدترین حالت ارتقاست: نسخهٔ تازه جدول‌هایش ساخته نشده ولی کدش بارگذاری
     * شده، و افزونه در اولین درخواست می‌میرد. به‌خاطر content-addressed بودن
     * نسخه‌ها، نسخهٔ قبلی هنوز روی دیسک است — ولی فقط اگر اشاره‌گر برگردد.
     */
    public function test_a_failed_migration_during_upgrade_restores_the_previous_release(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $releases = app(PluginReleaseManager::class);

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertCreated()->json('data.id');
        $this->approveAsOperator((int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        $firstDir = $releases->currentDir('sample-plugin');

        // نسخهٔ ۲.۰. جدولی *اعلام‌نشده* را لمس می‌کند ⇒ `verify()` باید ردش کند.
        $bad = $this->pluginZip(
            $this->manifest(['version' => '2.0.0']),
            extra: [
                'Laravel/database/migrations/2026_01_01_000001_drop_users.php' => $this->migrationDropping('users'),
            ]
        );

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", ['file' => $bad])
            ->assertStatus(422)
            ->assertJsonPath('code', 'migration.rejected');

        $this->assertSame(
            $firstDir,
            $releases->currentDir('sample-plugin'),
            'بعد از شکست migration باید نسخهٔ قبلی دوباره فعال باشد.'
        );
        $this->assertSame(
            '1.0.0',
            Plugin::find($id)?->version,
            'رکورد DB هم باید روی نسخهٔ قبلی بماند.'
        );
        $this->assertTrue(
            Schema::hasTable('users'),
            'جدول هسته باید سالم مانده باشد — این مهم‌ترین ادعای این تست است.'
        );
    }

    /**
     * K5.11 — بستهٔ ارتقایی که نصب روی دیسکش رد می‌شود نباید رکورد را عوض کند.
     *
     * ترتیب در `upgrade()` عمداً «نصب، بعد رکورد» است، پس ردِ نصب یعنی نسخهٔ
     * قبلی هم در DB و هم روی دیسک دست‌نخورده می‌ماند.
     */
    public function test_a_rejected_upgrade_package_leaves_the_record_and_disk_untouched(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');
        $releases = app(PluginReleaseManager::class);

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $this->pluginZip($this->manifest())])
            ->assertCreated()->json('data.id');
        $this->approveAsOperator((int) $id);
        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")->assertOk();

        $firstDir = $releases->currentDir('sample-plugin');
        $firstPath = Plugin::find($id)?->path;

        $auth->postJson("/api/v1/admin/plugins/{$id}/upgrade", [
            'file' => $this->pluginZip($this->manifest(['version' => '2.0.0']), 'unsigned'),
        ])->assertStatus(422);

        $plugin = Plugin::find($id);
        $this->assertSame('1.0.0', $plugin?->version, 'نسخه نباید عوض شده باشد.');
        $this->assertSame($firstPath, $plugin?->path, 'مسیر فایل نباید عوض شده باشد.');
        $this->assertSame($firstDir, $releases->currentDir('sample-plugin'), 'اشاره‌گر نباید جابه‌جا شده باشد.');
    }

    /**
     * افزونه‌ای که جدول اعلام می‌کند ولی migration همراه ندارد، نباید فعال شود.
     *
     * این شکاف را فعال‌سازی K5.2-W آشکار کرد. گاردِ `migration.no_release` فقط
     * وقتی اجرا می‌شد که اصلاً نسخه‌ای روی دیسک نباشد؛ ولی اگر نسخه هست و بسته
     * migration ندارد، شاخهٔ «این افزونه migration ندارد» بدون هیچ بررسی‌ای `ok`
     * برمی‌گرداند. نتیجه: افزونه‌ای که `db.tables` اعلام کرده ولی جدولش ساخته
     * نشده، فعال می‌شد و در اولین درخواست می‌مرد.
     *
     * یعنی اعتبارسنجیِ migration جلوی دست‌کاریِ جدولِ *اعلام‌نشده* را می‌گیرد، ولی
     * جلوی *ساخته‌نشدنِ* جدولِ اعلام‌شده را نمی‌گرفت.
     */
    public function test_declaring_a_table_without_a_migration_is_refused(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $zip = $this->pluginZip(
            $this->manifest(['slug' => 'tableplugin', 'db' => ['tables' => [['name' => 'notes']]]])
        );

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $zip])
            ->assertCreated()->json('data.id');

        $this->assertTrue($this->installToDisk($zip, 'tableplugin'), 'نصب روی دیسک باید موفق باشد.');
        $this->approveAsOperator((int) $id);

        $auth->postJson("/api/v1/admin/plugins/{$id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'migration.tables_missing');

        $this->assertFalse(
            Schema::hasTable('tableplugin_notes'),
            'جدول نباید ساخته شده باشد — هیچ migrationی برای ساختنش نبود.'
        );

        $this->assertFalse(
            (bool) Plugin::find($id)?->active,
            'افزونه نباید فعال بماند وقتی جدول اعلام‌شده‌اش وجود ندارد.'
        );
    }

    /**
     * افزونه‌ای که migration‌اش جدولی *خارج از اعلام* را لمس می‌کند نباید فعال شود.
     *
     * این بدترین حالت ممکن است، چون فعال شدن موفق یعنی کاربر فکر می‌کند همه‌چیز
     * درست است و بعداً داده‌اش به جدول اشتباه می‌رود.
     */
    public function test_a_migration_touching_an_undeclared_table_is_never_accepted(): void
    {
        $auth = $this->actingAs($this->owner(), 'sanctum');

        $zip = $this->pluginZip(
            $this->manifest(['slug' => 'tableplugin', 'db' => ['tables' => [['name' => 'notes']]]]),
            extra: [
                'Laravel/database/migrations/2026_01_01_000001_drop_users.php' => $this->migrationDropping('users'),
            ]
        );

        $id = $auth->postJson('/api/v1/admin/plugins/upload', ['file' => $zip])
            ->assertCreated()->json('data.id');

        $this->assertTrue($this->installToDisk($zip, 'tableplugin'), 'نصب باید موفق باشد.');
        $this->approveAsOperator((int) $id);

        $response = $auth->postJson("/api/v1/admin/plugins/{$id}/activate");

        $this->assertContains(
            $response->json('code'),
            ['migration.rejected', 'migration.no_release'],
            'کد ناشناخته: '.$response->content()
        );

        $this->assertTrue(
            Schema::hasTable('users'),
            'جدول هسته باید سالم مانده باشد — این مهم‌ترین ادعای این تست است.'
        );
    }

    /** نصب روی دیسک؛ `upload` هنوز installer را صدا نمی‌زند (K5.2-W). */
    private function installToDisk(UploadedFile $zip, string $slug, string $version = '1.0.0'): bool
    {
        return app(PluginInstaller::class)
            ->install($zip->getRealPath(), $slug, $version)['ok'] ?? false;
    }

    /** بدنهٔ migration که جدول هسته را حذف می‌کند — یعنی یک بدافزار. */
    private function migrationDropping(string $table): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;
    }

    /**
     * بدنهٔ migration سالم که جدول اعلام‌شدهٔ افزونه را می‌سازد.
     *
     * نام جدول باید **خام** باشد (`notes`، نه `tableplugin_notes`). دلیلش ترتیب
     * کارها در `PluginMigrator` است: اول `verify()` متن خام را می‌خواند و هر نامی
     * را که در `db.tables` اعلام نشده `migration.undeclared_table` می‌دهد، بعد
     * `rewrite()` نام‌ها را prefix می‌کند. پس اگر اینجا نام از پیش prefix‌شده را
     * بدهیم، `verify()` آن را ناشناخته می‌بیند و اگر ندهیم، `Schema::create` جدول
     * درست یعنی `tableplugin_notes` را می‌سازد.
     */
    private function migrationCreating(string $rawTable): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Support\\Facades\\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$rawTable}', function (\\Illuminate\\Database\\Schema\\Blueprint \$table): void {
                    \$table->id();
                    \$table->string('body')->nullable();
                    \$table->timestamps();
                });
            }
        };
        PHP;
    }
}
