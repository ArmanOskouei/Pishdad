<?php

namespace App\Services\Plugins;

use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * K5.2-W / K0.4 — مهر یکپارچگی روی **فایل‌های استخراج‌شده**، نه روی ZIP.
 *
 * ## چرا این نیمِ بازِ K0.4 یک سرویس تازه می‌خواهد
 *
 * چیزی که `K0.4` تا امروز تضمین می‌کرد، هشِ فایلِ ZIP بود. این هش **قبل از
 * استخراج** گرفته و در `plugins.checksum` می‌نشیند. پس هر دستکاریِ بعدی روی
 * فایل‌های روی دیسک — یک بایت در یک `src/…php`، یک `routes/api.php` که مسیر
 * `/admin` را اضافه می‌کند، یک فایل که کلاً تازه است — هیچ اثری روی آن عدد
 * نمی‌گذارد. هش یکپارچگیِ چیزی را ثابت می‌کند که **دیگر وجود ندارد**.
 *
 * آنچه این سرویس اضافه می‌کند: یک فهرستِ قطعی از فایل‌های روی دیسک و امضای
 * Ed25519 روی آن، با کلیدی که **per-install** است و در دیتابیس (`settings`)
 * می‌نشیند، نه کنار فایل‌ها. پس مهاجمی که به دیسک دسترسی دارد می‌تواند فایل و
 * حتی خودِ مهر را پاک یا جایگزین کند، ولی **نمی‌تواند مهر معتبر بسازد**.
 *
 * ## چرا مهر **کنارِ** نسخه است، نه داخلش
 *
 * مسیر: `storage/app/plugins/{slug}/releases/{label}.seal`.
 *
 *  ۱. **داخلِ پوشهٔ نسخه، یک فایلِ اضافه روی دیسک است** و شمارشِ فایل‌های
 *     استخراج‌شده را می‌شکند. تست‌های `PluginInstallerTest` شمارشِ دقیقِ درختِ
 *     نسخه را می‌سنجند؛ یک فایلِ بی‌ربط آن‌ها را بی‌دلیل قرمز می‌کند و بدتر،
 *     یک قراردادِ امنیتیِ ناخواسته می‌سازد («مهر، جزو بسته است»).
 *  ۲. **مهر، جزو بسته نیست.** اگر داخل ZIP می‌آمد، مهاجم آن را هم بازنویسی
 *     می‌کرد و نوشتنش به‌معنای «این فایل از ناشر آمده» گمراه‌کننده می‌شد.
 *
 * هزینه‌اش: مهر و پوشه دو نوشتنِ جدا هستند و یک crash بینشان، نسخه‌ای بدون مهر
 * می‌سازد. این **عمداً** وضعیتِ `unsealed` است، نه وضعیتِ خراب: بازبینی آن را
 * «تأییدنشده» می‌خواند و `reseal()` در یک فرمانِ واحد همه را درست می‌کند.
 *
 * ## فهرستِ قطعی چگونه ساخته می‌شود
 *
 * برای هر فایل: `relpath \0 sha256hex`، همه با `strcmp` روی **همان رشته**
 * مرتب، و کلِ آن با `\n` به هم چسبیده. `\0` جداکننده است چون در مسیرِ نسبیِ
 * مجازِ افزونه هرگز نمی‌آید (دروازهٔ `PluginPackageContract::normalizePath()`
 * NUL را رد می‌کند) — یعنی یک مسیرِ دستکاری‌شده نمی‌تواند مرزِ فیلد را جابه‌جا
 * کند و هشِ متفاوتی بسازد.
 *
 * ⚠️ **مرتب‌سازی روی رشتهٔ کامل، نه روی مسیر.** اگر مرتب‌سازی روی مسیر با
 * قاعده‌ای غیر از `strcmp` انجام شود — مثلاً `natsort` یا `sort()` محلیِ
 * متفاوت — دو اجرا روی همان فایل‌ها دو فهرست می‌دهند و هر بار «دستکاری» به
 * نظر می‌رسد. `strcmp` باینری است و به locale وابسته نیست.
 *
 * ## چرا `unsealed` در dispatch نمی‌بندد ولی `mismatch` می‌بندد
 *
 * نصب‌های موجود (پیش از این تسک) مهر ندارند. اگر نبودِ مهر را fail-closed
 * می‌کردیم، هر نصبِ کارِ خودِ کاربر بعد از ارتقای هسته می‌مرد — یعنی امنیت به
 * قیمتِ در دسترس‌نبودن. پس نبودِ مهر یعنی **«هنوز تأییدنشده»** و صریحاً همین
 * گفته می‌شود.
 *
 * ولی **مهرِ هست که به محتوا نمی‌خواند** یعنی دستکاریِ قطعی. آن fail-closed است:
 * اجرا متوقف می‌شود، پیام فارسی می‌رود و لاگ می‌شود. تفاوت این دو، تفاوتِ
 * «نمی‌دانیم» با «می‌دانیم خراب است» است، و fail-closed فقط برای دومی معنا دارد.
 *
 * ## هزینهٔ بازبینی در هر درخواست
 *
 * هش کردنِ همهٔ فایل‌ها در هر dispatch گران است (تا سقفِ ۵۰۰۰ فایل). پس یک
 * **یادداشتِ کوتاه‌عمر** هست: امضای آماری (`size` + `mtime` هر فایل، مرتب و
 * هش‌شده) خیلی ارزان است و در ۶۰ ثانیه معتبر می‌ماند. اگر امضای آماری عوض شده
 * باشد، هشِ کامل دوباره حساب می‌شود.
 *
 * ⛔ **سقفِ این یادداشت، یک حدّ صادقانه است که باید گفته شود:** کسی که بتواند هم
 * اندازه و هم mtime یک فایل را نگه دارد و فقط یک بایت را عوض کند، تا پایانِ ۶۰
 * ثانیه دیده نمی‌شود. به همین دلیل `activate()` و `upgrade()` **همیشه** هشِ
 * کامل می‌گیرند و هیچ یادداشتی ندارند — یعنی مرزِ واقعیِ این سیستم «فعال‌سازی»
 * است، نه «هر درخواست».
 */
