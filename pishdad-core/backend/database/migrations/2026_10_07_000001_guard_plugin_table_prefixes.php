<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * K5.12 — مرجع حقیقتِ نام‌های جدول مجاز: رجیستری + نگهبان DDL.
 *
 * spikeِ K5.7 ثابت کرد قاعدهٔ `{slug}_*` با `EVENT TRIGGER` قابل enforce است و سه
 * شکاف گذاشت. این مهاجرت هر سه را می‌بندد، و یک شکاف چهارم را هم که spike اصلاً
 * آزمایش نکرده بود.
 *
 * ## ⚠️ `SET LOCAL ROLE` جداسازی نمی‌دهد — این یافتهٔ تعیین‌کننده است
 *
 * طراحی اولیه «نقش دوم + `SET LOCAL ROLE`» بود. روی PostgreSQL 18.6 آزمایش شد و
 * **بی‌اثر** بود:
 *
 * ```
 * SET LOCAL ROLE plugin_ddl;   -- current_user = plugin_ddl, is_super = f
 * SET ROLE cms;                -- موفق! current_user = cms, is_super = t
 * ```
 *
 * `pg_auth_members` خالی بود — هیچ عضویتی وجود نداشت. علت این است که مجوزِ
 * `SET ROLE` از **`session_user`** می‌آید که همچنان superuser است. یعنی یک خط
 * کد در افزونه کافی است تا خودش را دوباره superuser کند.
 *
 * و چون نگهبان روی `current_user` گیت می‌کند، همان یک خط **کل دروازه را از کار
 * می‌اندازد**. امنیتی که چنین طراحی‌ای ادعا می‌کند واقعاً وجود ندارد.
 *
 * پس راه درست، **اتصال جدا با نقش `LOGIN` واقعی و رمز** است. آن‌وقت
 * `session_user = current_user` است و `SET ROLE cms` با
 * `permission denied to set role` شکست می‌خورد. `PluginDdlConnection` همین را
 * می‌سازد.
 *
 * ## چرا ACL به‌تنهایی کافی است و trigger فقط یک کار دارد
 *
 * شکاف سوم spike (`DROP`/`TRUNCATE`) با آزمایش بسته شد، ولی **نه با trigger**:
 *
 * ```
 * drop table users        → must be owner
 * truncate table users    → permission denied for table users
 * create index on users   → must be owner
 * alter table users …     → must be owner
 * ```
 *
 * چون نقش تازه در PostgreSQL با **صفر امتیاز** شروع می‌شود و مالک جدول‌های هسته
 * نیست، مالکیت خودِ PostgreSQL همه‌چیز را می‌بندد. ACL عملاً همان «توزیع policy
 * بیرون از دیتابیس» است که spike آن را شکاف بزرگ می‌دانست.
 *
 * پس **دامنهٔ trigger عمداً کوچک است**: فقط `CREATE TABLE` و `ALTER TABLE`، فقط
 * برای نقش افزونه، فقط وقتی نامِ تازه در رجیستری نباشد. `DROP` و `TRUNCATE` عمداً
 * پوشش داده نشده‌اند چون ACL آن‌ها را می‌بندد و هر شرطِ اضافه فقط سطح حمله را
 * بزرگ‌تر می‌کند.
 *
 * ## چرا `ALTER TABLE` در فهرست تگ‌هاست
 *
 * شکاف دوم spike (`RENAME TO`) دقیقاً همین بود. spike فقط `CREATE TABLE` را گذاشته
 * بود و نتیجه گرفت می‌شود جدول مجاز را ساخت و بعد به نام غیرمجاز تغییر نام داد.
 * با افزودن `ALTER TABLE`، نامِ **تازه** در `object_identity` می‌آید و رد می‌شود
 * (سنجیده شد: `denied r2`).
 *
 * ## دو تلهٔ که spike کشف کرد و اینجا رعایت شده
 *
 *  ۱. `ddl_command_end` رکورد `NEW` ندارد؛ باید از
 *     `pg_event_trigger_ddl_commands()` خواند.
 *  ۲. `object_identity` شامل نامِ شِما است (`public.evil` نه `evil`). اگر مستقیم
 *     مقایسه شود **همه‌چیز** رد می‌شود حتی وقتی باید بگذرد. `split_part(…, 2)`
 *      آن را درست می‌کند.
 *
 * ## چرا رجیستری و نه session variable
 *
 * spike از `current_setting('guard.allowed_prefix')` استفاده کرد و درست گفت که
 * هر کسی می‌تواند آن را `SET` کند. رجیستری این را می‌بندد: افزونه روی رجیستری
 * فقط `SELECT` دارد، پس نه می‌تواند دامنه‌اش را گسترش دهد و نه می‌تواند تابع
 * نگهبان را عوض کند (هر دو آزمایش شد: `permission denied` و `must be owner`).
 * مرجع حقیقت فقط از راه هسته پر می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ۱) رجیستری ────────────────────────────────────────────────────
        //
        // یک ردیف به‌ازای هر **نام نهایی** مجاز. عمداً `slug` جدا نگه داشته شده تا
        // بشود گزارش گرفت «کدام افزونه چه جدولی دارد» بدون join.
        if (! Schema::hasTable('plugin_table_prefixes')) {
            Schema::create('plugin_table_prefixes', function ($table) {
                $table->id();
                $table->string('slug', 64);
                $table->string('table_name', 64);
                $table->timestamps();

                $table->unique(['slug', 'table_name']);
                $table->index('table_name');
            });
        }

        // ── ۲) تابع نگهبان ────────────────────────────────────────────────
        //
        // نامِ نقش **متن ثابت** است و از `current_setting` نمی‌آید.
        //
        // نسخهٔ اول از یک GUC سفارشی می‌خواند (`pishdad.plugin_ddl_role`) و این
        // دقیقاً همان اشتباهی بود که spike با `allowed_prefix` مرتکب شده و ماهی
        // زیر بارش گرفته: یک GUC، تنظیم session است و هر کسی می‌تواند `SET`ش کند.
        // یعنی یک خط `SET pishdad.plugin_ddl_role = ''` کل دروازه را خاموش
        // می‌کرد — و چون تابع با امتیاز نقش افزونه اجرا می‌شود، همان بازی که
        // با `SET ROLE` می‌شد، از این راه هم می‌شد.
        //
        // متنِ ثابت امن است چون تابع مالکِ `cms` است و افزونه نمی‌تواند آن را
        // عوض کند (`must be owner of function` — آزمایش شد).
        //
        // `SECURITY DEFINER` عمداً استفاده نشده: تابع باید با امتیاز نقش افزونه
        // اجرا شود تا نتواند رجیستری را دور بزند.
        //
        // ── چرا `object_type` تفکیک می‌شود ─────────────────────────────
        //
        // نسخهٔ دوم هر شیئی را که می‌دید می‌سنجید و همین‌جا اولین باگش را لو داد:
        //
        // ```
        // create table acme_notes (id serial primary key)
        // → ERROR: اجازهٔ ساخت «acme_notes_id_seq» را ندارد
        // ```
        //
        // `serial` علاوه بر جدول، یک **sequence** و یک **index** هم می‌سازد و
        // PostgreSQL هر دو را از همین event trigger گزارش می‌کند. یعنی نگهبانِ
        // سخت‌گیر، جدولی را که **دقیقاً مجاز** بود رد می‌کرد — و این فقط در
        // دیتابیسِ واقعی معلوم شد، نه در بازبینی کد.
        //
        // پس تفکیک شد:
        //  · **جدول** — باید **دقیقاً** ثبت‌شده باشد. این مرزِ مالکیتِ داده است.
        //  · **چیزهای کمکی** (sequence، index، constraint) — کافی است نامشان از یک
        //    جدولِ ثبت‌شده **مشتق** شود. اینها داده‌ای از هسته را لمس نمی‌کنند و
        //    نامشان را PostgreSQL تعیین می‌کند، نه افزونه.
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION pishdad_guard_plugin_ddl() RETURNS event_trigger
        LANGUAGE plpgsql AS $$
        DECLARE
            obj      text;
            bare     text;
            otype    text;
            allowed  text[];
        BEGIN
            -- فقط نقش افزونه محدود است. هسته روی `cms` است و باید آزاد بماند،
            -- وگرنه مهاجرت‌های خودِ اپ رد می‌شدند.
            IF current_user <> 'pishdad_plugin_ddl' THEN
                RETURN;
            END IF;

            SELECT COALESCE(array_agg(table_name), '{}') INTO allowed
              FROM plugin_table_prefixes;

            -- رجیستری خالی یعنی «هیچ چیز مجاز نیست»، نه «هر چیز مجاز است».
            IF allowed = '{}' THEN
                RAISE EXCEPTION
                    'افزونه هیچ جدول ثبت‌شده‌ای ندارد، پس هیچ DDL‌ای مجاز نیست.'
                    USING ERRCODE = 'insufficient_privilege';
            END IF;

            FOR obj, otype IN
                SELECT object_identity, object_type
                  FROM pg_event_trigger_ddl_commands()
            LOOP
                -- `object_identity` نامِ شِما را هم دارد. بدون این split، هر
                -- نامی رد می‌شود حتی وقتی باید بگذرد.
                bare := split_part(obj, '.', 2);

                IF otype = 'table' THEN
                    IF NOT (bare = ANY(allowed)) THEN
                        RAISE EXCEPTION
                            'افزونه اجازهٔ ساخت جدول «%» را ندارد. تنها نام‌های ثبت‌شده مجازند.', bare
                            USING ERRCODE = 'insufficient_privilege';
                    END IF;
                ELSIF EXISTS (
                    -- مشتق از یک جدول ثبت‌شده؟ `acme_notes_id_seq` و
                    -- `acme_notes_pkey` هر دو از `acme_notes` مشتق‌اند.
                    SELECT 1 FROM unnest(allowed) AS t
                     WHERE bare = t OR bare LIKE t || '\_%'
                ) THEN
                    NULL;
                ELSE
                    RAISE EXCEPTION
                        'افزونه اجازهٔ ساخت «%» (%s) را ندارد.', bare, otype
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
            END LOOP;
        END $$;
        SQL);

        // ── ۳) نگهبان ─────────────────────────────────────────────────────
        //
        // `ALTER TABLE` برای `RENAME TO` لازم است. `DROP`/`TRUNCATE` عمداً نیست.
        //
        // ⚠️ `CREATE EVENT TRIGGER` فقط superuser می‌خواهد. نصب‌کنندهٔ وب
        // عمداً با کاربرِ عادی (مالکِ دیتابیس، نه superuser) کار می‌کند —
        // روی هاست اشتراکی/ابری معمولاً superuser اصلاً داده نمی‌شود. پس
        // تریگر شرطی است: روی محیطِ محدود، رجیستری و تابع ساخته می‌شوند ولی
        // enforce در سطح trigger رد می‌شود و هشدارش در لاگ می‌ماند (E18).
        // ACL خودِ PostgreSQL (مالکیت جدول‌ها) همچنان افزونه را محدود نگه
        // می‌دارد — همان‌طور که بخش «چرا ACL به‌تنهایی کافی است» توضیح داد.
        if ($this->installingRoleIsSuperuser()) {
            DB::unprepared('DROP EVENT TRIGGER IF EXISTS pishdad_guard_plugin_ddl_trg');
            DB::unprepared(<<<'SQL'
        CREATE EVENT TRIGGER pishdad_guard_plugin_ddl_trg
            ON ddl_command_end
            WHEN TAG IN ('CREATE TABLE', 'CREATE TABLE AS', 'ALTER TABLE')
            EXECUTE FUNCTION pishdad_guard_plugin_ddl();
        SQL);
        } else {
            logger()->warning('[K5.12] ساخت EVENT TRIGGER رد شد: نقشِ نصب‌کننده superuser نیست؛ enforce نگهبان DDL غیرفعال ماند.');
        }
    }

    /**
     * آیا نقشی که مهاجرت‌ها را اجرا می‌کند superuser است؟
     */
    private function installingRoleIsSuperuser(): bool
    {
        $row = DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = current_user');

        return $row !== null && (bool) $row->rolsuper;
    }

    public function down(): void
    {
        // حذفِ تریگر هم امتیاز superuser می‌خواهد؛ روی محیطِ محدود که تریگر
        // هرگز ساخته نشده، تلاش برای حذف خطای مجوز می‌داد.
        if ($this->installingRoleIsSuperuser()) {
            DB::unprepared('DROP EVENT TRIGGER IF EXISTS pishdad_guard_plugin_ddl_trg');
        }
        DB::unprepared('DROP FUNCTION IF EXISTS pishdad_guard_plugin_ddl()');

        Schema::dropIfExists('plugin_table_prefixes');
    }
};
