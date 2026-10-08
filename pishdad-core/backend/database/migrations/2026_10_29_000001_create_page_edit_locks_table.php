<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WF-H3 — قفلِ نرمِ ویرایشِ هم‌زمانِ صفحه.
| برای هر صفحه حداکثر یک ردیف (page_id یکتا). نگه‌داشتنِ قفل با heartbeat و
| انقضای خودکار پس از TTL انجام می‌شود؛ ردیفِ کهنه با «تصاحب» جایگزین می‌گردد.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_edit_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->unique()->constrained('pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('heartbeat_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_edit_locks');
    }
};
