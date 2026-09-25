<?php

namespace App\Http\Controllers\Api\V1\Site;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Admin\SiteSettingsController;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\Theme;
use App\Services\Layouts\LinkItems;
use App\Services\Plugins\ManifestRegistry;
use App\Services\Settings\CachedSettings;
use App\Services\Subscription\ClientSubscriptionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * کروم عمومی سایت (هدر/فوتر واقعی برای رندر [...path]).
 * عمومی، سبک، بدون احراز هویت و بدون هیچ داده حساس:
 * فقط عنوان/توضیح/لوگو + لی‌آوت header/footer + شبکه‌های فعال + قالب فعال.
 * داده مدیریتی مشترک نصب تک‌سایتی است (کلیدهای global)؛ مالک اشتراک فقط
 * برای تشخیص «نصب دارای اشتراک» به کار می‌رود، نه برای تفکیک داده.
 */
class SiteChromeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $siteId = $request->query('site_id');
        $subscription = $siteId
            ? ClientSubscriptionResolver::bySiteId((string) $siteId)
            : ClientSubscriptionResolver::default();

        // تنظیمات کروم/برند در این CMS تک‌سایتی **سراسری نصب** است (کلید global)
        // و باید بین همه مدیران مشترک باشد. نبودِ اشتراک فقط یعنی «نصب تازه»، نه
        // «تنظیمات را دور بریز» — پس در این حالت هم داده ذخیره‌شده خوانده می‌شود و
        // فقط اگر واقعاً چیزی ذخیره نشده باشد به پیش‌فرض‌های خنثی می‌رسیم.
        $cacheKey = 'chrome-'.($siteId ?: 'default-global');

        $data = CachedSettings::remember('site_chrome', $cacheKey, 120, function () {
            $siteStored = Setting::get('site', 'global', []) ?? [];
            $headerStored = Setting::get('layout', 'global:header', []) ?? [];
            $footerStored = Setting::get('layout', 'global:footer', []) ?? [];

            // نصب کاملاً تازه: نه تنظیمی ذخیره شده، نه قالب فعالی → پیش‌فرض خنثی.
            $theme = Theme::query()->where('active', true)->first();
            if ($siteStored === [] && $headerStored === [] && $footerStored === [] && $theme === null) {
                return $this->defaults();
            }

            $site = array_merge(
                SiteSettingsController::SITE_DEFAULTS,
                $siteStored
            );
            $socials = Setting::get('socials', 'global', ['socials' => []]) ?? ['socials' => []];
            $header = array_merge(
                ['widgets' => [['type' => 'logo', 'settings' => []], ['type' => 'nav', 'settings' => []]], 'layout' => []],
                $headerStored
            );
            $footer = array_merge(
                // مبنا = پیش‌فرض فوتر قالب فعال (manifest.footer) وگرنه هسته؛
                // تنظیم ذخیره‌شده همیشه غلبه می‌کند.
                $theme
                    ? ManifestRegistry::footerBase()
                    : ['widgets' => [['type' => 'about', 'settings' => []], ['type' => 'copyright', 'settings' => []]], 'layout' => []],
                $footerStored
            );

            // صفحه خانه تنظیم‌شده (افزودنی): id + slug منتشرشده (برای روت `/` فرانت).
            $homepageId = $site['homepage_page_id'] ?? null;
            $homepageSlug = null;
            if (is_numeric($homepageId) && (int) $homepageId > 0) {
                $homepageSlug = \App\Models\Page::query()
                    ->where('id', (int) $homepageId)
                    ->where('status', \App\Models\Page::STATUS_PUBLISHED)
                    ->whereNotNull('published_revision_id')
                    ->value('slug');
            }

            // غنی‌سازی نمایشی media_id ویجت‌ها.
            // NOTE: ویجت logo ساده‌سازی شد (لوگو همیشه از تنظیمات سایت)؛
            // media_id قدیمی ذخیره‌شده در settings آن نادیده گرفته می‌شود.
            $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');
            $ids = [];
            foreach (['header' => $header, 'footer' => $footer] as $widgets) {
                foreach ($widgets['widgets'] ?? [] as $w) {
                    if (($w['type'] ?? null) === 'logo') {
                        continue;
                    }
                    $mid = $w['settings']['media_id'] ?? null;
                    if (is_numeric($mid) && (int) $mid > 0) {
                        $ids[] = (int) $mid;
                    }
                }
            }
            $paths = $ids === [] || $base === '' ? collect() : Media::query()
                ->whereIn('id', array_unique($ids))
                ->pluck('path', 'id');

            // صفحات منتشرشده نصب برای رزولو زنده آیتم‌های page (بازگشتی برای
            // منوی کشویی؛ تغییر نام/اسلاگ خودکار منعکس می‌شود).
            $linkPageIds = [];
            foreach (['header' => $header, 'footer' => $footer] as $areaName => $layout) {
                foreach ($layout['widgets'] ?? [] as $w) {
                    $isLinks = ($areaName === 'header' && ($w['type'] ?? null) === 'nav')
                        || ($areaName === 'footer' && ($w['type'] ?? null) === 'links');
                    if ($isLinks && is_array($w['settings']['links'] ?? null)) {
                        $linkPageIds = [...$linkPageIds, ...LinkItems::collectPageIds($w['settings']['links'])];
                    }
                }
            }
            $linkPages = $linkPageIds === [] ? collect() : Page::query()
                ->whereIn('id', array_unique($linkPageIds))
                ->where('status', Page::STATUS_PUBLISHED)
                ->whereNotNull('published_revision_id')
                ->get(['id', 'title', 'slug'])
                ->keyBy('id');

            $enrich = function (array $layout, string $areaName) use ($paths, $base, $linkPages): array {
                $widgets = collect($layout['widgets'] ?? [])->map(function (array $w) use ($paths, $base, $areaName, $linkPages) {
                    // لوگو همیشه از تنظیمات سایت است؛ کلیدهای قدیمی پاک می‌شوند.
                    if (($w['type'] ?? null) === 'logo') {
                        unset($w['settings']['media_id'], $w['settings']['size'], $w['settings']['media_url']);

                        return $w;
                    }
                    $mid = $w['settings']['media_id'] ?? null;
                    if (is_numeric($mid) && isset($paths[(int) $mid])) {
                        $w['settings']['media_url'] = $base.'/'.ltrim($paths[(int) $mid], '/');
                    }
                    // آیتم page → title/url زنده؛ خراب graceful رد می‌شود.
                    $isLinks = ($areaName === 'header' && ($w['type'] ?? null) === 'nav')
                        || ($areaName === 'footer' && ($w['type'] ?? null) === 'links');
                    if ($isLinks && is_array($w['settings']['links'] ?? null)) {
                        $w['settings']['links'] = LinkItems::resolve($w['settings']['links'], $linkPages);
                    }

                    return $w;
                })->values()->all();
                $out = ['widgets' => $widgets, 'layout' => $layout['layout'] ?? []];
                // ستون مؤثر فوتر: layout.columns معتبر وگرنه تعداد واقعی ستون‌ها (۱ تا ۴).
                if ($areaName === 'footer') {
                    $out['layout']['columns'] = LinkItems::effectiveFooterColumns($out);
                }

                return $out;
            };

            return [
                'title' => $site['title'] ?? 'وب‌سایت من',
                'description' => $site['description'] ?? '',
                'logo_url' => $this->mediaUrl($site['logo_media_id'] ?? null),
                'favicon_url' => $this->mediaUrl($site['favicon_media_id'] ?? null),
                'phone' => $site['phone'] ?? null,
                'email' => $site['email'] ?? null,
                // سئو/GEO عمومی (بدون داده حساس): canonical، تصویر OG، خلاصه AI، robots، نشانی.
                'site_url' => $site['site_url'] ?? null,
                'og_image_url' => $this->mediaUrl($site['og_image_media_id'] ?? null),
                'ai_summary' => $site['ai_summary'] ?? '',
                'robots_index' => (bool) ($site['robots_index'] ?? true),
                'address' => $site['address'] ?? null,
                'locale' => $site['locale'] ?? 'fa',
                'timezone' => $site['timezone'] ?? 'Asia/Tehran',
                'homepage_page_id' => is_numeric($homepageId) ? (int) $homepageId : null,
                'homepage_slug' => $homepageSlug,
                'header' => $enrich($header, 'header'),
                'footer' => $enrich($footer, 'footer'),
                'socials' => collect($socials['socials'] ?? [])
                    ->where('active', true)->values()->all(),
                'theme' => $theme ? [
                    'name' => $theme->name,
                    'slug' => $theme->slug,
                    'version' => $theme->version,
                    'globals' => $theme->manifest['globals'] ?? [],
                ] : null,
            ];
        });

        return response()->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60');
    }

    private function defaults(): array
    {
        return [
            'title' => 'وب‌سایت من',
            'description' => '',
            'logo_url' => null,
            'favicon_url' => null,
            'phone' => null,
            'email' => null,
            'site_url' => null,
            'og_image_url' => null,
            'ai_summary' => '',
            'robots_index' => true,
            'address' => null,
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
            'homepage_page_id' => null,
            'homepage_slug' => null,
            'header' => ['widgets' => [['type' => 'logo', 'settings' => []], ['type' => 'nav', 'settings' => []]], 'layout' => []],
            'footer' => ['widgets' => [['type' => 'about', 'settings' => []], ['type' => 'copyright', 'settings' => []]], 'layout' => []],
            'socials' => [],
            'theme' => null,
        ];
    }

    /** حل path لوگو به نشانی عمومی؛ ناموجود → null (بدون نشت path). */
    private function mediaUrl(mixed $mediaId): ?string
    {
        if (! is_numeric($mediaId) || (int) $mediaId <= 0) {
            return null;
        }
        $path = Media::query()
            ->where('id', (int) $mediaId)
            ->value('path');
        if (! $path) {
            return null;
        }
        $base = rtrim((string) config('filesystems.disks.s3.url', ''), '/');

        return $base === '' ? null : $base.'/'.ltrim($path, '/');
    }
}
