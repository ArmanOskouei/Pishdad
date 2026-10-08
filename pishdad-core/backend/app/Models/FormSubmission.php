<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** WF-H10 — یک پاسخ ثبت‌شده از یک فرم عمومی. */
class FormSubmission extends Model
{
    protected $fillable = ['form_id', 'payload', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
