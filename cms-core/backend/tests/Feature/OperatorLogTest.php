<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\OperatorActivity;
use Tests\TestCase;

/**
 * WF-L4 — لاگ فعالیت اپراتور: فیلتر/جستجو، خروجی CSV و نگهداشت ۹۰ روز.
 *
 * این تست عمداً روی **همان مسیرِ HTTP** می‌زند که UI می‌زند (`/api/v1/p/central/…`)
 * تا قلابِ خودکارِ `Tests\TestCase` افزونهٔ قرنطینه را فعال کند و با یک تستِ
 * سبزِ توخالی (که اصلاً جدول را نمی‌بیند) اشتباه نشویم.
 */
class OperatorLogTest extends TestCase
{
    use RefreshDatabase;

    private const LIST = '/api/v1/p/central/operator/activity';

    private const EXPORT = '/api/v1/p/central/operator/activity/export';

    private function user(string $role = 'operator', string $name = 'اپراتور'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Secret!123'),
            'role' => $role,
        ]);
    }

    /**
     * ردیفِ لاگ با زمانِ دلخواه.
     *
     * `created_at` عمداً جدا از `create()` داده می‌شود چون در `$fillable` نیست؛
     * و پنجرهٔ نگهداشت بدون ردیفِ قدیمی قابل اثبات نیست.
     */
    private function row(?User $user, string $action, array $meta = [], ?string $at = null, ?string $ip = null): OperatorActivity
    {
        $row = OperatorActivity::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'ip' => $ip ?? '10.0.0.1',
            'meta' => $meta,
        ]);

        if ($at !== null) {
            $row->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }

        return $row;
    }

    public function test_listing_filters_by_action_operator_free_text_and_range(): void
    {
        $operator = $this->user('operator', 'رضا');
        $other = $this->user('operator', 'سمیرا');

        $this->row($operator, 'operator.setup_token', ['purpose' => 'نصب کلینیک سپید']);
        $this->row($operator, 'operator.kill_switch', ['reason' => 'نفوذ تأییدشده']);
        $this->row($other, 'operator.setup_token');

        $auth = $this->actingAs($operator, 'sanctum');

        // اکشنِ دقیق.
        $auth->getJson(self::LIST.'?action=operator.kill_switch')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.action', 'operator.kill_switch');

        // اپراتورِ مشخص.
        $auth->getJson(self::LIST.'?user_id='.$other->id)->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.user_id', $other->id);

        // متن آزاد روی متادیتا (نه فقط ستون‌های جدول).
        $auth->getJson(self::LIST.'?search='.urlencode('نفوذ'))->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.action', 'operator.kill_switch');

        // متن آزاد روی نام/ایمیلِ اپراتور.
        $auth->getJson(self::LIST.'?search='.urlencode('سمیرا'))->assertOk()->assertJsonCount(1, 'data.data');

        // بازهٔ تاریخی: فقط ردیفِ داخل بازه.
        $auth->getJson(self::LIST.'?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())->assertOk()
            ->assertJsonCount(3, 'data.data');

        $auth->getJson(self::LIST.'?from='.now()->subDay()->toDateString().'&action=operator.setup_token&user_id='.$operator->id)->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_listing_shows_a_single_operators_actions_in_the_response(): void
    {
        $operator = $this->user();
        $this->row($operator, 'operator.setup_token');
        $this->row($operator, 'operator.hardware_key');

        $this->actingAs($operator, 'sanctum')->getJson(self::LIST)->assertOk()
            // متادیتای فیلتر برای dropdown اکشن لازم است؛ نبودش یعنی UI حدس می‌زند.
            ->assertJsonPath('filters.default_days', 30)
            ->assertJsonFragment(['operator.setup_token'])
            ->assertJsonFragment(['operator.hardware_key']);
    }

    public function test_listing_defaults_to_the_last_thirty_days_and_hides_older_rows(): void
    {
        $operator = $this->user();

        $this->row($operator, 'operator.setup_token', [], now()->subDays(2)->toDateTimeString());
        $this->row($operator, 'operator.setup_token', [], now()->subDays(45)->toDateTimeString());

        // پیش‌فرض: ۳۰ روز اخیر ⇒ ردیفِ ۴۵ روزه بیرون می‌ماند…
        $this->actingAs($operator, 'sanctum')->getJson(self::LIST)->assertOk()->assertJsonCount(1, 'data.data');

        // …و بازهٔ صریح، همان ردیف را برمی‌گرداند (پس «پیش‌فرض» است، نه «سقف»).
        $this->actingAs($operator, 'sanctum')
            ->getJson(self::LIST.'?from='.now()->subDays(60)->toDateString())
            ->assertOk()->assertJsonCount(2, 'data.data');
    }

    public function test_listing_paginates_and_exposes_a_bounded_action_list(): void
    {
        $operator = $this->user();

        for ($i = 0; $i < 3; $i++) {
            $this->row($operator, 'operator.setup_token');
        }

        $this->actingAs($operator, 'sanctum')->getJson(self::LIST.'?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.last_page', 2);
    }

    public function test_csv_export_streams_the_filtered_rows_with_bom_and_headers(): void
    {
        $operator = $this->user('operator', 'رضا');
        $other = $this->user('operator', 'سمیرا');

        $this->row($operator, 'operator.setup_token', ['purpose' => 'نصب'], now()->subDays(2)->toDateTimeString(), '1.2.3.4');
        $this->row($other, 'operator.kill_switch', ['reason' => 'نفوذ'], now()->subDays(3)->toDateTimeString(), '5.6.7.8');
        $this->row($other, 'operator.hardware_key', [], now()->subDays(80)->toDateTimeString(), '9.9.9.9');

        $res = $this->actingAs($operator, 'sanctum')
            ->get(self::EXPORT.'?user_id='.$operator->id);

        $res->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString(
            'attachment; filename="operator-activity-',
            (string) $res->headers->get('content-disposition'),
        );

        $body = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'بدون BOM اکسلِ فارسی ستون‌ها را به‌هم می‌ریزد.');
        $this->assertStringContainsString('operator.setup_token', $body);
        $this->assertStringNotContainsString('operator.kill_switch', $body, 'CSV باید همان انتخابِ فیلترشده باشد.');
        $this->assertStringNotContainsString('operator.hardware_key', $body, 'ردیفِ خارج از پنجرهٔ ۳۰ روزه نباید بیاید.');
        $this->assertStringContainsString('1.2.3.4', $body);
    }

    public function test_csv_export_neutralises_formula_cells(): void
    {
        // بردارِ واقعی، سلول‌هایی است که کاربر/اپراتور کنترلشان می‌کند: نام و ایمیل
        // و IP. بدون گاردِ فرمول، اکسل همان لحظه فرمول را اجرا می‌کند.
        $operator = $this->user('operator', '=cmd|calc');
        $this->row($operator, 'operator.setup_token', ['purpose' => 'نصب عادی'], null, '+1-1');

        $body = $this->actingAs($operator, 'sanctum')->get(self::EXPORT)->assertOk()->streamedContent();

        [$header, $lines] = $this->csvRows($body);

        $this->assertSame(
            ['id', 'created_at', 'action', 'user_id', 'user_name', 'user_email', 'ip', 'meta'],
            $header,
        );
        $this->assertCount(1, $lines);

        $cells = $lines[0];

        foreach ($cells as $cell) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[=+\-@]/',
                $cell,
                'سلولِ آغازشده با = + - @ یعنی فرمولِ اجراشدنی در اکسل.',
            );
        }

        // نباید «حضورِ مقدار» را با مقدارِ خام بسنجیم: اگر `=cmd|calc` خام بماند
        // یعنی گارد کار نکرده، پس سطرِ بالا تنها چیزی است که آن را می‌گیرد.
        // اینجا دقیقاً همان سلول را می‌خواهیم: متنِ اصلی + پیشوندِ خنثی‌کننده.
        $this->assertSame("'=cmd|calc", $cells[4], 'نامِ فرمولی باید همان‌جا بماند، فقط با پیشوندِ خنثی‌کننده.');
        $this->assertSame("'+1-1", $cells[6]);
    }

    public function test_csv_export_leaves_plain_numbers_as_numbers(): void
    {
        // رابطهٔ `-1` در هیچ spreadsheetای اجرا نمی‌شود؛ پس پیشوند گذاشتن رویش
        // فقط داده را خراب می‌کند (`'-1` در اکسل تراز-چپ و غیرقابل‌جمع می‌شود).
        $operator = $this->user('operator', '-12.5');
        $this->row($operator, 'operator.setup_token', [], null, '-1');

        $body = $this->actingAs($operator, 'sanctum')->get(self::EXPORT)->assertOk()->streamedContent();

        [, $lines] = $this->csvRows($body);
        $cells = $lines[0];

        $this->assertSame('-12.5', $cells[4], 'عددِ منفی داده است، نه حمله — نباید متن شود.');
        $this->assertSame('-1', $cells[6]);
    }

    /**
     * بدنهٔ CSV را به سطر/سلول می‌شکند تا assertion روی «همهٔ سلول‌ها» باشد،
     * نه روی یک رشتهٔ بزرگ که با هر بازنویسیِ ستون می‌شکند.
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $lines = array_values(array_filter(preg_split('/\r\n/', trim($body)) ?: [], 'strlen'));

        $this->assertNotSame([], $lines, 'CSV خالی است.');

        $header = str_getcsv((string) array_shift($lines));

        return [$header, array_map(static fn (string $line): array => str_getcsv($line), $lines)];
    }

    public function test_export_and_listing_require_the_operator_role(): void
    {
        $this->row($this->user('operator'), 'operator.setup_token');

        $auth = $this->actingAs($this->user('admin', 'مدیر'), 'sanctum');

        $auth->getJson(self::LIST)->assertForbidden();
        $auth->get(self::EXPORT)->assertForbidden();
    }

    public function test_prune_deletes_only_rows_older_than_the_window(): void
    {
        $operator = $this->user();

        $fresh = $this->row($operator, 'operator.setup_token', [], now()->subDays(10)->toDateTimeString());
        $edge = $this->row($operator, 'operator.setup_token', [], now()->subDays(89)->toDateTimeString());
        $stale = $this->row($operator, 'operator.setup_token', [], now()->subDays(120)->toDateTimeString());
        $ancient = $this->row($operator, 'operator.setup_token', [], now()->subDays(400)->toDateTimeString());

        $this->artisan('operator:prune')->assertSuccessful();

        $this->assertModelExists($fresh);
        $this->assertModelExists($edge);
        $this->assertModelMissing($stale);
        $this->assertModelMissing($ancient);
        $this->assertSame(2, OperatorActivity::query()->count());
    }

    public function test_prune_window_is_overridable_and_supports_a_dry_run(): void
    {
        $operator = $this->user();

        $keep = $this->row($operator, 'operator.setup_token', [], now()->subDays(10)->toDateTimeString());
        $drop = $this->row($operator, 'operator.setup_token', [], now()->subDays(45)->toDateTimeString());

        // پنجرهٔ کوتاه‌تر از پیش‌فرض ⇒ ردیفِ ۴۵ روزه هم می‌رود.
        $this->artisan('operator:prune --days=30')->assertSuccessful();

        $this->assertModelExists($keep);
        $this->assertModelMissing($drop);

        // `--dry-run` هیچ چیزی را نمی‌نویسد، حتی وقتی همه‌چیز قدیمی است.
        $older = $this->row($operator, 'operator.setup_token', [], now()->subDays(60)->toDateTimeString());
        $this->artisan('operator:prune --days=1 --dry-run')->assertSuccessful();

        $this->assertModelExists($older);
    }
}
