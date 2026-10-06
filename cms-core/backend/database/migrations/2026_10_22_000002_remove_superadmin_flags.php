<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * حذفِ کاملِ «سوپرادمینِ مخفی».
 *
 * پیش‌تر یک کاربرِ مخفی با پرچم‌های `is_super_admin` (bypassِ کاملِ پرمیشن) و
 * `is_hidden` (پنهان از فهرست‌ها) وجود داشت. تصمیم مالک: حذفِ کاملِ آن — فقط
 * مدیرانِ عادی (با نقش/پرمیشنِ spatie) باقی می‌مانند.
 *
 * این مهاجرت هم رکورد(های) آن کاربر را پاک می‌کند و هم دو ستونِ bypass را.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'is_super_admin') || Schema::hasColumn('users', 'is_hidden')) {
            DB::table('users')->where(function ($q) {
                if (Schema::hasColumn('users', 'is_super_admin')) {
                    $q->where('is_super_admin', true);
                }
                if (Schema::hasColumn('users', 'is_hidden')) {
                    $q->orWhere('is_hidden', true);
                }
            })->delete();
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropColumn('is_super_admin');
            }
            if (Schema::hasColumn('users', 'is_hidden')) {
                $table->dropColumn('is_hidden');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_hidden')->default(false);
        });
    }
};
