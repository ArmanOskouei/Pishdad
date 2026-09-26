<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
| K7.5 — پیشوند central_ روی جدول‌های پنل مرکزی.
|
| معماری «schema جدا در PostgreSQL» رد شده (search_path با connection pooling
| مشکل‌دار است)، پس مرز پنل مرکزی با نام جدول ساخته می‌شود.
|
| فهرست RENAMES از وضعیت واقعی دیتابیس استخراج شده، نه از حدس:
| از ۱۹ مدل App\Models\Central، یازده تا از قبل پیشوند داشتند (central_*) و هشت تا
| بدون پیشوند بودند. هر هشت تا در 2026_09_26_000001_create_phase5_tables ساخته
| شده‌اند — همان فایلی که tickets / ticket_messages / plugins / plugin_hooks /
| notifications را هم ساخته، ولی آن پنج تا هسته‌ای‌اند (مدل‌شان در App\Models است
| نه App\Models\Central) و عمداً در این فهرست نیستند.
|
| side_presets و publisher_keys هم با وجود اسم بی‌پیشوند هسته‌ای هستند.
|
| RENAME داده را حفظ می‌کند؛ نه CREATE TABLE AS SELECT، نه dump/reload.
*/
return new class extends Migration
{
    /**
     * جدول بی‌پیشوند => نام پیشوند‌دار.
     *
     * @var array<string, string>
     */
    private const RENAMES = [
        'core_versions' => 'central_core_versions',
        'install_heartbeats' => 'central_install_heartbeats',
        'kill_switches' => 'central_kill_switches',
        'operator_activities' => 'central_operator_activities',
        'operator_hardware_keys' => 'central_operator_hardware_keys',
        'operator_setup_tokens' => 'central_operator_setup_tokens',
        'update_rollouts' => 'central_update_rollouts',
        'update_rollout_items' => 'central_update_rollout_items',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->renameOne($from, $to);
        }
    }

    public function down(): void
    {
        // تلهٔ array_reverse: این تابع کلید و مقدار را جابه‌جا نمی‌کند، فقط ترتیب را
        // برمی‌گرداند (کلیدهای رشته‌ای دست‌نخورده می‌مانند). پس برای برگشت باید
        // array_flip زد تا «پیشوند‌دار ← بی‌پیشوند» شود؛ وگرنه down() عملاً up()
        // می‌شد و مهاجرت را وارونه اعمال می‌کرد. preserve_keys هم اینجا فقط برای
        // حفظ همان ترتیب معکوس است تا فرزندان قبل از والد برگردند.
        foreach (array_reverse(array_flip(self::RENAMES), true) as $to => $from) {
            $this->renameOne($to, $from);
        }
    }

    private function renameOne(string $from, string $to): void
    {
        if (! Schema::hasTable($from)) {
            return;
        }

        if (Schema::hasTable($to)) {
            // حالت نیمه‌مهاجرت: هر دو نام وجود دارند. طبق قاعده «هرگز DROP» هیچ
            // چیزی حذف نمی‌شود و تصمیم ادغام/پاک‌سازی به کاربر واگذار می‌شود.
            Log::warning("[K7.5] هر دو جدول «{$from}» و «{$to}» موجودند؛ «{$from}» دست‌نخورده رها شد. هیچ داده‌ای حذف نشد.");

            return;
        }

        $schema = $this->schema();
        $qualifiedTo = $schema.'.'.$to;

        DB::statement('ALTER TABLE '.$this->quote($schema.'.'.$from).' RENAME TO '.$this->quote($to));

        $this->renameConstraints($qualifiedTo, $from, $to);
        $this->renameIndexes($schema, $to, $from, $to);
        $this->renameSequence($schema, $from, $to);
    }

    /**
     * PostgreSQL با RENAME TABLE خودِ ستون‌های FK دیگر جدول را دنبال می‌کند، ولی
     * نام constraint را نه. RENAME CONSTRAINT روی PK/UNIQUE ایندکس پشتیبان را هم
     * با خودش rename می‌کند، پس ایندکس‌های ساده جداگانه در renameIndexes می‌آیند.
     */
    private function renameConstraints(string $qualifiedTo, string $from, string $to): void
    {
        $rows = DB::select(
            'SELECT conname FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conrelid = to_regclass(?)',
            [$qualifiedTo]
        );

        foreach ($rows as $row) {
            $name = (string) $row->conname;

            if (! str_starts_with($name, $from.'_')) {
                continue;
            }

            $new = $to.substr($name, strlen($from));

            if ($this->constraintExists($new)) {
                continue;
            }

            DB::statement('ALTER TABLE '.$this->quote($qualifiedTo).' RENAME CONSTRAINT '.$this->quote($name).' TO '.$this->quote($new));
        }
    }

    private function renameIndexes(string $schema, string $table, string $from, string $to): void
    {
        $rows = DB::select(
            'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
            [$table]
        );

        foreach ($rows as $row) {
            $name = (string) $row->indexname;

            if (! str_starts_with($name, $from.'_')) {
                continue;
            }

            $new = $to.substr($name, strlen($from));

            if ($this->indexExists($schema, $new)) {
                continue;
            }

            DB::statement('ALTER INDEX '.$this->quote($schema.'.'.$name).' RENAME TO '.$this->quote($new));
        }
    }

    /**
     * دنبالهٔ serial بعد از RENAME TABLE خودبه‌خود rename نمی‌شود. ستون id از
     * نظر دیتابیس به OID دنباله وصل است پس default خراب نمی‌شود، ولی نام
     * بی‌پیشوندِ باقی‌مانده مرز پنل مرکزی را لو می‌دهد.
     */
    private function renameSequence(string $schema, string $from, string $to): void
    {
        $old = $from.'_id_seq';
        $new = $to.'_id_seq';

        if ($old === $new || $this->relationExists($schema, $old) === false) {
            return;
        }

        if ($this->relationExists($schema, $new) === true) {
            return;
        }

        DB::statement('ALTER SEQUENCE '.$this->quote($schema.'.'.$old).' RENAME TO '.$this->quote($new));
    }

    private function constraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM pg_constraint WHERE connamespace = current_schema()::regnamespace AND conname = ?',
            [$name]
        ) !== null;
    }

    private function indexExists(string $schema, string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 AS present FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
            [$schema, $name]
        ) !== null;
    }

    private function relationExists(string $schema, string $name): bool
    {
        return DB::selectOne('SELECT to_regclass(?) AS reg', [$schema.'.'.$name])?->reg !== null;
    }

    private function schema(): string
    {
        return (string) (DB::selectOne('SELECT current_schema() AS name')->name ?? 'public');
    }

    private function quote(string $identifier): string
    {
        $parts = array_map(
            fn (string $part): string => '"'.str_replace('"', '""', $part).'"',
            explode('.', $identifier)
        );

        return implode('.', $parts);
    }
};
