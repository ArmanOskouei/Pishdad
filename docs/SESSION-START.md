# روتین شروع سشن (SESSION START)

> **این فایل قراردادِ اجراست، نه پیشنهاد.** هر سشن جدید — پیش از هر کار دیگر —
> این ترتیب را اجرا می‌کند. ایجنت اصلی مسئولِ تربیتِ ساب‌ایجنت‌هاست؛ خودش کد نمی‌نویسد.
>
> مرجعِ مکانیکی: `scripts/session-start.ps1` · مرجعِ تخته: `docs/tools/task-board.mjs`

## ۰) یک فرمان، همهٔ مقدمات

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/session-start.ps1
```

این اسکریپت به‌ترتیب:

1. junctionِ شکستهٔ `pishdad-core/backend/public/storage` را (اگر باشد) موقتاً
   بیرون می‌برد و در `finally` **دقیقاً همان reparse point** را برمی‌گرداند —
   بدون `storage:link` و بدون دست‌زدن به کانتینر.
   (علت ریشه‌ای: targetِ آن یک مسیر لینوکسی داخل کانتینر است ⇒ `WinError 1920`
   و کرشِ `detect_incremental` در graphify.)
2. `graphify update` (کد، AST، صفر توکن) و سپس `graphify label --missing-only`.
3. سلامتِ گراف: تعداد نود/یال + زمانِ آخرین بیلد.
4. `tasks-guard.mjs` و در صورتِ drift، خودترمیمی با `tasks-index.mjs`.
5. خلاصهٔ تختهٔ تسک به‌تفکیکِ دسته (قابلِ‌اجرا / done / cancelled).

اگر خروجیِ آخر **`SESSION START: DEGRADED`** بود، **قبل از هر چیز** دلیل‌ها را به
کاربر بگو و رفع کن؛ ادامهٔ کار روی گرافِ کهنه مجاز نیست.

## ۱) چک گراف (خودکار در اسکریپت، ولی تأیید لازم است)

- عددِ `nodes/links` باید نسبت به سشن قبل **غیرنزولی** باشد و `mtime` امروز باشد.
- اگر `Rebuild failed` دیدی، آن را **بی‌پرده** بگو؛ بیلدِ خاموش‌شده از بیلدِ کرش‌کرده
  بدتر است (گراف «تازه» به‌نظر می‌رسد ولی کدِ حذف‌شده را توصیف می‌کند).
- پیش از هر `read` روی اسناد/کدِ بزرگ، اول از گراف بپرس (طبق `AGENTS.md`):
  `python -m graphify query "..." --budget 1500`.

## ۲) پرسیدنِ دستهٔ تسک

خلاصهٔ شمارش را که اسکریپت چاپ کرد به کاربر نشان بده و **بپرس کدام تخته را ببیند**:

| گزینه | معنا | منبعِ فایل/محل |
|---|---|---|
| `admin` | پنل مدیریت | `admin/**`، `Api/V1/Admin/**`، `Components/Admin/**` |
| `backend` | بک‌اند | `.php`، `Api/V1/**`، `app/**`، `Services/**`، migrations |
| `frontend` | فرانت‌اند سایت | `[locale]/**`، `components/site/**`، `src/themes/**`، `lib/**` |
| `other` | زیرساخت/داک/افزونه | `docs/**`، `scripts/**`، `deploy/**`، plugins |

> نگاشت در `task-board.mjs` بر پایهٔ ستونِ «فایل/محل» است (نه عنوان)، پس اگر
> دستهٔ یک تسک غلط به‌نظر رسید، محلِ فایلش را درست کن، نه عنوان را.

## ۳) نشان‌دادنِ جدول (توضیحات کامل + مجموع + اولویت)

```powershell
node docs/tools/task-board.mjs --category=admin        # یا backend / frontend / other
node docs/tools/task-board.mjs --category=admin --all   # شاملِ done
node docs/tools/task-board.mjs --category=admin --status=pending,partial
```

- خروجیِ مارک‌داون در `.local/session/board-<category>.md` نوشته می‌شود؛ آن را با
  ابزار **read** بخوان (کنسولِ ویندوز فارسی را به‌هم می‌ریزد) و **جدولِ کامل** را
  به کاربر نشان بده.
- جدول از قبل **به ترتیبِ اولویت** مرتب است: شدتِ بخش (`Critical > High > Medium > Low`)
  و سپس وضعیت (`partial > active > pending > waiting > blocked > deferred`).
- **مجموعِ کل** و **مجموعِ قابلِ‌اجرا در هر دسته** را صریح بگو.

## ۴) اجرای موازی با ساب‌ایجنت‌های تخصصی (پس از دستور کاربر)

> قانون: **یک تسک = یک ساب‌ایجنت مالکِ فایل** برای هر تسک؛ هیچ‌وقت دو ایجنت روی
> یک فایل ننویسند. ایجنت اصلی فقط هماهنگ می‌کند و تأیید می‌گیرد.

**مدل (الزامی — به‌روزشده به دستور مالک ۲۰۲۶-۱۰-۰۷، جایگزین SS-4):**
هر ساب‌ایجنت/ساب‌سشن با **muse spark 1.3 رایگان از راه opencode** باز شود و
**opencode go به کار نرود**. اگر رایگان لیمیت شد، با
**Muse Spark 1.3 Contributor از راه opencode go** باز شود و کار انجام شود.
مدل `glm-5.3*` **ممنوعِ مطلق** است و نسخهٔ **پولیِ** `opencode-go/space-bunny` هم
ممنوع است (فقط `-free`). این را هم در تعریفِ ایجنت و هم در پارامتر `model` ابزار
`subagent` صریح بگذار. شناسه دقیق provider را از انتخاب‌گر مدل بردار؛ حدس نزن.

> ⏰ **پیک DeepSeek** (دوشنبه تا جمعه ۰۱:۰۰ تا ۰۴:۰۰ و ۰۶:۰۰ تا ۱۰:۰۰ UTC؛
> بقیه از جمله آخرهفته Off-Peak): در پیک از مدل‌های DeepSeek استفاده نکن —
> خودکار muse spark 1.3 رایگان از راه opencode (هرگز opencode go)، و اگر لیمیت
> شد Contributor از راه opencode go. اگر تغییر خودکار ممکن نبود فقط بنویس:
> `مدل را به muse spark 1.3 free تغییر بده` و منتظر بمان.

> ⚠️ ایجنت‌های `*-review` مجوز `bash` ندارند (نمی‌توانند تست بزنند). برای اجرای
> تست‌ها یک ایجنتِ bash-دار (مثل `general`) با مدلِ رایگان جداگانه باز کن.

### ماتریس واگذاری

| نوعِ تسک | پیاده‌ساز (implementer) | بازبین (verifier) |
|---|---|---|
| بک‌اند Laravel | `web-scripting__php-pro` یا `backend-development__backend-architect` | `backend-review` + `security-review` |
| دیتابیس/migration | `database-design__database-architect` | `database-review` |
| پنل مدیریت (UI+API) | `application-performance__frontend-developer` | `frontend-review` + `ui-design__accessibility-expert` |
| فرانت‌اند سایت | `application-performance__frontend-developer` | `frontend-review` + `ui-design__accessibility-expert` |
| تست | `unit-testing__test-automator` / `backend-development__test-automator` | — |
| معماری/تصمیم | `architect-review` | — |
| دیپلوی | `host-deploy-agent` (فقط با creds) | `deployment-review` |
| گراف/داک (رایگان) | `graphify-updater` | — |

الگوهای آمادهٔ هارنس: `agent-teams__team-lead` (تقسیم کار) ·
`agent-teams__team-implementer` (مالکیتِ فایل) · `agent-teams__team-reviewer` ·
`agent-teams__team-debugger`.

### قواعد موازی‌سازی

1. هر تسک را با `subagent(..., background=true)` بالا بیاور تا هم‌زمان پیش بروند؛
   سقفِ پیشنهادی **۳ تا ۵ ایجنت هم‌راستا**.
2. **نقاط اشتراکِ فایل** (routes، رجیستری‌ها، `types`، i18n) را از ابتدا تعیینِ
   مالک کن؛ اگر دو تسک یک فایل را می‌خواهند، **سریالی** کن.
3. به هر ساب‌ایجنت در پرامپت بده: شناسهٔ تسک، متنِ کاملِ ردیف از `TASKS.md`،
   فایل‌های مالکیت‌شده، و **معیارِ پذیرش (DoD)**.
4. هر پیاده‌ساز باید بگوید: فایل‌های تغییرکرده، تست‌های اجراشده، شواهد.
   بعد از پایان، بازبین‌ها اجرا شوند؛ یافته‌ها تجمیع و به کاربر گزارش شود.
5. پس از سبزشدن، وضعیتِ ردیف در `TASKS.md` را `done` کن و `tasks-index.mjs` +
   `tasks-guard.mjs` را بزن (PASS لازم است).

## ۵) قیدهای همیشگی

- **ثبت پیش از اجرا:** هر درخواستِ جدید کاربر **اول** در `docs/TASKS.md` ثبت می‌شود.
- **ضد توقف:** بعد از هر shell/build/deploy بلافاصله مرحلهٔ بعدی را شروع کن
  (سه استثنای `AGENTS.md`).
- **بدون CDN خارجی، بدون React #301، گراف را تازه نگه دار** — طبق `AGENTS.md`.
- در پایان تسک، `graphify` را (ساب‌ایجنتِ رایگان) به‌روز کن.
