"use client";

/**
 * هوک احراز هویت F1 — فلو دقیق بک‌اند (AuthController):
 * login → (two_factor_required ? challenge → verify 2FA → token) : token
 * توکن هرگز در localStorage نیست؛ فقط کوکی httpOnly `auth_token` که Route Handler ست می‌کند.
 */

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { api, isChallenge, type LoginResult, type User } from "@/lib/api";
import { LOGIN_PATH } from "@/lib/login-path";
import { useTheme } from "@/lib/theme";

type Ctx = {
  user: User | null;
  loading: boolean;
  challenge: string | null;
  /** WF-M8 — ورود موفق ولی نقش کاربر «۲FA اجباری» است و هنوز فعال نشده. */
  setupRequired: boolean;
  login: (email: string, password: string) => Promise<{ need2fa: boolean; need2faSetup: boolean }>;
  verify2fa: (code: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshMe: () => Promise<void>;
};

const AuthCtx = createContext<Ctx | null>(null);

async function setCookie(token: string) {
  await fetch("/api/auth/session", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ token }) });
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [challenge, setChallenge] = useState<string | null>(null);
  const [setupRequired, setSetupRequired] = useState(false);
  const { pullFromServer, markLoggedOut } = useTheme();

  const refreshMe = useCallback(async () => {
    const me = await fetch("/api/auth/me").then((r) => (r.ok ? r.json() : null)).catch(() => null);
    setUser(me?.user ?? null);
    setLoading(false);
  }, []);

  useEffect(() => { void refreshMe(); }, [refreshMe]);

  const finishLogin = useCallback(async (r: LoginResult) => {
    if (isChallenge(r)) {
      setChallenge(r.challenge);
      return;
    }
    setChallenge(null);
    const needsSetup = r.requires_2fa_setup === true;
    await setCookie(r.token);
    // دو setState پیاپی در یک بلوک، پس React هر دو را با هم flush می‌کند و
    // دروازهٔ لاگین هیچ‌گاه با وضعیتِ ناهم‌زمان به داشبورد نمی‌پرد.
    setUser(r.user);
    setSetupRequired(needsSetup);
    // قلب ۰.۲: DB مرجع نهایی است — فقط تم سرور را بکش.
    // عمداً sync (push) نمی‌کنیم تا پیش‌فرض‌های localStorage مرورگر تازه روی DB بازنویسی نشود.
    await pullFromServer(r.token);
  }, [pullFromServer]);

  const login = useCallback(async (email: string, password: string) => {
    // از Route Handler می‌گذریم تا کوکی httpOnly هم‌زمان هندل شود
    const res = await fetch("/api/auth/login", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ email, password }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message ?? "اعتبار ورود نامعتبر است.");
    const result = (json?.data ?? json) as LoginResult;
    await finishLogin(result);
    return {
      need2fa: isChallenge(result),
      need2faSetup: !isChallenge(result) && result.requires_2fa_setup === true,
    };
  }, [finishLogin]);

  const verify2fa = useCallback(async (code: string) => {
    if (!challenge) throw new Error("شناسه مرحله دوم منقضی یا نامعتبر است.");
    const res = await fetch("/api/auth/verify-2fa", {
      method: "POST", headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ challenge, code }),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json?.message ?? "اعتبار ورود نامعتبر است.");
    await finishLogin((json?.data ?? json) as LoginResult);
  }, [challenge, finishLogin]);

  const logout = useCallback(async () => {
    await fetch("/api/auth/session", { method: "DELETE" }).catch(() => undefined);
    setUser(null);
    setChallenge(null);
    setSetupRequired(false);
    // نشست بسته شد — PUT دبونس معلق تم نباید با 401 به سرور برود.
    markLoggedOut();
  }, [markLoggedOut]);

  return <AuthCtx.Provider value={{ user, loading, challenge, setupRequired, login, verify2fa, logout, refreshMe }}>{children}</AuthCtx.Provider>;
}

export function useAuth(): Ctx {
  const c = useContext(AuthCtx);
  if (!c) throw new Error("useAuth بیرون از AuthProvider");
  return c;
}

