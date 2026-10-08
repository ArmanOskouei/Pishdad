<?php

namespace Tests\Feature;

use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginDispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pishdad\Plugins\Bindprobe\Http\ShowProbe;
use Pishdad\Plugins\Bindprobe\Models\Probe;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * K7.8 — route model binding در افزونه.
 *
 * ## چرا این تست وجود دارد
 *
 * هندلری که `Client $client` اعلام می‌کند پیش از این یک `TypeError` **بی‌پیام**
 * می‌داد: dispatcher مقدار رشته‌ای URL را می‌داد و PHP در حالت strict آن را
 * نمی‌پذیرفت. `PluginRouter` استثنا را به ۵۰۰ عمومی تبدیل می‌کرد، پس نویسندهٔ
 * افزونه نه پیام می‌دید و نه می‌دانست خط از کجاست.
 *
 * باگ تا K7.8 پنهان بود چون تنها هندلر آزمایشیِ افزونه (K7.13) پارامتر مدل
 * نداشت. اولین بستهٔ واقعی ۲۲ متد با typehint مدل داشت و **همه** می‌افتادند.
 *
 * ## چرا مستقیم `coerce()` صدا زده می‌شود
 *
 * `coerceModel` و `coerce` هر دو private هستند. از راه reflection صدا می‌زنیم
 * چون هدف این تست خودِ تبدیل است؛ تستِ مسیرِ کامل جداگانه وجود دارد و همان مسیر را می‌سنجد.
 *
 * ## چرا typehint از یک متد واقعی می‌آید
 *
 * `new ReflectionNamedType(Probe::class, false)` در PHP 8.5 خطای «Failed to
 * retrieve the reflection object» می‌دهد. اولین تلاش همین را ساخت و هر سه تست
 * قرمز شدند — یعنی هیچ‌چیز واقعاً سنجیده نمی‌شد. پس نوع از یک متدِ واقعیِ
 * fixture گرفته می‌شود، که دقیقاً همان چیزی است که `bindParams()` می‌بیند.
 */
class PluginModelBindingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * fixtureهای `tests/Fixtures` زیر PSR-4 نیستند — کلاس `Tests\` نیستند و در
     * `composer.json` هم نیستند، پس هیچ autoloadی بارگذاری‌شان نمی‌کند.
     *
     * `PluginRouteTable` این را با **کپی به دیسکِ بستهٔ شبیه‌سازی‌شده**
     * حل می‌کند، ولی آن راه برای این تست لازم نیست: `coerce()` فقط به
     * **کلاس** نیاز دارد، نه به اینکه از دیسکِ افزونه بارگذاری شود.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once __DIR__.'/../Fixtures/PluginBindprobe/Models/Probe.php';
        require_once __DIR__.'/../Fixtures/PluginBindprobe/Http/ShowProbe.php';
    }

    private function coerce(ReflectionParameter $parameter, mixed $value): mixed
    {
        // نگهبانِ خودِ شرط: اگر `NAMESPACE_ROOT` عوض شود ولی شرطِ
        // `coerceModel` نه، اینجا قرمز می‌شود — یعنی binding بی‌صدا از کار
        // می‌افتد و همهٔ افزونه‌ها `TypeError` می‌گیرند.
        $this->assertStringStartsWith(
            PluginAutoloader::NAMESPACE_ROOT,
            Probe::class,
            'مدل آزمایشی باید زیر namespace افزونه باشد.',
        );

        $method = new ReflectionMethod(app(PluginDispatcher::class), 'coerce');

        return $method->invoke(app(PluginDispatcher::class), $value, $parameter->getType());
    }

    private function firstParam(string $method): ReflectionParameter
    {
        return (new ReflectionMethod(ShowProbe::class, $method))->getParameters()[0];
    }

    private function makeTable(): void
    {
        Schema::create('plugin_bindprobe_models', function ($table) {
            $table->id();
            $table->string('name')->default('');
        });
    }

    public function test_a_model_parameter_is_resolved_from_the_path(): void
    {
        $this->makeTable();
        $id = DB::table('plugin_bindprobe_models')->insertGetId(['name' => 'x']);

        $resolved = $this->coerce($this->firstParam('show'), (string) $id);

        $this->assertInstanceOf(Probe::class, $resolved, 'مدل باید از مسیر resolve می‌شد.');
        $this->assertSame($id, (int) $resolved->getKey());
    }

    public function test_a_missing_record_fails_loudly_instead_of_being_null(): void
    {
        $this->makeTable();

        // `findOrFail` و نه `find`: با `find` مقدار `null` می‌رسید و هندلر بعداً
        // `->name` می‌خواند و `Call to a member function on null` می‌گرفت — باز
        // هم بی‌پیام. `findOrFail` یک استثنای قابل‌نگاشت به ۴۰۴ می‌دهد.
        $this->expectException(ModelNotFoundException::class);

        $this->coerce($this->firstParam('show'), '999999');
    }

    public function test_a_non_numeric_path_is_passed_through_untouched(): void
    {
        // رشتهٔ غیرعددی نباید به `findOrFail(0)` تبدیل شود — که رکوردِ اولِ جدول
        // را برمی‌گرداند، یعنی یک پاسخ کاملاً اشتباه. باید همان رشته بماند تا
        // خطا از خودِ هندلر بیاید.
        $this->assertSame('abc', $this->coerce($this->firstParam('show'), 'abc'));
    }

    public function test_a_core_model_is_never_resolved_by_the_plugin_dispatcher(): void
    {
        // مرز: افزونه نباید بتواند با typehint کردن یک مدلِ هسته، بارگذارِ هسته
        // را مجبور کند کوئری بزند. مدلِ هسته باید **همان رشته** را بگیرد.
        //
        // اگر این شرط نبود، یک افزونه می‌توانست با typehint کردن `User $user`
        // هر کاربری را با شناسهٔ دلخواه بارگذاری کند — و هر property خوانده
        // می‌شد. مرزِ «افزونه فقط مدلِ خودش را bind می‌کند» دقیقاً همین است.
        $this->assertSame(
            '7',
            $this->coerce($this->firstParam('core'), '7'),
            'مدل هسته نباید در dispatcher افزونه resolve شود.',
        );
    }

    public function test_a_scalar_parameter_is_unaffected(): void
    {
        // مسیر معمولی K7.13 (`int $id`) نباید با افزودن binding خراب شود.
        $this->assertSame('slug-1', $this->coerce($this->firstParam('raw'), 'slug-1'));
    }
}
