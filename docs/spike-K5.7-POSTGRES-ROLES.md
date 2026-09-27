# K5.7 — spike: آیا PostgreSQL می‌تواند قاعدهٔ `{slug}_*` را enforce کند؟

**تاریخ:** ۲۰۲۶-۰۹-۲۸ · **وضعیت:** پاسخ داده شد، پیاده‌سازی نشد
**محیط سنجش:** PostgreSQL 18.6 (Alpine 15.2)، `docker-compose` محلی

## پرسش

ردیف K5.7 می‌گوید:

> نقش `pishdad_owner` برای `migrate`، نقش `pishdad_app` برای runtime با DDL فقط روی `{slug}_*`. تا وقتی این enforce نشده، prefix فقط **انتخاب** است نه **محدودیت**.

و هشدار می‌دهد که متخصص مطمئن نیست مکانیزم migration لاراول ۱۱ زیر این تفکیک بدون تغییر کار می‌کند، و یک spike دو-روزه پیشنهاد می‌کند.

## پاسخ کوتاه

**آن‌طور که ردیف نوشته شده، در PostgreSQL ممکن نیست.** `GRANT` روی **شیء** کار می‌کند نه روی **الگو**، و جدول‌های `{slug}_*` هنوز هنگام اعمال `GRANT` وجود ندارند.

اما قاعده **قابل enforce است** — با `EVENT TRIGGER`، و spike زیر ثابت می‌کند که کار می‌کند.

## یافتهٔ ۱ — وضعیت فعلی: هیچ محدودیتی وجود ندارد

```
Role name | Attributes
cms       | Superuser, Create role, Create DB, Replication, Bypass RLS
```

اتصال اپ با همین `cms` است (`.env: DB_USERNAME=cms`، `DB_HOST=db`)، و `users`، `plugins` و `settings` همه مالکشان `cms` هستند.

یعنی **امروز** prefix نه انتخاب است و نه محدودیت — اصلاً هیچ مرزی در سطح دیتابیس وجود ندارد. و چون نقش superuser است، حتی `ROW LEVEL SECURITY` هم دور زده می‌شود (`Bypass RLS` در فهرست صفات).

این مهم است چون یعنی هر چیزی که امروز در سطح کد enforce می‌شود، **تنها** لایهٔ دفاع است.

## یافتهٔ ۲ — `GRANT` با الگو کار نمی‌کند

```sql
grant all on table central_% to cms;
-- ERROR:  syntax error at or near "%"
```

این بن‌بست نیست، یک محدودیت بنیادی است:

- `GRANT` نام **شیء مشخص** می‌گیرد: `GRANT ALL ON TABLE central_clients`.
- جدول‌های `{slug}_*` هنگام نقش‌سازی ** وجود ندارند** و افزونهٔ تازه در هر لحظه می‌تواند جدول‌های تازه اعلام کند.
- بنابراین «فقط این الگو را مجاز کن» در `GRANT` قابل نوشتن نیست.

هر راه‌حل مبتنی بر `GRANT` برای این تسک، در عمل یکی از این دو کار را می‌کند و هر دو غلط‌اند:

| راه‌حل | چرا غلط است |
|---|---|
| `GRANT CREATE` روی شِما | هر افزونه‌ای می‌تواند روی **جدول‌های هسته** هم بنویسد |
| `GRANT CREATE` روی هر جدول موجود | افزونهٔ تازه شِمای خودش را ندارد و شکست می‌خورد |

## یافتهٔ ۳ — `EVENT TRIGGER` کار می‌کند (آزمایش‌شده)

سنجش عملی، با یک شِمای آزمایشی که بعداً پاک شد:

```sql
CREATE OR REPLACE FUNCTION deny() RETURNS event_trigger LANGUAGE plpgsql AS $$
DECLARE obj text; name_ text; allowed text;
BEGIN
  allowed := current_setting('guard.allowed_prefix', true);
  IF allowed IS NULL OR allowed = '' THEN RETURN; END IF;
  FOR obj IN SELECT object_identity FROM pg_event_trigger_ddl_commands() LOOP
    name_ := split_part(obj, '.', 2);
    IF name_ !~ ('^' || allowed || '[a-z0-9_]+$') THEN
      RAISE EXCEPTION 'denied %', obj;
    END IF;
  END LOOP;
END $$;

CREATE EVENT TRIGGER guard_ddl ON ddl_command_end
  WHEN TAG IN ('CREATE TABLE') EXECUTE FUNCTION deny();
```

