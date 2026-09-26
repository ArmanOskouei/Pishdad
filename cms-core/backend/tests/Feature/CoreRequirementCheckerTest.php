<?php

namespace Tests\Feature;

use App\Services\Plugins\CoreRequirementChecker;
use Tests\TestCase;

/**
 * K3.0 / B25 — گرامر `requires.core`.
 *
 * این سرویس خالص است، پس تست‌ها هم بدون DB و بدون شبیه‌سازی‌اند: آرایهٔ مانیفست و
 * نسخهٔ هسته در می‌آید، `null` یا آرایهٔ خطا بیرون. مهم‌ترین بخش تست‌ها ستون آخر
 * است: ورودیِ بی‌معنی باید **رد** شود، نه اینکه بی‌صدا «سازگار» فرض شود.
 */
class CoreRequirementCheckerTest extends TestCase
{
    private function check(mixed $core, string $current): ?array
    {
        return CoreRequirementChecker::check(['requires' => ['core' => $core]], $current);
    }

    private function assertSatisfied(string $core, string $current): void
    {
        $this->assertNull(
            $this->check($core, $current),
            'این ترکیب باید سازگار باشد ولی رد شد: requires.core='.$core.' روی هستهٔ '.$current
        );
    }

    private function assertRejected(string $core, string $current, string $code = 'requires.core.unsatisfied'): void
    {
        $issue = $this->check($core, $current);

        $this->assertIsArray($issue, "باید رد می‌شد ولی سازگار فرض شد: {$core} روی {$current}");
        $this->assertSame($code, $issue['code']);
        $this->assertSame($core, $issue['required']);
        $this->assertSame($current, $issue['current']);
        $this->assertNotSame('', trim($issue['message']));
        // پیام فارسی است و در پاسخ API مستقیم به کاربر نشان داده می‌شود.
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $issue['message']);
    }

    // ── `*` ──────────────────────────────────────────────────────────────────

    public function test_star_accepts_every_core_version(): void
    {
        $this->assertSatisfied('*', '1.2.0');
        $this->assertSatisfied('*', '9.9.9');
        $this->assertSatisfied('*', '0.0.1');
    }

    // ── بازهٔ چندشرطی با فاصله ────────────────────────────────────────────────

    public function test_space_separated_range_is_conjunctive(): void
    {
        $this->assertSatisfied('>=1.4.0 <2.0.0', '1.4.0');
        $this->assertSatisfied('>=1.4.0 <2.0.0', '1.9.9');
        $this->assertRejected('>=1.4.0 <2.0.0', '1.2.0');
        $this->assertRejected('>=1.4.0 <2.0.0', '2.0.0');
    }

    public function test_partial_version_lower_bound(): void
    {
        $this->assertSatisfied('>=1.2', '1.2.0');
        $this->assertSatisfied('>=1.2', '1.9.0');
        $this->assertRejected('>=1.2', '1.1.9');
    }

    // ── `^` ──────────────────────────────────────────────────────────────────

    public function test_caret_allows_minor_and_patch_bumps(): void
    {
        $this->assertSatisfied('^1.2.0', '1.2.0');
        $this->assertSatisfied('^1.2.0', '1.9.9');
        $this->assertRejected('^1.2.0', '1.1.9');
        $this->assertRejected('^1.2.0', '2.0.0');
    }

    public function test_caret_locks_the_leftmost_non_zero_segment(): void
    {
        $this->assertSatisfied('^0.2.3', '0.2.9');
        $this->assertRejected('^0.2.3', '0.3.0');

        $this->assertSatisfied('^0.0.3', '0.0.3');
        $this->assertRejected('^0.0.3', '0.0.4');
    }

    // ── `1.2.*` ──────────────────────────────────────────────────────────────

    public function test_wildcard_pins_the_minor(): void
    {
        $this->assertSatisfied('1.2.*', '1.2.0');
        $this->assertSatisfied('1.2.*', '1.2.9');
        $this->assertRejected('1.2.*', '1.3.0');
        $this->assertRejected('1.2.*', '1.1.9');
    }

    public function test_wildcard_with_only_major(): void
    {
        $this->assertSatisfied('1.*', '1.9.9');
        $this->assertRejected('1.*', '2.0.0');
        $this->assertRejected('1.*', '0.9.9');
    }

    // ── `~` ──────────────────────────────────────────────────────────────────

    public function test_tilde_allows_patch_bumps_only(): void
    {
        $this->assertSatisfied('~1.2.0', '1.2.0');
        $this->assertSatisfied('~1.2.0', '1.2.9');
        $this->assertRejected('~1.2.0', '1.3.0');
    }

    public function test_tilde_without_patch_widens_to_the_minor(): void
    {
        $this->assertSatisfied('~1.2', '1.9.9');
        $this->assertRejected('~1.2', '2.0.0');
    }

    // ── نسخهٔ دقیق و عملگرهای تکی ────────────────────────────────────────────

    public function test_exact_version(): void
    {
        $this->assertSatisfied('1.5.0', '1.5.0');
        $this->assertRejected('1.5.0', '1.5.1');
    }

    public function test_single_comparators(): void
    {
        $this->assertSatisfied('<2.0.0', '1.9.9');
        $this->assertRejected('<2.0.0', '2.0.0');
        $this->assertSatisfied('!=1.0.0', '1.0.1');
        $this->assertRejected('!=1.0.0', '1.0.0');
    }

    // ── نبودن اعلام = سازگاری ────────────────────────────────────────────────

    public function test_absent_requires_is_compatible(): void
    {
        $this->assertNull(CoreRequirementChecker::check(null, '1.5.0'));
        $this->assertNull(CoreRequirementChecker::check(['name' => 'پلاگین'], '1.5.0'));
        $this->assertNull(CoreRequirementChecker::check(['requires' => []], '1.5.0'));
    }

    public function test_requires_without_core_is_compatible(): void
    {
        $this->assertNull(CoreRequirementChecker::check(['requires' => ['php' => '>=8.2']], '1.5.0'));
    }

    // ── fail-closed ──────────────────────────────────────────────────────────

    public function test_non_object_requires_is_an_error_not_silence(): void
    {
        $issue = CoreRequirementChecker::check(['requires' => '>=1.4.0'], '1.5.0');

        $this->assertIsArray($issue);
        $this->assertSame('requires.invalid', $issue['code']);
        $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $issue['message']);
    }

    /** ورودی غیرشته‌ای، خالی، یا خارج از گرامر پذیرفته‌شده ⇒ خطا. */
    public function test_unreadable_core_requirement_is_rejected(): void
    {
        foreach ([
            'empty' => '',
            'blank' => '   ',
            'word' => 'latest',
            'disjunction' => '1.4.0 || 2.0.0',
            'hyphen_range' => '1.2.0 - 2.0.0',
            'v_prefix' => 'v1.2.3',
            'operator_only' => '>',
            'operator_before_caret' => '>=^1.0',
            'tilde_tilde' => '~>1.2',
            'wildcard_in_middle' => '1.*.3',
            'trailing_garbage' => '1.2.0 !',
            'int' => 123,
            'array' => ['1.2.0'],
            'null' => null,
        ] as $label => $value) {
            $issue = $this->check($value, '1.5.0');

            $this->assertIsArray($issue, "«{$label}» نباید بی‌صدا سازگار فرض می‌شد.");
            $this->assertSame('requires.core.invalid', $issue['code'], "کد اشتباه برای «{$label}».");
        }
    }

    /** نسخهٔ ناخوانای خودِ هسته یعنی «سازگاری سنجیده نشد» — و سکوت در آنجا خطرناک است. */
    public function test_unreadable_current_core_version_is_rejected(): void
    {
        $issue = CoreRequirementChecker::check(['requires' => ['core' => '*']], 'latest');

        $this->assertIsArray($issue);
        $this->assertSame('core.version_invalid', $issue['code']);
    }

    public function test_pre_release_is_below_its_release(): void
    {
        $this->assertSatisfied('>=1.2.0-beta.1', '1.2.0-beta.1');
        $this->assertRejected('^1.2.0', '1.2.0-beta.1');
    }

    // ── نسخهٔ هسته ───────────────────────────────────────────────────────────

    public function test_core_version_prefers_config_over_the_constant(): void
    {
        config(['app.core_version' => '2.3.4']);
        $this->assertSame('2.3.4', CoreRequirementChecker::coreVersion());

        config(['app.core_version' => '  ']);
        $this->assertSame(CoreRequirementChecker::CORE_VERSION, CoreRequirementChecker::coreVersion());
    }

    public function test_core_version_falls_back_to_a_readable_semver(): void
    {
        $version = CoreRequirementChecker::coreVersion();

        $this->assertMatchesRegularExpression('/^\d+(\.\d+)+$/', $version);
        $this->assertNull(
            CoreRequirementChecker::check(['requires' => ['core' => '*']], $version)
        );
    }
}
