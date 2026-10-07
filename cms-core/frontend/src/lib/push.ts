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
 *   صدا زدنش در `useEffect` همیشه `denied` می‌دهد. حتی در event handler هم
 *   باید **اولین کاری که می‌کنیم** باشد: هر `await` قبل از آن، «user
 *   activation» را مصرف می‌کند و روی iOS نتیجه‌اش قطعاً `denied` است.
 * • روی iOS، push فقط از نسخهٔ ۱۶.۴ و **فقط** وقتی PWA به صفحهٔ اصلی
 *   نصب شده باشد کار می‌کند. پس باید این را تشخیص دهیم و پیام درست بدهیم
 *   به‌جای اینکه بنر بی‌فایده نشان دهیم.
 */

import { API_BASE } from './api';
import { publicConfig } from './runtime-config.ts';

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

/**
 * ریشهٔ پروکسیِ هم‌مبدأ.
 *
 * ‎⚠️ **این تنها راهی است که توکنِ نشستِ پنل به بک‌اند می‌رسد.** نشست کوکی‌محور
 * نیست: توکن عمداً در localStorage نیست و فقط در کوکیِ httpOnlyِ `auth_token`
 * زندگی می‌کند، که از JS خوانده نمی‌شود ⇒ مرورگر نمی‌تواند خودش
 * `Authorization: Bearer` بسازد. Route Handlerِ `app/api/proxy/[...path]` آن
 * کوکی را می‌خواند و Bearer را اضافه می‌کند (`src/lib/proxy-allowlist.ts`
 * ریشهٔ `v1` را باز می‌داند؛ تنها `v1/internal` منع شده).
 */
const PROXY_BASE = '/api/proxy';

/**
 * URL یک endpoint اعلان را می‌سازد.
 *
 * ‎⚠️ این تابع **قبلاً** `process.env.NEXT_PUBLIC_API_BASE` را می‌خواند که در
 * هیچ فایلی از ریپو تعریف نشده بود، پس همیشه به fallbackِ نسبیِ `/api/v1`
 * می‌افتاد. ولی `next.config.ts` هیچ `rewrite` ندارد، یعنی آن درخواست روی
 * origin خودِ Next می‌رفت و 404 می‌شد ⇒ `GET /push/public-key` هرگز کلید
 * نمی‌داد ⇒ opt-in **همیشه** شکست می‌خورد (بی‌صدا، چون caller فقط `false` می‌گیرد).
 *
 * ‎`API_BASE` تنها منبعِ حقیقتِ base است (از `publicConfig().apiUrl` در
 * `runtime-config.ts`) — دقیقاً همان چیزی که `src/lib/api.ts` هم استفاده می‌کند.
 *
 * ‎فقط برای مسیرهای **عمومی** (`public-key`, `subscribe`, `unsubscribe`) — مسیر
 * احراز هویت‌شده از {@link PROXY_BASE} می‌رود، نه از این.
 *
 * @param endpoint _segment_ بعد از `push/` — مثلاً `public-key` یا `subscribe`.
 */
function pushUrl(endpoint: string): string {
  return `${API_BASE}/v1/push/${endpoint}`;
}