final class PluginIntegritySeal
{
    /** پسوندِ مهر. نقطه‌دار نیست تا `releases()` آن را نسخه نشمارد. */
    public const SEAL_SUFFIX = '.seal';

    /** نسخهٔ قالبِ مهر. عمداً عدد است: تغییر شکل، بازبینیِ همه را نامعتبر می‌کند. */
    public const FORMAT = 1;

    /** مدت اعتبار یادداشتِ بازبینیِ سریع (ثانیه). */
    public const MEMO_TTL = 60;

    /** @param  PluginReleaseManager|null  $releases  تزریق‌پذیر برای تست. `null` یعنی از container. */
    public function __construct(
        private readonly ?PluginReleaseManager $releases = null,
        private readonly ?PluginTrustStore $trustStore = null,
    ) {}

    // این دو، memoize هستند نه `readonly` — و دلیلش فنی است: یک propertyِ
    // `readonly`ِ ارتقاپذیر را **نمی‌شود** تنبل پر کرد. اولین فراخوانی
    // `Error: Cannot modify readonly property` می‌داد، یعنی هر resolve از
    // container یک ۵۰۰ در مسیر dispatch.
    private ?PluginReleaseManager $resolvedReleases = null;

    private ?PluginTrustStore $resolvedTrustStore = null;

    public function releases(): PluginReleaseManager
    {
        return $this->releases ?? $this->resolvedReleases ??= app(PluginReleaseManager::class);
    }

    public function trustStore(): PluginTrustStore
    {
        return $this->trustStore ?? $this->resolvedTrustStore ??= app(PluginTrustStore::class);
    }

    // ── مسیرها ──────────────────────────────────────────────────────────────

