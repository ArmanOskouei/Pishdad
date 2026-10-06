# AGENTS.md — شروع سریع هر سشن

> **اولین قدم (الزامی):** روتین `docs/SESSION-START.md` را اجرا کن — یعنی
> `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/session-start.ps1`
> (گرافیفایِ ایمن + چک سلامت + خلاصهٔ تسک‌ها). بعد دستهٔ تسک را از کاربر بپرس.
> **قدم دوم:** `docs/SUMMARY.md` را بخوان (خلاصه همه تصمیمات).
> **قدم سوم:** `docs/TASKS.md` — منبع حقیقت کارهای باقی‌مانده.
> جزئیات را فقط در صورت نیاز از فایل‌های مرجع کامل بگیر.

## 🚫 قانون ضد توقف (الزامی — باگ شناخته‌شده)

**علت باگ:** هر بار بعد از اجرای یک دستور shell (بیلد، دیپلوی، تست)، نوبت پاسخ تمام می‌شد و کار نیمه‌کاره می‌ماند؛ کاربر مجبور می‌شد دستی «ادامه بده» بگوید.

**قاعده:** بعد از هر دستور shell، build، deploy یا تست — **بلافاصله مرحله بعدیِ `docs/TASKS.md` را شروع کن**. فقط در این سه حالت متوقف شو:
1. همه ردیف‌های `docs/TASKS.md` وضعیت `done` گرفتند.
2. کاربر درخواست جدیدی داد.
3. ردیفی واقعاً `Blocked` شد و ادامه‌اش نیاز به تصمیم کاربر است.

**قانون ثبت تسک:** هر درخواست جدید کاربر **اول** در `docs/TASKS.md` ثبت شود، **بعد** اجرا شود. هیچ کاری بدون ثبت انجام نشود.

## 🚫 قانون ضد CDN خارجی (الزامی)

**علت باگ:** فونت Vazirmatn از `cdn.jsdelivr.net` لود می‌شد. در هر محیطی که دسترسی به اینترنت ندارد (یا CDN مسدود است) فونت لود نمی‌شود و مرورگر به فونت جایگزین می‌افتد — نتیجه: **عنوان هدر سایت و کادر جستجو «ناخوانا» می‌شوند**.

**قاعده:** در این پروژه **هرگز** از CDN خارجی برای فونت/آیکون/اسکریپت استفاده نکن.
- فونت‌ها: محلی در `cms-core/frontend/public/fonts/` + تعریف `@font-face` در `src/app/vazirmatn.css`
- آیکون‌ها: SVG inline
- سایر دارایی‌ها: محلی یا بک‌اند
- اگر لازم شد، اول بپرس کاربر — پیش‌فرض همیشه محلی است.

## 🚫 قانون ضد React #301 (الزامی)

**علت باگ:** فراخوانی `load()`/`fetch()` یا `setState` **در حین render** (مثلاً `if (x === null) void load()` یا `if (k !== last) { setLast(k); … }`) باعث حلقه رندر و خطای Minified React error #301 (Too many re-renders) می‌شود — کل صفحه کرش می‌کند.

**قاعده:** هر بارگذاری داده باید در `useEffect` باشد، نه در بدنه render:

```ts
const load = useCallback(async () => { … }, [id, tab]);
useEffect(() => { void load(); }, [load]);
```

- ❌ `if (x === null && !error) void load();`
- ❌ `if (key !== lastKey) { setLastKey(key); void load(); }`
- ✅ همیشه `useEffect` + آرایه وابستگی درست.

## 🛡️ قانون صرفه‌جویی توکن (مهم — هر سشن رعایت کند)

- **هرگز فایل‌های بزرگ را کامل read نکن.** اسناد مرجع (`docs/PLAN.md`, `docs/ADMIN-PAGES-SPEC.md`, `docs/DEV-TASKS.md`, `docs/DESIGN-DIRECTIONS.md`) فقط **برداشت‌های جزئی** با `offset/limit` یا `grep` خوانده شوند.
- **اول `docs/SUMMARY.md` را بخوان** — خلاصه همه تصمیمات (~۳KB).
- فایل‌های باینری (`.pdf`, `.png`, `.html` بزرگ) **هرگز read نشوند** — فقط در صورت نیاز به مرورگر.
- برای جستجو در اسناد از `grep` استفاده کن، نه `read` کامل.
- اگر فایلی > ۵۰KB است، فقط range موردنیاز را با `offset/limit` بخوان.

## 🧠 graphify — قانون گراف دانش (الزامی برای همه ایجنت‌ها)