function publicKeyFromEnv(): string | null {
  return publicConfig().vapidPublicKey || null;
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
 * URL ثبت اشتراک را انتخاب می‌کند — و اینجا **دو مسیرِ واقعاً متفاوت** است.
 *
 * ‎⚠️ مسیر عمومی `push/subscribe` برای زائر است: نه Sanctum دارد، نه `user_id`
 * پر می‌کند. برای همین مدیر نباید از آن استفاده کند، وگرنه `$request->user()`
 * همیشه `null` می‌شود ⇒ اشتراک بی‌صدا به هیچ‌کس وصل نمی‌شود ⇒ اعلان برایش هرگز
 * نمی‌رسد (و در `ContentPublishedNotifier` فیلتر `user_id IS NOT NULL` آن را حذف
 * می‌کند).
 *
 * ‎⚠️ **تصمیم از flag صریح می‌آید، نه از بوییدنِ کوکی.** نسخهٔ قبل این را با
 * `document.cookie` حدس می‌زد، ولی کوکیِ واقعیِ نشست (`auth_token`) **httpOnly**
 * است و از JS دیده نمی‌شود؛ آن دو نامی که تست می‌شدند (`XSRF-TOKEN`/`cms_session`)
 * هرگز ست نمی‌شدند ⇒ همه‌چیز همیشه به مسیر عمومی می‌افتاد.
 *
 * ‎⚠️ چرا مدیر **مستقیم** به بک‌اند نمی‌رود (ولی زائر می‌رود):
 *
 * • `POST ${API_BASE}/v1/push/subscribe/auth` با `credentials: 'include'` جواب
 *   نمی‌دهد، و به دو دلیلِ جدا: Sanctum کوکیِ `auth_token` را اصلاً نمی‌شناسد
 *   (احراز هویتش Bearer است)، و CORS پیش‌فرض لاراول چون `config/cors.php` ندارد
 *   `supports_credentials: false` است، پس مرورگر اصلاً preflight را عبور نمی‌دهد.
 * • راه درست، پروکسیِ هم‌مبدأ است: Request به `/api/proxy/...` می‌رود، همان
 *   origin است (پس CORS در کار نیست)، و Route Handler کوکیِ `auth_token` را
 *   می‌خواند و `Authorization: Bearer` را برای ما اضافه می‌کند.
 *
 * ‎پس `authenticated` تعیین می‌کند «مبدأِ درخواست کجا باشد»، نه اینکه چه هدری
 * بفرستیم. مسیر `v1/push/subscribe/auth` هم در allowlist باز است.
 */
function subscribeUrl(authenticated: boolean): string {
  return authenticated ? `${PROXY_BASE}/v1/push/subscribe/auth` : pushUrl('subscribe');
}

/** گزینه‌های opt-in اعلان. */
export type SubscribeOptions = {
  /**
   * آیا این فراخوانی از یک نشستِ احراز هویت‌شده (پنل مدیر) می‌آید؟
   *
   * ‎`true` ⇒ از راه پروکسیِ هم‌مبدأ `/api/proxy/v1/push/subscribe/auth` تا
   * Route Handler توکنِ کوکیِ `auth_token` را به Bearer تبدیل کند و `user_id` به
   * کاربرِ واقعی وصل شود.
   * ‎`false`/حذف‌شده ⇒ مستقیم به مسیر عمومی `push/subscribe` (بنرِ زائر).
   *
   * جزئیاتِ چرایش در {@link subscribeUrl} آمده — آن را نخوان، این را بخوان.
   */
  authenticated?: boolean;
  /** پیام اختیاری برای تستِ دستی از پنل مدیر. */
  onMessage?: string;
};

/**
 * کاربر را opt-in می‌کند: permission می‌گیرد، سرویس‌ورکر ثبت می‌کند و
 * اشتراک برای سرور ارسال می‌شود.
 *
 * ‎⚠️ **باید مستقیم از رویداد کلیک صدا زده شود** و `requestPermission()`
 * باید اولین کاری باشد که می‌کنیم: هر `await` قبل از آن «user activation»
 * را مصرف می‌کند و روی iOS نتیجه‌اش قطعاً `denied` است.
 */
export async function subscribeToPush(options?: SubscribeOptions): Promise<boolean> {
  const authenticated = options?.authenticated === true;
  const support = pushSupport();

  if (!support.supported) {
    return false;
  }

  if (Notification.permission === 'denied') {
    // کاربر قبلاً رد کرده — فقط باید از تنظیمات مرورگر باز کند.
    return false;
  }

  // ⚠️ اولین کارِ ممکن، بدون هیچ `await` قبل از آن. (ترتیب، ویژگی است نه تصادف.)
  const permission = await Notification.requestPermission();

  if (permission !== 'granted') {
    return false;
  }

  // تازه حالا می‌توان کارهای کند را کرد — فعال‌سازی مصرف شده و دیگر لازم نیست.
  const registration = await registerServiceWorker();

  if (!registration) {
    return false;
  }

  await navigator.serviceWorker.ready;

  let key = publicKeyFromEnv();

  // اگر کلید در بیلد تعریف نشده، از سرور بگیر.
  if (!key) {
    const response = await fetch(pushUrl('public-key'));

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

  const response = await fetch(subscribeUrl(authenticated), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    // ‎⚠️ **عمداً بدون `credentials`.** برای مسیر عمومی لازم نیست (و CORS
    // پیش‌فرض لاراول با `supports_credentials: false` آن را نمی‌پذیرد)، و
    // برای مسیرِ پروکسی هم بی‌معناست: درخواست هم‌مبدأ است و کوکی خودکار می‌رود.
    // توکن را Route Handler اضافه می‌کند، نه مرورگر.
    body: JSON.stringify({
      endpoint: json.endpoint,
      keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
      locale: document.documentElement.lang || 'fa',
    }),
  });

  // ۴۰۱ یعنی نشست منقضی شده. عمداً نه redirect می‌کنیم (برخلاف `authed()`) و نه
  // throw: این فقط یک دکمهٔ اختیاری در کارت تنظیمات است، و callerها
  // try/catch ندارند — پرتاب کردن دکمه را برای همیشه در حالت «در حال فعال‌سازی…»
  // گیر می‌انداخت. fail بسته بهتر از ثبتِ بی‌صاحب است: اشتراکی که `user_id`
  // ندارد هرگز اعلان نمی‌گیرد، پس بی‌صدا شکست خوردنش صادقانه‌تر است.
  if (!response.ok) {
    return false;
  }

  // پیام تست اختیاری — برای اینکه کاربر مطمئن شود کار می‌کند.
  // permission از قبل `granted` است، پس دیگر نیازی به پرسش دوباره نیست.
  if (options?.onMessage) {
    try {
      new Notification(options.onMessage);
    } catch {
      /* بی‌اهمیت */
    }
  }

  clearOptOut();

  return true;
}

/** گزینه‌های لغو اشتراک. */
export type UnsubscribeOptions = {
  /**
   * برای تقارن با {@link SubscribeOptions}.
   *
   * ‎⚠️ **عمداً URL را عوض نمی‌کند**: routeِ `push/unsubscribe` در بک‌اند عمومی
   * است و خودش را روی `endpoint` کار می‌کند، پس برای مدیر و زائر یکی است.
   */
  authenticated?: boolean;
};

/** لغو اشتراک و پاک کردن آن از سرور. */
export async function unsubscribeFromPush(_options?: UnsubscribeOptions): Promise<boolean> {
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
      await fetch(pushUrl('unsubscribe'), {
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