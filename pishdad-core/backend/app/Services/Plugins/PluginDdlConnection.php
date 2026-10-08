<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * K5.12 — اجرای DDL افزونه با هویتِ نقش افزونه.
 *
 * ## چرا این کلاس وجود دارد و چرا `SET ROLE` کافی نیست
 *
 * spikeِ K5.7 و ردیف اصلی، هر دو از «نقش دوم» حرف می‌زدند و راه را `SET ROLE`
 * فرض کرده بودند. **روی PostgreSQL 18.6 آزمایش شد و بی‌اثر بود:**
 *
 * ```
 * SET LOCAL ROLE plugin_ddl;   -- is_super = f  ✓
 * SET ROLE cms;                -- موفق!  is_super = t  ✗
 * ```
 *
 * `pg_auth_members` خالی بود، پس عضویتی در کار نبود. علت: مجوزِ `SET ROLE` از
 * **`session_user`** می‌آید و `session_user` همچنان `cms` و superuser است.
 *
 * نتیجه دو چیز است. یک، افزونه می‌تواند خودش را دوباره superuser کند. دو، چون
 * نگهبان روی `current_user` گیت می‌کند، همان یک خط **کل دروازه را خاموش
 * می‌کند**. یعنی لایهٔ دفاعی‌ای که چنین طراحی‌ای ادعا می‌کند اصلاً وجود ندارد —
 * و بدتر، وجود دارد و آرام هم هست.
 *
 * راه درست **اتصال جداگانه** است. آن‌وقت `session_user = current_user =
 * pishdad_plugin_ddl` و `SET ROLE cms` با `permission denied to set role`
 * شکست می‌خورد (آزمایش شد).
 *
 * ## چرا اتصال پیش‌فرض عوض می‌شود و نه یک PDO کنار دست
 *
 * کدِ افزونه خودش `Schema::create()` و `DB::table()` می‌نواهد و هیچ چیز دربارهٔ
 * PDO ما نمی‌داند. اگر فقط یک PDO بسازیم و پاس بدهیم، آن `Schema::` همچنان روی
 * اتصال پیش‌فرض می‌رود یعنی `cms` — و نگهبان `current_user = cms` می‌بیند و
 * اصلاً وارد نمی‌شود. قاعده بی‌اثر ولی ظاهراً فعال.
 *
 * پس باید **همان چیزی** را عوض کنیم که آن کد می‌خواند: اتصال پیش‌فرض.
 *
 * ## چون پیش‌فرض سراسری است، بازگرداندنش حیاتی است
 *
 * یک exception در migration نباید پیش‌فرض را روی نقش افزونه جا بگذارد. آن‌وقت
 * درخواست بعدی — که کارِ عادی است — با امتیاز افزونه اجرا می‌شود. یعنی یک شکست
 * در نصب، سطح حمله را **باز** می‌گذارد؛ دقیقاً خلاف آنچه می‌خواهیم. پس `finally`
 * است و نه `catch`.
 *
 * ## این کلاس چه چیزی *نمی‌تواند* بکند
 *
 * جداسازی در سطح پایگاه‌داده است، نه در سطح زبان. کد افزونه در همان process
 * اجرا می‌شود و می‌تواند از `$app['db']` روی اتصال `cms` برسد و هر کاری بکند. پس
 * این یک **کاهش سطح حمله** است، نه مرز. تنها مرزِ واقعی، اجرای کد ناشناس در
 * process جداست (فاز ۱ انجام نشده).
 */
final class PluginDdlConnection
{
    /**
     * نامِ نقش. عمداً **ثابت در کد** است، نه از پیکربندی یا `env`.
     *
     * اگر از پیکربندی می‌آمد، هر کسی که به `.env` دسترسی داشته باشد می‌توانست
     * نگهبان را دور بزند — و بدتر، یک پیکربندی اشتباه بی‌صدا کل قاعده را خاموش
     * می‌کرد. ضمناً همین متن باید عیناً در تابع `pishdad_guard_plugin_ddl`
     * (که مالکش `cms` است و افزونه نمی‌تواند تغییرش دهد) باشد، پس یک جای
     * حقیقت داشتن ارزش دارد.
     */
    public const ROLE = 'pishdad_plugin_ddl';

    /** نامِ اتصال ثبت‌شده در `config/database.php`. */
    public const CONNECTION = 'plugin_ddl';

    /** @var list<string> اتصال‌هایی که حین اجرا معلق شده‌اند. */
    private array $suspended = [];