    /** مسیرِ مهرِ یک نسخه. کنارِ پوشهٔ نسخه، نه داخلش. */
    public function sealPath(string $slug, string $label): string
    {
        $slug = $this->releases()->normalizeSlug($slug);

        return $this->releases()->releaseDir($slug).'/'.$label.self::SEAL_SUFFIX;
    }

    /**
     * مسیرِ نسخه از روی برچسب، یا null اگر چیزی روی دیسک نبود.
     *
     * عمداً از `resolveRelease()` (که private است) استفاده نمی‌کنیم و به آن
     * تکیه هم نمی‌بریم: این سرویس باید بتواند نسخه‌ای را که هنوز فعال نیست هم
     * بازبینی کند، و آن مسیر از `releases()` می‌آید نه از اشاره‌گر.
     */
    public function releaseDir(string $slug, string $label): ?string
    {
        $slug = $this->releases()->normalizeSlug($slug);
        $base = $this->releases()->releaseDir($slug);

        if (! is_dir($base)) {
            return null;
        }

        $candidate = realpath($base.'/'.$this->assertLabel($label));
        $baseReal = realpath($base);

        if ($candidate === false || $baseReal === false || ! is_dir($candidate)) {
            return null;
        }

        if (! str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $baseReal), '/').'/')) {
            return null;
        }

        return str_replace('\\', '/', $candidate);
    }

    // ── زدنِ مهر ─────────────────────────────────────────────────────────────

    /**
     * مهر را روی فایل‌های **روی دیسک** می‌زند.
     *
     * عمداً بعد از `rename()` صدا زده می‌شود، نه داخل staging. اگر داخل staging
     * بود، مهر باید داخلِ پوشهٔ نسخه می‌نشست و دو مسئله درست می‌شد: شمارشِ
     * فایل‌های استخراج‌شده می‌شکست، و یک خطای نوشتنِ مهر کلِ نصبِ سالم را
     * بی‌دلیل رد می‌کرد.
     *
     * @return array{ok: bool, code: string, message: string, seal: ?string, files: int, digest: ?string}
     */
    public function seal(string $slug, string $releaseDir, ?string $label = null): array
    {
        $slug = $this->releases()->normalizeSlug($slug);
        $label = $label ?? $this->labelOf($releaseDir);

        try {
            $manifest = $this->manifestFor($releaseDir);
        } catch (RuntimeException $e) {
            Log::warning('plugin.seal_failed', ['slug' => $slug, 'release' => $label, 'reason' => $e->getMessage()]);

            return self::sealFailure('seal.unreadable', $e->getMessage());
        }

        if (! $manifest['ok']) {
            Log::warning('plugin.seal_failed', [
                'slug' => $slug, 'release' => $label, 'code' => $manifest['code'],
            ]);

            return self::sealFailure($manifest['code'], $manifest['message']);
        }

        $payload = self::payload($slug, $label, $manifest['digest']);

        try {
            $secret = $this->trustStore()->ensureSealKey($slug);
            $signature = sodium_crypto_sign_detached($payload, (string) base64_decode($secret, true));
        } catch (\Throwable $e) {
            Log::error('plugin.seal_key_error', ['slug' => $slug, 'error' => $e->getMessage()]);

            return self::sealFailure('seal.key_error', 'کلید مهر یکپارچگی ساخته نشد.');
        }

        $body = [
            'format' => self::FORMAT,
            'algorithm' => 'ed25519',
            'slug' => $slug,
            'release' => $label,
            'files' => $manifest['count'],
            'manifest_sha256' => $manifest['digest'],
            'signature' => base64_encode($signature),
            'signed_at' => gmdate('c'),
        ];

        $path = $this->sealPath($slug, $label);
        $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));

        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($tmp, $json, LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);

            Log::warning('plugin.seal_write_failed', ['slug' => $slug, 'release' => $label]);

            return self::sealFailure('seal.write_failed', 'مهر یکپارچگی روی دیسک نوشته نشد.');
        }

        $this->forgetMemo($slug, $label);

        return [
            'ok' => true,
            'code' => 'ok',
            'message' => "مهر یکپارچگی برای «{$label}» زده شد ({$manifest['count']} فایل).",
            'seal' => $path,
            'files' => $manifest['count'],
            'digest' => $manifest['digest'],
        ];
    }

    /**
     * مهرِ موجود را از نو می‌زند — مسیرِ صریحِ «unsealed ⇒ verified».
     *
     * تنها کاری که این متد می‌کند این است که دوباره فهرست می‌گیرد و امضا
     * می‌کند. یعنی **قبل از** فراخوانی‌اش باید فهمیده باشی که فایل‌ها همان‌اند؛
     * روی یک نصبِ واقعاً دستکاری‌شده این کار بدیهی نیست. دلیلش این است که
     * «دوباره مهر بزن» نباید یک میان‌برِ دور زدنِ بازبینی باشد.
     *
     * @return array{ok: bool, code: string, message: string, seal: ?string, files: int, digest: ?string}
     */
    public function reseal(string $slug, string $label): array
    {
        $slug = $this->releases()->normalizeSlug($slug);
        $dir = $this->releaseDir($slug, $this->assertLabel($label));

        if ($dir === null) {
            return self::sealFailure('seal.no_release', "نسخهٔ «{$label}» روی دیسک نیست.");
        }

        $result = $this->seal($slug, $dir, $label);
        Log::info('plugin.seal_resealed', ['slug' => $slug, 'release' => $label, 'ok' => $result['ok']]);

        return $result;
    }

    // ── بازبینی ─────────────────────────────────────────────────────────────

    /**
     * بازبینیِ کامل: امضا + فهرستِ فایل‌ها. هیچ یادداشتی در کار نیست.
     *
     * @return array{ok: bool, status: string, code: string, reason: string, release: ?string, files: int, digest: ?string}
     */
    public function verify(string $slug, string $label): array
    {
        $slug = $this->releases()->normalizeSlug($slug);

        try {
            $label = $this->assertLabel($label);
        } catch (InvalidArgumentException $e) {
            return self::verdict(false, 'invalid_label', 'seal.invalid_label', $e->getMessage());
        }

        $dir = $this->releaseDir($slug, $label);

        if ($dir === null) {
            return self::verdict(false, 'no_release', 'seal.no_release', "نسخهٔ «{$label}» روی دیسک نیست.");
        }

        $sealPath = $this->sealPath($slug, $label);

        if (! is_file($sealPath)) {
            // عمداً «تأییدنشده»، نه «معتبر». کلمهٔ دوم یعنی ادعای چیزی که
            // هنوز مدرکی برایش نیست.
            return self::verdict(false, 'unsealed', 'seal.unsealed', 'این نسخه هنوز مهر یکپارچگی ندارد.');
        }

        $body = json_decode((string) @file_get_contents($sealPath), true);

        if (! is_array($body) || ($body['format'] ?? null) !== self::FORMAT || ($body['algorithm'] ?? null) !== 'ed25519') {
            Log::warning('plugin.seal_unreadable', ['slug' => $slug, 'release' => $label]);

            return self::verdict(false, 'invalid_seal', 'seal.unreadable', 'مهر یکپارچگی خوانده نشد یا قالبش ناشناخته است.');
        }

        // هویتِ مهر باید همان هویتِ نسخه‌ای باشد که می‌خواهیم بازبینی کنیم.
        // وگرنه یک مهرِ سالمِ نسخهٔ دیگر را می‌شد اینجا کاشت.
        if (($body['slug'] ?? null) !== $slug || ($body['release'] ?? null) !== $label) {
            Log::warning('plugin.seal_identity_mismatch', ['slug' => $slug, 'release' => $label]);

            return self::verdict(false, 'identity_mismatch', 'seal.identity_mismatch', 'مهر یکپارچگی متعلق به این نسخه نیست.');
        }

        $signature = base64_decode((string) ($body['signature'] ?? ''), true);

        if (! is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return self::verdict(false, 'invalid_signature', 'seal.invalid_signature', 'امضای مهر یکپارچگی نامعتبر است.');
        }

        $digest = (string) ($body['manifest_sha256'] ?? '');

        if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            return self::verdict(false, 'invalid_seal', 'seal.unreadable', 'خلاصهٔ فهرست در مهر معتبر نیست.');
        }

        $payload = self::payload($slug, $label, $digest);

        try {
            $secret = $this->trustStore()->ensureSealKey($slug);
            $public = sodium_crypto_sign_publickey_from_secretkey((string) base64_decode($secret, true));
        } catch (\Throwable $e) {
            Log::error('plugin.seal_key_error', ['slug' => $slug, 'error' => $e->getMessage()]);

            return self::verdict(false, 'key_error', 'seal.key_error', 'کلید مهر یکپارچگی در دسترس نیست.');
        }

        // اول امضا، بعد محتوا. اگر امضا نخورد، هر چیزی در ادامه گمراه‌کننده
        // است — پس زودتر و ارزان‌ترین جا ممکن متوقف می‌شویم.
        if (! sodium_crypto_sign_verify_detached($signature, $payload, $public)) {
            Log::warning('plugin.seal_signature_invalid', ['slug' => $slug, 'release' => $label]);

            return self::verdict(false, 'invalid_signature', 'seal.invalid_signature', 'امضای مهر یکپارچگی معتبر نیست.');
        }

        try {
            $manifest = $this->manifestFor($dir);
        } catch (RuntimeException $e) {
            return self::verdict(false, 'mismatch', 'seal.mismatch', $e->getMessage());
        }

        if (! $manifest['ok']) {
            return self::verdict(false, 'mismatch', $manifest['code'], $manifest['message']);
        }

        if (! hash_equals($digest, $manifest['digest'])) {
            Log::warning('plugin.seal_content_mismatch', [
                'slug' => $slug,
                'release' => $label,
                'sealed_files' => $body['files'] ?? null,
                'disk_files' => $manifest['count'],
            ]);

            return self::verdict(false, 'mismatch', 'seal.mismatch', 'فایل‌های روی دیسک با مهر یکپارچگی نمی‌خواند.');
        }

        return self::verdict(true, 'verified', 'ok', '', $label, $manifest['count'], $manifest['digest']);
    }

    /**
     * دروازهٔ پیش از dispatch.
     *
     * سه حالتِ متمایز که **نباید** یکی شوند:
     *  - `verified`  ⇒ اجازهٔ اجرا.
     *  - `unsealed`  ⇒ اجازهٔ اجرا، ولی صادقانه: «هنوز تأییدنشده» + لاگ.
     *  - `mismatch`  ⇒ **fail-closed**: هیچ کدی از این بسته اجرا نمی‌شود.
     *
     * @return array{ok: bool, status: string, code: string, reason: string, release: ?string, files: int, digest: ?string}
     */
    public function guardForDispatch(string $slug): array
    {
        $slug = $this->releases()->normalizeSlug($slug);
        $dir = $this->releases()->currentDir($slug);

        if ($dir === null) {
            return self::verdict(true, 'no_release', 'ok', 'نسخهٔ فعالی روی دیسک نیست.');
        }

        $label = basename(str_replace('\\', '/', $dir));

        try {
            $stat = $this->statSignature($dir);
        } catch (RuntimeException $e) {
            return self::verdict(false, 'mismatch', 'seal.mismatch', $e->getMessage());
        }

        $memoKey = $this->memoKey($slug, $label);

        // یادداشت فقط وقتی معتبر است که **همان امضای آماری** را داشته باشد.
        $memo = Cache::get($memoKey);
        if (is_array($memo) && isset($memo['stat'], $memo['digest']) && hash_equals((string) $memo['stat'], $stat)) {
            return self::verdict(true, 'verified', 'ok', '', $label, (int) ($memo['files'] ?? 0), (string) $memo['digest']);
        }

        $verdict = $this->verify($slug, $label);

        if ($verdict['ok']) {
            Cache::put($memoKey, [
                'stat' => $stat,
                'digest' => $verdict['digest'],
                'files' => $verdict['files'],
            ], self::MEMO_TTL);

            return $verdict;
        }

        if ($verdict['status'] === 'unsealed') {
            // ⚠️ **این‌جا `ok` عمداً true می‌شود** و وضعیت `unsealed` می‌ماند.
            //
            // fail-closed برای نبودِ مهر یعنی هر ارتقای هسته همهٔ نصب‌های موجود
            // را می‌اندازد — امنیت به قیمتِ در دسترس‌نبودن. آنچه نباید از دست
            // برود صداقت است، و صداقت اینجا یعنی «می‌دانیم تأیید نشده»، که در
            // همین `status` و همین `reason` گفته شده.
            //
            // ضدّسیل: این فقط یک بار در هر نسخه لاگ می‌شود، نه در هر درخواست.
            $this->noticeOnce($slug, $label, $verdict['reason']);

            $verdict['ok'] = true;

            return $verdict;
        }

        return $verdict;
    }

    // ── فهرستِ قطعی ─────────────────────────────────────────────────────────

    /**
     * فهرستِ قطعیِ فایل‌های یک ریشه.
     *
     * @return array{ok: bool, count: int, digest: string, code: ?string, message: ?string}
     *
     * @throws RuntimeException اگر ریشه قابل‌پیمایش نباشد
     */
    public function manifestFor(string $root): array
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('پوشهٔ نسخه قابل‌پیمایش نیست.');
        }

        $root = str_replace('\\', '/', $real);
        $entries = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            // SELF_FIRST لازم است: بدون آن symlinkِ یک پوشه اصلاً دیده نمی‌شود
            // و فهرست «تمیز» به نظر می‌رسد در حالی که یک پیوند به بیرون روی دیسک است.
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                return self::manifestFailure('seal.symlink', 'پوشهٔ نسخه حاوی symlink است.');
            }

            if ($file->isDir()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            // مرز: هیچ چیزی بیرون از ریشه در فهرست نیاید. این هم یک symlinkِ
            // نیست که `isLink()` نگرفته باشد — یک خطای مسیر است.
            if (! str_starts_with($path, rtrim($root, '/').'/')) {
                return self::manifestFailure('seal.escaped_root', 'فایلی بیرون از ریشهٔ نسخه دیده شد.');
            }

            $hash = @hash_file('sha256', $path);

            if (! is_string($hash)) {
                return self::manifestFailure('seal.unreadable', 'یکی از فایل‌های نسخه خوانده نشد.');
            }

            $entries[] = ltrim(substr($path, strlen($root)), '/')."\0".$hash;
        }

        // سقفِ همان `PluginPackageContract` — نسخه‌ای با فایل‌های بیشتر از
        // سقفِ نصب، از تعریفِ بسته بیرون است و نباید «سالم» شمرده شود.
        if (count($entries) > PluginPackageContract::MAX_FILES) {
            return self::manifestFailure('seal.too_many_files', 'تعداد فایل‌های نسخه از سقف مجاز فراتر است.');
        }

        if ($entries === []) {
            return self::manifestFailure('seal.empty', 'نسخه هیچ فایلی ندارد.');
        }

        // `usort` با `strcmp`: مرتب‌سازیِ باینری و مستقل از locale. هر قاعدهٔ
        // دیگری این‌جا یعنی «هر اجرا یک هشِ متفاوت» و بازبینیِ همیشه قرمز.
        usort($entries, static fn (string $a, string $b): int => strcmp($a, $b));

        return [
            'ok' => true,
            'count' => count($entries),
            'digest' => hash('sha256', implode("\n", $entries)),
            'code' => null,
            'message' => null,
        ];
    }

    /**
     * امضای آماری: `relpath \0 size \0 mtime` برای هر فایل، مرتب و هش‌شده.
     *
     * ارزان است (فقط `stat`، بدون خواندنِ بایت‌ها) و برای یادداشتِ بازبینی
     * کافی است: یک بایت عوض‌کردن معمولاً mtime را عوض می‌کند.
     *
     * @throws RuntimeException اگر ریشه قابل‌پیمایش نباشد
     */
    private function statSignature(string $root): string
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('پوشهٔ نسخه قابل‌پیمایش نیست.');
        }

        $root = str_replace('\\', '/', $real);
        $entries = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('پوشهٔ نسخه حاوی symlink است.');
            }

            if ($file->isDir()) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (! str_starts_with($path, rtrim($root, '/').'/')) {
                throw new RuntimeException('فایلی بیرون از ریشهٔ نسخه دیده شد.');
            }

            $entries[] = ltrim(substr($path, strlen($root)), '/')."\0".(int) $file->getSize()."\0".(int) $file->getMTime();
        }

        usort($entries, static fn (string $a, string $b): int => strcmp($a, $b));

        return hash('sha256', implode("\n", $entries));
    }

    // ── کمکی ────────────────────────────────────────────────────────────────

    /**
     * رشته‌ای که امضا می‌شود.
     *
     * `slug` و `label` داخل رشته‌اند تا یک مهرِ سالم نتواند از یک نسخه به نسخهٔ
     * دیگر کاشته شود — و `format` داخل رشته است تا ارتقای قالب، مهرهای قدیمی را
     * نامعتبر کند نه اینکه نادیده بگیرد.
     */
    private static function payload(string $slug, string $label, string $digest): string
    {
        return self::FORMAT."\n".$slug."\n".$label."\n".$digest;
    }

    private function labelOf(string $releaseDir): string
    {
        return basename(str_replace('\\', '/', rtrim($releaseDir, '/\\')));
    }

    /**
     * برچسبِ نسخه باید یک قطعهٔ مسیرِ بی‌خطر باشد — دقیقاً همان قاعده‌ای که
     * `PluginReleaseManager` برای نامِ پوشه‌ها دارد، وگرنه `sealPath()` مسیرِ
     * نوشتن می‌سازد و مسیرِ نوشتن یعنی مسیرِ قابلِ فرار.
     */
    private function assertLabel(string $label): string
    {
        $label = trim($label);

        if ($label === '' || strlen($label) > 120 || str_contains($label, '..')) {
            throw new InvalidArgumentException("برچسب نسخه نامعتبر است: «{$label}».");
        }

        return $label;
    }

    private function memoKey(string $slug, string $label): string
    {
        return "plugin:seal:memo:{$slug}:{$label}";
    }

    private function forgetMemo(string $slug, string $label): void
    {
        Cache::forget($this->memoKey($slug, $label));
    }

    /** «نبودِ مهر» یک بار در هر نسخه لاگ می‌شود، نه در هر درخواست. */
    private function noticeOnce(string $slug, string $label, string $reason): void
    {
        $key = "plugin:seal:unsealed-notice:{$slug}:{$label}";

        if (Cache::get($key) === true) {
            return;
        }

        Cache::put($key, true, 86_400);

        Log::warning('plugin.seal_missing', [
            'slug' => $slug,
            'release' => $label,
            'reason' => $reason,
            'note' => 'این نسخه پیش از K5.2-W نصب شده یا مهرش پاک شده؛ تا زمان re-seal تأییدنشده است.',
        ]);
    }

    /** @return array{ok: false, count: int, digest: string, code: string, message: string} */
    private static function manifestFailure(string $code, string $message): array
    {
        return ['ok' => false, 'count' => 0, 'digest' => '', 'code' => $code, 'message' => $message];
    }

    /**
     * @return array{ok: bool, code: string, message: string, seal: ?string, files: int, digest: ?string}
     */
    private static function sealFailure(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'seal' => null, 'files' => 0, 'digest' => null];
    }

    /** @return array{ok: bool, status: string, code: string, reason: string, release: ?string, files: int, digest: ?string} */
    private static function verdict(
        bool $ok,
        string $status,
        string $code,
        string $reason,
        ?string $release = null,
        int $files = 0,
        ?string $digest = null,
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'code' => $code,
            'reason' => $reason,
            'release' => $release,
            'files' => $files,
            'digest' => $digest,
        ];
    }
}
