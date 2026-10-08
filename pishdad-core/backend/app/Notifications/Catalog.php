<?php

namespace App\Notifications;

use App\Services\Outbox\Outbox;
use App\Validation\SafeUrl;

/**
 * F4.2.B — منبعِ حقیقتِ اعلان‌های پنل.
 *
 * ## ⭐ چرا کاتالوگ و نه رشتهٔ آزاد
 *
 * هر اعلانِ پنل سه چیز دارد: عنوان، بدنه، و یک لینکِ عملیات. اگر هر سه از
 * فراخواننده بیایند، آن فراخواننده **می‌نویسد چه چیزی به کاربر گفته شود** — و
 * اگر ورودی‌اش HTML باشد، XSS در drawer است؛ اگر لینکش بیرونی باشد، phishing.
 * پس عنوان و بدنه از اینجا می‌آیند (فقط **جای‌گذاری**، نه قالبِ آزاد) و لینک
 * یک **ثابتِ همین فایل** است.
 *
 * ## ⭐ چرا بدنه هرگز HTML نیست
 *
 * نه `e()` لازم است، نه sanitizing: HTML **اصلاً تولید نمی‌شود**. قالب‌های این
 * فایل `%s` دارند و هیچ HTML ندارند. اگر روزی HTML لازم شد، یک قالبِ دیگر و یک
 * مسیرِ رندرِ جدا لازم است — چون «بعداً پاکش می‌کنیم» یعنی یک stored-XSS که
 * از روز اول قابلِ بهره‌برداری است.
 *
 * ## گروه‌ها
 *
 * `F4.2.W` بعد از بررسی ۲۷ تریگر، ۲۰ تا را فعال نگه داشت و ۷ تا را حذف کرد
 * («کنندهٔ رویداد = بینندهٔ اعلان» ⇒ نویز خالص). `F4.2.U` این‌ها را به **۵ کارت**
 * فرو کاهش داد، چون کاربر با ترجیح تصمیم می‌گیرد نه با ۲۰ گزینه. هر کلیدِ
 * اینجا دقیقاً یکی از آن ۵ گروه است.
 */
final class Catalog
{
    /**
     * چهار گروه = چهار کارتِ صفحهٔ ترجیحات.
     */
    public const GROUPS = [
        'security' => 'امنیت و ورود',
        'plugins' => 'افزونه‌ها و قالب‌ها',
        'content' => 'محتوا و صفحه‌ها',
        'system' => 'سامانه و نگه‌داری',
    ];

    /**
     * سه کانال (`F4.2.U`: ۵ کارت × ۳ کانال).
     *
     * `database` اینجا نیست چون از outbox رد نمی‌شود: `DatabaseChannel` خودش
     * سطرِ `notifications` است و «خاموش‌کردنش» یعنی نگفتنِ قالب، نه تحویل.
     */
    public const CHANNELS = ['email', 'sms', 'push', 'telegram'];

    /** حداکثر طول بدنه — `notifications.data` و کارتِ drawer هر دو این را می‌بینند. */
    public const MAX_BODY = 400;

    public const MAX_TITLE = 120;

