/**
 * کلاینت اعلان Web Push برای سایت و پنل.
 *
 * ## چرا یک ماژول متمرکز
 *
 * منطق opt-in در سه جا لازم است: بنر صفحهٔ اصلی، تنظیمات پروفایل مدیر، و
 * اعلان «خوش‌آمد». اگر هرکدام جدا بنویسیم، سه جا می‌توانند بر سرِ
 * permission تصمیم‌های متفاوت بگیرند و کاربر بنرهای متناقض ببیند.
 *
 * ## نکته‌های مرورگر
 *
 * • `Notification.requestPermission()` فقط با **حرکت کاربر** جواب می‌دهد.
 *   صدا زدنش در `useEffect` همیشه `denied` می‌دهد.
 * • روی iOS، push فقط از نسخهٔ ۱۶.۴ و **فقط** وقتی PWA به صفحهٔ اصلی
 *   نصب شده باشد کار می‌کند. پس باید این را تشخیص دهیم و پیام درست بدهیم
 *   به‌جای اینکه بنر بی‌فایده نشان دهیم.
 */

const OPT_OUT_KEY = 'pishdad:push-opted-out';

type PermissionState = NotificationPermission | 'unsupported';

export type PushSupport = {
  supported: boolean;
  permission: PermissionState;
  reason: 'ok' | 'unsupported' | 'needs-install' | 'denied' | 'opted-out' | null;
};

function serviceWorkerSupported(): boolean {
  return typeof navigator !== 'undefined' && 'serviceWorker' in navigator;
}

function isIos(): boolean {
  if (typeof navigator === 'undefined') return false;

  const ua = navigator.userAgent;

  // iPadOS ۱۳+ خودش را مکینتاش معرفی می‌کند ولی لمسی است.
  const iPadOs = /Macintosh/.test(ua) && 'ontouchend' in document;

  return /iPhone|iPad|iPod/.test(ua) || iPadOs;
}

function isStandalone(): boolean {
  if (typeof window === 'undefined') return false;

  return (
    window.matchMedia?.('(display-mode: standalone)').matches === true ||
    (navigator as unknown as { standalone?: boolean }).standalone === true
  );
}

export function hasOptedOut(): boolean {
  if (typeof localStorage === 'undefined') return false;

  try {
    return localStorage.getItem(OPT_OUT_KEY) === '1';
  } catch {
    // حالت خصوصی مرورگر ⇒ localStorage می‌تواند پرتاب کند.
    return false;
  }
}

function rememberOptOut(): void {
  try {
    localStorage.setItem(OPT_OUT_KEY, '1');
  } catch {
    /* بی‌اهمیت — فقط یک بهینه‌سازی تجربهٔ کاربری است */
  }
}

function clearOptOut(): void {
  try {
    localStorage.removeItem(OPT_OUT_KEY);
  } catch {
    /* بی‌اهمیت */
  }
}

/**
 * وضعیت فعلی پشتیبانی.
 *
 * برای تصمیم اینکه بنر اصلاً نمایش داده شود یا نه.
 */
export function pushSupport(): PushSupport {
  if (typeof window === 'undefined' || !('Notification' in window) || !serviceWorkerSupported()) {
    return { supported: false, permission: 'unsupported', reason: 'unsupported' };
  }

  const permission = Notification.permission as NotificationPermission;

  if (hasOptedOut()) {
    return { supported: true, permission, reason: 'opted-out' };
  }

  // iOS بدون نصب PWA هیچ‌وقت push نمی‌دهد.
  if (isIos() && !isStandalone()) {
    return { supported: false, permission, reason: 'needs-install' };
  }

  if (permission === 'denied') {
    return { supported: true, permission, reason: 'denied' };
  }

  return { supported: true, permission, reason: 'ok' };
}

async function apiBase(): Promise<string> {
  const configured = process.env.NEXT_PUBLIC_API_BASE;

  if (configured) {
    return configured.replace(/\/+$/, '');
  }

  return '/api/v1';
}

function publicKeyFromEnv(): string | null {
  return process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY?.trim() || null;
}

/**
 * ثبت سرویس‌ورکر.
 *
 * ‎⚠️ فقط اگر `Service-Worker-Allowed` اجازه بدهد کار می‌کند، و این header
 * را سرورِ ما باید روی پاسخ بدهد. برای ریشهٔ دامنه لازم نیست.
 */
export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
  if (!serviceWorkerSupported()) return null;

  try {
    return await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  } catch {
    // ثبت ناموفق نباید کل صفحه را خراب کند.
    return null;
  }
}

/**
 * base64url ⇒ `ArrayBuffer` برای `applicationServerKey`.
 *
 * ## چرا `ArrayBuffer` و نه `Uint8Array`
 *
 * ‎`PushSubscriptionOptionsInit.applicationServerKey` از نوع
 * `BufferSource` است و در TypeScript جدید، `Uint8Array` با
 * `ArrayBufferLike` به آن نمی‌خورد — چون `SharedArrayBuffer` هم ممکن است.
 * پس خروجی را مستقیم `ArrayBuffer` می‌سازیم تا نوع دقیق بخورد.
 */
function base64UrlToBuffer(value: string): ArrayBuffer {
  const padding = '='.repeat((4 - (value.length % 4)) % 4);
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');

  const raw = window.atob(base64);

  const buffer = new ArrayBuffer(raw.length);
  const view = new Uint8Array(buffer);

  for (let i = 0; i < raw.length; i += 1) {
    view[i] = raw.charCodeAt(i);
  }

  return buffer;
}

