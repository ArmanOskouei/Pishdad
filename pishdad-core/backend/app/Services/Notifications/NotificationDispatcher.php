<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Catalog;
use App\Notifications\CatalogNotification;
use App\Services\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F4.2.B — یک اعلانِ کاتالوگی، با رعایتِ ترجیحات، به سه مقصد می‌فرستد.
 *
 * ```
 *   catalog key ──► notifications (کانال داخلی، همیشه)
 *               └► notification_deliveries (email/sms/push، اگر ترجیح اجازه دهد)
 * ```
 *
 * ## ⭐ چرا `database` از ترجیح رد نمی‌شود
 *
 * چون «خاموش‌کردنِ اعلانِ داخلی» یعنی **نگفتنِ قالب**، نه نرسیدنِ چیزی. اگر
 * سطرِ `notifications` نوشته نشود، صندوق خالی می‌ماند و کاربر نمی‌فهمد
 * خاموشش کرده یا چیزی رخ نداده. ضمناً `F4.2.W` گفته صندوق باید استثنای
 * `SuspensionGuard` باشد — پس سطر باید حتماً بنشیند.
 *
 * ## ⭐ چرا هر دو نوشتن در **یک** تراکنش
 *
 * الگوی transactional outbox. اگر سطرِ `notifications` بنشیند و `enqueue()`
 * شکست بخورد، صندوق اعلانی نشان می‌دهد که هرگز از طریق ایمیل خبر نمی‌گیرد —
 * و هیچ لاگی هم نیست چون هر دو «موفق» بوده‌اند. یک تراکنش این را می‌بندد:
 * یا هر دو، یا هیچ‌کدام.
 */
final class NotificationDispatcher
{
    /**
     * سقفِ اشتراک‌های push در هر اعلانِ کاتالوگی.
     *
     * یک کاربرِ منطقی چند دستگاه دارد، ولی هیچ سقفی یعنی یک کاربر با هزار
     * ردیف (که ساختنش ممکن است) هر بارِ فراخوانی را ده‌ها سطر در صف می‌کند.
     */
    private const PUSH_SUBSCRIPTION_LIMIT = 20;

    /**
     * @param  array<int, scalar|null>  $values
     */
    public static function send(User $user, string $catalogKey, array $values = []): void
    {
        $entry = Catalog::entry($catalogKey);

        DB::transaction(function () use ($user, $catalogKey, $entry, $values): void {
            /**
             * ⭐ شناسه را **خودمان** می‌سازیم، چون `notify()` در لاروال `void` برمی‌گرداند.
             *
             * `DatabaseChannel` از `$notification->id ?? Str::uuid()` استفاده می‌کند،
             * پس set-کردنش کار می‌کند — ولی اگر به مقدارِ بازگشتی تکیه کنیم، ستون‌های
             * نمایه هیچ‌وقت پر نمی‌شوند و **بی‌سروصدا** می‌مانند: نه `catalog_key`،
             * نه `action_href`، نه `severity`. صندوق خالی‌تر و بی‌لینک نشان می‌داد
             * و هیچ خطایی هم نبود.
             */
            $id = (string) Str::uuid();

            $notification = new CatalogNotification($catalogKey, $values);
            $notification->id = $id;

            $user->notify($notification);

            // ستون‌های نمایه. عنوان/بدنه عمداً اینجا **نوشته نمی‌شوند** — منبعِ
            // حقیقت، کاتالوگ است (ببین `CatalogNotification`).
            //
            // ⭐ `catalog_key` را **خودمان** می‌نویسیم: `DatabaseChannel` فقط
            // `id/type/notifiable_*/data` می‌نویسد و از کلیدِ کاتالوگ خبر ندارد.
            // فراموش‌کردنش بی‌صداست — ستون هست، خالی هم می‌ماند، و صندوق اعلان
            // را به‌عنوان «ناشناس» رندر می‌کند (بدون عنوان، بدون لینک، بدون
            // شدت) در حالی که هیچ خطایی صادر نشده.
            DB::table('notifications')
                ->where('id', $id)
                ->update([
                    'catalog_key' => $catalogKey,
                    'severity' => self::severityFor($entry['group']),
                    'action_href' => $entry['action']['href'] ?? null,
                    'action_label' => $entry['action']['label'] ?? null,
                ]);

            self::enqueueChannels($user, $catalogKey, $entry, $values, $id);
        });
    }

