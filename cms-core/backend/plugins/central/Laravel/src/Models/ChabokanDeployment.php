<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;

/** رکورد دیپلوی چابکان (تسک ۳.۳) — روی Stub فقط ثبت می‌شود (دیپلوی واقعی TODO مرحله ۵). */
class ChabokanDeployment extends Model
{
    protected $table = 'central_chabokan_deployments';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['service', 'status', 'triggered_by', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
