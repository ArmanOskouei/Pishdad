<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PluginNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * K5.8 — سرویس اعلان: افزونه می‌خواند و می‌نویسد.
 *
 * ## چرا این «سرویس» است و نه «نقطهٔ اتصال»
 *
 * افزونه اینجا چیزی **اعلام** نمی‌کند؛ فراخوانی‌اش می‌کند. تفاوت عملی: اعلان
 * در زمان نصب بررسی و امضا می‌شود، ولی اینجا هر بار که افزونه بخواهد داده‌ای
 * می‌فرستد. پس در مانیفست دیده نمی‌شود و به‌جایش افزونه از مسیر
 * `/v1/p/{slug}/notifications` خودش POST می‌کند و هسته اینجا پاسخ می‌دهد.
 *
 * ## مرزهایی که این کنترلر نگه می‌دارد
 *
 *  ۱) **مقصد اعلان، کاربر جاری است** — نه هر کسی. پارامتر `user_id` نداریم
 *     اصلاً. یک افزونه که بتواند اعلان برای مدیر دیگر بنویسد، می‌تواند پیام
 *     جعلی بسازد.
 *  ۲) **عنوان اجباری و کوتاه.** متن بلند در drawer جا نمی‌شود و یک اعلان
 *     غیرقابل‌کنترل یعنی بنر بزرگی که کسی نمی‌تواند ببندد.
 *  ۳) **شدت از سه مقدار بسته.** نه `enum` آزاد، چون هر مقدار جدید یک رنگ و یک
 *     تصمیم UI است که باید پیاده شود.
 *  ۴) **`notification_id` فقط `int` مثبت** — رشتهٔ عددی رد می‌شود چون بعداً به
 *     مسیر تبدیل می‌شود (همان قاعدهٔ K6.2).
 */
class NotificationController extends Controller
{
    /**
     * چند روز نگه‌داری. تصمیم محصول: بله، حذف خودکار — بدون این جدول بی‌نهایت
     * رشد می‌کند و کسی متوجه نمی‌شود چون هیچ‌کس به هشدار حجم نمی‌نگرد.
     */
    private const RETENTION_DAYS = 30;

    /** سقف تعداد اعلان در یک صفحه — بیشتر از این کاربر عملاً نمی‌خواند. */
    private const PER_PAGE_MAX = 50;

    /**
     * `GET /v1/admin/notifications` — اعلان‌های کاربر جاری.
     *
     * فقط کاربر جاری. نه `user_id` در query، نه فیلتر مدیر دیگر — چون این مسیر
     * پشت `auth:sanctum` است و اگر روزی مدیری خواست اعلان مدیر دیگر را بخواند،
     * این باید مسیر جداگانه با مجوز جداگانه باشد، نه پارامتر همین‌جا.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min(self::PER_PAGE_MAX, $perPage));

        $this->prune($user);

        $rows = $user->notifications()
            ->latest()
            ->limit($perPage)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $this->field($n, 'title', ''),
                'body' => $this->field($n, 'body', null),
                'severity' => $this->severityOf($n),
                'notification_id' => $this->intField($n, 'notification_id'),
                'action_label' => $this->field($n, 'action_label', null),
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ])
            ->all();

        $unread = $user->notifications()->whereNull('read_at')->count();

        return response()->json([
            'data' => [
                'items' => $rows,
                'unread' => $unread,
            ],
        ]);
    }

    /**
     * `POST /v1/admin/notifications` — ثبت اعلان برای خودِ کاربر جاری.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:600'],
            'severity' => ['sometimes', Rule::in(PluginNotification::SEVERITIES)],
            'notification_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'action_label' => ['sometimes', 'nullable', 'string', 'max:40'],
        ], [
            'title.required' => 'عنوان اعلان الزامی است.',
            'severity.in' => 'شدت اعلان مجاز نیست.',
        ]);

        $user = $request->user();

        $user->notify(PluginNotification::fromPayload($validated));

        return response()->json([
            'message' => 'اعلان ثبت شد.',
        ], 201);
    }

    /**
     * `POST /v1/admin/notifications/read-all` — علامت‌زدن همه به‌عنوان خوانده.
     *
     * عمداً یک عملیات، نه `PATCH /{id}` تکی: drawer ما یک دکمهٔ «خواندم» دارد و
     * کلیک روی هر کارت جداگانه نیست. مسیر تکی اگر روزی لازم شد
     * `/{id}/read` است، نه بازنویسی این.
     */
    public function readAll(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'همهٔ اعلان‌ها خوانده‌شده شد.',
            'data' => ['updated' => $updated],
        ]);
    }

    /**
     * حذف اعلان‌های قدیمیِ همین کاربر.
     *
     * عمداً **در مسیر خواندن** اجرا می‌شود نه در یک command جدا: یک command
     * یعنی نیاز به cron که در این پروژه تضمین‌شده نیست، و آن وقت جدول باز هم
     * بی‌مهار رشد می‌کند. این‌طوری هر کاربری که اعلانش را می‌بیند، فضای خودش را
     * آزاد می‌کند.
     *
     * فقط خوانده‌شده‌ها پاک می‌شوند. اعلان خوانده‌نشده یعنی کاربر هنوز ندیده و
     * حذفش یعنی پیامی که هرگز نرسید.
     */
    private function prune(User $user): void
    {
        $user->notifications()
            ->whereNotNull('read_at')
            ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }

    private function field(object $notification, string $key, mixed $default): mixed
    {
        $data = $this->data($notification);

        return $data[$key] ?? $default;
    }

    private function intField(object $notification, string $key): ?int
    {
        $value = $this->field($notification, $key, null);

        // دادهٔ ذخیره‌شده ممکن است از یک نسخهٔ قدیمی یا دستِ کسی رشته باشد.
        // تبدیل به `int` اینجا انجام می‌شود نه با `asInt`، چون `is_int` ندارد
        // و «۱۲» فارسی هم باید رد شود.
        return is_int($value) && $value > 0 ? $value : null;
    }

    private function severityOf(object $notification): string
    {
        $value = $this->field($notification, 'severity', 'info');

        return is_string($value) && in_array($value, PluginNotification::SEVERITIES, true)
            ? $value
            : 'info';
    }

    /**
     * `data` یک ستون `text` است، نه `jsonb` — جدول استاندارد Laravel.
     * پس ممکن است رشتهٔ خام باشد و باید decode شود.
     *
     * @return array<string, mixed>
     */
    private function data(object $notification): array
    {
        $raw = $notification->data ?? null;

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
