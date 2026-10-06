<?php

namespace Pishdad\Plugins\Central\Subscription;

use App\Models\Setting;
use App\Models\User;
use App\Services\Subscription\SubscriptionSource as CoreSubscriptionSource;
use App\Services\Subscription\SubscriptionStatusMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\Invoice;
use Pishdad\Plugins\Central\Models\Subscription;
use Throwable;

/**
 * منبعِ وضعیتِ اشتراک از جدول‌های **خودِ افزونهٔ مرکزی**.
 *
 * ## چرا اینجا است و نه در هسته
 *
 * نسخهٔ قبل یک adapter در هسته بود که مستقیم مدل‌های همین بسته را import می‌کرد.
 * همان یک import بود که تستِ مرز مجبور بود سال‌ها در فهرستِ استثنا نگهش دارد.
 *
 * حالا داده سرِ جایش است — داخلِ بسته — و هسته فقط می‌پرسد «چه کسی وضعیت را
 * می‌داند؟».
 *
 * هسته هیچ چیز دربارهٔ `central` نمی‌داند: نه نامِ جدول، نه نامِ کلاس، نه حتی اینکه
 * چنین افزونه‌ای وجود دارد. فقط یک **قاعده** را می‌داند: هر بستهٔ همراه که کلاسی به
 * نامِ `Subscription\SubscriptionSource` دارد، منبعِ وضعیت است.
 *
 * ## چرا نامِ کلاس همان نامِ فایل است
 *
 * PSR-4 این را ایجاب می‌کند و لازم هم هست: هسته کلاس را با **قاعده** پیدا می‌کند،
 * نه با نامِ خاصِ این افزونه. اگر نامشان فرق کند، منبع پیدا نمی‌شود و تعلیق
 * بی‌صدا از کار می‌افتد.
 *
 * ⚠️ رابطِ هسته هم `SubscriptionSource` نام دارد، برای همین `alias` شده — وگرنه
 * کلاسِ ما رابطِ خودش را implement می‌کرد.
 *
 * ⚠️ تستِ مرز، رشته‌های `App\…` را حتی داخل کامنت می‌شمارد. پس حتی یک `@see` تزئینی
 * به کلاسِ هسته باید در فهرستِ استثناها بنشیند — وگرنه خودِ توضیح، تست را می‌شکند.
 * برای همین این‌جا ارجاعِ متنی نداریم و به‌جایش این را می‌نویسیم.
 */
final class SubscriptionSource implements CoreSubscriptionSource
{
    public function status(?int $ownerUserId = null): ?array
    {
        // اگر کلاس‌های این بسته در اجرا حاضر نباشند، بسته نصب نیست و منبعِ خصوصی
        // اصلاً وارد چرخه نمی‌شود.
        if (! self::pluginIsPresent()) {
            return null;
        }

        $subscription = $this->resolveSubscription($ownerUserId);

        if ($subscription === null) {
            return null;
        }

        $status = in_array($subscription->status, [Subscription::SUSPENDED, Subscription::DELETED], true)
            ? 'suspended'
            : 'active';

        return [
            'status' => $status,
            // پیامِ برند از هسته می‌آید: این متن هیچ چیزِ مرکزی نمی‌داند، پس
            // مالکش هم هسته است.
            'message' => SubscriptionStatusMessage::make($status, $this->brand($subscription)),
        ];
    }

    /**
     * J5 — وضعیتِ یک سایتِ مشخص.
     *
     * این متد دقیقاً کاری را می‌کند که `forSiteId()` در هسته **قبلاً خودش** با
     * کوئریِ مستقیم انجام می‌داد. حالا همان کوئری اینجاست، چون داده مالِ همین
     * بسته است.
     *
     * guardِ جدول هم اینجاست و نه در هسته: نامِ جدول را بستهٔ صاحبش می‌داند. هسته
     * فقط می‌پرسد «جواب داری؟».
     */
    public function statusForSite(?string $siteId = null): ?array
    {
        if (! self::pluginIsPresent() || ! self::tablesExist()) {
            return null;
        }

        $query = Subscription::query()->with('plan');

        // `null` یعنی «نصبِ پیش‌فرض» و رفتارش قدیمی است: قدیمی‌ترینِ نصب. بدون
        // این شاخه، صفحهٔ وضعیتِ سایتِ تک‌نصبی ۴۰۴ می‌داد.
        $subscription = $siteId !== null && $siteId !== ''
            ? $query->where('site_id', $siteId)->first()
            : $query->oldest('id')->first();

        if ($subscription === null) {
            return null;
        }

        $status = in_array($subscription->status, [Subscription::SUSPENDED, Subscription::DELETED], true)
            ? 'suspended'
            : 'active';

        return [
            'status' => $status,
            'message' => SubscriptionStatusMessage::make($status, $this->brand($subscription)),
        ];
    }

