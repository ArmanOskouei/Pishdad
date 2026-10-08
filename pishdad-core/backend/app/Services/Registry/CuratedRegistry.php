<?php

namespace App\Services\Registry;

use App\Services\Plugins\PluginReleaseManager;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * F1.2 — فهرستِ curated: «کاتالوگِ پیوندی» با یک دروازهٔ صداقت.
 *
 * ## این سرویس چه نیست
 *
 * فهرستِ بازار نیست. بازار (`/api/v1/market/*`) مسیرِ واقعیِ نصب است و چهار
 * دروازه دارد: ثبتِ ناشر، امضای Ed25519، تأییدِ مرکزی، و لایسنس. این فهرست
 * **هیچ‌کدام را ندارد** و عمداً هیچ‌کدام را دور نمی‌زند — فقط می‌گوید «چه چیزی
 * وجود دارد و چطور می‌شود به آن رسید».
 *
 * ## قاعدهٔ مرکزی: `execution_status` در نسخهٔ ۱ فقط `unavailable` است
 *
 * هر مدخل باید `execution_status` داشته باشد و در نسخهٔ ۱ تنها مقدارِ مجاز
 * `"unavailable"` است. این عمداً سخت‌گیرانه است:
 *
 *  - فهرستِ «ساده-پیوند» بدون این قاعده، ظرف یک ساعت به یک **دکمهٔ نصبِ دروغین**
 *    تبدیل می‌شود: یک فرانت، یک دکمه می‌کشد و کاربر می‌زند و چیزی نمی‌شود.
 *  - با این قاعده، نوشتنِ `"available"` در JSON **همین‌جا** رد می‌شود، پیش از
 *    آنکه به فرانت برسد.
 *
 * این fail-closed در سطحِ **داده** است نه UI، چون UI می‌تواند دور زده شود و
 * داده نمی‌شود.
 *
 * ## `package` و validator
 *
 * اگر مدخلی `package` داشته باشد — مسیرِ نسبیِ یک ZIP داخلِ مخزن — با
 * `PluginPackageValidator` همان بسته تحلیل می‌شود. یعنی «معتبر بودنِ بسته» در CI
 * با همان دروازه‌ای سنجیده می‌شود که نصب با آن سنجیده می‌شود؛ یک validator دوم
 * برای فهرست یعنی دو حقیقت که فقط یکی‌شان اجرا می‌شود.
 */
final class CuratedRegistry
{
    /** نسخهٔ قالبِ فهرست. تغییرِ شکل یعنی تغییرِ این عدد. */
    public const SCHEMA_VERSION = 1;

    /**
     * تنها مقدارِ مجازِ `execution_status` در نسخهٔ ۱.
     *
     * فهرستِ کاملِ مقدارهای ممکن برای آینده این‌جاست ولی **غیرفعال**:
     * `available` وقتی معتبر می‌شود که مسیرِ نصبِ K8.4 واقعاً به این فهرست وصل
     * شود. تا آن روز پذیرفتنش یعنی ادعای بی‌پشتوانه.
     */
    public const EXECUTION_UNAVAILABLE = 'unavailable';

    /** واژگانِ مجاز — فقط یکی. وجودِ بقیه در همین‌جا یادداشتِ عمدی است. */
    private const EXECUTION_STATUSES = [self::EXECUTION_UNAVAILABLE];

    private const KINDS = ['plugins', 'themes'];

    /** فیلدهای اجباریِ هر مدخل. */
    private const REQUIRED = ['slug', 'name_fa', 'summary_fa', 'homepage_url', 'source_url', 'license', 'execution_status'];

    private ?array $cache = null;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * مسیرِ فهرست.
     *
     * **چرا `registry/` داخلِ `pishdad-core/backend` است و نه ریشهٔ مخزن.** ظرف
     * `pishdad-app` فقط همین پوشه mount است، پس فایلِ ریشهٔ مخزن در آن دیده نمی‌شود
     * و `php artisan registry:check` در همان محیطی که CI اجرا می‌کند شکست
     * می‌خورد. یک دروازهٔ CI که فقط روی میزِ خودت کار کند، دروازه نیست.
     *
     * `CURATED_REGISTRY_PATH` مسیرِ دیگری می‌دهد، برای وقتی که فهرست بیرون از
     * مخزن نگهداری می‌شود.
     */
    public function path(): string
    {
        $override = (string) config('curated.path', '');

        if ($override !== '') {
            return $override;
        }

        return $this->path ?? base_path('registry/curated.json');
    }

