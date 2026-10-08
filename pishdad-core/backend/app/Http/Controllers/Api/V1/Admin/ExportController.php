<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\MediaUrl;
use DOMDocument;
use DOMElement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * WF-C6 — برون‌بری محتوا (JSON + WXR سازگار وردپرس).
 *
 * «کانال رشد = مهاجر وردپرس»: کسی که سایتش روی وردپرس است باید بتواند محتوا
 * را بیرون بکشد (WXR) و در این هسته بیاورد؛ و برعکس، پنل باید بتواند کل
 * محتوا را برای پشتیبان/انتقال برون‌بری کند.
 *
 * ## چرا JSON و WXR جدا
 *
 * - JSON: بستهٔ داخلیِ کامل (صفحات + بلوک‌ها + متا + تاریخچهٔ نسخه‌ها + رسانه +
 *   تنظیمات محتوایی). برای پشتیبان‌گیری/انتقال بین نصب‌های همین هسته.
 * - WXR: قالبِ رسمی وردپرس (RSS 2.0 + افزونه‌های `wp`/`content`/`dc`). برای
 *   مهاجرتِ واقعی به/از وردپرس؛ محتوای بلوک‌ها به HTML معنایی برمی‌گردد.
 *
 * ## چه چیزی عمداً بیرون نمی‌رود
 *
 * کلیدهای حساس (`ui` مخصوص هر مدیر، VAPID، seal) و شناسهٔ کاربر در JSON نمی‌آید.
 * تنظیمات فقط از گروه‌های محتوایی (`site`, `socials`, `layout`) صادر می‌شود.
 */
class ExportController extends Controller
{
    public const FORMAT = 'pishdad-content-bundle';

    public const VERSION = 1;

    /** گروه‌های تنظیماتِ محتوایی؛ تنظیمات کاربری/حساس صادر نمی‌شوند. */
    private const SETTING_GROUPS = ['site', 'socials', 'layout'];

    private const WP_NS = 'http://wordpress.org/export/1.2/';

    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    private const EXCERPT_NS = 'http://wordpress.org/export/1.2/excerpt/';

    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    /** بستهٔ JSON کامل: صفحات + بلوک‌ها + متا + تاریخچه + رسانه + تنظیمات. */
    public function exportJson(): JsonResponse
    {
        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'site' => $this->siteMeta(),
            'settings' => $this->settings(),
            'pages' => $this->pages(),
            'media' => $this->media(),
        ];

