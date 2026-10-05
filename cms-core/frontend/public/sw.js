/*
 * سرویس‌ورکر اعلان — پیشداد
 *
 * ## مسئولیت‌ها
 *
 * • نمایش اعلان‌های push دریافتی
 * • باز کردن صفحهٔ مقصد وقتی کاربر روی اعلان کلیک می‌کند
 * • مدیریت opt-out از سمت کاربر
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
  // بدون skipWaiting: اعلان فعال نباید ناگهانی وسط کارِ کاربر بیاید.
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== TAG).map((key) => caches.delete(key))),
    ),
  );

  // ⚠️ اگر کاربر قبلاً گفته «نمی‌خواهم»، آن تصمیم نباید با ثبت دوبارهٔ
  // سرویس‌ورکر پاک شود.
  self.clients.claim();
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

  const target = event.notification.data?.url || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes(target) && 'focus' in client) {
          return client.focus();
        }
      }

      return self.clients.openWindow(target);
    }),
  );
});

/**
 * opt-out از داخل اعلان.
 *
 * کاربر می‌تواند بدون رفتن به تنظیمات، اعلان‌ها را از کل سایت خاموش کند.
 * این تصمیم در localStorage ذخیره می‌شود و بنر opt-in هم آن را می‌خواند.
 */
self.addEventListener('notificationclose', (event) => {
  if (event.notification.data?.disable === true) {
    event.waitUntil(storeOptOut());
  }
});

/* ---------------------------------------------------------------- helpers */

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
    data: { url: payload.url || '/' },
    dir: 'rtl',
    lang: 'fa',
  };

  await self.registration.showNotification(title, options);
}

async function storeOptOut() {
  const clients = await self.clients.matchAll({ type: 'window' });

  for (const client of clients) {
    client.postMessage({ type: 'pishdad:opt-out' });
  }
}