    /**
     * فهرستِ خوانده و **سنجیده‌شده**.
     *
     * @throws RuntimeException اگر فایل نبود، JSON خراب بود، یا شکل غلط بود
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->path();
        $raw = @file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("فهرستِ curated خوانده نشد: «{$path}».");
        }

        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new RuntimeException("فهرستِ curated معتبر نیست: «{$path}».");
        }

        $errors = $this->shapeErrors($data);

        if ($errors !== []) {
            Log::warning('registry.curated_invalid', ['path' => $path, 'errors' => $errors]);

            throw new RuntimeException('فهرستِ curated شکلِ درست ندارد: '.implode(' | ', $errors));
        }

        $out = [];
        foreach (self::KINDS as $kind) {
            // `?? []` لازم است: فهرستِ خالی (`[]`) یعنی «هنوز چیزی منتشر
            // نشده» و باید سبز باشد. نبودنش یعنی شکلِ غلط — و آن را
            // `shapeErrors()` بالاتر گرفته و استثنا کرده، پس اینجا فقط یک
            // احتیاطِ ارزان است نه یک تصمیم.
            $out[$kind] = array_map(
                fn (array $entry): array => $this->present($kind, $entry),
                array_values(array_filter((array) ($data[$kind] ?? []), 'is_array'))
            );
        }

        return $this->cache = [
            'schema_version' => self::SCHEMA_VERSION,
            'execution_status' => (string) $data['execution_status'],
            'execution_status_fa' => (string) ($data['execution_status_fa'] ?? ''),
            'note_fa' => (string) ($data['note_fa'] ?? ''),
            'plugins' => $out['plugins'],
            'themes' => $out['themes'],
        ];
    }

    /**
     * فقط مدخل‌های یک نوع.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(string $kind): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            return [];
        }

        return $this->all()[$kind];
    }

    // ── شکل ─────────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function shapeErrors(array $data): array
    {
        $errors = [];

        if (($data['$schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'نسخهٔ قالب باید '.self::SCHEMA_VERSION.' باشد.';
        }

        $status = $data['execution_status'] ?? null;

        if (! is_string($status) || ! in_array($status, self::EXECUTION_STATUSES, true)) {
            // پیام عمداً صریح است: این نقطه جلوی «دکمهٔ نصبِ دروغین» است.
            $errors[] = 'execution_status باید '.self::EXECUTION_UNAVAILABLE.' باشد (در نسخهٔ ۱ فقط همین مقدار).';
        }

        foreach (self::KINDS as $kind) {
            if (! isset($data[$kind]) || ! is_array($data[$kind]) || array_is_list($data[$kind]) === false) {
                $errors[] = "«{$kind}» باید یک آرایهٔ فهرست‌وار (لیست) باشد.";

                continue;
            }

            $seen = [];

            foreach ($data[$kind] as $index => $entry) {
                foreach ($this->entryErrors($entry, "{$kind}[{$index}]") as $error) {
                    $errors[] = $error;
                }

                if (is_array($entry) && is_string($entry['slug'] ?? null)) {
                    if (in_array($entry['slug'], $seen, true)) {
                        $errors[] = "«{$kind}[{$index}]»: slug «{$entry['slug']}» تکراری است.";
                    }

                    $seen[] = $entry['slug'];
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function entryErrors(mixed $entry, string $where): array
    {
        if (! is_array($entry)) {
            return [$where.': هر مدخل باید یک شیء (object) باشد.'];
        }

        $errors = [];

        foreach (self::REQUIRED as $field) {
            if (! array_key_exists($field, $entry)) {
                $errors[] = "{$where}: فیلد «{$field}» لازم است.";
            }
        }

        // الگوی slug عمداً همانِ `PluginReleaseManager` است: slugِ فهرست باید
        // همان رشته‌ای باشد که نصب می‌پذیرد، وگرنه کاربر روی یک شناسه کلیک
        // می‌کند که هیچ‌وقت قابل نصب نیست.
        if (! is_string($entry['slug'] ?? null) || preg_match('/^[a-z0-9][a-z0-9._-]{1,39}$/', $entry['slug']) !== 1) {
            $errors[] = $where.': slug باید با الگوی هسته بخواند (a-z0-9، نقطه/خط‌تیره، ۲ تا ۴۰ نویسه).';
        }

        if (! in_array($entry['execution_status'] ?? null, self::EXECUTION_STATUSES, true)) {
            $errors[] = $where.': execution_status باید '.self::EXECUTION_UNAVAILABLE.' باشد؛ «available» در نسخهٔ ۱ ادعای بی‌پشتوانه است.';
        }

        foreach (['homepage_url', 'source_url'] as $field) {
            $value = $entry[$field] ?? null;

            if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $errors[] = $where.': «{$field}» باید یک URL مطلق و معتبر باشد.';
            }
        }

        return $errors;
    }

    /**
     * شکلِ بیرونیِ یک مدخل.
     *
     * `install_intent` **توصیف** است نه دستور: می‌گوید تنها راهِ رسمی چیست و
     * صریحاً می‌گوید که از این فهرست نصبی انجام نمی‌شود. هیچ endpointِ نصبی در
     * آن نیست — عمداً، چون ساختنش یعنی tier تازه‌ای که K8.4 ندارد.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function present(string $kind, array $entry): array
    {
        return [
            'kind' => $kind,
            'slug' => (string) $entry['slug'],
            'name_fa' => (string) $entry['name_fa'],
            'summary_fa' => (string) $entry['summary_fa'],
            'homepage_url' => (string) $entry['homepage_url'],
            'source_url' => (string) $entry['source_url'],
            'license' => (string) $entry['license'],
            'version' => is_string($entry['version'] ?? null) ? $entry['version'] : null,
            'categories' => array_values(array_map('strval', (array) ($entry['categories'] ?? []))),
            'execution_status' => (string) $entry['execution_status'],
            'install_intent' => [
                'available' => false,
                'why_fa' => 'فهرستِ curated در K8 نسخهٔ ۱ فقط پیوند است؛ نصب از این مسیر انجام نمی‌شود.',
                'official_path' => [
                    ['step' => 'register_publisher', 'endpoint' => '/api/v1/market/publishers'],
                    ['step' => 'submit_for_review', 'endpoint' => '/api/v1/market/submissions'],
                    ['step' => 'install_with_license', 'endpoint' => '/api/v1/market/plugins/{id}/install'],
                ],
            ],
        ];
    }
}
