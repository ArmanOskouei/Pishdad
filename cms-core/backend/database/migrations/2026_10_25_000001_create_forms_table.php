<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            // آرایهٔ فیلدهای دلخواه: [{key,label,type,required,placeholder,options,max_length}].
            $table->json('fields');
            // مقصد ارسال پاسخ: email | ticket.
            $table->string('destination', 20)->default('ticket');
            // فقط برای مقصد email: فهرست ایمیل‌های گیرنده.
            $table->json('recipients')->nullable();
            $table->string('success_message', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
