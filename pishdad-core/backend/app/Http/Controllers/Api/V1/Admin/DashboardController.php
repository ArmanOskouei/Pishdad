<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Analytics\SiteAnalytics;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * داشبورد پنل — آمار سایت، هشدارها و رجیستری ویجت.
 *
 * این کنترلر هیچ چیزی از افزونه نمی‌داند و هیچ مفهومِ صورت‌حسابی ندارد.
 * تنها هشدارِ مالیِ ممکن، سهمیهٔ فضای فایل است که در همهٔ نصب‌ها هست.
 *
 * ## ویجت‌های هسته کجا می‌روند؟
 *
 * کارت‌های **هسته** (مثل «صفحات پربازدید ۷ روز») در `stats` می‌آیند، نه در
 * `widgets()`: طبق K6.10 رجیستریِ ویجت عمداً خالی است تا زمانی که افزونه‌ها
 * در قرنطینه‌اند، و پر کردنش وعدهٔ دروغین می‌داد.
 */
class DashboardController extends Controller
{
    /** WF-M12 — پنجرهٔ کارت «صفحات پربازدید»: هفت روزِ تقویمی شامل امروز. */
    private const TOP_PAGES_DAYS = 7;

    /** پنج صفحه کافی است؛ بیشترش کارت را از صفحه بیرون می‌اندازد. */
    private const TOP_PAGES_LIMIT = 5;

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'pages_count' => Page::query()->count(),
                'media_count' => Media::query()->count(),
                'open_tickets' => Ticket::query()
                    ->where('status', '!=', Ticket::CLOSED)
                    ->count(),
                // دسته ۱ (برابری UI/UX): دلتای واقعی «جدید در ۷ روز اخیر» برای هر کارت — بدون mock.
                'recent' => [
                    'pages_new_7d' => Page::query()->where('created_at', '>=', now()->subDays(7))->count(),
                    'media_new_7d' => Media::query()->where('created_at', '>=', now()->subDays(7))->count(),
                    'tickets_new_7d' => Ticket::query()->where('created_at', '>=', now()->subDays(7))->count(),
                ],
                // WF-H21 — چک‌لیست راه‌اندازی اولین ورود. هر گام از **دادهٔ واقعی**
                // (تنظیمات/صفحات همین نصب) مشتق می‌شود، نه از حدسِ سمت کلاینت.
                'checklist' => $this->checklist(),
                // WF-M12 — کارت «صفحات پربازدید ۷ روز». داده از همان تحلیلِ
                // WF-H12 می‌آید (سرویسِ مشترک)، نه کوئریِ دوم.
                'top_pages_7d' => $this->topPagesWidget($user),
            ],
        ]);
    }

    /**
     * WF-M12 — ویجتِ «صفحات پربازدید ۷ روز».
     *
     * ## `null` یعنی «بی‌دسترسی»، نه «بی‌داده»
     *
     * گزارشِ تحلیل پشتِ `perm:analytics.view` است، ولی `dashboard/stats` چنین
     * میاندری ندارد (کارت‌های قبلی هم ندارند). بدونِ این گیت، بازدیدِ سایت از
     * مسیرِ دور زدنِ پرمیشن لو می‌رفت. پس همان معیارِ همان میاندری اینجا تکرار
     * می‌شود و UI **کارت را پنهان می‌کند**.
     *
     * بی‌داده اما `items: []` است: کارت می‌ماند و حالتِ خالیِ خودش را نشان
     * می‌دهد. این دو نباید یکی شوند، وگرنه کاربر «مجاز ولی بی‌بازدید» را با
     * «غیرمجاز» اشتباه می‌گیرد.
     *
     * @return array{days: int, items: array<int, array{path: string, page_id: int, title: string, views: int}>}|null
     */
    private function topPagesWidget(?User $user): ?array
    {
        if ($user === null || ! $user->can('analytics.view')) {
            return null;
        }

        // بازهٔ «۷ روز» = هفت روزِ تقویمی شاملِ امروز (تا پایان امروز)، نه
        // «۱۶۸ ساعتِ گذشته» — وگرنه حوالیِ نیمه‌شب بین دو بازدیدِ کارت می‌افتاد.
        $today = Carbon::today()->endOfDay();

        return [
            'days' => self::TOP_PAGES_DAYS,
            'items' => app(SiteAnalytics::class)->topPages(
                $today->copy()->subDays(self::TOP_PAGES_DAYS - 1)->startOfDay(),
                $today,
                self::TOP_PAGES_LIMIT,
            ),
        ];
    }

    /**
     * WF-H21 — پنج گامِ راه‌اندازی و وضعیت واقعی‌شان.
     *
     * @return array{logo: bool, page: bool, seo: bool, contact: bool, published: bool}
     */
    private function checklist(): array
    {
        return [
            // ۱) لوگو: فقط رسانهٔ انتخاب‌شده «واقعی» است؛ لوگوی پیش‌فرضِ برند حساب نمی‌شود.
            'logo' => $this->logoConfigured(),
            // ۲) اولین صفحه: هر ردیف صفحه (پیش‌نویس یا منتشر) یعنی کار شروع شده.
            'page' => Page::query()->exists(),
            // ۳) سئو: توضیح سایت **یا** متای عنوان/توضیحِ حداقل یک صفحه.
            'seo' => $this->seoConfigured(),
            // ۴) فرم تماس: حضور بلوک contact-form در بلوک‌های هر صفحه.
            'contact' => $this->contactFormPresent(),
            // ۵) اولین انتشار: وجود حداقل یک صفحهٔ منتشرشده.
            'published' => Page::query()->where('status', Page::STATUS_PUBLISHED)->exists(),
        ];
    }

    private function logoConfigured(): bool
    {
        $site = Setting::get('site', 'global', []) ?? [];

        return (int) ($site['logo_media_id'] ?? 0) > 0;
    }

    private function seoConfigured(): bool
    {
        $site = Setting::get('site', 'global', []) ?? [];
        if (trim((string) ($site['description'] ?? '')) !== '') {
            return true;
        }

        // مسیر JSON → بدون واکشی همهٔ ردیف‌ها، خودِ دیتابیس فیلتر می‌کند.
        return Page::query()
            ->where(function ($query): void {
                foreach (['title', 'description'] as $field) {
                    $query->orWhere(function ($inner) use ($field): void {
                        $inner->whereNotNull("meta->{$field}")->where("meta->{$field}", '!=', '');
                    });
                }
            })
            ->exists();
    }

    private function contactFormPresent(): bool
    {
        // jsonb @> '[{"type":"contact-form"}]' — همان کاری که `whereJsonContains`
        // روی Postgres می‌سازد. رشتهٔ خام نمی‌نویسیم تا کوئری قابل‌حمل بماند.
        return Page::query()
            ->whereJsonContains('blocks', [['type' => 'contact-form']])
            ->exists();
    }

    public function alerts(Request $request): JsonResponse
    {
        $alerts = [];

        // سهمیهٔ فضا در همهٔ نصب‌ها هست، پس هشدارِ نزدیک‌شدن به سقف هم هست.
        $quota = $this->quotaForTier('budget');
        $used = (int) Media::query()->sum('size');
        if ($quota > 0 && $used / $quota >= 0.8) {
            $alerts[] = [
                'type' => 'storage',
                'severity' => $used >= $quota ? 'critical' : 'warning',
                'message' => 'مصرف فضای فایل شما به سقف نزدیک شده است.',
                'action' => '/admin/media',
            ];
        }

        return response()->json(['data' => $alerts]);
    }

    /**
     * رجیستری ویجت‌های داشبورد پلاگین‌ها — فعلاً خالی با ساختار آماده.
     * پلاگین مرحله ۵ با همین شکل ثبت می‌کند: {key, title, component, order}.
     *
     * ⭐ ویجت‌های **هسته** اینجا نمی‌آیند (K6.10): هسته در `stats` می‌آید تا UI
     * به رجیستریِ افزونه‌ها وابسته نباشد.
     */
    public function widgets(): JsonResponse
    {
        return response()->json([
            'data' => [
                'registry_version' => '1.0',
                'widgets' => [],
                'slots' => ['top', 'main', 'side'],
            ],
        ]);
    }

    /** سقف فضای فایل هر تیر (بایت) — هم‌قرارداد با COST-TIERING تا تعیین نهایی. */
    private function quotaForTier(string $tier): int
    {
        return match ($tier) {
            'enterprise' => 20 * 1024 * 1024 * 1024,
            'standard' => 5 * 1024 * 1024 * 1024,
            default => 1024 * 1024 * 1024, // budget = ۱ گیگ
        };
    }
}
