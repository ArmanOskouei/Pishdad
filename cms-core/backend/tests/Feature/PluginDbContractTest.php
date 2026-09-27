<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginDbContract;
use Tests\TestCase;

/**
 * K5.6 — قرارداد `db.tables`.
 *
 * این تست‌ها مرزها را می‌سنجند، نه موفقیت را: هر قاعده یک دست‌کم ورودی دارد که
 * باید رد شود. تستی که فقط ورودی خوب می‌آزماید، برای fail-closed بی‌ارزش است.
 */
class PluginDbContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $db
     * @return array<int, string> کدهای issue
     */
    private function codes(mixed $db, string $slug = 'demo'): array
    {
        return array_column(PluginDbContract::check($db, $slug), 'code');
    }

    private function goodDb(array $tables = [['name' => 'posts']]): array
    {
        return ['tables' => $tables];
    }

    /**
     * مرزها باید **عددِ ثابت** باشند، نه ثابتِ کد.
     *
     * نسخهٔ اول این تست مرز را از روی PluginDbContract::MAX_TABLES می‌ساخت، پس
     * با تغییر آن ثابت، مرز تست هم تغییر می‌کرد و mutation زنده ماند: سقف ۲۰
     * جدول می‌شد ۱۰۰۰ و هیچ تستی قرمز نمی‌شد. این سه تست همان اعداد سند معماری
     * را عیناً می‌نویسند تا تغییر ناخواستهٔ سقف لو برود.
     */
    public function test_the_caps_match_the_architecture_document(): void
    {
        $this->assertSame(20, PluginDbContract::MAX_TABLES, 'سقف جدول در سند معماری ۲۰ است.');
        $this->assertSame(10, PluginDbContract::MAX_INDEXES_PER_TABLE, 'سقف ایندکس **در هر جدول** ۱۰ است.');
        $this->assertSame(40, PluginDbContract::MAX_PREFIXED_LENGTH, 'سقف طول نام ۴۰ نویسه است.');
    }

    // ── ۱) نبودن db مجاز است ────────────────────────────────────────────

    public function test_absent_db_is_fine(): void
    {
        $this->assertSame([], PluginDbContract::check(null, 'demo'), 'افزونهٔ بدون جدول نباید خطا بگیرد.');
    }

    public function test_a_well_formed_declaration_passes(): void
    {
        $db = $this->goodDb([
            ['name' => 'posts'],
            ['name' => 'comments', 'indexes' => ['post_id', 'author_id']],
        ]);

        $this->assertSame([], PluginDbContract::check($db, 'demo'));
    }

    // ── ۲) شکل کلی ─────────────────────────────────────────────────────

    public function test_db_must_be_an_object(): void
    {
        $this->assertSame(['db.not_object'], $this->codes(['posts']));
    }

    public function test_unknown_top_level_key_is_rejected(): void
    {
        $this->assertSame(['db.unknown_key'], $this->codes(['tables' => [], 'prefix' => 'x_']));
    }

    public function test_db_without_tables_is_rejected(): void
    {
        // `tables: null` تنها راه رسیدن به این خطاست — آبجکت خالی `{}` در PHP
        // به `[]` تبدیل می‌شود و زودتر `db.not_object` می‌گیرد.
        $this->assertSame(['db.no_tables'], $this->codes(['tables' => null]));
    }

    public function test_tables_must_be_a_list(): void
    {
        $this->assertSame(['db.tables_not_list'], $this->codes(['tables' => ['posts' => ['name' => 'posts']]]));
    }

    // ── ۳) سقف‌ها ──────────────────────────────────────────────────────

    public function test_twenty_tables_is_allowed_and_twenty_one_is_not(): void
    {
        $ok = [];
        for ($i = 0; $i < 20; $i++) {
            $ok[] = ['name' => 't'.$i];
        }
        $this->assertSame([], $this->codes($this->goodDb($ok)), 'سقف ۲۰ جدول باید پذیرفته شود.');

        $ok[] = ['name' => 'tone_too_many'];
        $this->assertSame(['db.too_many_tables'], $this->codes($this->goodDb($ok)));
    }

    public function test_ten_indexes_per_table_is_allowed_and_eleven_is_not(): void
    {
        $ten = [];
        for ($i = 0; $i < 10; $i++) {
            $ten[] = 'c'.$i;
        }
        $this->assertSame([], $this->codes($this->goodDb([['name' => 'posts', 'indexes' => $ten]])));

        $ten[] = 'c_one_too_many';
        $this->assertSame(['db.too_many_indexes'], $this->codes($this->goodDb([['name' => 'posts', 'indexes' => $ten]])));
    }

    /**
     * سقف «در هر جدول» است، نه در کل افزونه: ۲۰ جدول با ۱۰ ایندکس هر کدام باید
     * پذیرفته شود. اگر روزی کسی سقف را به «کل افزونه» تغییر دهد، این تست قرمز می‌شود.
     */
    public function test_the_index_budget_is_per_table_not_per_plugin(): void
    {
        $ten = [];
        for ($i = 0; $i < 10; $i++) {
            $ten[] = 'c'.$i;
        }
        $tables = [];
        for ($i = 0; $i < 20; $i++) {
            $tables[] = ['name' => 't'.$i, 'indexes' => $ten];
        }

        $this->assertSame(
            [],
            $this->codes($this->goodDb($tables)),
            20 * 10 .' ایندکس در کل باید پذیرفته شود، چون سقف در هر جدول است.'
        );
    }

    // ── ۴) نام‌گذاری ───────────────────────────────────────────────────

    public function test_name_must_be_raw_lowercase_with_underscores(): void
    {
        $this->assertSame(['db.table_bad_name'], $this->codes($this->goodDb([['name' => 'Posts']])));
        $this->assertSame(['db.table_bad_name'], $this->codes($this->goodDb([['name' => 'my-posts']])));
        $this->assertSame(['db.table_bad_name'], $this->codes($this->goodDb([['name' => '1posts']])));
    }

    public function test_declaring_the_prefix_yourself_is_rejected(): void
    {
        $this->assertSame(
            ['db.table_already_prefixed'],
            $this->codes($this->goodDb([['name' => 'demo_posts']])),
            'وگرنه جدول demo_demo_posts ساخته می‌شد.'
        );
    }

    public function test_prefixed_length_over_forty_is_rejected(): void
    {
        $long = str_repeat('a', 30);

        // ۸ + ۱ + ۳۰ = ۳۹ ⇒ زیر سقف، باید پذیرفته شود.
        $this->assertSame(
            [],
            $this->codes($this->goodDb([['name' => $long]]), 'abcdefgh'),
            '۳۹ نویسه باید پذیرفته شود.'
        );

        // ۱۲ + ۱ + ۳۰ = ۴۳ ⇒ بالای سقف.
        $this->assertSame(
            ['db.table_name_too_long'],
            $this->codes($this->goodDb([['name' => $long]]), 'abcdefghijkl')
        );
    }

    public function test_slug_with_dots_or_dashes_cannot_own_tables(): void
    {
        // `slug` اجازهٔ نقطه و خط تیره می‌دهد ولی شناسهٔ SQL اجازه ندارد.
        $this->assertSame(['db.slug_not_table_safe'], $this->codes($this->goodDb(), 'my-plugin'));
        $this->assertSame(['db.slug_not_table_safe'], $this->codes($this->goodDb(), 'my.plugin'));
    }

    public function test_duplicate_table_declaration_is_rejected(): void
    {
        $this->assertSame(
            ['db.table_duplicate'],
            $this->codes($this->goodDb([['name' => 'posts'], ['name' => 'posts']]))
        );
    }

    // ── ۵) تداخل با هسته ───────────────────────────────────────────────

    public function test_declaring_a_core_table_name_is_rejected(): void
    {
        foreach (['users', 'migrations', 'cache', 'personal_access_tokens'] as $core) {
            $this->assertSame(
                ['db.table_collides_core'],
                $this->codes($this->goodDb([['name' => $core]])),
                '«'.$core.'» جدول هسته است.'
            );
        }
    }

    /**
     * جدول‌های مرکزی با قاعدهٔ پیشوند رد می‌شوند، نه با فهرست.
     *
     * اگر روزی کسی فهرست را به اسم تک‌تک جدول‌ها برگرداند، این تست قرمز می‌شود:
     * جدول مرکزیِ تازه‌ای که یادش نرفت دیگر سوراخ امنیتی خاموش باز نمی‌کند.
     */
    public function test_central_tables_are_rejected_by_prefix_not_by_list(): void
    {
        $this->assertSame('central_', PluginDbContract::CENTRAL_PREFIX);

        // نامی که در هیچ فهرستی نیست ولی پیشوند مرکزی دارد.
        $this->assertSame(
            ['db.table_collides_core'],
            $this->codes($this->goodDb([['name' => 'central_anything_at_all']])),
            'هر نامی با پیشوند مرکزی باید رد شود، حتی اگر تازه باشد.'
        );
    }

    // ── ۶) شکل ایندکس ──────────────────────────────────────────────────

    public function test_index_names_are_validated(): void
    {
        $this->assertSame(
            ['db.index_bad_name'],
            $this->codes($this->goodDb([['name' => 'posts', 'indexes' => ['Post-Id']]]))
        );
    }

    public function test_indexes_must_be_a_list(): void
    {
        $this->assertSame(
            ['db.indexes_not_list'],
            $this->codes($this->goodDb([['name' => 'posts', 'indexes' => ['a' => 'author_id']]]))
        );
    }

    // ── ۷) خودِ نام نهایی ──────────────────────────────────────────────

    public function test_prefixed_builds_the_documented_name(): void
    {
        $this->assertSame('demo_posts', PluginDbContract::prefixed('demo', 'posts'));
    }

    public function test_every_core_table_is_reachable_through_the_declaration_path(): void
    {
        // اگر روزی فهرست CORE_TABLES را کوتاه کرد، این تست یادآوری می‌کند که آن
        // فهرست **بسته** است — هر جدول هسته باید از راه اعلام قابل رد باشد.
        foreach (PluginDbContract::CORE_TABLES as $core) {
            $this->assertNotSame(
                [],
                PluginDbContract::check(['tables' => [['name' => $core]]], 'demo'),
                'جدول هسته «'.$core.'» از راه اعلام رد نمی‌شود — یعنی می‌شود آن را اعلام کرد.'
            );
        }
    }
}
