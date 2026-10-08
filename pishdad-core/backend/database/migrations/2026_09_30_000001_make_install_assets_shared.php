<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dedupe('themes');
        $this->dedupe('plugins');
        $this->makeShared('themes', true);
        $this->makeShared('plugins', false);
    }

    public function down(): void
    {
        $this->restoreScoped('themes', true);
        $this->restoreScoped('plugins', false);
    }

    /**
     * نصب تک‌سایتی: slug باید یکتا باشد، ولی هر کاربر قبلاً قالب/پلاگین خودش را
     * با slug یکسان می‌ساخت. رکورد فعال (یا جدیدتر) هر slug نگه داشته می‌شود و
     * بقیه حذف تا ایندکس یکتای سراسری ساخته شود.
     */
    private function dedupe(string $table): void
    {
        $duplicates = DB::select(
            "SELECT slug, array_agg(id ORDER BY id) AS ids
             FROM {$table}
             WHERE slug IS NOT NULL
             GROUP BY slug
             HAVING count(*) > 1"
        );
        foreach ($duplicates as $row) {
            $ids = array_map('intval', str_replace(['{', '}'], '', explode(',', $row->ids)));
            $keep = DB::selectOne(
                "SELECT id FROM {$table}
                 WHERE slug = ?
                 ORDER BY active DESC NULLS LAST, id DESC
                 LIMIT 1",
                [$row->slug]
            );
            $keepId = (int) $keep->id;
            $drop = array_values(array_filter($ids, fn (int $id): bool => $id !== $keepId));
            if ($drop === []) {
                continue;
            }
            $list = implode(',', $drop);
            DB::statement("DELETE FROM {$table} WHERE id IN ({$list})");
        }
    }

    private function makeShared(string $table, bool $hasActiveIndex): void
    {
        $indexes = $this->indexes($table);

        if (in_array($table.'_user_id_slug_unique', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['user_id', 'slug']);
            });
        }
        if ($hasActiveIndex && in_array($table.'_user_id_active_index', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['user_id', 'active']);
            });
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['user_id']);
        });
        DB::statement("ALTER TABLE {$table} ALTER COLUMN user_id DROP NOT NULL");
        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        $indexes = $this->indexes($table);
        if (! in_array($table.'_slug_unique', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('slug');
            });
        }
        if ($hasActiveIndex && ! in_array($table.'_active_index', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->index('active');
            });
        }
    }

    private function restoreScoped(string $table, bool $hasActiveIndex): void
    {
        $indexes = $this->indexes($table);

        if (in_array($table.'_slug_unique', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique('slug');
            });
        }
        if ($hasActiveIndex && in_array($table.'_active_index', $indexes, true)) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropIndex('active');
            });
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropForeign(['user_id']);
        });
        DB::statement("ALTER TABLE {$table} ALTER COLUMN user_id SET NOT NULL");
        Schema::table($table, function (Blueprint $blueprint) use ($hasActiveIndex): void {
            $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $blueprint->unique(['user_id', 'slug']);
            if ($hasActiveIndex) {
                $blueprint->index(['user_id', 'active']);
            }
        });
    }

    private function indexes(string $table): array
    {
        return collect(DB::select(
            'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
            [$table]
        ))->map(fn ($row) => $row->indexname)->all();
    }
};
