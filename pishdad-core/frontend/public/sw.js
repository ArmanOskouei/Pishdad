/*
 * سرویس‌ورکر اعلان — پیشداد
 *
 * ## مسئولیت‌ها
 *
 * • نمایش اعلان‌های push دریافتی
 * • باز کردن صفحهٔ مقصد وقتی کاربر روی اعلان کلیک می‌کند
 *
 * ## چرا این فایل باید ریشه باشد
 *
 * مرورگر فقط سرویس‌ورکر را در ریشهٔ دامنه (`/sw.js`) قبول می‌کند. اگر
 * مسیرش عوض شود، رجیستری با خطای `SecurityError` رد می‌شود.
 *
 * ## چرا کش نمی‌کنیم
 *
 * اعلان‌ها محتوای شخصی‌اند و باید تازه باشند. کش کردن آن‌ها یعنی کاربر
 * اعلان کهنه می‌بیند و فکر می‌کند خبر تازه نیست.
 */

const TAG = 'pishdad-sw-v1';

/* -------------------------------------------------------------- lifecycle */

self.addEventListener('install', (event) => {
  // عمداً `skipWaiting`: این سرویس‌ورکر هیچ کش و هیچ handler برای `fetch`
  // ندارد، پس «کاریزاده‌کردن» صفحه را در میانهٔ کار کاربر انجام نمی‌دهد و
  // نسخهٔ تازه بلافاصله push می‌گیرد.
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();

      await Promise.all(keys.filter((key) => key !== TAG).map((key) => caches.delete(key)));

      // ⚠️ `claim` باید داخل `waitUntil` باشد وگرنه مرورگر آن را نمی‌بیند و
      // کنترل پیش از پایان، رها می‌شود.
      await self.clients.claim();
    })(),
  );
});

/* ------------------------------------------------------------------ push */

/**
 * پیام push می‌رسد.
 *
 * ⚠️ داده می‌تواند هم متن ساده باشد و هم JSON. هر دو را می‌پذیریم چون
 * نسخهٔ قدیمی‌تر سرور متن خام می‌فرستد.
 */
self.addEventListener('push', (event) => {
  let payload = {};

  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = { body: event.data ? event.data.text() : '' };
  }

  event.waitUntil(showNotification(payload));
});

/**
 * بستن اعلان با کلیک.
 *
 * `clients.openWindow` برای تب‌های تازه است؛ اگر تبی از قبل باز است بهتر
 * همان را جلو می‌آوریم تا کاربر رشتهٔ تب‌های تکراری جمع نشود.
 */
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  const target = safeTarget(event.notification.data?.url);

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        // ⚠️ تطبیق با `pathname` تجزیه‌شده، نه `url.includes(target)`. الگوی
        // قبلی substring بود: مسیرِ `/blog/post-1` تبِ `/blog/post-12` را هم
        // «همان صفحه» حساب می‌کرد.
        if (samePath(client.url, target) && 'focus' in client) {
          return client.focus();
        }
      }

      return self.clients.openWindow(target);
    }),
  );
});

/* ---------------------------------------------------------------- helpers */

/**
 * ⚠️ سخت‌گیریِ امنیتیِ مقصد کلیک.
 *
 * `data.url` از سرور می‌آید و به داده‌ای که مدیرِ سایت کنترلش می‌کند وابسته
 * است. اگر همان متنِ خام به `openWindow` برسد، slugِ `//evil.example/x`
 * به یک URLِ protocol-relative تبدیل می‌شود و کاربر به دامنهٔ بیرونی
 * پرتاب می‌شود. پس هر چیزی که originِ هم‌دامنه نداشته باشد، به خانه
 * برمی‌گردد.
 */
function safeTarget(raw) {
  if (typeof raw !== 'string' || raw === '') {
    return '/';
  }

  let url;

  try {
    url = new URL(raw, self.location.origin);
  } catch {
    return '/';
  }

  if (url.origin !== self.location.origin) {
    return '/';
  }

  return `${url.pathname}${url.search}${url.hash}`;
}

/** آیا URLِ تب در همین دامنه و با همین مسیر است؟ */
function samePath(clientUrl, target) {
  try {
    const url = new URL(clientUrl, self.location.origin);

    return url.origin === self.location.origin && url.pathname === new URL(target, self.location.origin).pathname;
  } catch {
    return false;
  }
}

async function showNotification(payload) {
  const title = payload.title || 'پیشداد';

  const options = {
    body: payload.body || '',
    // ⚠️ فقط آیکون‌هایی که واقعاً در `public/icons/` هستند. ارجاع به
    // فایلِ ناموجود بی‌صدا باعث می‌شود اعلان بدون آیکون نمایش داده شود.
    icon: payload.icon || '/icons/icon-128.png',
    badge: payload.badge || '/icons/icon-128.png',
    // ‎tag باعث می‌شود اعلان‌های هم‌موضوع جایگزین هم شوند نه اینکه پشت
    // سر هم جمع شوند.
    tag: payload.tag || undefined,
    // ⚠️ همین‌جا هم مهلک: مقصد پیش از ذخیره در `data` اعتبارسنجی می‌شود، نه
    // فقط هنگام کلیک. وگرنه هر `data.url` ناامن تا وقتی کاربر کلیک نکرده
    // باقی می‌ماند.
    data: { url: safeTarget(payload.url) },
    dir: 'rtl',
    lang: 'fa',
  };

  await self.registration.showNotification(title, options);
}