    /**
     * @param  array{channels: list<string>, title: string, body: string, group: string}  $entry
     * @param  array<int, scalar|null>  $values
     */
    private static function enqueueChannels(User $user, string $catalogKey, array $entry, array $values, ?string $notificationId): void
    {
        $subject = Catalog::render($entry['title'], $values);
        $body = Catalog::render($entry['body'], $values);

        foreach (Catalog::CHANNELS as $channel) {
            if (! self::allows($user, $catalogKey, $entry['group'], $channel)) {
                continue;
            }

            /**
             * ⭐ کلیدِ dedupe از **کلیدِ کاتالوگ + کاربر + کانال** ساخته می‌شود.
             *
             * اگر یک رویداد دوبار بیاید (retry رویداد، دوبار اجرا شدن یک
             * listener) باید **یک** ایمیل برود. ولی دو اعلانِ *متفاوت* از یک
             * کلید (مثلاً دو بار «افزونه فعال شد» برای دو افزونه) باید **دو**
             * ایمیل بروند — و به همین دلیل رویداد داخل `values` هم هست.
             */
            $dedupe = implode('|', ['cat', $catalogKey, $user->id, $channel, md5(json_encode($values) ?: '')]);

            /**
             * ⭐⭐ کانالِ `push` از الگوی «یک نشانی ⇒ یک سطر» خارج است.
             *
             * بقیهٔ کانال‌ها یک **نشانی** دارند (ایمیل، شماره، chat_id)؛ ولی
             * `push` مقصدش **فهرستِ اشتراک‌های مرورگرِ کاربر** است و
             * `Outbox::push()` **شناسهٔ سطرِ اشتراک** می‌خواهد. پس `destination()`
             * اینجا اصلاً صدا زده نمی‌شود: قبلاً شمارهٔ موبایل برمی‌گشت و همان
             * به `Outbox::email()` می‌افتاد — یعنی **push هرگز ارسال نمی‌شد** و
             * به‌جایش ایمیلی به یک شماره ساخته می‌شد که هرگز نمی‌رسد.
             */
            if ($channel === Outbox::CHANNEL_PUSH) {
                self::enqueuePush($user, $entry, $subject, $body, $dedupe, $notificationId);

                continue;
            }

            $target = self::destination($user, $channel);
            if ($target === null) {
                continue;
            }

            if ($channel === Outbox::CHANNEL_SMS) {
                // SMS متنِ کوتاه می‌خواهد، نه پاراگراف. همین متنِ برش‌خورده
                // کافی است — و `Outbox` provider ندارد، پس این سطر عمداً
                // `failed` می‌شود تا **دیده** شود نه اینکه بی‌سروصدا بماند.
                Outbox::sms($target, $dedupe, mb_substr($body, 0, 320), $notificationId);

                continue;
            }

            if ($channel === Outbox::CHANNEL_TELEGRAM) {
                // تلگرام سقفِ ۴۰۹۶ نویسه دارد؛ عنوان در خط اول می‌آید چون
                // برخلاف ایمیل، «موضوع» جدا از متن نیست و بدون آن کاربر نمی‌فهمد
                // پیام دربارهٔ چیست.
                Outbox::telegram($target, $dedupe, mb_substr($subject.' — '.$body, 0, 1500), $notificationId);

                continue;
            }

            Outbox::email($target, $dedupe, $subject, $body, $notificationId);
        }
    }

    /**
     * ⭐⭐ F5.3-b — صفِ push برای **همهٔ مرورگرهای کاربر**.
     *
     * ## چرا یک متدِ جدا و نه یک شاخهٔ ساده در حلقه
     *
     * سه دلیل، و هر سه به یک تصمیم ختم می‌شوند: این مسیر **تعداد-دلخواه** سطر
     * می‌سازد، نه یکی.
     *
     *  ۱. **مقصد، نشانی نیست.** `Outbox::push()` شناسهٔ سطرِ `push_subscriptions`
     *     می‌خواهد نه endpoint (که از ۲۰۰ نویسه رد می‌شود و کلیدِ ارسال است).
     *  ۲. **کلیدِ dedupe باید شناسهٔ اشتراک را داشته باشد.** قیدِ یکتا روی
     *     `dedupe_key` است؛ بدون این بخش همهٔ دستگاه‌ها در **یک** سطر جمع
     *     می‌شوند — enqueue اول موفق است و بقیه `null` می‌گیرند، یعنی فقط یک
     *     دستگاه اعلان می‌گیرد و بقیه هرگز. (همان دامی که
     *     `ContentPublishedNotifier` برای آن توضیح دارد.)
     *  ۳. **نبودِ اشتراک خطا نیست.** کاربر شاید هرگز opt-in نکرده باشد؛
     *     همان قاعدهٔ «مقصدی نیست ⇒ بی‌سکوت رد شو» که `destination()` دارد.
     *
     * ⚠️ عمداً **هیچ fallback ایمیلی** ندارد: `push` خواستهٔ خودِ کاربر است و
     * جایگزینش (ایمیل به شمارهٔ موبایل) یعنی اعلانی که کاربر نخواسته و که
     * هرگز هم نمی‌رسد — همان باگی که این تسک آمده درستش کند.
     *
     * @param  array{key: string, action: array{label: string, href: string}|null}  $entry
     */
    private static function enqueuePush(
        User $user,
        array $entry,
        string $subject,
        string $body,
        string $dedupe,
        ?string $notificationId,
    ): void {
        $subscriptions = PushSubscription::query()
            ->forUser((int) $user->id)
            ->orderBy('id')
            ->limit(self::PUSH_SUBSCRIPTION_LIMIT)
            ->get(['id']);

        if ($subscriptions->isEmpty()) {
            return;
        }

        /**
         * شکلِ payload همان چیزی است که `sw.js` می‌خواند: `title`/`body`/`url`/`tag`.
         *
         * ⚠️ `url` از `action.href` می‌آید که `Catalog::entry()` از allowlist
         * رد کرده (ببین `Catalog::isSafeActionHref`) و `sw.js` هم موقع کلیک
         * یک‌بار دیگر `safeTarget` می‌کند. اگر اعلان کاری برای انجام‌دادن ندارد
         * (`action = null`)، صندوق اعلان مقصد است، نه یک URL خالی.
         */
        $payload = [
            'title' => $subject,
            // اعلان مرورگر معمولاً دو خط بیشتر را نشان نمی‌دهد؛ متن بلندتر
            // فقط باعث می‌شود کاربر آن را ندوانده بخواند.
            'body' => mb_substr($body, 0, 200),
            'url' => $entry['action']['href'] ?? '/admin/notifications',
            'tag' => 'cat-'.$entry['key'],
        ];

        foreach ($subscriptions as $subscription) {
            /**
             * ⭐ `null` یعنی این سطر قبلاً در صف بوده (idempotent) — خطا نیست،
             * دقیقاً مثل بقیهٔ کانال‌ها.
             */
            Outbox::push(
                (int) $subscription->id,
                $dedupe.'|sub:'.$subscription->id,
                $payload,
                $notificationId,
            );
        }
    }