        return response()->json($bundle)
            ->header('Content-Disposition', 'attachment; filename="content-export-'.now()->format('Ymd-His').'.json"');
    }

    /** سند WXR 1.2 سازگار وردپرس از صفحاتِ منتشرشده و پیش‌نویس‌ها. */
    public function exportWxr(): Response
    {
        return response($this->buildWxr(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wordpress-export-'.now()->format('Ymd-His').'.xml"');
    }

    // ------------------------------------------------------------------
    // JSON
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function siteMeta(): array
    {
        $site = Setting::get('site', 'global', []);
        $site = is_array($site) ? $site : [];

        return [
            'title' => $site['title'] ?? null,
            'description' => $site['description'] ?? null,
            'url' => $site['site_url'] ?? null,
            'locale' => $site['locale'] ?? Page::LOCALE_DEFAULT,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function settings(): array
    {
        $out = [];

        foreach (self::SETTING_GROUPS as $group) {
            $rows = Setting::query()->where('group', $group)->get(['key', 'value']);
            $values = [];
            foreach ($rows as $row) {
                $values[(string) $row->key] = $row->value;
            }
            if ($values !== []) {
                $out[$group] = $values;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function pages(): array
    {
        return Page::query()
            ->with('revisions')
            ->orderBy('id')
            ->get()
            ->map(fn (Page $page): array => [
                'slug' => $page->slug,
                'title' => $page->title,
                'locale' => $page->locale,
                'status' => $page->status,
                'is_single' => (bool) $page->is_single,
                'blocks' => $page->blocks ?? [],
                'meta' => $page->meta,
                'published_at' => $page->published_at?->toIso8601String(),
                'created_at' => $page->created_at?->toIso8601String(),
                'revisions' => $page->revisions->map(fn ($revision): array => [
                    'version' => $revision->version,
                    'blocks' => $revision->blocks ?? [],
                    'meta' => $revision->meta,
                    'note' => $revision->note,
                    'created_at' => $revision->created_at?->toIso8601String(),
                ])->all(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function media(): array
    {
        return Media::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Media $m): array => [
                'id' => $m->id,
                'disk' => $m->disk,
                'path' => $m->path,
                'original_name' => $m->original_name,
                'mime' => $m->mime,
                'size' => $m->size,
                'alt' => $m->alt,
                'variants' => $m->variants,
            ])
            ->all();
    }

    // ------------------------------------------------------------------
    // WXR
    // ------------------------------------------------------------------

    private function buildWxr(): string
    {
        $site = Setting::get('site', 'global', []);
        $site = is_array($site) ? $site : [];
        $siteUrl = rtrim((string) ($site['site_url'] ?? ''), '/');

        $mediaUrl = [];
        foreach (Media::query()->get(['id', 'disk', 'path']) as $m) {
            $mediaUrl[(int) $m->id] = MediaUrl::for((string) $m->disk, (string) $m->path);
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        foreach ([
            'excerpt' => self::EXCERPT_NS,
            'content' => self::CONTENT_NS,
            'dc' => self::DC_NS,
            'wp' => self::WP_NS,
        ] as $prefix => $uri) {
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $uri);
        }
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $this->appendText($doc, $channel, 'title', (string) ($site['title'] ?? ''));
        $this->appendText($doc, $channel, 'link', $siteUrl);
        $this->appendText($doc, $channel, 'description', (string) ($site['description'] ?? ''));
        $this->appendText($doc, $channel, 'pubDate', now()->toRfc2822String());
        $this->appendText($doc, $channel, 'language', (string) ($site['locale'] ?? Page::LOCALE_DEFAULT));
        $this->appendNs($doc, $channel, self::WP_NS, 'wp:wxr_version', '1.2');
        $this->appendNs($doc, $channel, self::WP_NS, 'wp:base_site_url', $siteUrl);
        $this->appendNs($doc, $channel, self::WP_NS, 'wp:base_blog_url', $siteUrl);

        $pages = Page::query()->orderBy('id')->get();
        $authorIds = $pages->pluck('user_id')->filter()->unique()->values()->all();
        $authors = User::query()->whereIn('id', $authorIds)->get()->keyBy('id');
        $authorLogins = [];

        foreach ($authors as $author) {
            $login = $this->authorLogin($author->email ?? '', (int) $author->id);
            $authorLogins[(int) $author->id] = $login;

            $node = $doc->createElementNS(self::WP_NS, 'wp:author');
            $this->appendNs($doc, $node, self::WP_NS, 'wp:author_id', (string) $author->id);
            $this->appendNs($doc, $node, self::WP_NS, 'wp:author_login', $login);
            $this->appendNs($doc, $node, self::WP_NS, 'wp:author_email', (string) ($author->email ?? ''));
            $this->appendNs($doc, $node, self::WP_NS, 'wp:author_display_name', (string) ($author->name ?? $login));
            $channel->appendChild($node);
        }

        $categories = [];
        foreach ($pages as $page) {
            foreach ((array) (($page->meta ?? [])['categories'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $categories[$name] = true;
                }
            }
        }
        foreach (array_keys($categories) as $name) {
            $node = $doc->createElementNS(self::WP_NS, 'wp:category');
            $this->appendNs($doc, $node, self::WP_NS, 'wp:cat_name', $name);
            $channel->appendChild($node);
        }

        foreach ($pages as $page) {
            $channel->appendChild($this->pageToWxrItem($doc, $page, $mediaUrl, $siteUrl, $authorLogins));
        }

        return (string) $doc->saveXML();
    }

    private function pageToWxrItem(
        DOMDocument $doc,
        Page $page,
        array $mediaUrl,
        string $siteUrl,
        array $authorLogins,
    ): DOMElement {
        $item = $doc->createElement('item');

        $this->appendText($doc, $item, 'title', (string) $page->title);
        $this->appendText($doc, $item, 'link', $siteUrl !== '' ? $siteUrl.'/'.$page->slug : '/'.$page->slug);
        $this->appendText(
            $doc,
            $item,
            'pubDate',
            ($page->published_at ?? $page->created_at)?->toRfc2822String() ?? now()->toRfc2822String()
        );

        $login = $authorLogins[(int) $page->user_id] ?? $this->authorLogin('', (int) $page->user_id);
        $this->appendNs($doc, $item, self::DC_NS, 'dc:creator', $login);

        $guid = $this->appendText($doc, $item, 'guid', $siteUrl !== '' ? $siteUrl.'/?page_id='.$page->id : '/?page_id='.$page->id);
        $guid->setAttribute('isPermaLink', 'false');

        $this->appendText($doc, $item, 'description', '');
        $this->appendNs($doc, $item, self::CONTENT_NS, 'content:encoded', $this->blocksToHtml($page->blocks ?? [], $mediaUrl, $siteUrl));
        $this->appendNs($doc, $item, self::EXCERPT_NS, 'excerpt:encoded', (string) (($page->meta ?? [])['description'] ?? ''));

        $this->appendNs($doc, $item, self::WP_NS, 'wp:post_id', (string) $page->id);
        $this->appendNs($doc, $item, self::WP_NS, 'wp:post_date', ($page->created_at ?? now())->format('Y-m-d H:i:s'));
        $this->appendNs($doc, $item, self::WP_NS, 'wp:post_name', (string) $page->slug);
        $this->appendNs($doc, $item, self::WP_NS, 'wp:status', $page->status === Page::STATUS_PUBLISHED ? 'publish' : 'draft');
        $this->appendNs($doc, $item, self::WP_NS, 'wp:post_type', $page->is_single ? 'page' : 'post');
        $this->appendNs($doc, $item, self::WP_NS, 'wp:post_parent', '0');

        foreach ((array) (($page->meta ?? [])['categories'] ?? []) as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $cat = $doc->createElement('category');
            $cat->setAttribute('domain', 'category');
            $cat->setAttribute('nicename', $this->authorLogin($name, 0));
            $cat->appendChild($doc->createTextNode($name));
            $item->appendChild($cat);
        }

        return $item;
    }

    /**
     * بلوک‌های هسته → HTML معنایی برای `content:encoded`.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, string|null>  $mediaUrl
     */
    private function blocksToHtml(array $blocks, array $mediaUrl, string $siteUrl): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            switch ($type) {
                case 'hero':
                    if (! empty($data['title'])) {
                        $parts[] = '<h1>'.e((string) $data['title']).'</h1>';
                    }
                    if (! empty($data['subtitle'])) {
                        $parts[] = '<p>'.e((string) $data['subtitle']).'</p>';
                    }
                    $parts[] = $this->imgHtml((int) ($data['image_id'] ?? 0), $mediaUrl, $siteUrl, '');
                    break;

                case 'text':
                    $parts[] = (string) ($data['body'] ?? '');
                    break;

                case 'image':
                    $parts[] = $this->imgHtml((int) ($data['media_id'] ?? 0), $mediaUrl, $siteUrl, (string) ($data['alt'] ?? ''));
                    break;

                case 'gallery':
                    foreach ((array) ($data['media_ids'] ?? []) as $id) {
                        $parts[] = $this->imgHtml((int) $id, $mediaUrl, $siteUrl, '');
                    }
                    break;

                case 'cta':
                    if (! empty($data['label']) && ! empty($data['href'])) {
                        $parts[] = '<p><a href="'.e((string) $data['href']).'">'.e((string) $data['label']).'</a></p>';
                    }
                    break;

                case 'quote':
                    if (! empty($data['text'])) {
                        $parts[] = '<blockquote><p>'.e((string) $data['text']).'</p>'
                            .(! empty($data['author']) ? '<cite>'.e((string) $data['author']).'</cite>' : '')
                            .'</blockquote>';
                    }
                    break;

                case 'video':
                    if (! empty($data['url'])) {
                        $parts[] = '<p><a href="'.e((string) $data['url']).'">'.e((string) $data['url']).'</a></p>';
                    }
                    break;

                case 'faq':
                    foreach ((array) ($data['items'] ?? []) as $entry) {
                        if (! is_array($entry)) {
                            continue;
                        }
                        if (! empty($entry['q'])) {
                            $parts[] = '<h3>'.e((string) $entry['q']).'</h3>';
                        }
                        if (! empty($entry['a'])) {
                            $parts[] = '<p>'.e((string) $entry['a']).'</p>';
                        }
                    }
                    break;

                case 'contact-form':
                    $parts[] = '<h3>'.e((string) ($data['title'] ?? 'فرم تماس')).'</h3>';
                    break;

                default:
                    break;
            }
        }

        return implode("\n", array_filter($parts, fn ($p) => is_string($p) && trim($p) !== ''));
    }

    /** @param array<int, string|null> $mediaUrl */
    private function imgHtml(int $mediaId, array $mediaUrl, string $siteUrl, string $alt): string
    {
        if ($mediaId <= 0) {
            return '';
        }

        $url = $mediaUrl[$mediaId] ?? null;
        if (! is_string($url) || $url === '') {
            return '';
        }

        if ($siteUrl !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = $siteUrl.'/'.ltrim($url, '/');
        }

        return '<figure><img src="'.e($url).'" alt="'.e($alt).'"/></figure>';
    }

    private function appendText(DOMDocument $doc, DOMElement $parent, string $name, string $value): DOMElement
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }

    private function appendNs(DOMDocument $doc, DOMElement $parent, string $ns, string $qualified, string $value): DOMElement
    {
        $node = $doc->createElementNS($ns, $qualified);
        $node->appendChild($doc->createTextNode($value));
        $parent->appendChild($node);

        return $node;
    }

    private function authorLogin(string $email, int $fallbackId): string
    {
        $email = trim($email);
        if ($email !== '') {
            $local = strstr($email, '@', true);

            return is_string($local) && $local !== '' ? $local : $email;
        }

        return $fallbackId > 0 ? 'user'.$fallbackId : 'admin';
    }
}
