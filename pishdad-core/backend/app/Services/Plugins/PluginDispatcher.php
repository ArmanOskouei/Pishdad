<?php

namespace App\Services\Plugins;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * K7.11 — اجرای واقعی کد افزونه از راه `PluginRouter`.
 *
 * ## چرا این کلاس وجود نداشت
 *
 * `PluginRouteTable` مسیر را resolve می‌کرد و `PluginAutoloader` کلاس‌ها را
 * بارگذاری می‌کرد، ولی هیچ‌کدام به هم وصل نبودند. نتیجه: افزونه می‌توانست
 * مسیر **اعلام** کند و هیچ مسیری از افزونه **اجرا** نمی‌شد — `PluginRouter`
 * برای هر مسیر درست **۵۰۱** برمی‌گرداند. یعنی K7.8 و K7.9 (انتقال ۶۱ route
 * مرکزی) روی زیرساختی نوشته می‌شدند که هرگز اجرا نمی‌شد.
 *
 * ## سه guard که اینجا اجباری‌اند
 *
 * **۱. namespace.** هندلر باید زیر `Pishdad\Plugins\{Studly(slug)}\` باشد. بدون
 * این guard یک بسته می‌توانست `App\Http\Controllers\Admin\XController` را
 * صدا بزند و به هر کلاس هسته دست بزند. `PluginAutoloader` همین قاعده را هنگام
 * ثبت اجرا می‌کند، ولی آن‌جا روی *namespace اعلام‌شده* است — این‌جا روی *کلاس
 * واقعیِ فراخوانی‌شده*، چون اعلام می‌تواند دروغ بگوید.
 *
 * **۲. متد `__invoke` نبودن.** هندلر یا کلاسی است با `__invoke`، یا کلاسی با
 * متد `handle`. بدون این بررسی، فراخوانیِ متدِ دلخواه روی هر کلاسی در آن
 * namespace ممکن می‌شد.
 *
 * **۳. container.** هندلر از همان container اپ resolve می‌شود، پس می‌تواند
 * سرویس‌های هسته را تزریق بگیرد. این **عمدی** است و امن است: مرز در نام
 * namespace است، نه در اینکه چه چیزی تزریق شود.
 *
 * ## چرا خطای افزونه ۵۰۰ است و پیامش عمومی
 *
 * استثنای پرتاب‌شده از کد افزونه، **بدون** جزئیات به کاربر می‌رسد. دلیل: یک
 * استثنا می‌تواند connection string یا مسیر فایل را در پیامش داشته باشد و
 * مسیر فایل، ساختار دیسک را لو می‌دهد. جزئیات کامل می‌رود لاگ، با نام افزونه و
 * مسیر route تا مالک بتواند تشخیص دهد کدام بسته خراب است.
 */
class PluginDispatcher
{
    public function __construct(
        private readonly PluginAutoloader $autoloader,
        private readonly PluginReleaseManager $releases,
        private readonly Container $container,
    ) {}

    /**
     * مسیر resolve‌شده را اجرا می‌کند.
     *
     * @param  array{slug: string, method: string, path: string, handler: string, middleware: array, params: array}  $route
     */
    public function dispatch(Request $request, array $route): mixed
    {
        [$class, $method] = $this->resolveHandlerClass($route);

        $instance = $this->container->make($class);

        $params = $this->bindParams($instance, $method, $route['params'], $request);

        return $instance->{$method}(...$params);
    }

    /**
     * کلاس و متد هندلر را با guardهای امنیتی برمی‌گرداند.
     *
     * هندلر دو شکل دارد: `Class@method` (صریح) یا `Class` (که آن‌وقت متد از روی
     * متدِ HTTP تعیین می‌شود). شکل دوم بهتر است چون یک کلاس می‌تواند چند مسیر را
     * با متدهای متفاوتِ خودش اداره کند.
     *
     * @param  array{slug: string, method: string, handler: string}  $route
     * @return array{0: class-string, 1: string}
     */
    private function resolveHandlerClass(array $route): array
    {
        $handler = $route['handler'];
        $explicit = str_contains($handler, '@');
        $class = $explicit ? substr($handler, 0, (int) strpos($handler, '@')) : $handler;

        if (! class_exists($class)) {
            // کلاس نیست ⇒ شاید هنوز بارگذاری نشده. یک بار تلاش می‌کنیم.
            $this->ensureAutoloaded($route['slug']);

            if (! class_exists($class)) {
                throw new \RuntimeException("کلاس هندلر پیدا نشد: {$class}");
            }
        }

        $this->assertInsideNamespace($class, $route['slug']);

        $method = $explicit ? substr($handler, (int) strpos($handler, '@') + 1) : null;

        if ($method === null || $method === '') {
            $method = match (strtoupper($route['method'])) {
                'GET', 'HEAD' => 'show',
                'POST' => 'store',
                'PUT', 'PATCH' => 'update',
                'DELETE' => 'destroy',
                default => 'handle',
            };
        }

        if (! method_exists($class, $method)) {
            throw new \RuntimeException("متد «{$method}» روی هندلر وجود ندارد: {$class}");
        }

        $this->assertCallable($class, $method);

        return [$class, $method];
    }

    /**
     * بارگذاری کلاس‌های افزونه، یک‌بار در هر درخواست.
     *
     * `registerPackages` خودش در برابر مسیر نامعتبر و namespace اشتباه محافظت
     * دارد و شکست را بی‌صدا رد می‌کند، پس این‌جا فقط صدا زدنش امن است.
     */
    private function ensureAutoloaded(string $slug): void
    {
        if ($this->autoloader->isRegistered($slug)) {
            return;
        }

        $root = $this->releases->autoloadRoot($slug);

        if ($root === null) {
            // پیام «نسخهٔ فعال نیست» دیگر همیشه درست نبود: افزونهٔ داخلی
            // (K7.8) اصلاً نسخه ندارد، کدش در `plugins/` مخزن است. پس هر دو
            // حالت را یک جملهٔ درست پوشش می‌دهد.
            throw new \RuntimeException("بستهٔ «{$slug}» روی دیسک قابل بارگذاری نیست.");
        }

        $this->autoloader->register(
            $slug,
            $root,
            PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\',
        );
    }

    /**
     * کلاس باید زیر namespace اجباری همان افزونه باشد.
     *
     * این تنها جایی است که جلوی یک بستهٔ مخرب را می‌گیرد که می‌خواهد به کلاسِ
     * هسته دست بزند. `PluginAutoloader` هنگام ثبت همین قاعده را دارد، ولی آن
     * روی رشتهٔ اعلام‌شده در مانیفست است؛ این‌جا روی رشتهٔ واقعیِ هندلر، که
     * می‌تواند دروغ بگوید.
     *
     * @param  class-string  $class
     */
    private function assertInsideNamespace(string $class, string $slug): void
    {
        $expected = PluginAutoloader::NAMESPACE_ROOT.Str::studly($slug).'\\';

        if (! str_starts_with($class, $expected)) {
            Log::warning('plugin.handler_outside_namespace', [
                'slug' => $slug,
                'handler' => $class,
                'expected_prefix' => $expected,
            ]);

            throw new \RuntimeException("هندلر خارج از namespace افزونه است: {$class}");
        }
    }

    /**
     * متد باید عمومی و غیرثابت باشد.
     *
     * `method_exists` متدِ خصوصی و protected را هم true می‌گوید، پس بدون این
     * بررسی فراخوانی یا fatal می‌شد یا یک متدِ داخلیِ کلاس از بیرون قابل اجرا
     * می‌شد.
     *
     * @param  class-string  $class
     */
    private function assertCallable(string $class, string $method): void
    {
        $reflection = new ReflectionMethod($class, $method);

        if (! $reflection->isPublic()) {
            throw new \RuntimeException("متد «{$method}» عمومی نیست: {$class}");
        }

        if ($reflection->isStatic()) {
            throw new \RuntimeException("متد «{$method}» استاتیک است: {$class}");
        }

        if ($reflection->isAbstract()) {
            throw new \RuntimeException("متد «{$method}» انتزاعی است: {$class}");
        }
    }

    /**
     * پارامترهای مسیر را به امضای متد می‌چسباند.
     *
     * نام پارامتر در متد، کلید در اعلان را پیدا می‌کند — نه ترتیب. اگر به ترتیب
     * تکیه کنیم، جابه‌جایی دو کلید در مانیفست بی‌سروصدا آرگومان‌ها را عوض می‌کند.
     *
     * فقط تعدادی فرستاده می‌شود که متد واقعاً می‌پذیرد، تا متدی با امضای کوتاه‌تر
     * نشکند. `Request` از DI می‌آید نه از مسیر.
     *
     * @param  array<string, string>  $params
     * @return list<mixed>
     */
    /**
     * پارامترهای هندلر را از segmentهای مسیر پر می‌کند.
     *
     * ## چرا تبدیل نوع لازم بود
     *
     * مقادیر `params` همیشه **رشته**‌اند چون از URL آمده‌اند. اگر هندلر پارامترش
     * را `int $ticketId` اعلام کند، نسخهٔ قبل فقط `$params['id'] ?? null` را
     * می‌گذاشت و PHP در حالت strict یک `TypeError` **بی‌پیام** می‌داد — چون
     * انتقال ضمنی `string` به `int` در فراخوانی تابعِ typehinted مجاز نیست.
     *
     * آن خطای بی‌پیام یعنی کاربر فقط یک ۵۰۰ خالی می‌دید و هیچ راهنمایی برای
     * نویسندهٔ افزونه نبود. تبدیل نوع اینجا انجام می‌شود تا خطا از خودِ
     * validation بیاید.
     *
     * ## چرا تبدیل شکست‌پذیر عمداً ساکت است
     *
     * اگر تبدیل ممکن نبود، مقدار **بدون تبدیل** پاس داده می‌شود تا خودِ هندلر
     * تصمیم بگیرد. پس دادن `null` جای یک رشتهٔ نامعتبر بدتر بود: هندلر
     * انتظار داشت عدد ببیند و `null` می‌گرفت، یعنی TypeError با پیام گمراه‌کننده.
     */
    private function bindParams(object $instance, string $method, array $params, Request $request): array
    {
        $reflection = new ReflectionMethod($instance, $method);

        $ordered = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $ordered[] = $request;

                continue;
            }

            $name = $parameter->getName();
            $raw = $params[$name] ?? null;

            $ordered[] = $this->coerce($raw, $type);
        }

        return $ordered;
    }

    /**
     * تبدیل مقدار رشته‌ای مسیر به نوع اعلام‌شدهٔ پارامتر.
     *
     * اگر تبدیل ممکن نباشد **خودِ مقدار** برگردانده می‌شود، نه `null` — و
     * اگر مقدار اصلاً نبود `null` می‌ماند. هندلر باید خودش اعتبارسنجی کند.
     */
    private function coerce(mixed $value, ?\ReflectionType $type): mixed
    {
        if ($value === null || ! $type instanceof \ReflectionNamedType) {
            return $value;
        }

        $name = $type->getName();

        if (! $type->isBuiltin()) {
            return $this->coerceModel($value, $name);
        }

        $scalar = match ($name) {
            'int' => is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            'float' => is_numeric($value) ? (float) $value : $value,
            'bool' => is_string($value) && in_array(strtolower($value), ['1', 'true', '0', 'false'], true)
                ? in_array(strtolower($value), ['1', 'true'], true)
                : $value,
            'string' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };

        // اگر تبدیل نوع را تغییر نداد، یعنی مقدار با نوع اعلام‌شده نمی‌خواند.
        // همان مقدار خام را می‌دهیم تا خطا از هندلر بیاید نه از اینجا.
        return $scalar;
    }

    /**
     * K7.8 — route model binding برای افزونه.
     *
     * ## چه چیزی عوض شد
     *
     * پیش از این، هر typehint غیربیتی **همان رشتهٔ خام** را می‌گرفت. پس هندلری
     * که `Client $client` اعلام می‌کرد یک `TypeError` **بی‌پیام** می‌داد.
     *
     * این باگ تا K7.8 پنهان بود چون تنها هندلرِ آزمایشیِ افزونه (K7.13) پارامتر
     * مدل نداشت — `int $id` می‌خواست و coerce معمولی کافی بود. اولین بستهٔ
     * واقعی، ۲۲ متد با typehintِ مدل داشت و **همه** با `TypeError` می‌افتادند.
     *
     * یعنی بدون این تغییر، افزونهٔ مرکزی نه می‌توانست روی یک رکورد کار کند و
     * نه نویسنده‌اش می‌فهمید چرا: ۵۰۰ عمومی، هیچ پیامی، هیچ سطری از کدش.
     *
     * ## چرا `findOrFail` و نه `find`
     *
     * `find` با `null` برمی‌گردد و هندلر بعداً `->name` می‌خواند و
     * `Error: Call to a member function on null` می‌گیریم — باز هم بی‌پیام.
     * `findOrFail` یک `ModelNotFoundException` می‌دهد که `PluginRouter` آن را
     * به **۴۰۴** تبدیل می‌کند، دقیقاً مثل هسته.
     *
     * ## چرا باز هم یک محدودیت هست
     *
     * این **هر** کلاسی نیست. فقط subclassِ `Model` که namespace افزونه را داشته
     * باشد. یک افزونه نمی‌تواند با typehint کردن یک کلاس دلخواه، سازندهٔ
     * دلخواه را در هر dispatch اجرا کند — چون مسیری که صدا زده می‌شود فقط
     * `newInstance()` + `findOrFail()` است، نه `new $class(...$args)`.
     */
    private function coerceModel(mixed $value, string $class): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (! class_exists($class)) {
            return $value;
        }

        if (! is_subclass_of($class, Model::class)) {
            return $value;
        }

        // فقط مدل‌های داخل namespace همین افزونه. مدلِ هسته (`App\Models\…`)
        // عمداً رد می‌شود: افزونه باید آن را خودش صدا بزند، نه اینکه هسته
        // برایش کوئری بزند. این تفاوت مرز را نگه می‌دارد.
        if (! str_starts_with($class, PluginAutoloader::NAMESPACE_ROOT)) {
            return $value;
        }

        if (! preg_match('/^\d+$/', $value)) {
            // شناسه باید عدد باشد. رشتهٔ دیگر یعنی افزونه مسیری اعلام کرده که
            // `PluginRouteTable` باید ردش می‌کرد — اینجا رد نمی‌شود که خطا
            // از خودِ مدل بیاید.
            return $value;
        }

        return $class::query()->findOrFail((int) $value);
    }
}
