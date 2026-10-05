<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| K7.6 — ردیف‌های per-user ⇒ ردیف‌های per-installation.
|
| ## تصمیمِ ql-bound (Q1) که این مهاجرت اجرا می‌کند
|
| «اشتراک» ملکِ **نصب** است، نه ملکِ کاربر. یعنی کلید، `site_id` است. کاربر
| سوپرادمینِ مخفی از این مدل بیرون است: نه ردیف مشتری می‌سازد، نه صورتحساب
| (همان قاعده‌ای که `ClientSubscriptionResolver::forUser()` از قبل اعمال می‌کند).
|
| ## وضعیتِ قبل
|
| تا L-B12 کلیدِ جدول `subscription_subscriptions` عملاً **per-user** بود:
| `unique('owner_user_id')` روی همان ستون، و `ensureFor()` هم `site_id` را از روی
| کاربر می‌ساخت (`'site-'.$user->id`). نتیجه: سه کاربر روی یک نصب ⇒ سه ردیف
| اشتراک برای **یک** نصب، و هر کدام با `site_id` یکسان. یعنی نصب سه بار صورت‌حساب
| می‌شد و `publicStatus($siteId)` همیشه اولینِ آن‌ها را برمی‌گرداند.
|
| ## این مهاجرت چه می‌کند — و چه **نمی‌کند**
|
|  ۱) هر ردیف زنده یک `site_id` غیرخالی می‌گیرد. منشأ به‌ترتیب: کاربرِ مالک
|     (قراردادِ قدیمی `ensureFor`) وگرنه خودِ `id`. این کلید **مشتق و پایدار** است
|     و هرگز به کاربرِ دیگری اشاره نمی‌کند.
|  ۲) ردیف‌های تکراریِ یک نصب (بیش از یک ردیف زنده با `site_id` یکسان) به **یکی**
|     جمع می‌شوند: قدیمی‌ترین `id` می‌ماند و بقیه **soft-delete** می‌شوند.
|  ۳) ردیفِ سوپرادمین soft-delete می‌شود — پایهٔ «سوپرادمین مستثنی است».
|
| ⛔ **هیچ سطری حذف نمی‌شود** (قاعدهٔ «هرگز DROP»): از `deleted_at` استفاده
| می‌شود که مدل `Subscription` با `SoftDeletes` از قبل دارد و
| `effectiveStatus()` آن را `deleted` می‌خواند. فاکتورهای ردیفِ بازنده **سرِ جایشان
| می‌مانند** و به سطرِ برنده منتقل نمی‌شوند — تاریخِ مالی باید دست‌نخورده بماند،
| وگرنه سابقهٔ فاکتورِ کاربرِ دوم پاک می‌شود.
|
| ⚠️ **این مهاجرت داده است، نه DDL.** قیدِ یکتای `site_id` عمداً ساخته نمی‌شود:
| نقشِ DDL افزونه مالکِ جدول نیست و در PostgreSQL `CREATE INDEX` مالکیت می‌خواهد،
| پس ساختنش یعنی شکستنِ نصب. تا آن تصمیم جداگانه، یکتایی فقط در **داده** است.
| TODO(K7.6): قیدِ یکتا روی `site_id`، در migrationای که با نقشِ هسته اجرا شود.
|
| ## چرا در `database/migrations` و نه در پوشهٔ افزونه
|
| دوتا دلیل، هر دو عملی: (۱) این جدول‌ها متعلق به افزونه‌اند ولی migrationهای
| افزونه با **نقشِ DDL محدود** اجرا می‌شوند و آن نقش حتی `SELECT` روی `users`
| ندارد — یعنی قاعدهٔ «سوپرادمین مستثنی است» اصلاً قابل اجرا نمی‌شد. (۲) در نصبِ
| عمومیِ بدون افزونه، جدول‌ها وجود ندارند و کل فایل باید بی‌سروصدا رد شود — که با
| `hasTable` guard می‌شود. ترتیب هم مشکلی نیست: برای نصبِ تازه، این مهاجرت قبل از
| نصبِ افزونه اجرا می‌شود و no-op است، و برای نصبِ موجود (که جدول‌ها هست) کارش را
| می‌کند.
*/
return new class extends Migration
{
    private const TABLE = 'subscription_subscriptions';

    public function up(): void
    {
        if (! $this->ready()) {
            return;
        }

        $this->dropSuperadminRows();
        $this->giveEveryRowAnInstallationKey();
        $this->collapseDuplicateInstallations();
    }

    /**
     * ⚠️ برگشت **ناقص** است و این عمدی است، نه یک فراموشی.
     *
     *  • ردیف‌های سوپرادمین دقیقاً قابل شناسایی‌اند (`owner_user_id` در فهرست
     *    سوپرادمین‌ها) ⇒ برمی‌گردند.
     *  • ردیف‌های بازندهٔ جمع‌شدن **قابل شناسایی نیستند**: پس از این مهاجرت هر
     *    `site_id` فقط یک ردیفِ زنده دارد، پس هیچ predicateای نمی‌تواند بگوید کدام
     *    ردیفِ soft-deleteشده «بازنده» بوده و کدام از قبل soft-delete شده بود. تنها
     *    راهِ صددرصد، ثبت فهرستِ id هنگام `up()` است که یعنی جدولِ وضعیت تازه.
     *    به‌جایش ساختن جدول، صریح می‌گوییم: **این مهاجرت یک‌طرفه است.**
     *
     *  دلیلِ قابل قبول بودنش: `PluginMigrator` اصلاً rollback نمی‌کند (فقط
     *  `pishdad_plugin_migrations` جلو را می‌گیرد)، و راهِ برگشتِ دستی هم هست:
     *  `Subscription::withTrashed()` همه‌چیز را نشان می‌دهد و
     *  `updateOrCreate(['id' => …], ['deleted_at' => null])` سطر را برمی‌گرداند.
     *  پس داده **گم نمی‌شود** — فقط خودکار برنمی‌گردد.
     */
    public function down(): void
    {
        if (! $this->ready()) {
            return;
        }

        $ids = $this->superAdminIds();

        if ($ids === []) {
            return;
        }

        DB::table(self::TABLE)
            ->whereNotNull('deleted_at')
            ->whereIn('owner_user_id', $ids)
            ->update(['deleted_at' => null]);
    }

    /**
     * ⭐ ۱) قاعدهٔ «سوپرادمین صورتحساب ندارد».
     *
     * soft-delete، نه delete: سطر می‌ماند، `effectiveStatus()` می‌گوید `deleted` و
     * اگر روزی تصمیم عوض شد برگشت‌پذیر است.
     */
    private function dropSuperadminRows(): void
    {
        $ids = $this->superAdminIds();

        if ($ids === []) {
            return;
        }

        DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->whereIn('owner_user_id', $ids)
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * ۲) هر ردیف زنده یک کلیدِ نصب می‌گیرد.
     *
     * ترتیبِ منشأ عمدی است: کلیدِ قدیمیِ همان کاربر (`site-{id}`) قبل از هر چیز، چون
     * `publicStatus($siteId)` و `install_token` جایی همان را می‌بینند و تغییر دادنش
     * آن‌ها را بی‌سروصدا از کار می‌اندازد. `install-{id}` فقط برای ردیفِ بی‌مالک است.
     */
    private function giveEveryRowAnInstallationKey(): void
    {
        // `btrim` عمداً: یک `site_id` که فقط فاصله دارد عملاً خالی است — نه
        // `publicStatus($siteId)` آن را پیدا می‌کند نه `install_token`، پس نصب
        // بی‌صدا از صورتحساب می‌افتد.
        $rows = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('site_id')->orWhereRaw("btrim(site_id) = ''"))
            ->orderBy('id')
            ->get(['id', 'owner_user_id']);

        foreach ($rows as $row) {
            $key = $row->owner_user_id !== null
                ? 'site-'.$row->owner_user_id
                : 'install-'.$row->id;

            DB::table(self::TABLE)->where('id', $row->id)->update(['site_id' => $key]);
        }
    }

    /**
     * ۳) چند ردیف برای **یک** نصب ⇒ یکی.
     *
     * قدیمی‌ترین `id` برنده است، چون `SubscriptionData::subscriptionFor()` و
     * `publicStatus()` هر دو قدیمی‌ترین را برمی‌گردانند و تغییر دادنشان یعنی شکستنِ
     * انتظاراتی که تست‌های موجود دارند.
     */
    private function collapseDuplicateInstallations(): void
    {
        $duplicated = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->whereNotNull('site_id')
            ->where('site_id', '!=', '')
            ->groupBy('site_id')
            ->havingRaw('count(*) > 1')
            ->pluck('site_id');

        foreach ($duplicated as $siteId) {
            $losers = DB::table(self::TABLE)
                ->whereNull('deleted_at')
                ->where('site_id', $siteId)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1)
                ->all();

            if ($losers === []) {
                continue;
            }

            DB::table(self::TABLE)
                ->whereIn('id', $losers)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return list<int> */
    private function superAdminIds(): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_super_admin')) {
            return [];
        }

        return array_map('intval', DB::table('users')->where('is_super_admin', true)->pluck('id')->all());
    }

    /**
     * نصبِ عمومیِ بدون افزونهٔ اشتراک: جدول‌ها نیستند و این مهاجرت باید بی‌سروصدا
     * رد شود — نه اینکه `php artisan migrate` را بشکند.
     */
    private function ready(): bool
    {
        return Schema::hasTable(self::TABLE)
            && Schema::hasColumn(self::TABLE, 'site_id')
            && Schema::hasColumn(self::TABLE, 'deleted_at');
    }
};
