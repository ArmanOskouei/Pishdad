<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * تسک ۴.۲ — DoD: لیست + آپلود ZIP (manifest + پذیرش signature_valid:false) +
 * فعال‌سازی/پیش‌نمایش/حذف (محافظت از قالب فعال).
 */
class ThemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // آپلودها به دیسک واقعی نرسند.
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
    }

    private function zipWithManifest(?array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'theme').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($manifest !== null) {
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
        }
        $zip->addFromString('index.html', '<html></html>');
        $zip->close();

        return $path;
    }

    private function uploadedTheme(?array $manifest = null): UploadedFile
    {
        $manifest ??= ['name' => 'قالب سپیده', 'slug' => 'sepideh-'.uniqid(), 'version' => '1.2.0'];
        $path = $this->zipWithManifest($manifest);

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }

    public function test_index_autoprovides_default_theme(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/themes');

        $res->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('default', $res->json('data.0.slug'));
        $this->assertTrue($res->json('data.0.active'));
    }

    public function test_upload_accepts_zip_with_manifest_and_marks_signature_unverified(): void
    {
        $user = $this->user();

        $res = $this->actingAs($user, 'sanctum')->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ]);

        $res->assertCreated();
        $this->assertFalse($res->json('data.signature_valid'));
        $this->assertSame('قالب سپیده', $res->json('data.name'));
        $this->assertSame('1.2.0', $res->json('data.version'));
        $this->assertFalse($res->json('data.active'));
        // مورد ۶: آپلود مشتری → pending.
        $this->assertSame('pending', $res->json('data.review_status'));
    }

    public function test_upload_rejects_non_zip_and_missing_manifest(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $txt = tempnam(sys_get_temp_dir(), 't').'.txt';
        file_put_contents($txt, 'hello');
        $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($txt, 'theme.txt', 'text/plain', null, true),
        ])->assertStatus(422);

        $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => new UploadedFile($this->zipWithManifest(null), 'theme.zip', 'application/zip', null, true),
        ])->assertStatus(422)->assertJsonPath('message', 'فایل manifest.json معتبر در ZIP یافت نشد.');
    }

    public function test_activate_switches_and_preview_returns_url(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson('/api/v1/admin/themes')->assertOk();

        $upload = $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ])->assertCreated()->json('data');

        // مورد ۶: فعال‌سازی قبل از تأیید = 422.
        $auth->postJson("/api/v1/admin/themes/{$upload['id']}/activate")
            ->assertStatus(422);

        // در هسته هیچ endpointِ تأییدِ قالبی وجود ندارد — صفِ بازبینی فقط برای
        // افزونه است. چرخهٔ واقعیِ قالب این است: `ThemeController::upload`
        // قالبِ آپلودکنندهٔ اپراتور را از ابتدا `approved` ثبت می‌کند
        // (`isReviewExempt`)، و `activate` فقط `approved` را می‌پذیرد. پس
        // «تأیید» در این تست یعنی «قالبی که اپراتور بارگذاری کرده».
        $approved = $this->uploadAsOperator();

        $auth->postJson("/api/v1/admin/themes/{$approved['id']}/activate")
            ->assertOk()
            ->assertJsonPath('message', 'قالب فعال شد.');

        $list = $auth->getJson('/api/v1/admin/themes')->assertOk()->json('data');
        $active = collect($list)->where('active', true)->values();
        $this->assertCount(1, $active);
        $this->assertSame($approved['id'], $active->first()['id']);

        $auth->postJson("/api/v1/admin/themes/{$approved['id']}/preview")
            ->assertOk()
            ->assertJsonStructure(['data' => ['preview_url']]);
    }

    public function test_delete_protects_active_theme(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');
        $auth->getJson('/api/v1/admin/themes')->assertOk();
        $default = Theme::query()->where('slug', 'default')->firstOrFail();

        $auth->deleteJson("/api/v1/admin/themes/{$default->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'قالب فعال را نمی‌توان حذف کرد. ابتدا قالب دیگری را فعال کنید.');

        $upload = $auth->withHeader('Accept', 'application/json')->post('/api/v1/admin/themes/upload', [
            'file' => $this->uploadedTheme(),
        ])->assertCreated()->json('data');

        $auth->deleteJson("/api/v1/admin/themes/{$upload['id']}")
            ->assertOk()
            ->assertJsonPath('message', 'قالب حذف شد.');
    }

    public function test_theme_survives_creator_deletion(): void
    {
        $creator = $this->user();
        $theme = Theme::query()->create([
            'user_id' => $creator->id, 'name' => 'قالب ماندگار', 'slug' => 'persistent-theme',
            'version' => '1.0.0', 'active' => false,
        ]);

        $creator->delete();

        $this->assertDatabaseHas('themes', ['id' => $theme->id, 'user_id' => null]);
    }

    /** مشترک نصب: قالبِ تأییدشده برای همهٔ مدیران قابل فعال‌سازی است، نه فقط آپلودکننده. */
    public function test_themes_are_shared_across_managers(): void
    {
        $a = $this->user();
        $b = $this->user();

        // ادعای این تست «مشترک بودن» است، نه «تأیید مرکزی» — و تأییدِ قالب در
        // هسته از راهِ آپلودِ اپراتور اتفاق می‌افتد (`ThemeController::upload`
        // برای نقش `operator` مستقیم `approved` می‌نویسد؛ endpointِ تأییدِ جدا
        // برای قالب وجود ندارد، چون `ReviewService` فقط افزونه را می‌بیند).
        $approved = $this->uploadAsOperator();

        $this->assertSame('approved', $approved['review_status'], 'قالبِ اپراتور باید از ابتدا تأییدشده باشد.');

        // مدیر دوم — که نه آپلودکننده است و نه اپراتور — فعال می‌کند.
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/admin/themes/{$approved['id']}/activate")
            ->assertOk()
            ->assertJsonPath('message', 'قالب فعال شد.');

        $list = $this->actingAs($b, 'sanctum')->getJson('/api/v1/admin/themes')->assertOk()->json('data');
        $this->assertNotEmpty(collect($list)->where('id', $approved['id'])->all());

        // مدیر اوّل هم همان قالب را می‌بیند ⇒ فیلترِ مالکیتی روی خواندن نیست.
        $first = $this->actingAs($a->fresh(), 'sanctum')->getJson('/api/v1/admin/themes')->assertOk()->json('data');
        $this->assertNotEmpty(collect($first)->where('id', $approved['id'])->all());
    }

    /**
     * آپلودِ قالب به‌عنوان اپراتور ⇒ رکورد از ابتدا `approved`.
     *
     * جایگزینِ تأییدِ دستی: در هسته صفِ بازبینیِ قالب نیست. چرخهٔ واقعی
     * (`ThemeController`) این است که آپلودکنندهٔ اپراتور از قفلِ بازبینی معاف
     * است، پس `review_status` همان لحظهٔ آپلود `approved` می‌شود و بعد
     * `/activate` آن را می‌پذیرد.
     *
     * @return array<string, mixed> رکورد قالب
     */
    private function uploadAsOperator(): array
    {
        $operator = User::query()->create([
            'name' => 'اپراتور', 'email' => 'op'.uniqid().'@example.com',
            'password' => Hash::make('Operator!1234'), 'role' => 'operator',
        ]);

        $theme = $this->actingAs($operator, 'sanctum')
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/admin/themes/upload', ['file' => $this->uploadedTheme()])
            ->assertCreated()
            ->json('data');

        // actingAs سراسری است؛ به اولین مشتری برمی‌گردیم تا ادامه تست با مدیر باشد.
        $owner = User::query()->orderBy('id')->firstOrFail();
        $this->actingAs($owner->fresh(), 'sanctum');

        return $theme;
    }
}
