<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Catalog;
use App\Services\Notifications\TelegramSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * F4.2.B — صندوق و ترجیحاتِ اعلان.
 *
 * ## ⭐ چرا مسیرها `notification-*` و نه همان `notifications`
 *
 * `K5.8` مسیر `POST /v1/admin/notifications` را **رزرو کرده** (سرویسِ افزونه) و
 * `done` است. اگر اینجا هم `POST notifications` می‌ساختیم، دو کنترلر برای یک
 * مسیر می‌شد و هرکدام می‌توانستند دیگری را بی‌سروصدا بازنویسی کنند — یا بستهٔ
 * لاراول آخرِ تعریف را برنده شود و مسیرِ افزونه بی‌صدا به تنظیمات برگردد.
 *
 * پس فضای‌نامِ جدید و **غیرهمپوشان**: اینجا فقط **خواندنِ** صندوق و
 * نوشتنِ ترجیح. نوشتنِ اعلان (سمت افزونه) همچنان مالکیتِ `K5.8` است.
 */
class NotificationInboxController extends Controller
{
    /**
     * `GET /v1/admin/notification-inbox`.
     *
     * فقط کاربرِ جاری — و این یک قراردادِ امنیتی است نه یک تصمیمِ UI: `notifiable`
     * از خودِ session پر می‌شود، پس پارامترِ `user_id` اصلاً وجود ندارد و مدیر
     * نمی‌تواند صندوقِ مدیرِ دیگر را بخواند.
     *
     * ⭐ هر سطر از `Catalog` ساخته می‌شود، نه از `data`:
     *  - `action_href` از ستونِ **از پیش اعتبارسنجی‌شده** خوانده می‌شود و دوباره
     *    از allowlist رد می‌گردد (fail-closed روی دادهٔ قدیمیِ قبل از مهاجرت).
     *  - عنوان/بدنه از قالبِ امروزِ کاتالوگ می‌آید ⇒ یک منبعِ حقیقت.
     *  - ردیف‌هایی که `catalog_key` ندارند (`PluginNotification` از K5.8) از
     *    `data` خوانده می‌شوند و `action_href`شان **همیشه** `null` است.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = max(1, min(50, (int) $request->integer('per_page', 20)));

        $rows = $user->notifications()
            ->latest()
            ->limit($perPage)
            ->get()
            ->map(fn (object $n): array => $this->row($n))
            ->all();

        return response()->json([
            'data' => [
                'items' => $rows,
                'unread' => $user->notifications()->whereNull('read_at')->count(),
            ],
        ]);
    }

    /**
     * `GET /v1/admin/notification-preferences` — ماتریسِ ۵ کارت × ۳ کانال.
     *
     * خروجی همیشه **کامل** است (نه فقط سطرهای ذخیره‌شده) چون UI باید کلیدهای
     * خاموش را هم نشان بدهد تا کاربر بداند چه چیزی را روشن کند.
     */
    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'groups' => Catalog::groupLabels(),
                'channels' => Catalog::CHANNELS,
                'matrix' => $this->matrix($user),
                'settings' => $this->settings($user),
            ],
        ]);
    }

    /**
     * `PUT /v1/admin/notification-preferences/settings` — تنظیماتِ کانالِ کاربر.
     *
     * اینجا برخلاف `updatePreferences`، واحدِ تنظیم **کاربر** است نه (گروه، کانال):
     *  - `telegram_chat_id` نشانیِ شخصی برای کانالِ تلگرام.
     *  - `daily_digest` تصمیمِ سراسریِ «یک پیامِ خلاصه در روز».
     *
     * هر دو اختیاری‌اند؛ فرانت هر بار هر دو را می‌فرستد ولی نبودنِ یکی نباید
     * دیگری را پاک کند.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // تلگرام: عدد (منفی برای گروه) یا `@username`. فاصله و نویسهٔ
            // کنترلی رد می‌شود چون این رشته مستقیم به Bot API می‌رود.
            'telegram_chat_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_@.-]+$/'],
            'daily_digest' => ['sometimes', 'boolean'],
        ], [
            'telegram_chat_id.regex' => 'شناسهٔ گفتگوی تلگرام نامعتبر است.',
            'telegram_chat_id.max' => 'شناسهٔ گفتگوی تلگرام بلندتر از حد مجاز است.',
            'daily_digest.boolean' => 'وضعیت خلاصهٔ روزانه نامعتبر است.',
        ]);

        $user = $request->user();
        $update = [];

        if (array_key_exists('telegram_chat_id', $validated)) {
            $chat = $validated['telegram_chat_id'];
            $update['telegram_chat_id'] = is_string($chat) && trim($chat) !== '' ? trim($chat) : null;
        }

        if (array_key_exists('daily_digest', $validated)) {
            $update['notification_daily_digest'] = (bool) $validated['daily_digest'];
        }

        if ($update !== []) {
            $user->forceFill($update)->save();
        }

        return response()->json([
            'message' => 'تنظیمات کانال‌ها ذخیره شد.',
            'data' => ['settings' => $this->settings($user->fresh())],
        ]);
    }

    /**
     * `GET /v1/admin/notification-preferences/telegram-bot` — وضعیتِ رباتِ نصب.
     *
     * E73 — توکنِ ربات پیش‌تر فقط در `.env` بود و در پنل هیچ راهی برای دیدن یا
     * عوض‌کردنش نبود؛ کاربر chat_id می‌داد ولی پیامی نمی‌رفت و نمی‌فهمید چرا.
     * توکنِ کامل **هرگز** به مرورگر نمی‌رود — فقط شکلِ ماسک‌شده برای اینکه
     * مدیر بفهمد کدام ربات وصل است. مسیر `perm:settings.edit` دارد چون رازِ
     * نصب است، نه تنظیمِ شخصی (برخلاف chat_id که مالِ خودِ کاربر است).
     *
     * E74 — `is_default` یعنی «توکنِ مؤثر از رباتِ عمومیِ پیشداد می‌آید».
     * پنل با آن می‌فهمد پاک‌کردن یعنی برگشت به رباتِ عمومی، نه خاموشی؛
     * برای خاموشیِ واقعی باید `TELEGRAM_BOT_TOKEN=` در `.env` باشد.
     *
     * @return array{configured: bool, masked: string|null, is_default: bool}
     */
    public function botSettings(): JsonResponse
    {
        return response()->json([
            'data' => [
                'configured' => TelegramSender::configured(),
                'masked' => TelegramSender::masked(),
                'is_default' => TelegramSender::configured() && TelegramSender::usesDefaultBot(),
            ],
        ]);
    }

    /**
     * `PUT /v1/admin/notification-preferences/telegram-bot` — ذخیره/پاک‌سازیِ توکن.
     *
     * خالی/`null` یعنی پاک‌سازی (برگشت به `.env`). `.env` اگر پر باشد همیشه
     * برنده است و با این مسیر عوض نمی‌شود — چیزی که اپراتور در محیط قفل کرده
     * نباید با یک کلیک در پنل عوض شود.
     */
    public function updateBotToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['sometimes', 'nullable', 'string', 'max:256'],
        ]);

        $token = array_key_exists('token', $validated) ? trim((string) $validated['token']) : null;

        if ($token !== null && $token !== '' && preg_match(TelegramSender::TOKEN_PATTERN, $token) !== 1) {
            throw ValidationException::withMessages([
                'token' => 'شکل توکن معتبر نیست. توکن را از @BotFather بگیرید (مثل 123456:ABC-DEF...).',
            ]);
        }

        // E74 — اگر اپراتور در `.env` کانال را **عمداً** بسته باشد
        // (`TELEGRAM_BOT_TOKEN=` خطِ خالی)، ذخیرهٔ توکن در پنل بی‌صدا بی‌اثر
        // می‌ماند و مدیر فکر می‌کند تنظیمش کرده. به‌جای آن صریح می‌گوییم چه
        // چیزی باید عوض شود.
        if ($token !== null && $token !== '' && config('telegram.bot_token') === '') {
            throw ValidationException::withMessages([
                'token' => 'در فایل .env خطِ خالیِ TELEGRAM_BOT_TOKEN= هست و آن یعنی «کانال خاموش»؛ تا آن خط را خالی نگذارید یا توکن خودتان را داخلش بگذارید، توکنِ پنل اعمال نمی‌شود.',
            ]);
        }

        TelegramSender::setToken($token === '' ? null : $token);

        return response()->json([
            'message' => 'توکن ربات تلگرام ذخیره شد.',
            'data' => [
                'configured' => TelegramSender::configured(),
                'masked' => TelegramSender::masked(),
                'is_default' => TelegramSender::configured() && TelegramSender::usesDefaultBot(),
            ],
        ]);
    }

    /**
     * `POST /v1/admin/notification-preferences/telegram-bot/test` — پیامِ آزمایشی.
     *
     * مقصد: `chat_id` داده‌شده، وگرنه chat_id خودِ کاربر. بدونِ هیچ‌کدام ۴۲۲ —
     * نمی‌شود به «هیچ‌کجا» پیام داد. خطای provider (توکن غلط، ربات بلاک‌شده،
     * قطعی) هم ۴۲۲ با همان پیام می‌دهد تا کاربر بداند کجای زنجیره خراب است.
     */
    public function testBotToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chat_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_@.-]+$/'],
        ], [
            'chat_id.regex' => 'شناسهٔ گفتگوی تلگرام نامعتبر است.',
        ]);

        $chat = isset($validated['chat_id']) ? trim((string) $validated['chat_id']) : '';
        if ($chat === '') {
            $own = $request->user()->telegram_chat_id;
            $chat = is_string($own) ? trim($own) : '';
        }

        if ($chat === '') {
            return response()->json(['message' => 'اول شناسهٔ گفتگوی تلگرام را ذخیره کنید، بعد پیام آزمایشی بفرستید.'], 422);
        }

        try {
            TelegramSender::send($chat, 'پیام آزمایشی پیشداد — ربات وصل است.');
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'پیام آزمایشی فرستاده شد؛ تلگرام را ببینید.']);
    }

    /**
     * `PUT /v1/admin/notification-preferences` — ثبتِ ترجیحِ گروه.
     *
     * `PUT` و نه `PATCH`: سطرِ (کاربر، گروه، کانال) یک **تک‌مورد** است و این
     * جایگذاریِ کاملِ آن. ضمناً `catalog_key` پارامتر ندارد عمداً — استثنای
     * روی یک اعلانِ خاص از همین مسیر نوشته نمی‌شود چون در UI چنین کنترلی
     * وجود ندارد و پس‌از-کار `F4.2.F` است.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group_key' => ['required', 'string', Rule::in(array_keys(Catalog::GROUPS))],
            'channel' => ['required', 'string', Rule::in(Catalog::CHANNELS)],
            'enabled' => ['required', 'boolean'],
        ], [
            'group_key.in' => 'گروهِ ناشناخته.',
            'channel.in' => 'کانالِ ناشناخته.',
            'enabled.required' => 'وضعیت الزامی است.',
        ]);

        $user = $request->user();

        // `updateOrInsert` و نه `upsert` چون `catalog_key` nullable است و در
        // Postgres چند NULL در unique مجازند — یعنی قیدِ جدول این سطر را
        // محافظت نمی‌کند و تکرارِ سطر ممکن است.
        DB::table('notification_preferences')->updateOrInsert(
            [
                'user_id' => $user->id,
                'group_key' => (string) $validated['group_key'],
                'channel' => (string) $validated['channel'],
                'catalog_key' => null,
            ],
            [
                'enabled' => (bool) $validated['enabled'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'ترجیح ذخیره شد.',
            'data' => ['matrix' => $this->matrix($user->fresh())],
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * ⭐ ساختِ سطرِ صندوق. `body` **هرگز** HTML نیست — چون از `Catalog::plain()`
     * می‌آید و آن `strip_tags` می‌کند.
     *
     * @return array<string, mixed>
     */
    private function row(object $n): array
    {
        $key = is_string($n->catalog_key ?? null) ? $n->catalog_key : null;

        $title = $n->type;
        $body = '';
        $href = null;
        $label = null;

        if ($key !== null && Catalog::has($key)) {
            $entry = Catalog::entry($key);
            $values = $this->valuesOf($n);
            $title = Catalog::render($entry['title'], $values);
            $body = Catalog::render($entry['body'], $values);

            // دوباره از allowlist رد می‌کند: سطرهای نوشته‌شده پیش از این
            // migration لینکِ تأییدنشده دارند و نباید از راه دور بیرون بزنند.
            $stored = is_string($n->action_href ?? null) ? $n->action_href : null;
            if ($stored !== null && Catalog::isSafeActionHref($stored)) {
                $href = $stored;
                $label = is_string($n->action_label ?? null) ? $n->action_label : null;
            }
        } else {
            // ردیفِ `K5.8` — از افزونه آمده. عنوان/بدنه در `data` است و هیچ
            // لینکی ندارد: `action_href` از دستِ افزونه پذیرفته نمی‌شود.
            $data = $this->decode($n);
            $title = is_string($data['title'] ?? null) ? Catalog::plain($data['title']) : $n->type;
            $body = is_string($data['body'] ?? null) ? Catalog::plain($data['body']) : '';
        }

        return [
            'id' => $n->id,
            'catalog_key' => $key,
            'title' => mb_substr($title, 0, Catalog::MAX_TITLE),
            'body' => $body,
            'severity' => is_string($n->severity ?? null) ? $n->severity : 'info',
            'action_href' => $href,
            'action_label' => $label,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, list<bool>> گروه ⇒ کانال ⇒ فعال؟
     */
    private function matrix(User $user): array
    {
        $stored = DB::table('notification_preferences')
            ->where('user_id', $user->id)
            ->whereNull('catalog_key')
            ->get(['group_key', 'channel', 'enabled'])
            ->keyBy(fn (object $r): string => $r->group_key.'|'.$r->channel);

        $out = [];

        foreach (array_keys(Catalog::GROUPS) as $group) {
            $row = [];
            foreach (Catalog::CHANNELS as $channel) {
                $row[$channel] = isset($stored[$group.'|'.$channel])
                    ? (bool) $stored[$group.'|'.$channel]->enabled
                    : self::groupDefault($group, $channel);
            }
            $out[$group] = $row;
        }

        return $out;
    }

    /**
     * تنظیماتِ کانالِ کاربر. `telegram_configured` وضعیتِ **نصب** را می‌گوید نه
     * کاربر را: اگر توکنِ ربات نباشد، UI باید صادقانه بگوید «پیامی نمی‌رود»،
     * حتی اگر کاربر chat_id داده باشد.
     *
     * @return array{telegram_chat_id: string|null, daily_digest: bool, telegram_configured: bool}
     */
    private function settings(User $user): array
    {
        $chat = $user->telegram_chat_id;

        return [
            'telegram_chat_id' => is_string($chat) && trim($chat) !== '' ? trim($chat) : null,
            'daily_digest' => (bool) $user->notification_daily_digest,
            'telegram_configured' => TelegramSender::configured(),
        ];
    }

    /**
     * پیش‌فرضِ کارت وقتی کاربر چیزی ذخیره نکرده.
     *
     * ⭐ «هیچ کانالی برای این گروه خاموش نیست» مگر آنکه کاتالوگ خلافش را
     * بگوید. خاموشی پیش‌فرض یعنی کاربر یک اعلانِ امنیتی را گم می‌کند و اصلاً
     * نمی‌فهمد چرا. پس «سکوت» باید **فعال‌سازیِ صریح** باشد، نه نبودِ سطر.
     */
    private static function groupDefault(string $group, string $channel): bool
    {
        foreach (Catalog::keys() as $key) {
            $entry = Catalog::entry($key);
            if ($entry['group'] === $group && in_array($channel, $entry['channels'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<scalar|null> */
    private function valuesOf(object $n): array
    {
        $values = $this->decode($n)['values'] ?? [];

        return is_array($values) ? array_values($values) : [];
    }

    /** @return array<string, mixed> */
    private function decode(object $n): array
    {
        $raw = $n->data ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
