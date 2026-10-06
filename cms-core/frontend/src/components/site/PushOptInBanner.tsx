'use client';

/**
 * بنر opt-in اعلان.
 *
 * ## قواعد نمایش
 *
 * بنر فقط وقتی دیده می‌شود که **همهٔ** این‌ها درست باشند:
 *
 * • مرورگر از push پشتیبانی می‌کند
 * • کاربر قبلاً opt-out نکرده
 * • کاربر قبلاً permission نداده (`default`)
 *
 * ‎⚠️ اگر permission را `denied` کرده باشیم، بنر را **نشان نمی‌دهیم** —
 * تنها راه بازگرداندنش از تنظیمات مرورگر است و بنرِ بی‌فایده فقط
 * آزاردهنده می‌شود.
 *
 * ## چرا دکمهٔ «بعداً»
 *
 * بدون گزینهٔ رد کردن، کاربرِ بی‌میل هر بار که صفحه را باز می‌کند بنر
 * می‌بیند و این آزاردهنده‌تر از نبودِ کلِ قابلیت است.
 */

import { useCallback, useEffect, useState } from 'react';

import {
  hasOptedOut,
  pushSupport,
  subscribeToPush,
  unsubscribeFromPush,
} from '@/lib/push';

type Phase = 'hidden' | 'idle' | 'asking' | 'done' | 'failed' | 'unsupported';

export function PushOptInBanner() {
  const [phase, setPhase] = useState<Phase>('hidden');

  useEffect(() => {
    const support = pushSupport();

    if (!support.supported) {
      setPhase('unsupported');

      return;
    }

    // اگر کاربر قبلاً اجازه داده، دیگر چیزی برای پرسیدن نمانده.
    if (support.permission === 'granted') {
      setPhase('done');

      return;
    }

    if (hasOptedOut() || support.permission === 'denied') {
      setPhase('hidden');

      return;
    }

    setPhase('idle');
  }, []);

  const onAccept = useCallback(async () => {
    setPhase('asking');

    // بنرِ زائر ⇒ مسیر عمومی `push/subscribe` (بدون نشست).
    const ok = await subscribeToPush({ authenticated: false });

    setPhase(ok ? 'done' : 'failed');
  }, []);

  const onDismiss = useCallback(() => {
    void unsubscribeFromPush({ authenticated: false });
    setPhase('hidden');
  }, []);

  if (phase === 'hidden' || phase === 'done') {
    return null;
  }

  // روی iOS بدون نصب PWA، push کار نمی‌کند — به‌جای بنر بی‌فایده توضیح
  // می‌دهیم.
  if (phase === 'unsupported') {
    const support = pushSupport();

    if (support.reason === 'needs-install') {
      return (
        <aside
          aria-label="راهنمای اعلان"
          style={{
            background: 'var(--surface, #fff)',
            border: '1px solid var(--border, #e5e7eb)',
            borderRadius: 12,
            padding: '16px 20px',
            margin: '16px 0',
          }}
        >
          <p style={{ margin: 0, fontSize: 14, lineHeight: 1.9 }}>
            برای دریافت اعلان روی آیفون و آیپد، ابتدا این سایت را با دکمهٔ
            «هم‌رسانی» به صفحهٔ اصلی اضافه کنید، سپس دوباره همین صفحه را باز
            کنید.
          </p>
        </aside>
      );
    }

    return null;
  }

  return (
    <aside
      role="region"
      aria-label="دریافت اعلان"
      style={{
        background: 'var(--surface, #fff)',
        border: '1px solid var(--border, #e5e7eb)',
        borderRadius: 12,
        padding: '20px',
        margin: '16px 0',
      }}
    >
      <h2 style={{ margin: '0 0 8px', fontSize: 16 }}>اعلان تازه‌ترین محتوا</h2>

      <p style={{ margin: '0 0 16px', fontSize: 14, lineHeight: 1.9, color: 'var(--text-muted)' }}>
        اگر محتوای تازه‌ای منتشر شد، همین‌جا باخبر می‌شوید. هر زمان بخواهید می‌توانید
        خاموشش کنید.
      </p>

      {phase === 'failed' ? (
        <p role="status" style={{ margin: '0 0 12px', fontSize: 13, color: '#b91c1c' }}>
          اعلان‌سازی ممکن نشد. اگر قبلاً آن را در تنظیمات مرورگر مسدود کرده‌اید،
          از آنجا دوباره اجازه بدهید.
        </p>
      ) : null}

      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        <button
          type="button"
          onClick={onAccept}
          disabled={phase === 'asking'}
          style={{
            background: 'var(--primary, #0f766e)',
            color: '#fff',
            border: 'none',
            borderRadius: 8,
            padding: '10px 18px',
            fontSize: 14,
            cursor: phase === 'asking' ? 'wait' : 'pointer',
            opacity: phase === 'asking' ? 0.7 : 1,
          }}
        >
          {phase === 'asking' ? 'در حال فعال‌سازی…' : 'فعال‌سازی اعلان'}
        </button>

        <button
          type="button"
          onClick={onDismiss}
          style={{
            background: 'transparent',
            color: 'var(--text-muted)',
            border: '1px solid var(--border, #e5e7eb)',
            borderRadius: 8,
            padding: '10px 18px',
            fontSize: 14,
            cursor: 'pointer',
          }}
        >
          فعلاً نه
        </button>
      </div>
    </aside>
  );
}