<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Pishdad\Plugins\Central\Models\CentralLog;
use Pishdad\Plugins\Central\Models\Client;
use Pishdad\Plugins\Central\Models\InstallHeartbeat;
use Tests\TestCase;

/** تسک ۵.۵ — DoD: تشخیص نصب ساکت + لاگ متمرکز با فیلتر. */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
    }

    private function client(): Client
    {
        return Client::query()->create([
            'name' => 'مشتری', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ]);
    }

    private function signedHeartbeat(Client $client, string $status = 'online', ?int $ts = null): array
    {
        $client->refresh();
        $ts ??= time();
        $sig = hash_hmac('sha256', "{$client->site_id}|{$status}|{$ts}", $client->install_token);

        return [
            'headers' => ['X-Install-Token' => $client->install_token],
            'body' => [
                'site_id' => $client->site_id, 'status' => $status,
                'version' => '1.2.0', 'timestamp' => $ts, 'signature' => $sig,
            ],
        ];
    }

    /**
     * J5 — مسیرِ قدیمیِ heartbeat در هسته **گم شده** و نباید برگردد.
     *
     * ⚠️ این نگهبانی با افزونهٔ **حاضر** اجرا می‌شود، و فرقش با «۴۰۴ بدون افزونه»
     * معناست: آن فقط می‌گوید افزونه نبود؛ این می‌گوید جابه‌جایی واقعاً انجام شده.
     *
     * اگر کسی `/api/v1/internal/heartbeat` را به هسته برگرداند، دو مسیر برای یک
     * کنترلر زندگی می‌کنند: یکی زیر prefix افزونه، یکی در هسته. آن وقت یک نصبِ
     * عمومی که افزونه ندارد همچنان از راه هسته پاسخ می‌داد — دقیقاً همان چیزی که
     * J5 حذفش کرد و `CentralZeroFootprintTest` دیگر نمی‌تواند بگیردش چون آن
     * تست پوشه را برمی‌دارد.
     *
     * انتظار `404` است نه `401`: `401` یعنی مسیر هست و فقط احراز هویت خورده.
     */
public function test_the_old_core_heartbeat_path_is_gone(): void
    {
        foreach (['/api/v1/internal/heartbeat', '/api/internal/heartbeat'] as $uri) {
            $this->postJson($uri, [])->assertNotFound(
                "«{$uri}» در هسته نباید وجود داشته باشد. مسیرِ رسمی زیر prefix افزونه است:"
                .' `POST /api/v1/p/central/internal/heartbeat`.'
            );
        }
    }

    public function test_heartbeat_upserts_with_token_and_hmac(): void
    {
        $client = $this->client();
        $this->assertNotEmpty($client->install_token);

        $hb = $this->signedHeartbeat($client);
        $this->withHeaders($hb['headers'])->postJson('/api/v1/p/central/internal/heartbeat', $hb['body'])
            ->assertOk()->assertJsonPath('message', 'heartbeat ثبت شد.');

        $this->assertDatabaseHas('central_install_heartbeats', [
            'site_id' => $client->site_id, 'status' => 'online', 'version' => '1.2.0',
        ]);
        $this->assertEquals('1.2.0', $client->fresh()->current_version);

        // امضای اشتباه، توکن اشتباه، timestamp قدیمی → 401.
        $bad = $hb['body'];
        $bad['signature'] = 'x';
        $this->withHeaders($hb['headers'])->postJson('/api/v1/p/central/internal/heartbeat', $bad)->assertUnauthorized();

        $this->withHeaders(['X-Install-Token' => 'wrong'])
            ->postJson('/api/v1/p/central/internal/heartbeat', $hb['body'])->assertUnauthorized();

        $old = $this->signedHeartbeat($client, 'online', time() - 600);
        $this->withHeaders($old['headers'])->postJson('/api/v1/p/central/internal/heartbeat', $old['body'])
            ->assertUnauthorized();
    }

    public function test_health_grid_detects_silent_installs(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        $client = $this->client();

        $hb = $this->signedHeartbeat($client);
        $this->withHeaders($hb['headers'])->postJson('/api/v1/p/central/internal/heartbeat', $hb['body']);

        $grid = $auth->getJson('/api/v1/p/central/monitoring/health')->assertOk()->json('data.grid');
        $this->assertEquals('online', collect($grid)->firstWhere('site_id', $client->site_id)['state']);

        // عقب بردن last_seen → ساکت.
        InstallHeartbeat::query()
            ->where('site_id', $client->site_id)
            ->update(['last_seen_at' => now()->subMinutes(20)]);

        $res = $auth->getJson('/api/v1/p/central/monitoring/health')->assertOk()->json('data');
        $this->assertEquals('silent', collect($res['grid'])->firstWhere('site_id', $client->site_id)['state']);
        $this->assertNotEmpty($res['silent']);
    }

    public function test_logs_filter_and_thirty_day_cap(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');

        CentralLog::query()->create(['site_id' => 's1', 'level' => 'info', 'message' => 'عادی']);
        CentralLog::query()->create(['site_id' => 's1', 'level' => 'error', 'message' => 'خطا']);
        CentralLog::query()->create(['site_id' => 's2', 'level' => 'error', 'message' => 'خطای دیگر']);
        $old = CentralLog::query()->create([
            'site_id' => 's1', 'level' => 'info', 'message' => 'قدیمی',
        ]);
        CentralLog::query()->where('id', $old->id)->update(['created_at' => now()->subDays(31)]);

        $auth->getJson('/api/v1/p/central/monitoring/logs?level=error')->assertOk()
            ->assertJsonPath('data.total', 2);
        $auth->getJson('/api/v1/p/central/monitoring/logs?site_id=s2')->assertOk()
            ->assertJsonPath('data.total', 1);
        $auth->getJson('/api/v1/p/central/monitoring/logs')->assertOk()
            ->assertJsonPath('data.total', 3);
    }

    public function test_non_operator_is_forbidden(): void
    {
        $user = User::query()->create([
            'name' => 'مشتری', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Client!123'), 'role' => 'admin',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/p/central/monitoring/health')->assertForbidden();
    }
}