> گراف در `graphify-out/graph.json` (~5730 نود، ~14100 یال، 328 community) ساخته شده. هوک `post-commit` نصب است.
> ساختار فایل: کلیدِ یال‌ها **`links`** است (نه `edges`)، و هر نود `file_type` دارد (`code` / `document` / `concept`).

- **قبل از هر read روی اسناد بزرگ یا کد، اول کوئری بزن:**
  ```bash
  python -m graphify query "سوال به فارسی" --budget 1500
  # یا: graphify query "How does billing work?"
  # یا: graphify path "BlockRenderer" "Database"
  # یا: graphify explain "Subscription"
  ```
  فقط اگر گراف جواب نداد، برو سراغ `read`/`grep`. این یک کوئری ~1K توکن است به جای 15K خواندن 3 فایل.
- **بعد از هر تسک / قبل از پایان سشن / بعد از تغییر هر فایل `docs/*.md`:** حتما به **ساب‌ایجنت رایگان** واگذار کن (صفر هزینه برای مدل اصلی):
  ```bash
  # opencode → Task tool (ترجیحی - رایگان، openrouter):
  Task(subagent_type="graphify-updater", prompt="Run graphify --update for E:\\Website Design\\ArmanOskouei.ir")
  # معادل دستی (وقتی Task نداری):
  graphify update "E:\\Website Design\\ArmanOskouei.ir"   # فقط کد (AST، 0 توکن)
  # برای داک حتما ساب‌ایجنت لازم است — CLI تنها کد را می‌زند، داک را نادیده می‌گیرد
  ```
  ساب‌ایجنت `graphify-updater` با `openrouter/openrouter/free` اجرا می‌شود — کد با AST (0 توکن)، داک با free LLM (~0.5K رایگان). مدل اصلی هیچ توکنی نمی‌دهد. `post-commit` هوک کد را خودکار در بک‌گراند می‌زند.
- **کد:** هوک `post-commit` به صورت خودکار `_rebuild_code` را در بک‌گراند اجرا می‌کند — نیازی به اجرای دستی بعد از کامیت کد نیست.
- **`--watch` ممنوع:** هر save را ری‌بلد می‌کند و توکن را هدر می‌دهد؛ فقط `post-commit` + واگذاری در پایان تسک کافی است.
- **`graphify label --missing-only` را بعد از هر `update` بزن:** بدون آن، communityهای تازه اسمشان را از hub می‌گیرند (مثلاً `build-flavour.ts` می‌رفت زیر `gen-types.mjs`) و کوئری‌ها بی‌ربط برمی‌گردند.
- **محدودیت‌های محیط (تجربهٔ تأییدشده):** ساب‌ایجنت `graphify-updater` با `Forbidden: Access denied by security policy` مسدود می‌شود → فاز داک را با CLI/دستی انجام بده. `pip install` هم DNS ندارد، پس فایل‌های `.sql` هرگز وارد گراف نمی‌شوند؛ برای نگه‌داشتنِ «چرا»، دلیلشان را در یک `README.md` کنارشان بنویس تا از مسیر داک وارد گراف شود. `Dockerfile` هم پارسر ندارد و در گراف نیست.
  - ⚠️ `detect_incremental` روی ویندوز با **`WinError 1920`** می‌افتد: symlinkِ لاراول `cms-core/backend/public/storage` هدفش قابل‌دسترس نیست و detect پیش از هر استخراجی `stat` می‌کند. افزودنش به `.graphifyignore` **کمک نمی‌کند** (کرش داخلِ ارزیابی خودِ الگو رخ می‌دهد). بنابراین فاز داکِ `--update` در این ماشین با خطای مسیر می‌خورد — نه با نبود کلید. رفع واقعی: یا آن symlinkِ شکسته (که gitignore است و لاراول با `storage:link` بازمی‌سازد) حذف شود، یا اسکنِ symlink در graphify اصلاح شود. چون کانتینرها بالا هستند، حذفش نیاز به تأیید کاربر دارد.
  - ✅ **رفع شد (۲۰۲۶-۱۰-۰۶):** `scripts/session-start.ps1` این junction را با `move` روی همان درایو **موقتاً بیرون می‌برد** و در `finally` **دقیقاً همان reparse point** را برمی‌گرداند ⇒ دیگر نه حذف لازم است و نه `storage:link`. هر فرمانِ graphify که فایل اسکن می‌کند (`update`/`extract`) باید از این مسیر برود؛ `label`/`query` (که فقط `graph.json` را می‌خوانند) نیازی ندارند.
  - ⚠️ ساب‌ایجنتِ `graphify-updater` هنوز `graphify update` را **مستقیم** می‌زند و روی ویندوز کرش می‌کند؛ برای فاز داک از `scripts/session-start.ps1` استفاده کن.
