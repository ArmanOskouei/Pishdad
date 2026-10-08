<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * WF-H17 — Badge «آپدیت موجود».
 *
 * فهرست مدیر (`GET /v1/admin/plugins`) باید برای هر افزونهٔ نصب‌شده بگوید آیا
 * نسخهٔ به‌روزتری در دسترس است، بدون اینکه فرانت دربارهٔ نسخه‌ها حدس بزند:
 *   · `latest_version` — آخرین نسخهٔ بازار یا آخرین نسخهٔ اعلام‌شده در مانیفست.
 *   · `update_available` — شرطِ Badge (نسخهٔ نصب‌شده از نسخهٔ در دسترس عقب‌تر است).
 *   · `changelog` — متن تغییرات، اگر اعلام شده باشد.
 *
 * چون روی `plugins.slug` یکتایی هست، فهرستِ بازار و نصب‌شده هم‌زمان به‌صورت
 * دو ردیفِ جدا ممکن نیست؛ رکوردِ `source=market` همان منبعِ «آخرین نسخه» است.
 * برای افزونهٔ نصب‌شده دستی، اطلاعات انتشار از مانیفست خوانده می‌شود. اگر هیچ‌کدام
 * نباشد «آپدیت نیست» گزارش می‌شود — نه یک نسخهٔ ساختگی.
 */
class PluginUpdateBadgeTest extends TestCase
{
    use RefreshDatabase;

    private ?User $owner = null;

    private function owner(): User
    {
        if ($this->owner !== null) {
            return $this->owner;
        }

        $this->seed(RolesPermissionsSeeder::class);
        $user = User::query()->create([
            'name' => 'مالک', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Owner!1234'), 'role' => 'admin',
        ]);
        $user->assignRole('owner');

        return $this->owner = $user->fresh();
    }

    /** @param array<string, mixed> $over */
    private function plugin(array $over = []): Plugin
    {
        return Plugin::query()->create(array_merge([
            'name' => 'نمونه',
            'slug' => 'update-'.uniqid(),
            'version' => '1.0.0',
            'active' => false,
            'system' => false,
            'source' => Plugin::SOURCE_LOCAL,
            'signature_valid' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => [],
        ], $over));
    }

    /** @return array<string, mixed> */
    private function rowFor(string $slug): array
    {
        $rows = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/plugins?per_page=50')
            ->assertOk()
            ->json('data.data');

        $row = collect($rows)->firstWhere('slug', $slug);
        $this->assertNotNull($row, "ردیف افزونهٔ {$slug} در فهرست نبود.");

        return $row;
    }

    public function test_index_reports_an_available_update_from_the_declared_release_info(): void
    {
        $this->plugin([
            'slug' => 'updatable',
            'version' => '1.0.0',
            'manifest' => [
                'latest_version' => '2.1.0',
                'changelog' => "افزودن حالت تاریک\nرفع باگ Y در پرداخت",
            ],
        ]);

        $row = $this->rowFor('updatable');

        $this->assertTrue($row['update_available']);
        $this->assertSame('2.1.0', $row['latest_version']);
        $this->assertStringContainsString('رفع باگ Y', (string) $row['changelog']);
    }

    public function test_no_badge_when_the_installed_version_is_current_or_there_is_no_release_info(): void
    {
        $this->plugin([
            'slug' => 'current', 'version' => '2.1.0',
            'manifest' => ['latest_version' => '2.1.0'],
        ]);

        $this->plugin([
            'slug' => 'silent', 'version' => '1.0.0',
            'manifest' => [],
        ]);

        $current = $this->rowFor('current');
        $this->assertFalse($current['update_available'], 'نسخهٔ برابر نباید Badge بسازد.');
        $this->assertSame('2.1.0', $current['latest_version']);

        $silent = $this->rowFor('silent');
        $this->assertFalse($silent['update_available']);
        $this->assertNull($silent['latest_version']);
        $this->assertNull($silent['changelog']);
    }

    public function test_a_newer_market_release_version_wins_over_an_older_declaration(): void
    {
        // رکوردِ بازار (approved و غیرِ yank) منبعِ «آخرین نسخه» است. چون slug
        // یکتاست، همین رکورد هم افزونهٔ نصب‌شده است؛ پس نسخهٔ نصب‌شده در
        // `manifest.latest_version` یک نسخهٔ قدیمی‌تر اعلام شده تا مسیرِ
        // «بازار برنده است» قابلِ سنجش باشد.
        $this->plugin([
            'slug' => 'market-fed',
            'version' => '3.0.0',
            'source' => Plugin::SOURCE_MARKET,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => [
                'latest_version' => '2.0.0',
                'changelog' => 'نسخهٔ بازار',
            ],
        ]);

        $row = $this->rowFor('market-fed');

        $this->assertSame('3.0.0', $row['latest_version']);
        $this->assertFalse($row['update_available'], 'نسخهٔ نصب‌شده همان نسخهٔ بازار است.');
        $this->assertSame('نسخهٔ بازار', $row['changelog']);
    }

    public function test_show_endpoint_exposes_the_same_update_fields(): void
    {
        $plugin = $this->plugin([
            'slug' => 'show-probe',
            'version' => '1.0.0',
            'manifest' => ['latest_version' => '1.5.0', 'changelog' => 'اصلاح'],
        ]);

        $row = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/admin/plugins/{$plugin->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue($row['update_available']);
        $this->assertSame('1.5.0', $row['latest_version']);
        $this->assertSame('اصلاح', $row['changelog']);
    }
}