    /**
     * ⭐⭐ ۸ شرطِ allowlistِ `action_href`.
     *
     * هر هشت باید بگذرد. این‌ها شرطِ «در کاتالوگ بودن» نیستند (آن یک شرطِ
     * جدا و صریح است) — شرطِ «بی‌خطر بودنِ همان رشته» هستند، چون روزی کسی یک
     * مسیرِ تازه به کاتالوگ اضافه می‌کند و باید بداند چه چیزی مجاز است.
     *
     *  ۱. **در کاتالوگ بودن** — رشته از فراخواننده نمی‌آید؛ فقط ثابتِ همین فایل.
     *     بدون این، ۷ شرط بعدی هم روی ورودیِ مهاجم اجرا می‌شوند که بی‌معناست.
     *  ۲. **فقط مسیر داخلی** — باید با `/` شروع شود و `/` بعدش نباشد. بدون
     *     lookahead، `//evil.com` (protocol-relative) و `/\evil.com` هر دو عبور
     *     می‌کنند و مرورگر دومی را به `https://evil.com` تبدیل می‌کند. (همان
     *     دامی که `F0-B1` در `LinkItems` بست.)
     *  ۳. **بدون scheme** — `https:`، `http:`، `mailto:`، `tel:`، `data:`،
     *     `javascript:`، `vbscript:`، `file:` رد. لینکِ بیرونی در اعلان، یعنی
     *     دکمه‌ای که کاربر را از پنل می‌برد بیرون با هویتِ همان نشست.
     *  ۴. **بدون query و بدون fragment** — `?` و `#` رد. `?next=` موتورِ
     *     open-redirect است و `#` می‌تواند لنگرِ مربوط به کنترل‌های پنهان باشد.
     *     ضمناً هر دو **ردیابی** هستند: کاتالوگ نباید بتواند اعلان را به یک
     *     پرامپترِ کمپینِ خاص بچسباند.
     *  ۳. **بدون نویسهٔ کنترلی** — `\t`، `\0`، `\r\n` رد، و رشتهٔ **همان‌طور**
     *     آزموده می‌شود نه نسخهٔ پاک‌شده. بدون این، `java\tscript:` زنده می‌ماند
     *     چون `\t` داخل رشته است و الگوی پروتکل را دور می‌زند.
     *  ۶. **طول محدود** — حداکثر ۲۰۰ نویسه. یک URL خیلی بلند در جدولِ
     *     `notifications` (اندیس‌دار) فضا را بی‌دلیل می‌بلعد و در drawer هم جا
     *     نمی‌شود.
     *  ۷. **بدون `..`** — هیچ قطعهٔ بالا-رفتنی. `/admin/../../etc` در مرورگر
     *     به `/etc` می‌رسد و از فضای نامِ پنل بیرون می‌رود.
     *  ۸. **سازگار با `SafeUrl`** — `SafeUrl::isAllowedHref()` (`F0.2`).
     *
     *     چرا این یکی؟ چون پروژه **یک** تعریفِ allowlist دارد و `LinkItems`،
     *     ویجت‌ها و `BlockRenderer` همه به آن واگذار می‌کنند. اگر اینجا
     *     نسخهٔ چهارم را بنویسیم، یک روزی این دو ناهمگون می‌شوند — و همان چیزی
     *     می‌شود که `F0.2` برای حلش ساخته شد: یک allowlist سست در جای اشتباه
     *     که **هیچ‌کس بازبینی نمی‌کند** چون ظاهرش مثل بقیه است.
     */
    public const ACTION_HREF_MAX = 200;

