<?php

namespace Tests\Feature;

use App\Models\Central\CentralLog;
use App\Models\Central\Client;
use App\Models\Central\InstallHeartbeat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** دسته UIUX — مانیتورینگ: جستجوی متنی لاگ + آپتایم ۳۰ روزه. */
class MonitoringExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        return User::query()->create([
            'name' => 'اپراتور', 'email' => uniqid().'@example.com',
            'password' => Hash::make('Operator!1'), 'role' => 'operator',
        ]);
    }

    public function test_logs_text_search(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        CentralLog::query()->create(['level' => 'error', 'message' => 'خطای اتصال دیتابیس', 'site_id' => 'site-a']);
        CentralLog::query()->create(['level' => 'info', 'message' => 'heartbeat موفق', 'site_id' => 'site-b']);

        $res = $auth->getJson('/api/central/monitoring/logs?q='.urlencode('دیتابیس'))
            ->assertOk()->json('data');
        $this->assertSame(1, $res['total']);
        $this->assertSame('خطای اتصال دیتابیس', $res['data'][0]['message']);

        // جستجو در site_id هم می‌گردد؛ رفتار قبلی (بدون q) دست‌نخورده.
        $auth->getJson('/api/central/monitoring/logs')->assertOk();
        $auth->getJson('/api/central/monitoring/logs?site_id=site-b')->assertOk();
    }

    public function test_uptime_returns_daily_series(): void
    {
        $auth = $this->actingAs($this->operator(), 'sanctum');
        $client = Client::query()->create([
            'name' => 'مشتری', 'tier' => 'budget', 'status' => 'active',
            'site_id' => 'site-'.uniqid(),
        ]);
        InstallHeartbeat::query()->create([
            'site_id' => $client->site_id, 'status' => 'online',
            'version' => '1.0.0', 'last_seen_at' => now(),
        ]);

        $res = $auth->getJson('/api/central/monitoring/uptime?days=30')
            ->assertOk()->json('data');

        $this->assertSame(30, $res['days']);
        $row = collect($res['clients'])->firstWhere('client_id', $client->id);
        $this->assertNotNull($row);
        $this->assertSame('online', $row['today']);
        $this->assertCount(30, $row['days']);
        // فقط امروز واقعی است؛ گذشته unknown (صادقانه — تاریخچه ذخیره نمی‌شود).
        $this->assertSame('online', $row['days'][29]['status']);
        $this->assertSame('unknown', $row['days'][0]['status']);
    }
}
