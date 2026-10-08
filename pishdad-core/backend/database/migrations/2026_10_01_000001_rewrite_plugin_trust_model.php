<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| فاز ۰ — بازنویسی مدل اعتماد پلاگین.
|
| پیش از این مهاجرت، سه مفهوم متفاوت زیر یک نام واحد (`signature_valid`) مخلوط شده بودند:
|
|   ۱. اصالت    — بایت‌های مانیفست از کلید خصوصی ناشر آمده است.
|   ۲. یکپارچگی — فایل‌های روی دیسک همان‌اند که در لحظه نصب بودند.
|   ۳. تأیید ما — پلتفرم این نسخه را دیده و تأیید کرده (اداری، نه رمزنگاری).
|
| نتیجه: `config('plugins.public_key')` یک کلید واحد بود، پس
| `signature_valid = true` عملاً یعنی «امضاشده توسط ما» — یعنی همان «تأیید»،
| با لباس «اصالت». با باز شدن مسیر آپلود محلی این مدل فرو می‌ریزد.
|
| این مهاجرت چهار کار می‌کند:
|   - تفکیک سه مفهوم به سه ستون مستقل + `digest_verified_at` (fail-closed: null).
|   - افزودن `publisher_keys` به‌عنوان trust store چندناشره.
|   - افزودن `system_plugins` به‌عنوان allowlist؛ `system` دیگر از مانیفست خوانده نمی‌شود.
|   - تغییر پیش‌فرض `review_status` از `approved` به `unverified` (رفع fail-open).
|
| نکته: `content_digest` در این فاز NULL می‌ماند چون نیازمند استخراج ZIP است (فاز ۱).
| NULL یعنی «هرگز تأیید نشده» — عمداً fail-closed.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            // منشأ نصب: local (آپلود دستی) | market (بازار) | core (قالب مرکزی)
            $table->string('source', 20)->default('local')->after('system');
            $table->index(['source']);

            // ۱. اصالت — آیا امضای ناشر معتبر است؟ trust store جدید.
            // طول ۶۴ = hex(sha256(public_key)) که همان key_id اعلام‌شده در مانیفست است.
            $table->string('publisher_key_id', 64)->nullable()->after('source');
            $table->boolean('publisher_verified')->default(false)->after('publisher_key_id');

            // ۲. یکپارچگی — آیا فایل‌های روی دیسک با آنچه امضا شده یکی است؟
            $table->string('content_digest', 64)->nullable()->after('publisher_verified');
            $table->index(['content_digest']);
            $table->timestamp('digest_verified_at')->nullable()->after('content_digest');
        });

        // رفع fail-open: هر مسیر کدی که review_status را ست نکند، قبلاً روی
        // 'approved' می‌افتاد. یعنی یک forceFill یا مسیر آینده بازار، پلاگین
        // را خودکار تأییدشده می‌کرد.
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('review_status', 20)->default('unverified')->change();
        });

        Schema::table('themes', function (Blueprint $table) {
            $table->string('review_status', 20)->default('unverified')->change();
        });

        // trust store چندناشره. کلید واحد قبلی به‌عنوان ردیف legacy باقی می‌ماند.
        Schema::create('publisher_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('public_key');                       // base64 (sodium_crypto_sign_publickey)
            $table->string('key_fingerprint', 64)->unique();    // sha256 کلید عمومی، برای key_id
            $table->string('status', 20)->default('active');   // active | revoked
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });

        // allowlist پلاگین سیستمی. تا پیش از این، `system: true` از مانیفست خوانده
        // می‌شد (PluginController:99 و :196) — یعنی هر کاربری با پرمیشن
        // plugins.edit می‌توانست پلاگینی بسازد که نه غیرفعال شود نه حذف.
        Schema::create('system_plugins', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // پلاگین‌های موجود که پیش‌تر از طریق مانیفست system شده بودند، به
        // allowlist منتقل می‌شوند تا رفتار قبلی حفظ شود ولی دیگر مانیفست قادر
        // به اعطای آن نباشد.
        DB::table('plugins')->where('system', true)->orderBy('id')->each(function ($row) {
            DB::table('system_plugins')->insertOrIgnore([
                'slug' => $row->slug,
                'notes' => 'مهاجرت خودکار از ستون system (فاز ۰).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // ستون system بازسازی می‌شود تا allowlist و ستون هم‌راستا بمانند.
        DB::table('plugins')->orderBy('id')->each(function ($row) {
            $inAllowlist = DB::table('system_plugins')->where('slug', $row->slug)->exists();
            DB::table('plugins')->where('id', $row->id)->update(['system' => $inAllowlist]);
        });

        Schema::dropIfExists('system_plugins');
        Schema::dropIfExists('publisher_keys');

        Schema::table('plugins', function (Blueprint $table) {
            $table->dropIndex(['content_digest']);
            $table->dropIndex(['source']);
            $table->dropColumn([
                'source', 'publisher_key_id', 'publisher_verified',
                'content_digest', 'digest_verified_at',
            ]);
        });

        Schema::table('plugins', function (Blueprint $table) {
            $table->string('review_status', 20)->default('approved')->change();
        });
        Schema::table('themes', function (Blueprint $table) {
            $table->string('review_status', 20)->default('approved')->change();
        });
    }
};