    /**
     * ⭐ کاتالوگ.
     *
     * ساختار هر ورودی:
     *  - `group`     یکی از `GROUPS` — کارتِ ترجیح و گروهِ گزارش.
     *  - `title`     عنوان، با `%s` برای جای‌گذاریِ دادهٔ رویداد.
     *  - `body`      بدنه، متنِ ساده. هرگز HTML.
     *  - `action`    ثابت: `['label' => …, 'href' => …]`. **فقط** وقتی رویداد
     *                کاری برای انجام‌دادن دارد.
     *  - `channels`  کانال‌هایی که این اعلان به‌صورت پیش‌فرض رویشان فعال است.
     *                `database` همیشه فعال است و اینجا نیست.
     */
    private const ENTRIES = [

        // ── security ──────────────────────────────────────────────────
        'security.login_new_device' => [
            'group' => 'security',
            'title' => 'ورود از دستگاه تازه',
            'body' => 'از دستگاهی وارد شدید که قبلاً از آن وارد نشده بودید: %s. اگر شما نبودید، رمز را عوض کنید.',
            'action' => ['label' => 'نشست‌ها', 'href' => '/admin/profile/sessions'],
            'channels' => ['email', 'sms'],
        ],
        'security.password_changed' => [
            'group' => 'security',
            'title' => 'رمز عبور عوض شد',
            'body' => 'رمز عبور حساب شما تغییر کرد. اگر شما این کار را نکردید، فوراً با پشتیبانی تماس بگیرید.',
            'action' => ['label' => 'امنیت', 'href' => '/admin/profile'],
            'channels' => ['email'],
        ],
        'security.2fa_disabled' => [
            'group' => 'security',
            'title' => 'ورود دومرحله‌ای خاموش شد',
            'body' => 'احراز هویت دومرحله‌ای حساب شما غیرفعال شد. با فعال‌کردنش، حتی با رمزِ لو رفته هم کسی وارد نمی‌شود.',
            'action' => ['label' => 'فعال‌سازی', 'href' => '/admin/profile'],
            'channels' => ['email', 'sms'],
        ],

        // ── resources ─────────────────────────────────────────────────
        // مصرفِ فضا تنها منبعِ محدودیتی است که در این نصب وجود دارد، پس
        // هشدارش می‌ماند — ولی مقصدش مدیریتِ فایل است، نه پرداخت.
        'resources.quota_warning' => [
            'group' => 'system',
            'title' => 'نزدیکِ سقفِ فضای فایل',
            'body' => 'مصرف فضای فایل شما به %s رسیده که نزدیکِ سقفِ این نصب است. پاک‌سازیِ فایل‌های بلااستفاده پیشنهاد می‌شود.',
            'action' => ['label' => 'مدیریت فایل', 'href' => '/admin/media'],
            'channels' => ['email'],
        ],

        // ── plugins ───────────────────────────────────────────────────
        'plugins.enabled' => [
            'group' => 'plugins',
            'title' => 'افزونه فعال شد',
            'body' => 'افزونهٔ «%s» فعال شد و امضای آن معتبر است.',
            'channels' => ['email'],
        ],
        'plugins.disabled' => [
            'group' => 'plugins',
            'title' => 'افزونه غیرفعال شد',
            'body' => 'افزونهٔ «%s» غیرفعال شد. امکانات آن تا فعال‌سازی دوباره در دسترس نیست.',
            'action' => ['label' => 'افزونه‌ها', 'href' => '/admin/plugins'],
            'channels' => ['email'],
        ],
        'plugins.updated' => [
            'group' => 'plugins',
            'title' => 'افزونه به‌روزرسانی شد',
            'body' => 'افزونهٔ «%s» از نسخهٔ %s به %s رفت.',
            'action' => ['label' => 'افزونه‌ها', 'href' => '/admin/plugins'],
            'channels' => ['email'],
        ],
        'plugins.review_pending' => [
            'group' => 'plugins',
            'title' => 'افزونه در انتظار تأیید',
            'body' => 'افزونهٔ «%s» بارگذاری شد و در انتظار تأیید است. تا تأیید نشود، قابلِ فعال‌سازی نیست.',
            'action' => ['label' => 'افزونه‌ها', 'href' => '/admin/plugins'],
            'channels' => ['email'],
        ],
        'plugins.removed' => [
            'group' => 'plugins',
            'title' => 'افزونه حذف شد',
            'body' => 'افزونهٔ «%s» حذف شد. داده‌هایش دست‌نخورده ماند اما امکاناتش در دسترس نیست.',
            'channels' => ['email'],
        ],
        'theme.changed' => [
            'group' => 'plugins',
            'title' => 'قالب سایت عوض شد',
            'body' => 'قالب سایت به «%s» تغییر کرد. اگر شما نبودید، رمز را عوض کنید.',
            'action' => ['label' => 'قالب', 'href' => '/admin/themes'],
            'channels' => ['email'],
        ],

        // ── content ───────────────────────────────────────────────────
        'page.published' => [
            'group' => 'content',
            'title' => 'صفحه منتشر شد',
            'body' => 'صفحهٔ «%s» منتشر شد و از این پس برای همه قابلِ دسترسی است.',
            'action' => ['label' => 'صفحات', 'href' => '/admin/pages'],
            'channels' => ['email'],
        ],
        'page.unpublished' => [
            'group' => 'content',
            'title' => 'صفحه از انتشار خارج شد',
            'body' => 'صفحهٔ «%s» دیگر عمومی نیست.',
            'action' => ['label' => 'صفحات', 'href' => '/admin/pages'],
            'channels' => ['email'],
        ],
        'ticket.replied' => [
            'group' => 'content',
            'title' => 'پاسخِ تیکت رسید',
            'body' => 'تیکت «%s» پاسخ داده شد: %s',
            'action' => ['label' => 'تیکت‌ها', 'href' => '/admin/tickets'],
            'channels' => ['email', 'sms'],
        ],
        /**
         * E75 — «پیام تازه از فرم تماس» با تلگرامِ فوری.
         *
         * تا پیش از این، پیامِ فرم تماس فقط سطرِ دیتابیس + ایمیلِ صف‌شده
         * می‌ساخت و تلگرام فقط در خلاصهٔ روزانه (۰۸:۰۰، با cronِ جدا) می‌رفت —
         * یعنی عملاً هیچ‌وقت (cron روی نصب‌ها نیست). کاربر انتظار دارد پیام
         * همان لحظه برسد، پس این کلید فقط تلگرام دارد: ایمیل از همان مسیرِ
         * قبلی (`Outbox::email` در کنترلر/سرویسِ فرم) می‌رود و آوردنش اینجا
         * یعنی دو ایمیل برای یک پیام.
         *
         * E77 — متن دقیقاً به فرمِ خواستهٔ کاربر است (سربرگ + نام/ایمیل/
         * تلفن/متن). ترتیبِ values در هر دو محلِ دیسپچ باید همین باشد:
         * [نام، ایمیل، تلفن، متن]. تلفنِ ناموجود `—` می‌شود، نه حذفِ سطر —
         * چون render موقعیتی (`%s`) است و کم‌وزیاد شدنِ آرگومان متن را جابه‌جا
         * می‌کند.
         *
         * ⚠️ فقط تک‌خطِ جدید (`\n` تکی): `Catalog::render()` در انتها از
         * `plain()` رد می‌شود و آن `\s{2,}` را جمع می‌کند — یعنی خطِ خالی
         * (`\n\n`) به فاصله تبدیل می‌شود. پس قالب با `\n` تکی نوشته شده تا
         * در تلگرام (و صندوق) سطر‌به‌سطر بماند.
         */
        'ticket.created' => [
            'group' => 'content',
            'title' => 'پیام جدید از فرم تماس',
            'body' => "از طریق وب سایت فرم تماس ارسال شده است.\nنام فرستنده: %s\nایمیل فرستنده: %s\nشماره تماس فرستنده: %s\nمتن پیام: %s",
            'action' => ['label' => 'تیکت‌ها', 'href' => '/admin/tickets'],
            'channels' => ['telegram'],
        ],

        // ── system ────────────────────────────────────────────────────
        'system.quota_exceeded' => [
            'group' => 'system',
            'title' => 'سقفِ منابع تمام شد',
            'body' => 'یکی از منابع نصب از سقف گذشته است: %s. تا رفعش، رشد سایت متوقف می‌شود.',
            'action' => ['label' => 'مدیریت فایل', 'href' => '/admin/media'],
            'channels' => ['email', 'telegram'],
        ],
        'system.maintenance' => [
            'group' => 'system',
            'title' => 'نگه‌داری برنامه‌ریزی شد',
            'body' => 'نگه‌داری برنامه‌ریزی‌شده از %s تا %s انجام می‌شود و سایت در این بازه در دسترس نیست.',
            'channels' => ['email', 'sms', 'telegram'],
        ],
    ];

