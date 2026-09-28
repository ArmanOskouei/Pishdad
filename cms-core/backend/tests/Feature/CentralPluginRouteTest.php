<?php

namespace Tests\Feature;

use App\Http\Controllers\PluginRouter;
use App\Models\Plugin;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugins\PluginAutoloader;
use App\Services\Plugins\PluginReleaseManager;
use App\Services\Plugins\PluginRouteTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Pishdad\Plugins\Central\Http\OperatorReplyController;
use Tests\TestCase;

/**
 * K7.13 — انتقال `operatorReply` به افزونهٔ مرکزی، end-to-end.
 *
 * تصمیم محصول: مرکز یک افزونهٔ معمولی است (K7.9)، پس routeهای مرکزی هم مال
 * افزونه‌اند. این تست ثابت می‌کند انتقال **شدنی** است: از dispatch واقعی
 * `PluginRouter` تا نوشتن در `tickets` و اعلان.
 *
 * وابستگی‌هایی که این انتقال با افزونه آورد و در ledger ثبت شده:
 * `Ticket`، `TicketMessage`، `TicketNotification`.
 */
class CentralPluginRouteTest extends TestCase
{
    use RefreshDatabase;

    private PluginRouteTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        Route::match(PluginRouteTable::METHODS, 'p/{slug}/{any?}', PluginRouter::class)
            ->where('slug', '[^/]+')
            ->where('any', '.*')
            ->name(PluginRouter::ROUTE_NAME)
            ->middleware('throttle:60,1');

        $this->table = app(PluginRouteTable::class);
        $this->table->flush();