    /**
     * اجرای یک closure با پیش‌فرض روی نقش افزونه، و بازگرداندن قطعیِ پیش‌فرض.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(callable $work): mixed
    {
        $original = DB::getDefaultConnection();

        $this->suspend($original);

        try {
            DB::setDefaultConnection(self::CONNECTION);

            return $work();
        } finally {
            // بازگرداندن، حتی اگر خودِ بازگرداندن شکست بخورد: اول تلاش می‌کنیم
            // مقدار درست را بنویسیم، بعد اتصال معلق را پاک می‌کنیم.
            try {
                DB::setDefaultConnection($original);
            } finally {
                $this->resumeAll();
            }
        }
    }

    /**
     * آیا این نصب می‌تواند DDL افزونه را اجرا کند؟
     *
     * برای `PluginMigrator` لازم است: وقتی نقش یا رمز نباشد باید **رد** کند،
     * نه اینکه بی‌صدا جدول را بدون prefix بسازد.
     */
    public function isAvailable(): bool
    {
        // ⚠️ رمز از **همان جایی** خوانده می‌شود که اتصالِ واقعی می‌خواند.
        //
        // نسخهٔ اول `config('plugins.plugin_ddl_password')` می‌خواند و
        // `config/database.php` از `env()` — دو منبعِ متفاوت برای یک مقدار.
        // در تست که `config` را بازنویسی می‌کرد، این متد `true` برمی‌گرداند و
        // خودِ اتصال شکست می‌خورد. یعنی نگهبانی که باید fail-closed باشد، باز
        // بود: `isAvailable()` گفت «هست» و بعد `password authentication failed`.
        //
        // خواندن از `database.connections.plugin_ddl.password` تنها راهی است که
        // تضمین می‌کند این سؤال و آن اتصال **یک حقیقت** را می‌بینند.
        $password = config('database.connections.plugin_ddl.password');

        if (! is_string($password) || $password === '') {
            return false;
        }

        try {
            $pdo = new PDO(
                $this->dsn(),
                self::ROLE,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $pdo = null;

            return true;
        } catch (Throwable $e) {
            // پیامِ PDO می‌تواند شامل رشتهٔ اتصال باشد؛ فقط در لاگ.
            Log::warning('plugin.ddl_unavailable', [
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * قطعِ اتصال افزونه تا درخواست بعدی تازه authenticate کند.
     *
     * جدا از `disconnect()` صدا زده می‌شود چون حتی در مسیر موفق، بعد از
     * `setDefaultConnection` برگشت، اتصال باز می‌ماند و تا پایان درخواست
     * زنده است.
     */
    public function disconnect(): void
    {
        try {
            DB::purge(self::CONNECTION);
        } catch (Throwable) {
            // قطع کردن نباید خطای دوم بسازد.
        }
    }

    /**
     * معلق کردن یک اتصال.
     *
     * چرا لازم است: `DB::setDefaultConnection()` تنها یک رشته را عوض می‌کند، ولی
     * **resolver** را که قبلاً ساخته شده نمی‌دهد. یعنی کدی که پیش از این،
     * `Schema::` را روی پیش‌فرض گرفته، هنوز همان اتصال را می‌بیند. پس باید
     * اتصالِ قبلی را از حافظه پاک کنیم تا از نو ساخته شود — با نامی که دیگر
     * پیش‌فرض نیست.
     */
    private function suspend(string $connection): void
    {
        $this->suspended[] = $connection;

        DB::purge($connection);
    }

    /** @return list<string> */
    private function takeSuspended(): array
    {
        $out = $this->suspended;
        $this->suspended = [];

        return $out;
    }

    private function resumeAll(): void
    {
        foreach ($this->takeSuspended() as $connection) {
            try {
                DB::purge($connection);
            } catch (Throwable) {
                // اگر purge شکست بخورد، اتصال از پاک درنمی‌آید و درخواست بعدی
                // با همان نشست زنده ادامه می‌دهد. این باید **دیده** شود.
                Log::error('plugin.ddl_resume_failed', [
                    'connection' => $connection,
                ]);
            }
        }

        $this->disconnect();
    }

    private function dsn(): string
    {
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            (string) config('database.connections.pgsql.host', '127.0.0.1'),
            (int) config('database.connections.pgsql.port', 5432),
            (string) config('database.connections.pgsql.database', ''),
        );
    }

    /** برای تست‌ها: نامِ اتصالی که باید رویش بود. */
    public function connectionName(): string
    {
        return self::CONNECTION;
    }

    /**
     * نامِ اتصالی که باید روی **هسته** بماند وقتی DDL افزونه در حال اجراست.
     *
     * تاریخِ migration و رجیستری باید با `cms` نوشته شوند، نه با نقش افزونه.
     * اگر کدی وسطِ اجرای افزونه بخواهد تاریخ بنویسد، این متد می‌گوید کجا.
     */
    public function coreConnection(): string
    {
        return DB::getDefaultConnection() === self::CONNECTION
            ? 'pgsql'
            : DB::getDefaultConnection();
    }

    /**
     * رمزِ نقش، از **همان** منبعی که اتصال می‌خواند.
     *
     * یک منبعِ حقیقت. هر جای دیگری که رمز را جدا بخواند، یا به `null` می‌خورد
     * (اگر `config()` باشد و ترتیبِ بارگذاری نادرست باشد) یا در تست با رمزِ
     * اشتباه وصل می‌شود.
     *
     * @throws RuntimeException اگر رمز تنظیم نشده باشد
     */
    public function requirePassword(): string
    {
        $password = config('database.connections.plugin_ddl.password');

        if (! is_string($password) || $password === '') {
            throw new RuntimeException(
                'PLUGIN_DDL_PASSWORD تنظیم نشده. بدون آن migration افزونه اجرا نمی‌شود —'
                .' عمداً رد می‌شود، چون اجرای بی‌نگهبان از رد کردن بدتر است.'
            );
        }

        return $password;
    }
}