    /**
     * J5 — کارتِ اشتراک برای یک کاربر، از جدول‌های خودِ این بسته.
     *
     * ## چرا این متد رکورد می‌سازد
     *
     * نسخهٔ قدیمیِ `ClientSubscriptionResolver::forUser()` وقتی مشتری نداشت،
     * با `firstOrCreate` یک ردیف می‌ساخت و ۳۰ روز اعتبار می‌داد. آن نوشتن روی
     * جدولِ افزونه **در هسته** بود — و J5 درست همین را حذف کرد، چون هسته نباید
     * دیتابیسِ افزونه را تغییر دهد.
     *
     * حالا همان رفتار اینجاست، داخلِ بسته‌ای که صاحبِ جدول است. برای مشتری
     * نتیجه فرقی نمی‌کند (کارتش فعال است)، ولی مرز سالم است: اگر فردا بستهٔ دیگری
     * مالکِ اشتراک شد، همین قرارداد را پیاده می‌کند و خودش تصمیم می‌گیرد.
     *
     * ⚠️ این تنها جایی است که افزونه **می‌نویسد**، و این عمدی است.
     */
    public function forUser(?int $ownerUserId): ?array
    {
        if ($ownerUserId === null || ! self::pluginIsPresent() || ! self::tablesExist()) {
            return null;
        }

        // Q1 (نصب یعنی سایت) — کاربرِ ناموجود صورتحساب ندارد.
        $user = User::query()->find($ownerUserId);

        if ($user === null) {
            return null;
        }

        $client = Client::query()->firstOrCreate(
            ['owner_user_id' => $user->id],
            [
                'site_id' => 'site-'.$user->id,
                'name' => $user->name ?? 'سایت من',
                'tier' => 'budget',
                'status' => 'active',
            ]
        );

        $subscription = Subscription::query()->firstOrCreate(
            ['client_id' => $client->id],
            [
                'site_id' => $client->site_id,
                'status' => Subscription::ACTIVE,
                'due_at' => now()->addDays(30),
            ]
        );

        $active = $this->isActive($subscription);

        return [
            'tier' => is_string($client->tier ?? null) ? $client->tier : null,
            'status' => is_string($subscription->status ?? null) ? $subscription->status : null,
            'due_at' => $this->date($subscription->due_at ?? null),
            // «روز تا سررسیدِ پرداخت»، نه «روز تا حذفِ نهایی». برای اشتراکِ فعال
            // دومی همیشه صفر بود و کارت «۰ روز مانده» می‌گفت در حالی که ۲۸ روز
            // مانده بود — یعنی کارت همیشه دروغ می‌گفت.
            //
            // برای اشتراکِ معلق `null`، نه صفر: صفر یعنی «امروز سررسید شد» که
            // برای کاربری که یک ماه تعلیق است گمراه‌کننده است.
            'days_remaining' => $active ? $subscription->daysUntilDue() : null,
            'overdue_invoice_count' => $this->overdueInvoiceCount($client->id, $subscription->id),
            'is_due_soon' => $active && $this->isDueSoon($subscription),
        ];
    }

    /** آیا این اشتراک در وضعیتِ «پرداخت‌شده و فعال» است؟ */
    private function isActive(Subscription $subscription): bool
    {
        return $subscription->status === Subscription::ACTIVE;
    }

