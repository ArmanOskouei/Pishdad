<?php

namespace Tests\Feature;

use App\Http\Controllers\PluginRouter;
use App\Models\Plugin;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugins\PluginAutoloader;
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
     * افزونهٔ مرکزی را فعال می‌کند، با مانیفستی که **فقط یک مسیر** دارد.
     *
     * ## چرا مانیفست دستی و نه مانیفستِ بستهٔ واقعی
     *
     * این تست یک چیز را می‌سنجد: **dispatch** — از resolve تا اجرا تا اعلان.
     * مانیفستِ بستهٔ واقعی ۶۳ مسیر دارد که ۲۲ فایلِ تستِ مرکزی جداگانه
     * می‌سنجند. اگر اینجا هم از آن استفاده می‌کردیم، هر تغییر در آن ۶۳ مسیر
     * این تست را هم می‌شکست و دو چیزِ متفاوت را به هم گره می‌زد.
     *
     * ## چرا دیگر release نمی‌سازیم
     *
     * پیش از K7.8 اینجا یک release واقعی در `storage/app/plugins/central/`
     * می‌ساخت، چون `PluginDispatcher` فقط `storage` را می‌دید. حالا بستهٔ
     * مرکزی در **مخزن** است (`plugins/central/Laravel/src`) و
     * `AppServiceProvider::bootBundledPlugins()` آن را در هر درخواست ثبت
     * می‌کند.
     *
     * آن روش هم یک پیامِ گمراه‌کننده داشت که حالا دیگر درست نیست:
     * «مسیر افزونه از **اشاره‌گر روی دیسک** می‌آید» — برای بستهٔ داخلی دیگر
     * اشاره‌گری در کار نیست.
     */
    private function centralPlugin(): Plugin
    {
        $slug = 'central';

        // J11 — بسته در قرنطینه است، پس هستهٔ اصلی PSR-4 آن را ثبت نمی‌کند.
        // این تست dispatch را مستقیم می‌سنجد، پس خودش ثبت می‌کند (وگرنه
        // `PluginDispatcher::ensureAutoloaded()` با «بسته روی دیسک قابل
        // بارگذاری نیست» می‌مرد).
        $this->registerQuarantinedPluginAutoload($slug, $this->centralDir());

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

        // `firstOrCreate` نه `create` — از K7.8 رکوردِ بستهٔ مرکزیِ واقعی در
        // جدول هست و `TestCase` آن را فعال می‌کند. `create` روی همان slug به
        // `plugins_slug_unique` می‌خورد.
        $plugin = Plugin::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => 'مرکزی',
                'version' => '1.0.0',
                'active' => true,
                'review_status' => Plugin::REVIEW_APPROVED,
                'manifest' => $manifest,
            ],
        );

        // K7.8 — `installOnDisk()` حذف شد.
        //
        // آن روش یک release ساختگی در `storage/app/plugins/central/` می‌ساخت
        // چون `PluginDispatcher::ensureAutoloaded()` فقط `storage` را می‌دید.
        // بستهٔ مرکزی حالا در **مخزن** است (`plugins/central/Laravel/src`) و
        // `AppServiceProvider::bootBundledPlugins()` آن را در هر درخواست ثبت
        // می‌کند، پس نه ساختنِ release لازم است و نه کپی کردن فایل‌ها.
        //
        // مانیفست کوچکِ بالا فقط یک route دارد و عمداً: این تست می‌خواهد
        // **dispatch** را جداگانه بسنجد، نه اینکه ۶۳ مسیرِ بستهٔ واقعی را
        // دوباره تست کند — آن‌ها را ۲۲ فایلِ تستِ مرکزی می‌سنجند.
        $plugin->forceFill(['manifest' => $manifest])->save();

        $this->table->flush();

        return $plugin;
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
        $root = $this->centralDir().'/Laravel/src';

        $this->assertDirectoryExists($root, 'ریشهٔ PSR-4 بستهٔ (قرنطینه‌شدهٔ) مرکزی باید وجود داشته باشد.');

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