        Plugin::query()->delete();
    }

    /**
     * افزونهٔ مرکزی را **واقعاً روی دیسک نصب** می‌کند.
     *
     * ## چرا `require_once` تنها کافی نیست
     *
     * `PluginDispatcher::ensureAutoloaded()` (`:123`) قبل از هر dispatch
     * `$this->releases->autoloadRoot($slug)` را صدا می‌زند و اگر `null` بدهد
     * `RuntimeException` می‌اندازد: «نسخهٔ فعالی روی دیسک نیست». یعنی مسیر
     * افزونه از مانیفست نمی‌آید — از **اشاره‌گر روی دیسک** می‌آید.
     *
     * تلاش اول فقط `require_once` کرد و ۵۰۰ بی‌پیام گرفت. ریشه‌اش همین بود و
     * `withoutExceptionHandling` هم آن را نشان نداد چون خطا داخل
     * `PluginRouter` به یک پاسخ عمومی تبدیل می‌شود.
     *
     * پس اینجا یک release واقعی با اشاره‌گر `.current` ساخته می‌شود — همان
     * ساختاری که `PluginInstaller` می‌سازد.
     */
    private function centralPlugin(): Plugin
    {
        $slug = 'central';

        $manifest = [
            'slug' => $slug,
            'name' => 'مرکزی',
            'version' => '1.0.0',
            'api' => [
                'prefix' => $slug,
                'routes' => [[
                    'method' => 'post',
                    // `{id}` از هسته می‌آید: مشتری درخواست را به تیکت
                    // واقعی وصل می‌کند، نه به شناسهٔ داخلی افزونه.
                    'path' => 'tickets/{id}/operator-reply',
                    'handler' => 'Pishdad\\Plugins\\Central\\Http\\OperatorReplyController@store',
                ]],
            ],
        ];

        $plugin = Plugin::query()->create([
            'name' => 'مرکزی',
            'slug' => $slug,
            'active' => true,
            'review_status' => Plugin::REVIEW_APPROVED,
            'manifest' => $manifest,
        ]);

        $this->installOnDisk($slug, $manifest);

        $this->table->flush();

        return $plugin;
    }

    /**
     * بستهٔ مرکزی را **واقعاً روی دیسک** می‌سازد.
     *
     * ## چرا اصلاً دیسک لازم است
     *
     * `PluginDispatcher::ensureAutoloaded()` (`:123`) قبل از هر dispatch
     * `$this->releases->autoloadRoot($slug)` را صدا می‌زند و اگر `null` بدهد
     * `RuntimeException` می‌اندازد. یعنی مسیر افزونه از مانیفست DB نمی‌آید — از
     * **اشاره‌گر روی دیسک** می‌آید.
     *
     * تلاش اول فقط `require_once` کرد و ۵۰۰ بی‌پیام گرفت، چون `PluginRouter`
     * خطا را به پاسخ عمومی تبدیل می‌کند و `withoutExceptionHandling` هم آن را
     * نشان نمی‌دهد. ریشه‌اش را با اجرای مستقیم `dispatch()` پیدا شد.
     *
     * ## سه قالب دقیق که باید رعایت شوند
     *
     *  ۱) `.current` **JSON** است، نه متن ساده: `readPointer()` (`:732`) کل
     *     فایل را `readJson` می‌کند و `$raw['release']` می‌خواند.
     *  ۲) ریشهٔ PSR-4 = `Laravel/src` (`PSR4_SUBPATH:60`)، نه ریشهٔ release.
     *  ۳) `manifest.json` داخل release لازم است، وگرنه `isUsable()` (`:376`)
     *     همان release را مرده می‌گزارد.
     */
    private function installOnDisk(string $slug, array $manifest): void
    {
        $releases = dirname(__DIR__, 2).'/storage/app/plugins/'.$slug.'/releases';
        $label = '1.0.0-'.substr(md5($slug), 0, 8);
        $versionDir = $releases.'/'.$label;
        $srcDir = $versionDir.'/Laravel/src/Http';

        if (! is_dir($srcDir)) {
            mkdir($srcDir, 0o777, true);
            copy(
                dirname(__DIR__).'/Fixtures/PluginCentral/Http/OperatorReplyController.php',
                $srcDir.'/OperatorReplyController.php',
            );
        }

        // `isUsable()` این را می‌خواهد وگرنه release مرده تلقی می‌شود.
        file_put_contents(
            $versionDir.'/manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        // قالب JSON — نسخهٔ اول متن ساده بود و `readJson` آن را نمی‌خواند.
        file_put_contents(
            $releases.'/.current',
            json_encode(['release' => $label], JSON_UNESCAPED_SLASHES),
        );
    }

    private function user(): User
    {
        return User::query()->create([
            'name' => 'کاربر',
            'email' => uniqid().'@example.com',
            'password' => Hash::make('Pass!1234'),
            'role' => 'admin',
        ])->fresh();
    }

    private function ticket(User $owner, string $status = Ticket::OPEN): Ticket
    {
        return Ticket::query()->create([
            'user_id' => $owner->id,
            'subject' => 'مشکل ورود',
            'status' => $status,
        ]);
    }

    // ── dispatch ──────────────────────────────────────────────────────────

    public function test_the_route_resolves_from_the_manifest(): void
    {
        $this->centralPlugin();

        $route = $this->table->resolve('central', 'tickets/42/operator-reply');

        $this->assertNotNull($route, 'مسیر باید از مانیفست افزونه resolve شود.');
        $this->assertSame('POST', $route['method']);
        $this->assertSame(['id' => '42'], $route['params']);
    }

    public function test_the_handler_class_loads_from_the_installed_psr4_root(): void
    {
        $this->centralPlugin();

        // ⚠️ `class_exists` قبل از هر dispatch زودهنگام است: افزونه هنوز
        // ثبت نشده چون `ensureAutoloaded()` داخل `dispatch()` صدا زده می‌شود.
        // پس این تست خودش ثبت را انجام می‌دهد — همان کاری که dispatcher
        // می‌کند — و این **هم** ساختار دیسک را می‌سنجد: اگر
        // `autoloadRoot()` یا PSR-4 اشتباه باشد، همین‌جا می‌شکند.
        $root = app(PluginReleaseManager::class)->autoloadRoot('central');

        $this->assertNotNull($root, 'ریشهٔ PSR-4 باید از اشاره‌گر دیسک خوانده شود.');

        app(PluginAutoloader::class)->register(
            'central',
            $root,
            PluginAutoloader::NAMESPACE_ROOT.Str::studly('central').'\\',
        );

        $this->assertTrue(
            class_exists(OperatorReplyController::class),
            'کلاس باید از راه autoload افزونه بارگذاری شود، نه require دستی.',
        );
    }

    // ── رفتار واقعی از راه HTTP ──────────────────────────────────────────

    public function test_an_operator_reply_writes_to_the_core_ticket_table(): void
    {
        $this->centralPlugin();
        $operator = $this->user();
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        $auth = $this->actingAs($operator, 'sanctum');
        $res = $auth->postJson('/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply', [
            'body' => 'مشکل شما برطرف شد.',
        ]);

        $res->assertCreated()->assertJsonPath('message', 'پاسخ اپراتور ثبت شد.');

        $this->assertSame(1, $ticket->messages()->count(), 'پیام باید در جدول هسته نوشته شود.');
        $this->assertSame('operator', $ticket->messages()->first()->author_type);
    }

    public function test_the_reply_notifies_the_ticket_owner(): void
    {
        $this->centralPlugin();
        $operator = $this->user();
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        $auth = $this->actingAs($operator, 'sanctum');
        $auth->postJson('/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply', [
            'body' => 'پاسخ',
        ])->assertCreated();

        $this->assertSame(1, $customer->notifications()->count(), 'سازندهٔ تیکت باید اعلان بگیرد.');
        $this->assertSame(0, $operator->notifications()->count(), 'پاسخ‌دهنده نباید اعلان بگیرد.');
    }

    public function test_a_missing_ticket_returns_404(): void
    {
        $this->centralPlugin();
        $operator = $this->user();

        $auth = $this->actingAs($operator, 'sanctum');
        $auth->postJson('/api/v1/p/central/tickets/999999/operator-reply', ['body' => 'سلام'])
            ->assertNotFound();
    }

    public function test_a_closed_ticket_is_refused(): void
    {
        // همان قاعدهٔ `reply()` هسته: تیکت بسته پاسخ نمی‌گیرد. اگر افزونه
        // این را از قلم بیندازد، رفتار هسته و افزونه فرق می‌کند.
        $this->centralPlugin();
        $operator = $this->user();
        $customer = $this->user();
        $ticket = $this->ticket($customer, Ticket::CLOSED);

        $auth = $this->actingAs($operator, 'sanctum');
        $auth->postJson('/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply', ['body' => 'سلام'])
            ->assertStatus(422);

        $this->assertSame(0, $ticket->messages()->count(), 'هیچ پیامی نباید نوشته شود.');
    }

    public function test_the_body_is_required(): void
    {
        $this->centralPlugin();
        $operator = $this->user();
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        $auth = $this->actingAs($operator, 'sanctum');
        $auth->postJson('/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply', [])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_an_inactive_plugin_cannot_reply(): void
    {
        $p = $this->centralPlugin();
        $p->forceFill(['active' => false])->save();
        $this->table->flush();

        $operator = $this->user();
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        $auth = $this->actingAs($operator, 'sanctum');
        $auth->postJson('/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply', ['body' => 'سلام'])
            ->assertNotFound();

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_the_route_requires_authentication(): void
    {
        $this->centralPlugin();
        $customer = $this->user();
        $ticket = $this->ticket($customer);

        // ⚠️ catch-all هسته `auth:sanctum` ندارد — احراز هویت داخل هندلر و
        // از راه `$request->user()` است. نسخهٔ اول این تست انتظار ۴۰۱ از خودِ
        // مسیر داشت و ۵۰۰ گرفت، چون `user()->id` روی `null` خطا می‌داد.
        //
        // هندلر مرجع حالا خودش `null` را می‌بیند و ۴۰۱ می‌دهد. یعنی قرارداد
        // روشن است: **افزونه باید احراز هویت را خودش بسنجد**، و این تست همان
        // قرارداد را قفل می‌کند.
        $this->postJson(
            '/api/v1/p/central/tickets/'.$ticket->id.'/operator-reply',
            ['body' => 'سلام'],
        )->assertUnauthorized();

        $this->assertSame(0, $ticket->messages()->count(), 'هیچ پیامی نباید نوشته شود.');
    }
}