- **هر سشن جدید:** `graphify-out/GRAPH_REPORT.md` را به عنوان حافظه بخوان، نه کل `PLAN.md` را.

## 🚀 شروع سشن (اجباری — روتین جدید)

**هر سشن جدید، پیش از هر کار دیگر، این ترتیب را اجرا کن:**

1. **یک فرمان:** `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/session-start.ps1`
   → junctionِ شکستهٔ storage را موقتاً بیرون می‌برد و در `finally` برمی‌گرداند،
   `graphify update` + `label --missing-only` را می‌زند، سلامتِ گراف را چک می‌کند،
   `tasks-guard` را (با خودترمیمی) می‌راند و خلاصهٔ تخته را می‌دهد.
   اگر `SESSION START: DEGRADED` شد، **اول** دلیل را بگو و رفع کن.
2. **چک گراف:** عدد `nodes/links` غیرنزولی + `mtime` امروز. `Rebuild failed` را
   بی‌پرده بگو. پیش از `read` بزرگ، اول `python -m graphify query "..."`.
3. **بپرس کاربر کدام تخته را ببیند:** `admin` (پنل مدیریت) · `backend` (بک‌اند) ·
   `frontend` (فرانت‌اند سایت) · `other` (زیرساخت/داک/افزونه).
4. **جدول را نشان بده:** `node docs/tools/task-board.mjs --category=<c>` →
   خروجی در `.local/session/board-<c>.md` (با read بخوان). جدول از قبل
   **اولویت‌بندی‌شده** است (Critical>High>Medium>Low و بعد وضعیت). **مجموعِ کل و
   مجموعِ قابلِ‌اجرا** را صریح بگو.
5. **با دستور کاربر، موازی اجرا کن:** یک تسک = یک ساب‌ایجنتِ مالکِ فایل،
   `background=true`، سقف ۳–۵ ایجنت هم‌راستا، پیاده‌ساز + بازبینِ تخصصی.
   ماتریسِ کاملِ واگذاری و قواعدِ موازی‌سازی در `docs/SESSION-START.md`.
   (نقاط اشتراک مثل routes/رجیستری/i18n را سریالی کن.)
   **مدلِ هر ساب‌ایجنت/ساب‌سشن: `opencode/space-bunny-free`** (پارامتر `model` را
   هنگام صدا زدن ابزار `subagent` صریح بده).

**جزئیات، ماتریسِ ساب‌ایجنت‌ها و قیدها: `docs/SESSION-START.md`.**

## 🤖 قانون مدلِ ساب‌ایجنت‌ها (الزامی)

- **ممنوعِ مطلق:** مدلِ `glm-5.3*` را **هیچ‌جا** اجرا نکن — نه ساب‌ایجنت، نه ساب‌سشن، نه مدلِ اصلی.
- **ممنوع:** نسخهٔ **پولیِ** `opencode-go/space-bunny` (به‌دستورِ مالک). فقط نسخهٔ رایگان.
- **الزامی:** هر ساب‌ایجنت/ساب‌سشن با مدل **`opencode/space-bunny-free`** باز شود.
  هم در تعریفِ ایجنت‌های `*-review` (اکنون همان است: `model: opencode/space-bunny-free`)
  و هم **صریحاً** در پارامتر `model` هنگام فراخوانی `subagent`.
- اگر تسکی واقعاً به مدلِ دیگری نیاز داشت، **اول از کاربر بپرس**؛ پیش‌فرض همیشه `opencode/space-bunny-free` است.

## شروع کار (پس از روتین بالا)
1. `docs/SUMMARY.md` را بخوان → تصویر کامل پروژه.
2. تسک‌های خروجیِ تخته را اجرا کن: **UIUX → Backend API → Frontend** + DoD.
3. قبل از کدنویسی ماژول‌های حساس، بخش مربوط در `docs/PLAN.md` را بخوان.

## قیدهای حیاتی
- هسته خام + پلاگین اعلانی (امضای Ed25519 + schema-per-plugin)
- سوپرادمین = توکن یکبارمصرف per-install
- درگاه فقط در پنل مرکزی (مدل اشتراکی)
- RTL از روز اول (logical properties) + فونت Vazirmatn
- کش ISR چابکان اِفمِرال → cacheHandler روی Redis
- شخصی‌سازی ظاهر پنل (CSS Variables سراسری نصب، صفحه ۱.۱۴)
- امنیت/observability = شرط تمام‌شدن هر فاز

## ساختار (وقتی کد شروع شد)
`cms-core/` → `backend/` (Laravel API) + `frontend/` (Next.js) + `installer/`

## نمونه تعاملی
`docs/demo/dashboard-demo.html` — ۵ جهت بصری.

_وقتی کدنویسی شروع شد، این فایل را به‌روز کن._