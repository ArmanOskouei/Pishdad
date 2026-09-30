<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * F4.1.C — مدل دادهٔ «۳ قالب × ۲-۳ رنگ‌بندی» برای سایت عمومی.
 *
 * ## چرا مدل تازه لازم است
 *
 * `L8` این را از قبل ثبت کرده بود: «سه‌قالبه + رنگ کامل — ✅ تایید، **نیاز به بازطراحی
 * مدل داده**». و درست همان‌طور است:
 *
 *  ۱. جدول `themes` امروز **کپیِ `plugins` است** — `user_id`, `signature_valid`,
 *     `review_status`, `manifest`, `path`. یعنی برای «یک پوستهٔ نصب‌شده از بازار»
 *     ساخته شده. ولی قالبِ سایتِ خودِ مشتری **نه نصب می‌شود، نه امضا دارد، نه
 *     قابل بازبینی است** — او از داخل پنل خودش انتخابش می‌کند.
 *  ۲. `site_chrome` امروز **هیچ توکن طراحی‌ای ندارد**. فقط محتوای سایت است
 *     (`title`, `description`, `logo_media_id`, `socials`, …) — نه `primary`،
 *     نه `radius`، نه `font`. پس جایی برای «رنگ‌بندی» وجود ندارد.
 *  ۳. توکن‌های امروزِ سایت در **CSS هسته** تعریف شده‌اند (`globals.css` روی
 *     `:root`) و از راه `themeVars()` روی کانتینر ریخته می‌شوند. این یعنی رنگ
 *     قالبِ کاربر **در CSS کد** است، نه در داده — و با عوض‌کردن قالب، رنگ عوض
 *     نمی‌شود.
 *
 * ## شکلِ پیشنهادی
 *
 * سه جدول، و هر کدام یک کار:
 *
 * | جدول | یک سطر = | چرا جدا |
 * |---|---|---|
 * | `site_themes` | یک پوستهٔ متمایز (بوم، تایپوگرافی، چیدمان) | قالب و رنگ دو چیز جدا هستند: کاربر می‌تواند پوستهٔ «مینیمال» را با رنگ «زرد» بگیرد |
 * | `site_theme_presets` | یک رنگ‌بندیِ آماده که به N پوسته می‌چسبد | `ADMIN-PAGES-SPEC.md:155` می‌گوید «هر قالب ۲-۳ رنگ‌بندی» و «۱۵ ترکیب کل» — یعنی رنگ‌ها مشترک‌اند و تکرارشان اشتباه است |
 * | `site_theme_settings` | انتخاب فعلی هر نصب + override‌ها | انتخاب و مقدار باید تاریخچه‌پذیر باشند تا `theme='{template}'` کار کند |
 *
 * ## چرا `presets` جداست — این تصمیمِ اصلی است
 *
 * اگر رنگ‌بندی داخل `site_themes` می‌بود، «۳ قالب × ۳ رنگ = ۹ ترکیب» می‌شد نه
 * ۱۵. و با هر قالب جدید، رنگ‌ها دوباره نوشته می‌شدند — همان ۲۶ مقدار هاردکدی که
 * `Q6` در `docs/TASKS.md` از آن شکایت کرده. جدا بودنشان یعنی افزودن رنگ چهارم
 * **یک سطر** است، نه ویرایش هر قالب.
 *
 * ## چرا `settings` جداست و نه یک ستون روی نصب
 *
 * `F4.1.W` تصمیم گرفته جداسازی ۳ قالب با `key='draft:{template}'` باشد — «نه قالب‌بندی
 * کلید». این یعنی هر قالب **دست‌کم دو ردیف** دارد (پیش‌نویس و فعال). اگر این‌ها
 * روی جدول نصب بودند، «قالب فعال» یک سطر جدا می‌خواست و ردیف فعال و پیش‌نویس
 * قابل تفکیک نبود.
 *
 * ## چرا `overrides` یک JSON است و نه ستون‌به‌ستون
 *
 * چون نقش‌ها و scopeها دقیقاً همان چیزی‌اند که `F4.1.W` گفت: «۱۰ scope × ۸ role».
 * ستون‌بندیِ ۸۰ ستون یعنی هر نقش جدید یک migration. JSON با کلیدِ `role.scope` این
 * را به یک سطر تبدیل می‌کند و `sparse` بودنش طبیعی است — همان چیزی که خود
 * یادداشت گفته.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. site_themes: the templates themselves -------------------
        if (! Schema::hasTable('site_themes')) {
            Schema::create('site_themes', function ($table): void {
                $table->id();

                // کلید یکتا ولی **نه** کلید خارجی به جدول نصب: قالبِ سایت
                // ممکن است از بازار بیاید یا از seed، و در هر دو حالت یک سطر
                // محلیِ خودش دارد. `source` این را ثبت می‌کند.
                $table->string('key', 60)->unique();

                $table->string('name', 120);
                $table->string('slug', 80)->index();

                // ارث‌بری: رنگ از `site_theme_presets`، چیدمان از خودِ سطر.
                $table->string('layout', 40)->default('editorial');

                // توکن‌هایی که **اختصاصیِ این پوسته** هستند و از رنگ نمی‌آیند:
                // تایپوگرافی، شعاع گوشه، تراکم چیدمان. این‌ها همان «تنظیمات
                // پیشرفته» `ADMIN-PAGES-SPEC.md:156` هستند.
                $table->jsonb('layout_tokens')->nullable();

                // شمارهٔ ترتیب در ویرایشگر. نه `id` — چون ترتیب دستیِ کاربر
                // با ترتیب درج فرق دارد و بازطراحی جدول نباید ترتیب را بشکند.
                $table->unsignedSmallInteger('sort_order')->default(0)->index();

                $table->boolean('is_builtin')->default(false);
                $table->string('source', 20)->default('builtin'); // builtin|market|import
                $table->string('version', 20)->default('1.0.0');

                $table->timestamps();

                // یک اسلاگ نمی‌تواند دو بار بیاید — وگرنه انتخاب کاربر مبهم می‌شود
                // و `first()` بی‌صدا یکی را برمی‌دارد.
                $table->unique('slug');
            });
        }

        // ---- 2. site_theme_presets: the shared colour schemes -----------
        if (! Schema::hasTable('site_theme_presets')) {
            Schema::create('site_theme_presets', function ($table): void {
                $table->id();
                $table->string('key', 60)->unique();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();

                /**
                 * توکن‌های رنگ. کلیدها همین‌هایی‌اند که `themeVars()` می‌شناسد و
                 * `globals.css` روی `:root` تعریف کرده — نه اسم‌های تازه. اگر
                 * اسم جدید اختراع کنیم، CSS هسته باید برایش قانون جدید بنویسد
                 * و آن یعنی یک شاخهٔ تازه در هر بلوک.
                 *
                 * ساختار عمداً `role → color` است، نه `role → {light, dark}`:
                 * روشن/تاریک **دو مجموعهٔ مستقل** از همین توکن‌هاست (F4.1.W)،
                 * پس هر preset باید دو سطر داشته باشد، یا این جدول به دو بخش
                 * تقسیم شود. فعلاً یک سطر = یک مجموعه، و جدول رنگ‌های
                 * روشن/تاریک از همان `key` با پسوند `-dark` ساخته می‌شود.
                 */
                $table->jsonb('tokens');

                $table->unsignedSmallInteger('sort_order')->default(0)->index();
                $table->boolean('is_builtin')->default(false);
                $table->timestamps();
            });
        }

        // ---- 3. site_theme_settings: the per-install selection ----------
        if (! Schema::hasTable('site_theme_settings')) {
            Schema::create('site_theme_settings', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();

                /**
                 * `F4.1.W`: جداسازی سه قالب با `key='draft:{template}'` — «نه
                 * قالب‌بندی کلید». یعنی هر نصب **سه جفت** ردیف دارد:
                 *   `default:minimal` · `default:minimal-dark`
                 *   `draft:minimal`  · `draft:minimal-dark`
                 *
                 * پس این ستون کلیدِ ترکیبی است، نه `theme_id`. این عمداً از
                 * راه دورترین حدسی که ممکن بود انتخاب شود.
                 */
                $table->string('key', 80);

                $table->unsignedBigInteger('theme_id');
                $table->unsignedBigInteger('preset_id');

                // `mode: system` در F4.1.W یعنی کاربر «پیش‌فرضِ خودِ قالب» را
                // انتخاب کرده. پس این nullable است و معنا دارد.
                $table->string('mode', 16)->default('light'); // light|dark|system

                /**
                 * override‌های sparse با کلید `role.scope` — همان ۱۰×۸=۸۰ خانه‌ای
                 * که F4.1.W گفت. JSON چون ستون‌بندی‌اش ۸۰ ستون و یک migration
                 * برای هر نقش تازه می‌خواست.
                 */
                $table->jsonb('overrides')->nullable();

                $table->timestamps();

                $table->unique(['user_id', 'key']);
                $table->foreign('theme_id')->references('id')->on('site_themes')->cascadeOnDelete();
                $table->foreign('preset_id')->references('id')->on('site_theme_presets')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // برعکسِ ترتیب، و با guard — چون `down` نباید چیزی را که
        // `2026_10_08` ساخته بشکند اگر کسی اشتباهی این را rollback کند.
        Schema::dropIfExists('site_theme_settings');
        Schema::dropIfExists('site_theme_presets');
        Schema::dropIfExists('site_themes');
    }
};
