<?php

namespace Tests\Unit;

use App\Services\Push\Curve;
use App\Services\Push\Ecdh;
use App\Services\Push\Point;
use App\Services\Push\VapidKeys;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * تست کلیدهای VAPID و امضای ES256.
 *
 * ## چرا این تست‌ها حیاتی‌اند
 *
 * سه باگِ واقعی در نسخهٔ اول این کد بود که همگی **بی‌سروصدا** بودند:
 *
 * ۱. کلید خصوصی از `$details['key']` گرفته می‌شد که روی OpenSSL 3.x یک
 *    PEM **عمومی** است، نه خصوصی — پس امضا همیشه شکست می‌خورد.
 * ۲. `s` در خروجی DER اصلاً به امضا اضافه نمی‌شد — فقط `r`.
 * ۳. `r`/`s` با `''`-padding می‌آمدند، پس مختصاتِ کوتاه‌تر از ۳۲ بایت
 *    جابه‌جا می‌شدند.
 *
 * هر سه فقط در **تولید** خودش را نشان می‌دادند، پس تست باید دقیقاً همان
 * مسیر تولیدی را صدا بزند — نه یک بازسازیِ موازی.
 *
 * ## بخشِ دوم — آنچه **در دیتابیس** ذخیره می‌شود
 *
 * نیمهٔ پایین این فایل رفتارِ جدول `settings` را می‌سنجد: رمزنگاری در محل
 * ذخیره، اتمی‌بودنِ ساختِ جفت، و رفتار هنگام خرابی.
 *
 * ### چرا تراکنش دست‌به‌دست و نه `RefreshDatabase`
 *
 * `RefreshDatabase` در اولین اجرا `migrate:fresh` می‌زند — یعنی **کلِ**
 * دیتابیسِ تست را پاک می‌کند. با چند پروسهٔ تستِ هم‌زمان روی `pishdad_test` این
 * کار به `SQLSTATE[40P01]` (deadlock روی `TRUNCATE`) و «relation does not
 * exist» ختم می‌شود؛ یعنی خودِ تست‌ها قفل می‌شوند، نه یک باگ واقعی.
 *
 * این تست‌ها فقط به جدول `settings` نیاز دارند، پس به‌جای پاک‌کردنِ کلِ
 * دیتابیس: یک تراکنش باز می‌شود، ردیف‌های `group = 'push'` پاک می‌شوند و
 * آخرِ کار تراکنش برگردانده می‌شود. ایزوله‌بودن کامل است و هیچ جدولی برای
 * کسِ دیگری را درگیر نمی‌کند.
 *
 * ⚠️ تست‌های این بخش از واژه‌هایی که `Tests\TestCase` با آن‌ها قلابِ
 * «افزونهٔ مرکزی» را روشن می‌کند دوری می‌کنند — آن قلاب کلِ جدول‌های مرکزی
 * را می‌سازد و به این تست‌ها ربطی ندارد.
 */
class VapidKeysTest extends TestCase
{
    /** تنها چیزی که بخشِ ذخیره‌سازی لازم دارد. */
    private const SETTINGS_MIGRATION = '2026_09_23_000002_create_settings_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepareCleanSettings();

        $this->beginDatabaseTransactionForSettings();