    /**
     * ⭐ ترجیح: اول استثنای کاربر روی همان کلید، بعد پیش‌فرضِ گروه، بعد
     * پیش‌فرضِ کاتالوگ.
     *
     * ترتیب این‌ها **دلخواه نیست**: اگر از پیش‌فرضِ گروه شروع کنیم، استثنای
     * کاربر بی‌اثر می‌شود چون هیچ‌وقت به آن نمی‌رسیم.
     */
    public static function allows(User $user, string $catalogKey, string $group, string $channel): bool
    {
        $override = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('catalog_key', $catalogKey)
            ->value('enabled');

        if ($override !== null) {
            return (bool) $override;
        }

        $groupDefault = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('group_key', $group)
            ->whereNull('catalog_key')
            ->value('enabled');

        if ($groupDefault !== null) {
            return (bool) $groupDefault;
        }

        return in_array($channel, Catalog::defaultChannels($catalogKey), true);
    }

    /**
     * نشانیِ مقصد برای کانال. `null` یعنی «مقصدی نیست ⇒ بی‌سکوت رد شو».
     *
     * نبودِ ایمیل یا موبایل **خطا نیست** — کاربر ممکن است هرگز ثبت نکرده باشد،
     * و ساختنِ سطرِ صف برای نشانیِ خالی یعنی پیامی که هرگز نمی‌رسد ولی در
     * آمار «ارسال‌شده» می‌شود.
     */
    private static function destination(User $user, string $channel): ?string
    {
        if ($channel === Outbox::CHANNEL_EMAIL) {
            $email = is_string($user->email) ? trim($user->email) : '';

            return $email === '' ? null : $email;
        }

        if ($channel === Outbox::CHANNEL_TELEGRAM) {
            // ⭐ دو شرط، نه یکی. «کاربر chat_id داده» کافی نیست: اگر توکنِ ربات
            // روی این نصب تنظیم نشده باشد، ساختنِ سطرِ صف یعنی پیامی که هرگز
            // نمی‌رود ولی در جدول و آمار «تحویل» می‌شود. پس fail-soft: رد شو.
            if (! TelegramSender::configured()) {
                return null;
            }

            $chat = $user->telegram_chat_id;

            return is_string($chat) && trim($chat) !== '' ? trim($chat) : null;
        }

        /**
         * ⭐⭐ SMS صریح است، نه «هر چیزِ دیگر».
         *
         * نسخهٔ قبلی شماره را به‌عنوان **fallback** برمی‌گرداند، یعنی هر کانالِ
         * نشناخته‌ای که به این متد می‌رسد شمارهٔ موبایل را به‌عنوان `recipient`
         * می‌گرفت. `push` دقیقاً همین شد: به `Outbox::email()` افتاد و شمارهٔ
         * موبایل ایمیل شد — پیامی که نه push بود و نه به مقصد می‌رسید.
         *
         * پس `null` برای هر چیزِ ناشناخته: **سکوتِ امن** بهتر از مقصدِ غلط است.
         * `push` هم اصلاً به این متد نمی‌رسد (شاخهٔ خودش را دارد).
         */
        if ($channel === Outbox::CHANNEL_SMS) {
            $phone = $user->phone;

            return is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
        }

        return null;
    }

    private static function severityFor(string $group): string
    {
        return match ($group) {
            'security' => 'warning',
            default => 'info',
        };
    }

    /**
     * ساختِ کلیدِ پایدار برای `dedupe` — کمکیِ `NotificationDispatcher` نیست و
     * فقط در تست‌ها استفاده می‌شود تا کلید را **دقیقاً** مثل کد تولید کند.
     *
     * @param  array<int, scalar|null>  $values
     */
    public static function dedupeKey(User $user, string $catalogKey, string $channel, array $values = []): string
    {
        return implode('|', ['cat', $catalogKey, $user->id, $channel, md5(json_encode($values) ?: '')]);
    }
}
