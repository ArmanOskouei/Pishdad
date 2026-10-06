<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentImportMap;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;

/**
 * WF-C6 — درون‌بری محتوا از خروجی XML وردپرس (WXR).
 *
 * ## نگاشت
 *
 * هر `<item>` از نوع `page`/`post` به یک ردیف `pages` نگاشته می‌شود و HTMLِ
 * `content:encoded` به بلوک‌های هسته می‌شکند: `<blockquote>` → `quote`، تصویر
 * → `image` (اگر رسانه‌اش پیدا شود) و بقیه → `text`. دسته‌ها/برچسب‌ها در
 * `meta` می‌مانند و نویسنده به کاربرِ متناظر (ایمیل/نام) یا کاربرِ درون‌بری
 * می‌نگردد.
 *
 * ## idempotency
 *
 * پیش از ساخت هر مورد، `content_import_maps` چک می‌شود (`source=wxr`,
 * `source_id=post:{wp:post_id}`). درون‌بری دوبارهٔ همان فایل ⇒ همه `skipped` و
 * هیچ ردیف تکراری‌ای ساخته نمی‌شود.
 *
 * ## فایل‌های بزرگ — صادقانه
 *
 * صفِ امنِ پس‌زمینه برای این کار در هسته وجود ندارد و «صف‌بندیِ جعلی» یعنی
 * کاربر فکر کند کار در پس‌زمینه پیش می‌رود در حالی که رفته. پس: سقفِ سختِ
 * ۲۰ مگابایت (بیشتر ⇒ ۴۲۲ تمیز) و پردازشِ **جریانی** با `XMLReader` — هر
 * `<item>` جدا خوانده و جدا پردازش می‌شود، پس حافظه با اندازهٔ فایل رشد نمی‌کند.
 * گزارشِ واقعی (ساخته/ردشده/خطا) برگردانده می‌شود؛ هیچ پیشرفتِ ساختگی‌ای نیست.
 */
class ImportController extends Controller
{
    public const SOURCE = 'wxr';

    /** سقف آپلود (کیلوبایت) — بیشتر از این ۴۲۲ می‌گیرد، نه صفِ جعلی. */
    private const MAX_KB = 20480;

    /** سقفِ ایمنیِ تعداد آیتم‌های پردازش‌شده در یک درخواست. */
    private const MAX_ITEMS = 5000;

    private const MAX_ERROR_SAMPLES = 20;

    public function importWxr(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:'.self::MAX_KB,
        ], [
            'file.required' => 'فایل XML وردپرس را انتخاب کنید.',
            'file.max' => 'حجم فایل بیش از حد مجاز است (حداکثر ۲۰ مگابایت).',
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return response()->json(['message' => 'فایل آپلودشده قابل خواندن نیست.'], 422);
        }

        if (! $this->looksLikeWxr($path)) {
            return response()->json([
                'message' => 'این فایل یک خروجی XML وردپرس (WXR) به نظر نمی‌رسد.',
            ], 422);
        }

        $report = $this->process($path, (int) $request->user()->id);