    /**
     * @return list<string> کلیدهای ۲۰تاییِ فعال
     */
    public static function keys(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function has(string $key): bool
    {
        return isset(self::ENTRIES[$key]);
    }

    /** @return array{key: string, group: string, title: string, body: string, action: array{label: string, href: string}|null, channels: list<string>} */
    public static function entry(string $key): array
    {
        if (! isset(self::ENTRIES[$key])) {
            throw new \InvalidArgumentException("کلیدِ اعلان در کاتالوگ نیست: {$key}");
        }

        $e = self::ENTRIES[$key];

        /**
         * ⭐ لینک در لحظهٔ **خواندنِ کاتالوگ** ساخته و آزموده می‌شود، نه
         * ذخیره. یعنی یک مسیرِ ناامن در این فایل، سطرِ ناامن در DB می‌سازد —
         * و آن‌وقت هر رندرِ آینده باید دوباره شک کند.
         */
        $action = null;
        if (isset($e['action'])) {
            $action = self::assertAction($key, $e['action']);
        }

        return [
            'key' => $key,
            'group' => $e['group'],
            'title' => $e['title'],
            'body' => $e['body'],
            'action' => $action,
            'channels' => $e['channels'],
        ];
    }

    /**
     * ⭐ تنها گذرگاهِ `action_href` — و ۸ شرطِ بالا اینجاست.
     *
     * @param  array{label: string, href: string}  $action
     * @return array{label: string, href: string}
     *
     * @throws \InvalidArgumentException — کاتالوگ خودش خطا دارد، نه ورودی کاربر
     */
    public static function assertAction(string $key, array $action): array
    {
        $href = (string) $action['href'];

        if (! self::isSafeActionHref($href)) {
            throw new \InvalidArgumentException("لینکِ اعلانِ «{$key}» از allowlist رد شد: {$href}");
        }

        return [
            'label' => mb_substr((string) $action['label'], 0, 40),
            'href' => $href,
        ];
    }

    /**
     * ⭐ ۷ شرطِ باقی‌مانده. شرطِ هشتم (در کاتالوگ بودن) را `assertAction()` روی
     * ورودیِ خودش دارد — این متد رشتهٔ خام را می‌آزماید.
     */
    public static function isSafeActionHref(string $href): bool
    {
        // ۶) طول
        if ($href === '' || mb_strlen($href) > self::ACTION_HREF_MAX) {
            return false;
        }

        // ۵) نویسهٔ کنترلی. ⚠️ رشتهٔ **خام** آزموده می‌شود، نه نسخهٔ پاک‌شده —
        //    همان دلیلی که `SafeUrl` هم پاک‌سازی را فقط برای probe می‌کند.
        if (preg_match('/[\x00-\x1f\x7f]/', $href) === 1) {
            return false;
        }

        // ۲) فقط مسیر داخلی، و `/` بعد از `/` نباشد.
        if (! str_starts_with($href, '/') || str_starts_with($href, '//') || str_starts_with($href, '/\\')) {
            return false;
        }

        // ۳) بدون scheme و ۴) بدون query/fragment. یک بررسیِ کافی است چون هر
        //    چهار با هم یعنی «بعد از اسلشِ اول فقط حروف، رقم، `-`، `_`، `.` و
        //    `/`» — و `..` را شرطِ ۷ می‌گیرد.
        if (preg_match('#^/[a-z0-9\-_./]*$#i', $href) !== 1) {
            return false;
        }

        // ۷) بدون قطعهٔ بالا-رفتنی. `...` مجاز است ولی `..` به‌عنوان قطعه نه:
        //     `/admin/..` و `/admin/../..` رد، `/admin/a..b` قبول.
        foreach (explode('/', $href) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        // ۸) هم‌سنجی با تعریفِ واحدِ پروژه (`F0.2`).
        return SafeUrl::isAllowedHref($href);
    }

    // ------------------------------------------------------------------
    // رندرِ متن
    // ------------------------------------------------------------------

    /**
     * ⭐ جای‌گذاری، **نه** `sprintf` روی ورودیِ کاربر.
     *
     * `$values` فقط **مقدار** می‌گیرد، نه قالب. یعنی `%s` در دادهٔ رویداد
     * نمی‌تواند چیزی بسازد و `%1$s` هم نمی‌تواند به قالبِ اصلی اشاره کند.
     * به همین دلیل قالبِ نهایی دوباره `%` ندارد و اسکن نمی‌شود.
     *
     * @param  array<int, scalar|null>  $values
     */
    public static function render(string $template, array $values): string
    {
        $i = 0;

        $out = preg_replace_callback(
            '/%s/',
            function () use (&$i, $values): string {
                $value = $values[$i] ?? null;
                $i++;

                return is_scalar($value) ? (string) $value : '';
            },
            $template,
        ) ?? $template;

        return self::plain($out);
    }

    /**
     * ⭐ تنها جایی که خروجیِ متن ساخته می‌شود، و **همیشه** اجرا می‌شود.
     *
     * کارها، به ترتیبِ اهمیت:
     *  ۱) هر برچسبِ HTML حذف می‌شود. نه escape — حذف. چون escape در متنِ
     *     نمایشی، `&lt;b&gt;` را به کاربر نشان می‌دهد.
     *  ۲) فاصله‌های کنترلی (شامل `&nbsp;`) به فاصلهٔ ساده. `U+00A0` در RTL
     *     می‌شکند و کارتِ drawer به‌هم می‌ریزد.
     *  ۳) فاصله‌های تکراری فشرده و برش به `MAX_BODY`.
     *
     * سه فیلترِ لازم‌اند چون ورودیِ `render()` دادهٔ رویدادِ واقعی است (نامِ
     * فایل، نامِ افزونه، پاسخِ تیکت) و هیچ‌کدام HTML نیستند ولی هیچ‌کس هم
     * تضمین نکرده که نباشند.
     */
    public static function plain(string $text): string
    {
        $out = strip_tags($text);
        $out = preg_replace('/[\p{Z}\x{00A0}]+/u', ' ', $out) ?? $out;
        $out = preg_replace('/\s{2,}/', ' ', $out) ?? $out;
        $out = trim($out);

        return mb_substr($out, 0, self::MAX_BODY);
    }

    /** @return array<string, string> نامِ گروه‌ها برای UI */
    public static function groupLabels(): array
    {
        return self::GROUPS;
    }

    /**
     * @return array<int, string> کانال‌هایی که این کلید به‌صورت پیش‌فرض فعال است
     */
    public static function defaultChannels(string $key): array
    {
        return self::entry($key)['channels'];
    }

    /**
     * ⭐ مسیرِ `database` برای `CatalogNotification` — نه `database` در فهرستِ
     * `CHANNELS`. کانالِ داخلی همیشه فعال است و ترجیحِش وجود ندارد.
     */
    public static function isOutboxChannel(string $channel): bool
    {
        return in_array($channel, [Outbox::CHANNEL_EMAIL, Outbox::CHANNEL_SMS, Outbox::CHANNEL_PUSH, Outbox::CHANNEL_TELEGRAM], true);
    }
}
