# `_quarantine` — افزونه‌های قرنطینه‌شده

> **این پوشه هرگز نباید در نسخهٔ رایگان/عمومی (`dist-pishdad`) برود.**
> اسکریپت‌های `scripts/build-distribution.mjs` و `scripts/verify-distribution.mjs`
> به‌صراحت آن را رد می‌کنند. اسم پوشه با `_` شروع می‌شود و `PluginReleaseManager`
> آن را یک slug معتبر نمی‌داند، پس نه PSR-4 ثبت می‌شود، نه route، نه مانیفست.

## چه چیزی اینجاست و چرا

| مسیر | چیست | چرا قرنطینه شد |
|---|---|---|
| `central/` | افزونهٔ پنل مرکزی (بازبینی، مشتریان، صورتحساب، چابکان، به‌روزرسانی، مانیتورینگ) | ویژگی پنل مرکزی صاحب پلتفرم است، نه بخشی از نسخهٔ رایگان (تصمیم ۲۰۲۶-۱۰-۰۳ — J11) |
| `subscription/` | افزونهٔ «اشتراک من» (پلن/فاکتور/تغییر پلن) | همان دلیل؛ فروش اشتراک از پنل مرکزی انجام می‌شود (J11) |
| `core-subscription/` | لایهٔ **هستهٔ** اشتراکِ دور: `RemoteSubscriptionResolver`, `Transport`, `HttpTransport` و تستشان | نسخهٔ رایگان نباید هیچ مسیر کدِ اشتراکِ دور داشته باشد (J12) |

> ⚠️ `PushSubscription` (وب‌پوش/اعلان مرورگر) اینجا **نیست** و به اشتراک
> مربوط نمی‌شود. آن یک قابلیت عمومیِ هسته است و دست‌نخورده می‌ماند (J13).

## چطور برشان گردانیم (restore)

این فایل‌ها قبلاً tracked نبودند (در `.gitignore` هستند)، پس انتقال فیزیکی با
`Move-Item`/`mv` انجام شده، نه `git mv`. برای بازگردانی:

```powershell
# از ریشهٔ مخزن
Move-Item "cms-core/backend/plugins/_quarantine/central"      "cms-core/backend/plugins/central"
Move-Item "cms-core/backend/plugins/_quarantine/subscription" "cms-core/backend/plugins/subscription"

# لایهٔ هستهٔ اشتراک
Move-Item "cms-core/backend/plugins/_quarantine/core-subscription/RemoteSubscriptionResolver.php" "cms-core/backend/app/Services/Subscription/"
Move-Item "cms-core/backend/plugins/_quarantine/core-subscription/Transport.php"                   "cms-core/backend/app/Services/Subscription/"
Move-Item "cms-core/backend/plugins/_quarantine/core-subscription/HttpTransport.php"               "cms-core/backend/app/Services/Subscription/"
Move-Item "cms-core/backend/plugins/_quarantine/core-subscription/RemoteSubscriptionResolverTest.php" "cms-core/backend/tests/Feature/"
```

بعد از بازگردانی، `SubscriptionResolver::impl()` را دوباره به resolverِ واقعی
وصل کنید. روش پیشنهادی (بدون هارد-کدکردن نام کلاس در هسته) با پیکربندی است:

```dotenv
SUBSCRIPTION_RESOLVER=App\Services\Subscription\RemoteSubscriptionResolver
# یا برای حالت افزونه‌ای:
SUBSCRIPTION_RESOLVER=App\Services\Subscription\PluginSubscriptionResolver
```

سپس `php artisan config:clear` و تست‌ها:

```bash
docker exec cms-app php artisan test --filter="QuarantinedPluginsInvisible|PushSubscription"
```

## قفلِ ایمنی

`tests/Feature/QuarantinedPluginsInvisibleTest.php` ثابت می‌کند که اسکنِ افزونه‌ها
(`PluginReleaseManager::sourceSlugs()`)، مسیر PSR-4 و route-discovery هرگز
`central`/`subscription` را نمی‌بینند. اگر کسی این پوشه را اشتباهی به
`plugins/{slug}` برگرداند یا اسمش را عوض کند، آن تست قرمز می‌شود.