    /**
     * «به‌زودی» یعنی چه؟
     *
     * ⚠️ این تعریف **داخلِ افزونه** است و عمداً. پیش‌تر هر دو افزونه (اشتراک و مرکزی)
     * این عدد را جداگانه حدس می‌زدند و هیچ‌کدام نمی‌دانستند دیگری چه می‌گوید.
     *
     * عدد با `SubscriptionData::isDueSoon()` (افزونهٔ اشتراک) یکی است — ۱۴ روز —
     * تا جایی که هر دو زنده‌اند، کاربر دو هشدارِ متفاوت نبیند.
     */
    private function isDueSoon(Subscription $subscription, int $days = 14): bool
    {
        if ($subscription->due_at === null) {
            return false;
        }

        $now = now();
        $due = $subscription->due_at->copy();

        if ($due->lessThan($now)) {
            // گذشته یعنی معوق، نه «به‌زودی». هشدارِ معوق مسیرِ خودش را دارد.
            return false;
        }

        return $due->lessThanOrEqualTo($now->copy()->addDays($days));
    }

    private function overdueInvoiceCount(int $clientId, int $subscriptionId): int
    {
        return Invoice::query()
            ->where('client_id', $clientId)
            ->where('subscription_id', $subscriptionId)
            ->where('status', Invoice::PENDING)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveSubscription(?int $ownerUserId): ?Subscription
    {
        // اول اشتراکِ همین کاربر؛ اگر نبود، قدیمی‌ترینِ نصب (نصبِ تکی).
        if ($ownerUserId !== null) {
            $client = Client::query()->where('owner_user_id', $ownerUserId)->first();

            if ($client !== null) {
                $own = Subscription::query()->where('client_id', $client->id)->with('plan')->first();
                if ($own !== null) {
                    return $own;
                }
            }
        }

        return Subscription::query()->with('plan')->oldest('id')->first();
    }

    private function brand(Subscription $subscription): ?string
    {
        // ⚠️ عمداً **بدون** `CachedSettings`.
        //
        // نسخهٔ قبل نامِ سایت را با کشِ ۳۰۰ ثانیه‌ای می‌خواند، ولی این مسیر فقط از
        // `/site/status` رد می‌شود و آن کنترلر **خودش** کلِ پاسخ را ۶۰ ثانیه کش
        // می‌کند. یعنی کشِ دوم چیزی اضافه نمی‌کرد — فقط یک وابستگیِ تازه به هسته
        // می‌آورد و باید در فهرستِ استثناها می‌نشست.
        //
        // `Setting` خودش قبلاً در فهرستِ مجازِ افزونه هست، و خواندنش از این مسیر
        // عملاً یک‌بار در هر ۶۰ ثانیه اتفاق می‌افتد. هزینه‌ای ندارد.
        $site = Setting::get('site', 'global', []);

        return is_array($site) && ! empty($site['title']) ? (string) $site['title'] : null;
    }

    /**
     * آیا بستهٔ مرکزی در این اجرا حاضر است؟
     *
     * معیار، **بارگذاری‌شدن کلاس‌های بسته** است — نه ردیفِ `plugins` در دیتابیس.
     * چرا: (۱) در نصبِ عمومی پوشهٔ افزونه وجود ندارد، پس کلاس‌ها هم `false` می‌دهند و
     * منبعِ خصوصی اصلاً وارد چرخه نمی‌شود؛ (۲) در تست‌ها `RefreshDatabase` ردیفِ
     * manifest را پاک می‌کند، اما کدِ بسته همچنان حاضر است و باید پاسخ بدهد.
     *
     * توجه: این عمداً وضعیتِ فعال/غیرفعال در دیتابیس را کنار می‌گذارد؛ وضعیتِ
     * افزونه یک تصمیمِ استقرار است، نه شرطِ پاسخ‌گوییِ resolver.
     */
    private static function pluginIsPresent(): bool
    {
        return class_exists(Subscription::class);
    }

    /**
     * J5 — جدول‌های بسته واقعاً هستند؟
     *
     * حاضر بودنِ کلاس و وجودِ جدول دو چیز جدا هستند: نصبِ تازه کلاس‌ها را دارد ولی
     * مهاجرت‌ها هنوز اجرا نشده‌اند. بدون این guard، کوئری روی جدولِ غایب ۵۰۰ می‌داد
     * — همان چیزی که K7.2 در سایتِ عمومی دید.
     *
     * نامِ جدول از روی خودِ مدل خوانده می‌شود تا بعد از `prefix_central_tables` هم
     * درست بماند.
     */
    private static function tablesExist(): bool
    {
        try {
            return Schema::hasTable((new Subscription)->getTable())
                && Schema::hasTable((new Client)->getTable());
        } catch (Throwable $e) {
            return false;
        }
    }
}