        // حافظهٔ کلید عمرِ «یک درخواست» دارد. پاک‌کردنش اینجا چیزی را پنهان
        // نمی‌کند: هر تست حافظه را **خودش** از صفر می‌سازد و می‌سنجد.
        VapidKeys::flush();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    /**
     * جدولِ `settings` را آماده و پاک می‌کند — با تحملِ رقابت.
     *
     * ⚠️ چرا این‌قدر محتاط: `pishdad_test` بین چند پروسهٔ تستِ هم‌زمان مشترک
     * است و `RefreshDatabase` در اجرای دیگران `migrate:fresh` می‌زند، یعنی
     * کلِ دیتابیس را `TRUNCATE` می‌کند. بررسیِ «جدول هست؟» و استفاده از آن دو
     * دستورِ جدا هستند، پس بینِ آن‌ها می‌شود جدول را برداشتند.
     *
     * ⚠️ عمداً `fresh` خودِ ما اجرا نمی‌شود (کارِ بقیه را خراب می‌کند) و عمداً
     * کلِ مهاجرت‌ها هم اجرا نمی‌شوند (پرهزینه و برخوردی). فقط همان یک
     * مهاجرتِ لازم، آن هم فقط وقتی جدول واقعاً نیست.
     *
     * اگر باز هم شکست بخورد، خطای «relation does not exist» یعنی رقابتِ محیط
     * است، نه باگِ این تست.
     */
    private function prepareCleanSettings(): void
    {
        for ($attempt = 1; ; $attempt++) {
            if (! Schema::hasTable('settings')) {
                $this->artisan('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.self::SETTINGS_MIGRATION,
                ])->run();
            }

            try {
                DB::table('settings')->where('group', 'push')->delete();

                return;
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }

                usleep(500_000);
            }
        }
    }

    /** تراکنشِ دستی، تا `migrate:fresh` لازم نشود. */
    private function beginDatabaseTransactionForSettings(): void
    {
        DB::beginTransaction();
    }

    public function test_generated_public_key_is_an_uncompressed_point(): void
    {
        $keys = VapidKeys::generate();

        $raw = Ecdh::b64d($keys['publicKey']);

        $this->assertSame(65, strlen($raw));
        $this->assertSame("\x04", $raw[0]);
        $this->assertTrue(Curve::isOnCurve(Ecdh::decodePoint($raw)));
    }

    /**
     * مهم‌ترین تست: امضای تولیدشده باید با کلید عمومیِ متناظر **تأیید** شود.
     *
     * اگر این پاس نشود، سرویس push هر درخواست را با خطای ۴۰۱ رد می‌کند.
     *
     * `openssl_verify` امضای خامِ JWS را نمی‌پذیرد، پس اینجا DER را از
     * `r ‖ s` می‌سازیم تا بتوانیم با همان تابع استاندارد بررسی کنیم.
     */
    public function test_signature_verifies_with_the_public_key(): void
    {
        $keys = VapidKeys::generate();

        $message = 'a.b.c';

        $raw = Ecdh::b64d(VapidKeys::signEs256($message, $keys['privateKey']));

        $this->assertSame(1, openssl_verify(
            $message,
            $this->rawToDer($raw),
            $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($keys['publicKey']))),
            OPENSSL_ALGO_SHA256,
        ));
    }

    /**
     * ‎ES256 در JWS از امضای **خام** `r ‖ s` استفاده می‌کند، نه DER.
     *
     * اگر کد DER بدهد، برای بعضی کلیدها (تقریباً ۲۰٪) نامعتبر می‌شود و
     * برای بقیه درست — بدترین نوع باگ، چون «تقریباً کار می‌کند».
     */
    public function test_signature_is_raw_r_s_not_der(): void
    {
        $keys = VapidKeys::generate();

        $raw = Ecdh::b64d(VapidKeys::signEs256('x', $keys['privateKey']));

        // ‏r ‖ s هرکدام دقیقاً ۳۲ بایت
        $this->assertSame(64, strlen($raw));

        // DER با `0x30` شروع می‌شود
        $this->assertNotSame("\x30", substr($raw, 0, 1));
    }

    /**
     * هر دو عددِ امضا باید دقیقاً ۳۲ بایت باشند، حتی وقتی کوتاه‌اند.
     *
     * ASN.1 عددِ کوچک را با بایت کمتری می‌نویسد؛ اگر کد صفرگذاری نکند،
     * جابه‌جایی رخ می‌دهد و امضا برای همیشه غلط می‌شود.
     */
    public function test_both_signature_halves_are_always_32_bytes(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $keys = VapidKeys::generate();

            $raw = Ecdh::b64d(VapidKeys::signEs256('m'.$i, $keys['privateKey']));

            $this->assertSame(64, strlen($raw), 'امضا باید همیشه ۶۴ بایت باشد');

            $this->assertSame(1, openssl_verify(
                'm'.$i,
                $this->rawToDer($raw),
                $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($keys['publicKey']))),
                OPENSSL_ALGO_SHA256,
            ));
        }
    }

    public function test_signature_does_not_verify_against_a_different_key(): void
    {
        $keys = VapidKeys::generate();
        $other = VapidKeys::generate();

        $raw = Ecdh::b64d(VapidKeys::signEs256('tampered', $keys['privateKey']));

        $this->assertNotSame(1, openssl_verify(
            'tampered',
            $this->rawToDer($raw),
            $this->pointToPem(Ecdh::decodePoint(Ecdh::b64d($other['publicKey']))),
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function test_two_generated_keys_differ(): void
    {
        $first = VapidKeys::generate();
        $second = VapidKeys::generate();

        $this->assertNotSame($first['publicKey'], $second['publicKey']);
        $this->assertNotSame($first['privateKey'], $second['privateKey']);
    }

    /**
     * کلید عمومی باید از روی اسکالرِ کلید خصوصی قابل بازتولید باشد.
     *
     * اگر این نبود، VAPID یک کلید امضا می‌کرد ولی کلید عمومیِ اعلام‌شده
     * به آن تعلق نداشت — و سرویس push هر درخواست را رد می‌کرد.
     */
    public function test_public_key_is_derived_from_the_private_key(): void
    {
        $keys = VapidKeys::generate();

        $this->assertSame(
            $keys['publicKey'],
            Ecdh::publicFromPrivate(VapidKeys::base64ToScalar($keys['privateKey'])),
        );
    }

    // ============================================================ F5.3-g
    // آنچه واقعاً **در دیتابیس** می‌نشیند.
    //
    // ⚠️ هر سه اشکالِ زیر در نسخهٔ قبل بی‌سروصدا بودند و هر سه فقط با
    // دستکاریِ جدول `settings` دیده می‌شوند — نه با `generate()`.

    /**
     * 🔴 کلیدِ خصوصیِ plaintext باید **هنگام خواندن** رمز شود و برگردد.
     *
     * نصب‌های قدیمی کلید را اصلاً رمز نمی‌کردند. نسخهٔ قبل فقط
     * `return $stored` می‌کرد، یعنی plaintext را تحویل امضاکننده می‌داد و
     * ردیف **دست‌نخورده** می‌ماند — پس invariantِ «کلیدِ خصوصی هیچ‌وقت
     * رمزنشده روی دیسک نیست» برای این ردیف‌ها هیچ‌وقت برقرار نشد و یک کپی
     * از دیتابیس یا بکاپ، کلیدِ امضای push را کامل لو می‌داد.
     */
    public function test_a_legacy_plaintext_private_key_is_encrypted_again_when_read(): void
    {
        $pair = VapidKeys::generate();

        // دقیقاً شکلِ ردیفِ یک نصبِ قدیمی: عمومی سالم، خصوصی **خام**.
        $this->putPushSetting('vapid.public_key', $pair['publicKey']);
        $this->putPushSetting('vapid.private_key', $pair['privateKey']);

        $resolved = VapidKeys::get();

        // رمزنگاریِ مجدد نباید کلید را عوض کند — عوض‌کردنش یعنی بی‌اعتبار شدن
        // همهٔ اشتراک‌های آن مرورگرها.
        $this->assertSame($pair['privateKey'], $resolved['privateKey']);
        $this->assertSame($pair['publicKey'], $resolved['publicKey']);

        $stored = $this->getPushSetting('vapid.private_key');

        $this->assertNotSame(
            $pair['privateKey'],
            $stored,
            'کلید خصوصی نباید به شکل رمزنشده در دیتابیس بماند.',
        );

        $this->assertSame(
            $pair['privateKey'],
            Crypt::decryptString($stored),
            'رمزِ ذخیره‌شده باید همان کلیدِ قبلی باشد، نه کلیدِ دیگری.',
        );
    }

    /**
     * خواندنِ دوم نباید جفتِ تازه بسازد و نباید دوباره رمز کند.
     *
     * «دوباره رمز کردن» بی‌خطر به نظر می‌رسد ولی نیست: هر بار `encryptString`
     * یک MAC و یک IV تازه می‌سازد، پس **هر** خواندن ردیف را بازنویسی می‌کند.
     * نتیجه: نوشتن روی هر درخواستی که کلید را می‌خواند، و `updated_at`ِ
     * همیشه‌تغییرکننده که ابزارِ پشتیبان‌گیری را هر بار مجبور می‌کند یک
     * تفاوتِ بی‌معنا را به‌عنوان «تغییرِ واقعی» ببیند.
     */
    public function test_a_second_read_neither_regenerates_nor_re_encrypts(): void
    {
        $first = VapidKeys::get();

        $storedAfterFirst = $this->getPushSetting('vapid.private_key');
        $publicAfterFirst = $this->getPushSetting('vapid.public_key');

        // حافظهٔ درخواست را دور بریز تا خواندنِ دوم **واقعاً** به دیتابیس برود.
        VapidKeys::flush();

        $second = VapidKeys::get();

        $this->assertSame($first['publicKey'], $second['publicKey'], 'جفتِ کلید نباید بازتولید شود.');
        $this->assertSame($first['privateKey'], $second['privateKey']);

        $this->assertSame(
            $storedAfterFirst,
            $this->getPushSetting('vapid.private_key'),
            'خواندنِ دوم نباید دوباره رمز کند.',
        );

        $this->assertSame($publicAfterFirst, $this->getPushSetting('vapid.public_key'));

        // کلیدِ برگشتی باید امضا کند، یعنی واقعاً plaintext است نه ciphertext.
        $this->assertSame(64, strlen(Ecdh::b64d(VapidKeys::signEs256('x', $second['privateKey']))));
    }

    /**
     * ⭐ اثباتِ حافظه: بدون حافظه، `broadcast(…, 500)` یعنی ۱۰۰۰ کوئریِ
     * تنظیمات + ۵۰۰ ضربِ اسکالرِ P-256.
     *
     * راهِ اثباتِ مستقیم و بی‌حاشیه: ردیف‌ها را **پاک کن** و دوباره بخوان. اگر
     * حافظه کار کند، همان کلید برمی‌گردد؛ اگر نه، جفتِ تازه ساخته می‌شود.
     *
     * (تستِ مکملِ `…do_not_re_read_the_settings` همان ادعا را از سمتِ شمارشِ
     * کوئری می‌سنجد.)
     */
    public function test_the_resolved_key_pair_is_memoized_for_the_lifetime_of_the_request(): void
    {
        $first = VapidKeys::get();

        DB::table('settings')->where('group', 'push')->delete();

        $this->assertSame(
            $first,
            VapidKeys::get(),
            'در طول یک درخواست نباید دوباره به دیتابیس رفت.',
        );

        // ولی درخواستِ **بعدی** باید دوباره بخواند و دوباره بسازد.
        VapidKeys::flush();

        $next = VapidKeys::get();

        $this->assertNotSame($first, $next);
        $this->assertTrue(VapidKeys::exists());
    }

    /**
     * همان ادعا، از سمتِ هزینه: ساختنِ ۲۰ هدر نباید **هیچ** کوئریِ تنظیماتی
     * بزند. چون تنها جایی که `publicKeyFor()` (ضربِ اسکالر) صدا زده می‌شود
     * همین مسیرِ خواندن است، صفرِ کوئری یعنی صفرِ ضربِ اسکالر هم.
     */
    public function test_repeated_authorization_headers_do_not_touch_the_settings_table(): void
    {
        VapidKeys::get();

        DB::enableQueryLog();
        DB::flushQueryLog();

        for ($i = 0; $i < 20; $i++) {
            $header = VapidKeys::authorizationHeader('https://fcm.googleapis.com', 'mailto:a@b.com');

            $this->assertStringStartsWith('vapid t=', $header);
        }

        $offending = array_values(array_filter(
            DB::getQueryLog(),
            static fn (array $q): bool => str_contains($q['query'], 'settings')
                || str_contains($q['query'], 'pg_advisory_xact_lock'),
        ));

        $this->assertSame(
            [],
            $offending,
            'هدرهایِ پیاپی نباید دیتابیس را بخوانند: '.json_encode($offending, JSON_UNESCAPED_UNICODE),
        );

        DB::disableQueryLog();
    }

    /**
     * 🔴 جفتِ کلید باید **زیر قفل** ساخته شود.
     *
     * `GET push/public-key` عمومی و بدون احراز هوست، پس دو درخواستِ اولِ
     * هم‌زمان کاملاً ممکن‌اند. بدون قفل، هر دو جفت می‌ساختند و هر تماس‌گیرنده
     * یک کلید عمومیِ متفاوت می‌گرفت؛ «آخرین نویسنده» برنده می‌شد و آن مرورگرها
     * تا ابد کلیدِ اشتباه نگه می‌داشتند و ۴۰۱ می‌خوردند — بی‌سروصدا.
     *
     * ⚠️ قفلِ سطری به‌تنهایی **کافی نیست**: در PostgreSQL قفلِ سطری روی ردیفِ
     * **غایب** هیچ چیزی نمی‌گیرد، و رقابتِ واقعی دقیقاً روی ردیفِ غایب است.
     * برای همین یک قفلِ سراسریِ تراکنشی هم لازم است.
     */
    public function test_first_time_generation_happens_under_a_lock(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        VapidKeys::get();

        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        $this->assertStringContainsStringIgnoringCase(
            'for update',
            $sql,
            'ساختِ جفتِ کلید باید زیر قفلِ سطریِ ردیف‌های تنظیمات انجام شود.',
        );

        $this->assertStringContainsString(
            'pg_advisory_xact_lock',
            $sql,
            'ردیفِ غایب با قفلِ سطری گرفته نمی‌شود، پس قفلِ سراسری هم لازم است.',
        );

        DB::disableQueryLog();
    }

    /**
     * اگر جفتی از قبل هست، **هرگز** نباید بازتولید شود.
     *
     * این همان چیزی است که فراخوانِ دومِ هم‌زمان به آن تکیه می‌کند: بعد از
     * گرفتن قفل، دوباره خوانده می‌شود و اگر جفت آمده، همان برگردانده می‌شود.
     */
    public function test_an_existing_key_pair_is_never_regenerated(): void
    {
        $pair = VapidKeys::generate();

        $this->putPushSetting('vapid.public_key', $pair['publicKey']);
        $this->putPushSetting('vapid.private_key', Crypt::encryptString($pair['privateKey']));

        $resolved = VapidKeys::get();

        $this->assertSame($pair['publicKey'], $resolved['publicKey']);
        $this->assertSame($pair['privateKey'], $resolved['privateKey']);

        // ساختِ دوباره یک ردیفِ تکراری می‌ساخت (و با قیدِ یکتای `(group,key)`
        // می‌ترکید) — این نشان می‌دهد مسیرِ «ساخت» اصلاً اجرا نشده.
        $this->assertSame(
            2,
            DB::table('settings')->where('group', 'push')->count(),
            'نباید ردیفِ تکراری برای همان کلید ساخته شود.',
        );
    }

    /**
     * 🔴 رمزگشاییِ ناموفق **نباید** بی‌صدا جفتِ تازه بسازد.
     *
     * نسخهٔ قبل `catch (\Throwable) { return ''; }` داشت و `get()` آن را
     * «جفتِ ناسازگار» می‌خواند و یک جفتِ **تازه** می‌ساخت. یعنی یک
     * `APP_KEY` عوض‌شده کلیدِ امضا را عوض می‌کرد و **تمامِ** اشتراک‌های
     * ذخیره‌شده را بی‌اعتبار می‌کرد — بدونِ یک خط در لاگ. بدترین نوع خرابی:
     * نه اعلانی می‌رسد، نه کسی می‌فهمد چرا.
     *
     * وضعیتِ «رمز با کلیدِ دیگری ساخته شده» دقیقاً چیزی است که چرخشِ `APP_KEY`
     * تولید می‌کند، و `Crypt` با کلیدِ فعلی آن را نمی‌خواند.
     *
     * این یک تست سه چیز را با هم می‌سنجد چون با یک `expectException` جا
     * نمی‌شوند: بلند شکست خوردن، دست‌نخوردنِ دیتابیس، و ثبتِ لاگ.
     */
    public function test_a_decrypt_failure_throws_logs_and_keeps_the_stored_key_pair(): void
    {
        $pair = VapidKeys::generate();

        $this->putPushSetting('vapid.public_key', $pair['publicKey']);

        $foreignCiphertext = $this->encryptWithAnotherKey($pair['privateKey']);

        $this->putPushSetting('vapid.private_key', $foreignCiphertext);

        // ‌‌سanity: این ciphertext با کلیدِ فعلیِ `APP_KEY` باز نمی‌شود.
        $undecryptable = false;

        try {
            Crypt::decryptString($foreignCiphertext);
        } catch (\Throwable) {
            $undecryptable = true;
        }

        $this->assertTrue($undecryptable, 'پیش‌شرطِ تست: ciphertext باید نا‌خوانا باشد.');

        $logged = [];

        Log::spy();

        $threw = false;

        try {
            VapidKeys::get();
        } catch (\RuntimeException) {
            $threw = true;
        }

        // ۱. باید بلند شکست بخورد، نه اینکه بی‌صدا کلیدِ تازه بدهد.
        $this->assertTrue(
            $threw,
            'رمزگشاییِ ناموفق نباید جفتِ تازه بسازد؛ باید استثنا بدهد.',
        );

        // ۲. دیتابیس دست‌نخورده مانده — نه کلیدِ تازه، نه حذفِ اشتراک‌ها.
        $this->assertSame(
            $pair['publicKey'],
            $this->getPushSetting('vapid.public_key'),
            'کلید عمومیِ ذخیره‌شده نباید عوض شود.',
        );

        $this->assertSame(
            $foreignCiphertext,
            $this->getPushSetting('vapid.private_key'),
            'کلیدِ خصوصیِ ذخیره‌شده نباید بازنویسی یا دور ریخته شود.',
        );

        // ۳. لاگ شده — وگرنه این وضعیت فقط با «اعلان نمی‌رسد» خودش را
        //    نشان می‌دهد.
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use (&$logged): bool {
                $logged[] = ['message' => $message, 'context' => $context];

                return str_contains($message, 'vapid');
            })
            ->once();

        // ۴. و لاگ **هیچ مادهٔ محرمانه‌ای** ندارد.
        $haystack = (string) json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($pair['privateKey'], $haystack);
        $this->assertStringNotContainsString($foreignCiphertext, $haystack);
    }

    // ------------------------------------------------------------- کمکی‌ها

    /**
     * همان کاری که `VapidKeys` انجام می‌دهد: `settings.value` ستونِ JSON است،
     * پس مقدار باید JSON-encoded نوشته شود.
     */
    private function putPushSetting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['group' => 'push', 'key' => $key],
            ['value' => json_encode($value, JSON_UNESCAPED_SLASHES), 'updated_at' => now()],
        );
    }

    /** مقدارِ خوانده‌شده به زبانِ خود `VapidKeys` (بدونِ JSON). */
    private function getPushSetting(string $key): string
    {
        $raw = DB::table('settings')
            ->where('group', 'push')
            ->where('key', $key)
            ->value('value');

        if (! is_string($raw) || ! str_starts_with(trim($raw), '"')) {
            return (string) $raw;
        }

        $decoded = json_decode($raw, true);

        return is_string($decoded) ? $decoded : '';
    }

    /** ciphertextی که با `APP_KEY` **دیگری** ساخته شده — یعنی چرخشِ کلید. */
    private function encryptWithAnotherKey(string $plaintext): string
    {
        return (new Encrypter(random_bytes(32), 'AES-256-CBC'))->encryptString($plaintext);
    }

    /** ‎`r ‖ s` خام ⇒ DER، تا `openssl_verify` بپذیرد. */
    private function rawToDer(string $raw): string
    {
        $this->assertSame(64, strlen($raw));

        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32), "\x00");

        // ASN.1 INTEGER علامت‌دار است؛ اگر بیتِ بالا روشن باشد باید
        // یک بایت صفر جلوش گذاشته شود تا منفی تعبیر نشود.
        if ($r === '' || ord($r[0]) > 0x7F) {
            $r = "\x00".$r;
        }

        if ($s === '' || ord($s[0]) > 0x7F) {
            $s = "\x00".$s;
        }

        $body = "\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        return "\x30".chr(strlen($body)).$body;
    }

    private function pointToPem(Point $point): string
    {
        $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $bytes = Ecdh::encodePoint($point);

        $bitString = "\x03".chr(strlen($bytes) + 1)."\x00".$bytes;

        $der = "\x30".chr(strlen($algorithm.$bitString)).$algorithm.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}