نتیجه:

| فرمان | نتیجه |
|---|---|
| `CREATE TABLE g.acme_notes (id int)` | ✅ ساخته شد |
| `CREATE TABLE g.users (id int)` | ❌ `ERROR: denied g.users` |

پس قاعده **قابل enforce است** و دقیقاً همان چیزی است که K5.7 می‌خواهد.

### دو نکتهٔ پیاده‌سازی که spike کشف کرد

**۱. `ddl_command_end` رکورد `NEW` ندارد.** اولین تلاش با `NEW.object_identity` شکست خورد با `missing FROM-clause entry for table "new"`. در event trigger باید از `pg_event_trigger_ddl_commands()` خواند.

**۲. `object_identity` شامل نام شِما است** — `g.acme_notes`، نه `acme_notes`. اگر مستقیم مقایسه شود، **همه‌چیز رد می‌شود** حتی وقتی باید بگذرد. spike اول دقیقاً همین اشتباه را داشت و جدول مجاز را هم رد کرد؛ `split_part(obj, '.', 2)` آن را درست می‌کند.

نکتهٔ دوم یعنی هر پیاده‌سازی‌ای که این را از قلم بیندازد، یا همه‌چیز را می‌بندد یا — بدتر — همه‌چیز را باز می‌گذارد.

## آنچه این spike **نگفت**

این‌ها هنوز پاسخ ندارند و کار انجام‌شده را کامل نمی‌کنند:

1. **مسئلهٔ کاربر جاری.** `current_setting('guard.allowed_prefix')` یک تنظیم session است. هر کدام از اتصال‌ها (`migrate`، `pishdad_app`، connection جای دیگر) مقدار خودش را می‌گذارد. یعنی policy باید **بیرون از دیتابیس** توزیع شود، وگرنه هر کسی `SET` می‌کند و از قاعده رد می‌شود. این یعنی یک پیکربندی جداگانه لازم است — و همین یعنی spike تمام نیست.
2. **`ALTER TABLE ... RENAME`.** `RENAME TO` نام تازه می‌سازد و `WHEN TAG IN ('CREATE TABLE')` آن را نمی‌گیرد. یعنی می‌شود جدول مجاز را ساخت و بعد به نامی غیرمجاز تغییر نام داد.
3. **`DROP` و `TRUNCATE`.** `WHEN TAG` فعلاً فقط `CREATE TABLE` است. هرچند هدف K5.7 «DDL» است، حذف جدول هسته هم دست‌کاری است و باید جداگانه تصمیم بگیرد.
4. **مکانیزم migration لاراول.** ردیف K5.7 هشدار می‌دهد این زیر تفکیک دو-نقشی کار نمی‌کند. **سنجیده نشد** — چون خودِ طراحی (نقش دوم) هنوز انتخاب نشده، و انتخاب طراحی بر این spike اثر می‌گذارد.

## توصیه

**این را به‌عنوان «انجام شد» ثبت نکن.** قابلیت اثبات شد، ولی سه شکاف بالا هنوز باز است و یکی از آن‌ها (توزیع policy بیرون از دیتابیس) طراحی بزرگ‌تری از خودِ trigger است.

پیشنهاد ترتیب:

1. تصمیم نقش‌ها: آیا واقعاً دو نقش لازم است، یا یک نقش غیر-superuser + event trigger کافی است؟ (دومی ساده‌تر و همان چیزی است که spike ثابت کرده کار می‌کند.)
2. `RENAME TO` و `DROP` — دامنهٔ «DDL» دقیقاً چیست؟
3. توزیع policy: چطور `allowed_prefix` به هر session می‌رسد بدون اینکه قابل دور زدن باشد.
4. فقط بعد از این‌ها، spike لاراول زیر تفکیک دو-نقشی.

تا آن زمان، `db.tables` و `PluginDbContract` تنها لایهٔ enforce‌اند — و این باید آگاهانه پذیرفته شود، نه اینکه فرض شود جای دیگری هم هست.