/** خطای API با بدنهٔ کامل پاسخ، تا UI بتواند جزئیات ساختاریافته را بخواند. */
export class ApiError extends Error {
  constructor(message: string, readonly status: number, readonly payload: unknown) {
    super(message);
    this.name = "ApiError";
  }
}

/** فراخوانی multipart احرازهویت‌شده (آپلود ZIP قالب/پلاگین) — boundary دست‌نخورده می‌ماند. */
export async function authedForm<T>(path: string, form: FormData, method = "POST"): Promise<T> {
  const res = await fetch(`/api/proxy${path}`, { method, body: form, cache: "no-store" });
  const json = await res.json().catch(() => ({}));
  if (res.status === 401) { window.location.href = LOGIN_PATH; throw new ApiError("نشست منقضی شد — دوباره وارد شوید.", 401, json); }
  // بدنهٔ کامل نگه داشته می‌شود: اعتبارسنجی پلاگین جزئیات ساختاریافته در
  // بدنهٔ خطا برمی‌گرداند و UI باید بتواند آن را رندر کند، نه فقط پیام را.
  if (!res.ok) throw new ApiError(json?.message ?? "خطایی رخ داد.", res.status, json);
  return (json?.data ?? json) as T;
}

/**
 * پوشش کامل پاسخ، وقتی `data` کافی نیست.
 *
 * `authed` فقط `data` را برمی‌گرداند و هر چیزی که بک‌اند کنارش گذاشته — مثل
 * `warning` صادقانهٔ `activate()` دربارهٔ هوک‌ها — بی‌صدا دور می‌ریزد.
 */
export type ApiEnvelope<T> = {
  data: T;
  message?: string;
  warning?: string | null;
  hooks_dispatched?: boolean;
};

export async function authedEnvelope<T>(path: string, init?: { method?: string; body?: unknown }): Promise<ApiEnvelope<T>> {
  const res = await fetch(`/api/proxy${path}`, {
    method: init?.method ?? "GET",
    headers: { "Content-Type": "application/json" },
    body: init?.body === undefined ? undefined : JSON.stringify(init.body),
    cache: "no-store",
  });
  const json = await res.json().catch(() => ({}));
  if (res.status === 401) { window.location.href = LOGIN_PATH; throw new ApiError("نشست منقضی شد — دوباره وارد شوید.", 401, json); }
  if (!res.ok) throw new ApiError(json?.message ?? "خطایی رخ داد.", res.status, json);
  return {
    data: (json?.data ?? json) as T,
    message: typeof json?.message === "string" ? json.message : undefined,
    warning: typeof json?.warning === "string" ? json.warning : null,
    hooks_dispatched: typeof json?.hooks_dispatched === "boolean" ? json.hooks_dispatched : undefined,
  };
}

/** فراخوانی API احرازهویت‌شده از کلاینت (توکن داخل کوکی httpOnly است — Route Handler پروکسی می‌کند). */
export async function authed<T>(path: string, init?: { method?: string; body?: unknown }): Promise<T> {
  const res = await fetch(`/api/proxy${path}`, {
    method: init?.method ?? "GET",
    headers: { "Content-Type": "application/json" },
    body: init?.body === undefined ? undefined : JSON.stringify(init.body),
    cache: "no-store",
  });
  const json = await res.json().catch(() => ({}));
  // B7: هر دو مسیر `ApiError` می‌دهند و هر دو ۴۰۱ را `ApiError` با status 401.
  // پیام و `instanceof Error` تغییر نمی‌کند ⇒ هیچ مصرف‌کننده‌ای نمی‌شکند،
  // ولی UI می‌تواند «نشست منقضی» را از «خطای اعتبارسنجی» جدا کند.
  if (res.status === 401) { window.location.href = LOGIN_PATH; throw new ApiError("نشست منقضی شد — دوباره وارد شوید.", 401, json); }
  if (!res.ok) throw new ApiError(json?.message ?? "خطایی رخ داد.", res.status, json);
  return (json?.data ?? json) as T;
}
