'use client';

/**
 * کارت opt-in اعلان مرورگر برای مدیر (P1.13).
 *
 * ## تفاوت با بنر زائر
 *
 * • زائر فقط می‌پرسد و دکمهٔ «رد» دارد.
/// • مدیر این را به‌عنوان یک **تنظیم** می‌بیند: وضعییی فعلی، روشن/خاموش
 *   کردن، و دکمهٔ ارسال پیام آزمایشی.
///
/// دلیل: مدیر می‌خواهد مطمئن شود کار می‌کند، نه فقط اینکه پرسیده شده.
 */

import { useCallback, useEffect, useState } from 'react';

import {
  pushSupport,
  subscribeToPush,
  unsubscribeFromPush,
  type PushSupport,
} from '@/lib/push';

type Status = 'loading' | 'off' | 'on' | 'unsupported' | 'needs-install' | 'blocked';

function statusFrom(support: PushSupport, active: boolean): Status {
  if (support.reason === 'unsupported') return 'unsupported';
  if (support.reason === 'needs-install') return 'needs-install';
  if (support.permission === 'denied') return 'blocked';
  if (support.permission === 'granted' && active) return 'on';

  return 'off';
}

export function PushSettingsCard() {
  const [status, setStatus] = useState<Status>('loading');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    const support = pushSupport();

    let active = false;

    if (support.supported && 'serviceWorker' in navigator) {
      try {
        const registration = await navigator.serviceWorker.getRegistration();
        active = (await registration?.pushManager.getSubscription()) != null;
      } catch {
        active = false;
      }
    }

    setStatus(statusFrom(support, active));
  }, []);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const onEnable = useCallback(async () => {
    setBusy(true);
    setMessage(null);

    // پیام آزمایشی: مدیر باید ببیند واقعاً اعلان می‌رسد.
    // `authenticated: true` ⇒ مسیر `push/subscribe/auth` تا `user_id` پر شود.
    const ok = await subscribeToPush({
      authenticated: true,
      onMessage: 'اعلان‌های پیشداد فعال شد ✓',
    });

    setBusy(false);
    setMessage(ok ? 'اعلان‌سازی روشن شد.' : 'فعال‌سازی ممکن نشد.');
    await refresh();
  }, [refresh]);

  const onDisable = useCallback(async () => {
    setBusy(true);
    setMessage(null);

    await unsubscribeFromPush({ authenticated: true });

    setBusy(false);
    setMessage('اعلان‌سازی خاموش شد.');
    await refresh();
  }, [refresh]);

  return (
    <section
      aria-labelledby="push-settings-title"
      style={{
        background: 'var(--surface, #fff)',
        border: '1px solid var(--border, #e5e7eb)',
        borderRadius: 12,
        padding: 20,
        marginBlockEnd: 16,
      }}
    >
      <h2 id="push-settings-title" style={{ margin: '0 0 4px', fontSize: 16 }}>
        اعلان مرورگر
      </h2>

      <p style={{ margin: '0 0 16px', fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.9 }}>
        هنگام انتشار محتوای تازه، اعلان روی این دستگاه دریافت کنید.
      </p>

      <p style={{ margin: '0 0 12px', fontSize: 14 }}>
        وضعیت: <strong>{describe(status)}</strong>
      </p>

      {status === 'needs-install' ? (
        <p style={{ margin: '0 0 12px', fontSize: 13, color: '#b45309' }}>
          روی آیفون و آیپد، ابتدا این سایت را به صفحهٔ اصلی اضافه کنید و بعد دوباره تلاش
          کنید.
        </p>
      ) : null}

      {status === 'blocked' ? (
        <p style={{ margin: '0 0 12px', fontSize: 13, color: '#b91c1c' }}>
          اعلان‌سازی در تنظیمات مرورگر مسدود شده است. برای فعال کردن، از نوار آدرس گزینهٔ
          تنظیمات سایت را باز کنید.
        </p>
      ) : null}

      {message ? (
        <p role="status" style={{ margin: '0 0 12px', fontSize: 13 }}>
          {message}
        </p>
      ) : null}

      {status === 'off' || status === 'loading' ? (
        <button
          type="button"
          className="btn btn-primary"
          onClick={onEnable}
          disabled={busy || status === 'loading'}
        >
          {busy ? 'در حال فعال‌سازی…' : 'روشن کردن اعلان'}
        </button>
      ) : null}

      {status === 'on' ? (
        <button
          type="button"
          className="btn"
          onClick={onDisable}
          disabled={busy}
        >
          {busy ? 'در حال خاموش کردن…' : 'خاموش کردن اعلان'}
        </button>
      ) : null}
    </section>
  );
}

function describe(status: Status): string {
  switch (status) {
    case 'on':
      return 'روشن';
    case 'off':
      return 'خاموش';
    case 'needs-install':
      return 'نیازمند نصب برنامه روی iOS';
    case 'blocked':
      return 'مسدود شده در مرورگر';
    case 'unsupported':
      return 'پشتیبانی نمی‌شود';
    default:
      return 'در حال بررسی…';
  }
}