/**
 * مسیر ثبت را انتخاب می‌کند.
 *
 * ‎⚠️ مسیر `push/subscribe` **عمومی** است (زائر حساب ندارد). برای همین
 * Sanctum روی آن middleware ندارد و اگر مدیر از همین مسیر subscribe کند،
 * `$request->user()` همیشه `null` می‌شود ⇒ `user_id` خالی ذخیره می‌شود ⇒
 * اعلان برایش هرگز ارسال نمی‌شود.
 *
 * پس اگر نشست فعال باشد، از مسیر احراز هویت‌شده استفاده می‌کنیم تا
 * اشتراک به کاربرِ واقعی وصل شود.
 */
function subscribeEndpoint(): string {
  return typeof document !== 'undefined' && isAdminSession()
    ? '/push/subscribe/auth'
    : '/push/subscribe';
}

/**
 * آیا نشستِ پنل فعال است؟
 *
 * عمداً از cookie استفاده می‌شود نه از state ری‌اکت، چون این تابع باید در
 * event handler (خارج از رندر) هم درست کار کند. کوکی Sanctum فقط
 * **وجودش** را می‌بینیم؛ اعتبارسنجی واقعی سمت سرور انجام می‌شود و اگر
 * منقضی باشد مسیر عمومی fallback می‌شود.
 */
function isAdminSession(): boolean {
  if (typeof document === 'undefined') return false;

  const cookie = document.cookie;

  return /(?:^|;\s*)XSRF-TOKEN=/.test(cookie) || /cms_session/.test(cookie);
}

/**
 * کاربر را opt-in می‌کند: permission می‌گیرد، سرویس‌ورکر ثبت می‌کند و
 * اشتراک برای سرور ارسال می‌شود.
 *
 * ‎⚠️ **باید مستقیم از رویداد کلیک صدا زده شود.** مرورگر permission را
 * بدون حرکت کاربر نمی‌دهد.
 *
 * @param  onMessage  پیام اختیاری برای تستِ دستی از پنل مدیر
 */
export async function subscribeToPush(options?: { onMessage?: string }): Promise<boolean> {
  const support = pushSupport();

  if (!support.supported) {
    return false;
  }

  if (Notification.permission === 'denied') {
    // کاربر قبلاً رد کرده — فقط باید از تنظیمات مرورگر باز کند.
    return false;
  }

  const registration = await registerServiceWorker();

  if (!registration) {
    return false;
  }

  await navigator.serviceWorker.ready;

  const permission = await Notification.requestPermission();

  if (permission !== 'granted') {
    return false;
  }

  let key = publicKeyFromEnv();

  // اگر کلید در بیلد تعریف نشده، از سرور بگیر.
  if (!key) {
    const response = await fetch(`${await apiBase()}/push/public-key`);

    if (response.ok) {
      const body = (await response.json()) as { vapid_public_key?: string | null };
      key = body.vapid_public_key ?? null;
    }
  }

  // بدون کلید عمومی، مرورگر `subscribe()` را رد می‌کند.
  if (!key) {
    return false;
  }

  const existing = await registration.pushManager.getSubscription();

  const subscription =
    existing ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64UrlToBuffer(key),
    }));

  const json = subscription.toJSON();

  if (!json.endpoint) {
    return false;
  }

  const response = await fetch(`${await apiBase()}/${subscribeEndpoint()}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      endpoint: json.endpoint,
      keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
      locale: document.documentElement.lang || 'fa',
    }),
  });

  if (!response.ok) {
    return false;
  }

  // پیام تست اختیاری — برای اینکه کاربر مطمئن شود کار می‌کند.
  if (options?.onMessage) {
    try {
      const permission = await Notification.requestPermission();

      if (permission === 'granted') {
        new Notification(options.onMessage);
      }
    } catch {
      /* بی‌اهمیت */
    }
  }

  clearOptOut();

  return true;
}

/** لغو اشتراک و پاک کردن آن از سرور. */
export async function unsubscribeFromPush(): Promise<boolean> {
  rememberOptOut();

  if (!serviceWorkerSupported()) {
    return false;
  }

  try {
    const registration = await navigator.serviceWorker.getRegistration();

    const subscription = await registration?.pushManager.getSubscription();

    const endpoint = subscription?.endpoint;

    if (subscription) {
      await subscription.unsubscribe();
    }

    if (endpoint) {
      await fetch(`${await apiBase()}/push/unsubscribe`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ endpoint }),
      });
    }

    return true;
  } catch {
    return false;
  }
}

/**
 * ثبت‌کنندهٔ پیام‌های سرویس‌ورکر.
 *
 * سرویس‌ورکر وقتی کاربر از داخل اعلان opt-out می‌کند پیام می‌فرستد.
 */
export function onServiceWorkerMessage(handler: (type: string) => void): () => void {
  if (!serviceWorkerSupported()) {
    return () => undefined;
  }

  const listener = (event: MessageEvent) => {
    const type = (event.data as { type?: string } | undefined)?.type;

    if (typeof type === 'string') {
      handler(type);
    }
  };

  navigator.serviceWorker.addEventListener('message', listener);

  return () => navigator.serviceWorker.removeEventListener('message', listener);
}

/** اعلان خوش‌آمد — فقط یک‌بار در هر مرورگر. */
const WELCOME_KEY = 'pishdad:push-welcome-shown';

export function markWelcomeShown(): void {
  try {
    localStorage.setItem(WELCOME_KEY, '1');
  } catch {
    /* بی‌اهمیت */
  }
}

export function shouldShowWelcome(): boolean {
  try {
    return localStorage.getItem(WELCOME_KEY) !== '1';
  } catch {
    return false;
  }
}