        return response()->json([
            'message' => $report['created'] > 0
                ? "درون‌بری انجام شد: {$report['created']} مورد ساخته شد."
                : 'مورد تازه‌ای برای درون‌بری نبود.',
            'data' => $report,
        ]);
    }

    // ------------------------------------------------------------------
    // گذرها
    // ------------------------------------------------------------------

    /**
     * دو گذرِ جریانی روی فایل:
     *  ۱) جمع‌آوری پیوست‌ها + نگاشتِ رسانه‌های موجود (best-effort).
     *  ۲) ساخت صفحات/نوشته‌ها با نگاشت به بلوک‌های هسته.
     *
     * @return array<string, mixed>
     */
    private function process(string $path, int $userId): array
    {
        $attachments = [];
        $this->eachItem($path, function (array $item) use (&$attachments): void {
            if ($item['post_type'] === 'attachment' && $item['attachment_url'] !== '') {
                $attachments[$item['post_id']] = $item['attachment_url'];
            }
        });

        $mediaByUrl = [];
        foreach ($attachments as $sourceId => $url) {
            $mediaId = $this->resolveMedia($url);
            if ($mediaId === null) {
                continue;
            }
            $mediaByUrl[$url] = $mediaId;
            ContentImportMap::query()->updateOrCreate(
                ['source' => self::SOURCE, 'source_id' => 'attachment:'.$sourceId],
                ['target_type' => 'media', 'target_id' => $mediaId]
            );
        }

        $report = [
            'created' => 0,
            'skipped' => 0,
            'errors' => 0,
            'media_linked' => count($mediaByUrl),
            'media_unresolved' => 0,
            'authors' => 0,
            'categories' => 0,
            'truncated' => false,
            'error_samples' => [],
        ];

        $authorCache = [];
        $seen = 0;

        $this->eachItem($path, function (array $item) use (
            &$report,
            &$authorCache,
            &$seen,
            $attachments,
            $mediaByUrl,
            $userId,
        ): void {
            if ($seen >= self::MAX_ITEMS) {
                $report['truncated'] = true;

                return;
            }

            $type = $item['post_type'];
            if (! in_array($type, ['page', 'post'], true)) {
                return;
            }
            $seen++;

            $sourceId = 'post:'.$item['post_id'];
            if (ContentImportMap::findTarget(self::SOURCE, $sourceId, 'page') !== null) {
                $report['skipped']++;

                return;
            }

            try {
                $page = $this->createPage($item, $userId, $mediaByUrl, $attachments, $authorCache, $report);

                ContentImportMap::query()->create([
                    'source' => self::SOURCE,
                    'source_id' => $sourceId,
                    'target_type' => 'page',
                    'target_id' => $page->id,
                ]);

                $report['created']++;
                $report['categories'] += count($item['categories']);
            } catch (Throwable $e) {
                $report['errors']++;
                if (count($report['error_samples']) < self::MAX_ERROR_SAMPLES) {
                    $report['error_samples'][] = ($item['title'] !== '' ? $item['title'] : $sourceId).': '.$e->getMessage();
                }
            }
        });

        return $report;
    }

    /**
     * پیمایشِ جریانیِ `<item>`ها. هر آیتم جدا به یک آرایهٔ تخت تبدیل می‌شود و
     * بعد از پردازش رها می‌شود ⇒ حافظه محدود می‌ماند.
     *
     * @param  callable(array<string, mixed>): void  $callback
     */
    private function eachItem(string $path, callable $callback): void
    {
        $reader = new XMLReader();
        if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return;
        }

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'item') {
                    continue;
                }

                $outer = $reader->readOuterXml();
                $item = $this->parseItem($outer);
                if ($item !== null) {
                    $callback($item);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * یک `<item>` را به آرایهٔ تخت تبدیل می‌کند.
     *
     * از `local-name()` استفاده می‌شود (نه پیشوند) تا با نسخه‌های مختلفِ WXR
     * (1.0/1.1/1.2) کار کند. تفکیکِ `content:encoded` از `excerpt:encoded` با
     * `namespaceURI` انجام می‌شود.
     *
     * @return array<string, mixed>|null
     */
    private function parseItem(string $outer): ?array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if (! @$doc->loadXML($outer, LIBXML_NONET)) {
            return null;
        }

        $xp = new DOMXPath($doc);

        $local = function (string $name) use ($xp): string {
            $node = $xp->query("//*[local-name()='".$name."']")->item(0);

            return $node ? trim($node->textContent) : '';
        };

        $content = '';
        $excerpt = '';
        foreach ($xp->query("//*[local-name()='encoded']") as $node) {
            /** @var DOMElement $node */
            $ns = (string) $node->namespaceURI;
            if (str_contains($ns, 'purl.org/rss')) {
                $content = $node->textContent;
            } elseif (str_contains($ns, 'excerpt')) {
                $excerpt = $node->textContent;
            }
        }

        $categories = [];
        $tags = [];
        foreach ($xp->query("/*[local-name()='item']/*[local-name()='category']") as $node) {
            /** @var DOMElement $node */
            $name = trim($node->textContent);
            if ($name === '') {
                continue;
            }
            if ($node->getAttribute('domain') === 'post_tag') {
                $tags[] = $name;
            } else {
                $categories[] = $name;
            }
        }

        $postId = $local('post_id');
        $guid = $local('guid');
        if ($postId === '') {
            $postId = $guid !== '' ? substr(sha1($guid), 0, 16) : '';
        }

        return [
            'post_id' => $postId,
            'post_type' => $local('post_type'),
            'status' => $local('status'),
            'title' => $local('title'),
            'post_name' => $local('post_name'),
            'post_date' => $local('post_date'),
            'creator' => $local('creator'),
            'attachment_url' => $local('attachment_url'),
            'content' => $content,
            'excerpt' => $excerpt,
            'categories' => $categories,
            'tags' => $tags,
        ];
    }

    /**
     * یک آیتم را به `pages` + بلوک‌های هسته نگاشت می‌کند.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $mediaByUrl
     * @param  array<string, string>  $attachments
     * @param  array<string, int>  $authorCache
     * @param  array<string, mixed>  $report
     */
    private function createPage(
        array $item,
        int $userId,
        array $mediaByUrl,
        array $attachments,
        array &$authorCache,
        array &$report,
    ): Page {
        $authorId = $this->resolveAuthor((string) $item['creator'], $userId, $authorCache, $report);

        $blocks = $this->blocksFromHtml((string) $item['content'], $mediaByUrl, $attachments, $report);

        $slug = $this->uniqueSlug((string) $item['post_name'], (string) $item['post_id']);

        $meta = [];
        $excerpt = trim(strip_tags((string) $item['excerpt']));
        if ($excerpt !== '') {
            $meta['description'] = Str::limit($excerpt, 400, '');
        }
        if ($item['categories'] !== []) {
            $meta['categories'] = $item['categories'];
        }
        if ($item['tags'] !== []) {
            $meta['tags'] = $item['tags'];
        }
        if ((string) $item['post_date'] !== '') {
            $meta['imported_at'] = (string) $item['post_date'];
        }

        $title = (string) $item['title'] !== '' ? (string) $item['title'] : $slug;
        $isPublished = (string) $item['status'] === 'publish';

        $page = Page::query()->create([
            'user_id' => $authorId,
            'title' => $title,
            'slug' => $slug,
            'locale' => Page::LOCALE_DEFAULT,
            'status' => Page::STATUS_DRAFT,
            'is_single' => (string) $item['post_type'] === 'page',
            'blocks' => $blocks,
            'meta' => $meta !== [] ? $meta : null,
        ]);

        $revision = $page->snapshot($blocks, $meta !== [] ? $meta : null, $authorId, 'ورود از وردپرس');

        if ($isPublished) {
            $publishedAt = now();
            if ((string) $item['post_date'] !== '') {
                try {
                    $publishedAt = Carbon::parse((string) $item['post_date']);
                } catch (Throwable) {
                    $publishedAt = now();
                }
            }

            $page->forceFill([
                'status' => Page::STATUS_PUBLISHED,
                'published_revision_id' => $revision->id,
                'published_at' => $publishedAt,
            ])->save();
        }

        return $page;
    }

    /**
     * HTMLِ وردپرس → بلوک‌های هسته.
     *
     * @param  array<string, int>  $mediaByUrl
     * @param  array<string, string>  $attachments
     * @param  array<string, mixed>  $report
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    private function blocksFromHtml(string $html, array $mediaByUrl, array $attachments, array &$report): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $wrapped = '<?xml encoding="UTF-8"><div id="wxr-root">'.$html.'</div>';
        if (! @$doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
            return [['type' => 'text', 'data' => ['body' => $html]]];
        }

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return [['type' => 'text', 'data' => ['body' => $html]]];
        }

        $blocks = [];
        foreach (iterator_to_array($root->childNodes) as $node) {
            $this->appendNodeAsBlock($doc, $node, $blocks, $mediaByUrl, $attachments, $report);
        }

        return $blocks;
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @param  array<string, int>  $mediaByUrl
     * @param  array<string, string>  $attachments
     * @param  array<string, mixed>  $report
     */
    private function appendNodeAsBlock(
        DOMDocument $doc,
        DOMNode $node,
        array &$blocks,
        array $mediaByUrl,
        array $attachments,
        array &$report,
    ): void {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $blocks[] = ['type' => 'text', 'data' => ['body' => '<p>'.e($text).'</p>']];
            }

            return;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['script', 'style'], true)) {
            return;
        }

        if ($tag === 'blockquote') {
            $text = trim($node->textContent);
            if ($text !== '') {
                $blocks[] = ['type' => 'quote', 'data' => ['text' => mb_substr($text, 0, 2000)]];
            }

            return;
        }

        $images = [];
        if ($tag === 'img') {
            $images[] = $node;
        } else {
            foreach ($node->getElementsByTagName('img') as $img) {
                $images[] = $img;
            }
        }

        if ($images !== []) {
            foreach ($images as $img) {
                $this->appendImageBlock($img, $blocks, $mediaByUrl, $attachments, $report);
            }

            // تصاویر به بلوکِ `image` رفتند؛ متنِ باقی‌مانده بدونِ تکرارِ تصویر
            // به بلوکِ `text` می‌رود (وگرنه هر تصویر دو بار در محتوا می‌آمد).
            $clone = $node->cloneNode(true);
            foreach (iterator_to_array($clone->getElementsByTagName('img')) as $img) {
                $img->parentNode?->removeChild($img);
            }
            $remaining = trim($clone->textContent);
            if ($remaining !== '') {
                $html = (string) $doc->saveHTML($clone);
                if ($tag === 'a') {
                    $html = '<p>'.$html.'</p>';
                }
                $blocks[] = ['type' => 'text', 'data' => ['body' => $html]];
            }

            return;
        }

        $text = trim($node->textContent);
        if ($text === '') {
            return;
        }

        $html = (string) $doc->saveHTML($node);
        if ($tag === 'a') {
            $html = '<p>'.$html.'</p>';
        }
        $blocks[] = ['type' => 'text', 'data' => ['body' => $html]];
    }

    /**
     * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
     * @param  array<string, int>  $mediaByUrl
     * @param  array<string, string>  $attachments
     * @param  array<string, mixed>  $report
     */
    private function appendImageBlock(
        DOMNode $img,
        array &$blocks,
        array $mediaByUrl,
        array $attachments,
        array &$report,
    ): void {
        /** @var DOMElement $img */
        $src = trim($img->getAttribute('src'));
        $alt = trim($img->getAttribute('alt'));

        $mediaId = $this->resolveMediaFromSrc($src, $mediaByUrl, $attachments);
        if ($mediaId !== null) {
            $data = ['media_id' => $mediaId];
            if ($alt !== '') {
                $data['alt'] = $alt;
            }
            $blocks[] = ['type' => 'image', 'data' => $data];

            return;
        }

        // رسانه پیدا نشد (فایلِ باینری همراهِ WXR نیست): تصویر دور ریخته نمی‌شود،
        // به‌صورت HTML دورِ `<img>` داخل یک بلوک متنی حفظ می‌شود — صداقت محتوا
        // مهم‌تر از «تمیز بودنِ» ظاهری است.
        $report['media_unresolved']++;
        if ($src !== '') {
            $blocks[] = [
                'type' => 'text',
                'data' => ['body' => '<figure><img src="'.e($src).'" alt="'.e($alt).'"/></figure>'],
            ];
        }
    }

    /**
     * @param  array<string, int>  $mediaByUrl
     * @param  array<string, string>  $attachments
     */
    private function resolveMediaFromSrc(string $src, array $mediaByUrl, array $attachments): ?int
    {
        if ($src === '') {
            return null;
        }

        if (isset($mediaByUrl[$src])) {
            return $mediaByUrl[$src];
        }

        $base = basename((string) (parse_url($src, PHP_URL_PATH) ?: $src));
        if ($base === '') {
            return null;
        }

        foreach ($attachments as $url) {
            if (basename((string) (parse_url($url, PHP_URL_PATH) ?: $url)) === $base) {
                return $this->resolveMedia($url);
            }
        }

        return $this->resolveMedia($src);
    }

    /** نگاشتِ best-effortِ URLِ پیوست به رسانهٔ موجود (فایلِ باینری در WXR نیست). */
    private function resolveMedia(string $url): ?int
    {
        if ($url === '') {
            return null;
        }

        $base = basename((string) (parse_url($url, PHP_URL_PATH) ?: $url));
        if ($base === '') {
            return null;
        }

        $media = Media::query()
            ->where(function ($q) use ($base): void {
                $q->where('original_name', $base)->orWhere('path', 'like', '%'.$base);
            })
            ->orderBy('id')
            ->first();

        return $media ? (int) $media->id : null;
    }

    /**
     * @param  array<string, int>  $authorCache
     * @param  array<string, mixed>  $report
     */
    private function resolveAuthor(string $creator, int $fallback, array &$authorCache, array &$report): int
    {
        if ($creator === '') {
            return $fallback;
        }

        if (array_key_exists($creator, $authorCache)) {
            return $authorCache[$creator];
        }

        $user = User::query()
            ->where('email', $creator)
            ->orWhere('name', $creator)
            ->orderBy('id')
            ->first();

        if ($user !== null) {
            $authorCache[$creator] = (int) $user->id;
            $report['authors']++;

            return $authorCache[$creator];
        }

        $authorCache[$creator] = $fallback;

        return $fallback;
    }

    /** اسلاگِ یکتا per-locale؛ `post_name` وردپرس دست‌نخورده می‌ماند. */
    private function uniqueSlug(string $postName, string $postId): string
    {
        $base = trim($postName);
        if ($base === '') {
            $base = 'imported-'.($postId !== '' ? $postId : Str::random(6));
        }
        $base = mb_substr($base, 0, 180);

        $slug = $base;
        $i = 1;
        while (Page::query()->where('slug', $slug)->where('locale', Page::LOCALE_DEFAULT)->exists()) {
            $i++;
            $slug = $base.'-'.$i;
            if ($i > 50) {
                $slug = $base.'-'.Str::random(6);
                break;
            }
        }

        return $slug;
    }

    /** آیا فایل ریشهٔ RSS/RDF/Atom دارد؟ (تشخیصِ سبک WXR قبل از پردازش) */
    private function looksLikeWxr(string $path): bool
    {
        $reader = new XMLReader();
        if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return false;
        }

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                return in_array($reader->localName, ['rss', 'RDF', 'feed'], true);
            }
        } finally {
            $reader->close();
        }

        return false;
    }
}
