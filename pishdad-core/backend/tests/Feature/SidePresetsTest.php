<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\SidePreset;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ستون‌های کناری + پریست‌های مشترک (ارجاع زنده):
 * ساخت/لود پریست، اشتراک زنده بین دو صفحه، toggle مستقل، حذفِ در حال استفاده.
 */
class SidePresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // F0.1: پرمیشن‌ها با سیدر ساخته می‌شوند؛ RefreshDatabase اجرایشان نمی‌کند.
        $this->seed(RolesPermissionsSeeder::class);
    }

    private function user(): User
    {
        // F0.1: مسیرهای نوشتن صفحه `perm:pages.edit` گرفتند.
        // `->tap()` استفاده نمی‌شود چون `Builder::create()` خودش از `tap()`
        // داخلی استفاده می‌کند و callback ما را با `Builder` صدا می‌زند.
        $user = User::query()->create([
            'name' => 'مشتری تست',
            'email' => 'u'.uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => 'admin',
        ]);
        $user->givePermissionTo('pages.view', 'pages.edit');

        return $user;
    }

    private function blocks(): array
    {
        return [
            ['type' => 'text', 'data' => ['body' => 'متن ستون']],
            ['type' => 'cta', 'data' => ['label' => 'شروع', 'href' => '/start']],
        ];
    }

    private function page(User $user, string $slug): Page
    {
        $page = Page::query()->create([
            'user_id' => $user->id, 'title' => $slug, 'slug' => $slug,
            'status' => Page::STATUS_PUBLISHED, 'blocks' => [],
        ]);
        $rev = $page->snapshot([], null, $user->id, 'ایجاد');
        $page->forceFill([
            'published_revision_id' => $rev->id,
            'published_at' => now(),
        ])->save();

        return $page->fresh();
    }

    public function test_create_and_load_preset(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $created = $auth->postJson('/api/v1/admin/side-presets', [
            'name' => 'ستون راست فروشگاه',
            'side' => 'right',
            'blocks' => $this->blocks(),
        ])->assertCreated()
            ->assertJsonPath('message', 'پریست ساخته شد.')
            ->json('data');

        $auth->getJson('/api/v1/admin/side-presets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'ستون راست فروشگاه');

        $auth->getJson("/api/v1/admin/side-presets/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.side', 'right')
            ->assertJsonCount(2, 'data.blocks');
    }

    public function test_side_must_be_left_or_right_and_blocks_validated(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $auth->postJson('/api/v1/admin/side-presets', [
            'name' => 'بد', 'side' => 'middle', 'blocks' => [],
        ])->assertStatus(422);

        $auth->postJson('/api/v1/admin/side-presets', [
            'name' => 'بد', 'side' => 'left',
            'blocks' => [['type' => 'nope', 'data' => []]],
        ])->assertStatus(422);
    }

    public function test_live_sharing_between_two_pages(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $preset = SidePreset::query()->create([
            'user_id' => $user->id, 'name' => 'مشترک', 'side' => 'left',
            'blocks' => [['type' => 'text', 'data' => ['body' => 'نسخه یک']]],
        ]);

        $a = $this->page($user, 'page-a');
        $b = $this->page($user, 'page-b');

        // اتصال یک پریست به دو صفحه.
        $auth->putJson("/api/v1/admin/pages/{$a->id}", ['left_preset_id' => $preset->id])->assertOk();
        $auth->putJson("/api/v1/admin/pages/{$b->id}", ['left_preset_id' => $preset->id])->assertOk();

        // ویرایش پریست از یک مسیر → هر دو صفحه تنظیمات جدید را نشان می‌دهند.
        $auth->putJson("/api/v1/admin/side-presets/{$preset->id}", [
            'blocks' => [['type' => 'text', 'data' => ['body' => 'نسخه دو']]],
        ])->assertOk()->assertJsonPath('message', 'پریست به‌روزرسانی شد.');

        foreach (['page-a', 'page-b'] as $slug) {
            $this->getJson("/api/v1/site/pages/{$slug}")
                ->assertOk()
                ->assertJsonPath('data.sidebars.left.enabled', true)
                ->assertJsonPath('data.sidebars.left.preset_id', $preset->id)
                ->assertJsonPath('data.sidebars.left.blocks.0.data.body', 'نسخه دو');
        }
    }

    public function test_independent_toggle_per_page(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $preset = SidePreset::query()->create([
            'user_id' => $user->id, 'name' => 'راست', 'side' => 'right',
            'blocks' => [['type' => 'text', 'data' => ['body' => 'ستون']]],
        ]);

        $a = $this->page($user, 'tog-a');
        $b = $this->page($user, 'tog-b');

        $auth->putJson("/api/v1/admin/pages/{$a->id}", [
            'right_preset_id' => $preset->id, 'right_enabled' => false,
        ])->assertOk();
        $auth->putJson("/api/v1/admin/pages/{$b->id}", [
            'right_preset_id' => $preset->id, 'right_enabled' => true,
        ])->assertOk();

        $this->getJson('/api/v1/site/pages/tog-a')
            ->assertOk()
            ->assertJsonPath('data.sidebars.right.enabled', false)
            ->assertJsonPath('data.sidebars.right.preset_id', $preset->id);

        $this->getJson('/api/v1/site/pages/tog-b')
            ->assertOk()
            ->assertJsonPath('data.sidebars.right.enabled', true);
    }

    /** سمت باید با جایگاه بخواند (۴۲۲) ولی پریست هر مدیر برای همه قابل استفاده است. */
    public function test_preset_side_must_match_slot_and_is_shared(): void
    {
        $user = $this->user();
        $other = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $left = SidePreset::query()->create([
            'user_id' => $user->id, 'name' => 'چپ', 'side' => 'left', 'blocks' => [],
        ]);
        $shared = SidePreset::query()->create([
            'user_id' => $other->id, 'name' => 'مشترک', 'side' => 'left', 'blocks' => [],
        ]);
        $page = $this->page($user, 'slot-page');

        // پریست چپ در جایگاه راست → 422.
        $auth->putJson("/api/v1/admin/pages/{$page->id}", ['right_preset_id' => $left->id])
            ->assertStatus(422);

        // پریست مدیر دیگر در جایگاه درست → OK (مشترک).
        $auth->putJson("/api/v1/admin/pages/{$page->id}", ['left_preset_id' => $shared->id])
            ->assertOk();

        // پریست مدیر دیگر هم دیده می‌شود.
        $auth->getJson("/api/v1/admin/side-presets/{$shared->id}")->assertOk();
    }

    public function test_presets_are_shared_across_managers(): void
    {
        $a = $this->user();
        $b = $this->user();

        $presetId = $this->actingAs($a, 'sanctum')->postJson('/api/v1/admin/side-presets', [
            'name' => 'پریست مشترک',
            'side' => 'left',
            'blocks' => [['type' => 'text', 'data' => ['body' => 'نسخه اول']]],
        ])->assertCreated()->json('data.id');

        $bAuth = $this->actingAs($b, 'sanctum');
        $bAuth->getJson('/api/v1/admin/side-presets')
            ->assertOk()
            ->assertJsonPath('data.0.id', $presetId);
        $bAuth->getJson("/api/v1/admin/side-presets/{$presetId}")
            ->assertOk()
            ->assertJsonPath('data.name', 'پریست مشترک');
        $bAuth->putJson("/api/v1/admin/side-presets/{$presetId}", [
            'blocks' => [['type' => 'text', 'data' => ['body' => 'نسخه ویرایش‌شده']]],
        ])->assertOk();

        $this->actingAs($a, 'sanctum')->getJson("/api/v1/admin/side-presets/{$presetId}")
            ->assertOk()
            ->assertJsonPath('data.blocks.0.data.body', 'نسخه ویرایش‌شده');
    }

    public function test_delete_in_use_returns_422_with_count(): void
    {
        $user = $this->user();
        $auth = $this->actingAs($user, 'sanctum');

        $preset = SidePreset::query()->create([
            'user_id' => $user->id, 'name' => 'پرکاربرد', 'side' => 'left', 'blocks' => [],
        ]);
        $a = $this->page($user, 'del-a');
        $b = $this->page($user, 'del-b');
        $auth->putJson("/api/v1/admin/pages/{$a->id}", ['left_preset_id' => $preset->id])->assertOk();
        $auth->putJson("/api/v1/admin/pages/{$b->id}", ['left_preset_id' => $preset->id])->assertOk();

        $auth->deleteJson("/api/v1/admin/side-presets/{$preset->id}")
            ->assertStatus(422)
            ->assertJsonPath('usage_count', 2);

        // بعد از جدا کردن، حذف موفق است.
        $auth->putJson("/api/v1/admin/pages/{$a->id}", ['left_preset_id' => null])->assertOk();
        $auth->putJson("/api/v1/admin/pages/{$b->id}", ['left_preset_id' => null])->assertOk();
        $auth->deleteJson("/api/v1/admin/side-presets/{$preset->id}")
            ->assertOk()
            ->assertJsonPath('message', 'پریست حذف شد.');
    }
}
