/**
 * F1 — لایه API.
 * همه فراخوانی‌ها به API لاراول از اینجا می‌گذرند: baseURL از env، تزریق توکن،
 * مدیریت 401/refresh، پیام فارسی یکدست. هیچ fetch مستقیم به بک‌اند در کامپوننت‌ها مجاز نیست.
 */

import { publicConfig } from "./runtime-config.ts";

// در زمانِ **اجرا** خوانده می‌شود، نه در زمانِ بیلد: نشانیِ API روی هر نصب
// متفاوت است و باندلِ بیلدشده باید برای همه‌شان یکی باشد. مقدار از محیطِ سرور
// در HTML تزریق می‌شود (نگاه کنید به `runtime-config.ts`).
export const API_BASE = publicConfig().apiUrl;

export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

function faMessage(status: number, backendMsg?: string): string {
  if (backendMsg) return backendMsg;
  if (status === 401) return "اعتبار ورود نامعتبر است.";
  if (status === 422) return "ورودی معتبر نیست.";
  if (status === 429) return "تلاش زیاد — کمی بعد دوباره امتحان کنید.";
  if (status >= 500) return "خطای سرور — لطفاً بعداً تلاش کنید.";
  return "خطایی رخ داد.";
}

type ApiOpts = {
  method?: string;
  body?: unknown;
  token?: string;
  /** برای revalidate بعدی ISR — فعلاً no-store در پنل (داده زنده ادمین). */
  cache?: RequestCache;
};

export async function api<T>(
  path: string,
  { method = "GET", body, token, cache = "no-store" }: ApiOpts = {},
): Promise<T> {
  const headers: Record<string, string> = { "Content-Type": "application/json", Accept: "application/json" };
  if (token) headers.Authorization = `Bearer ${token}`;
  const res = await fetch(`${API_BASE}${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
    cache,
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, faMessage(res.status, json?.message));
  return (json?.data ?? json) as T;
}

/* ── تایپ‌های F1 (قرارداد openapi.yaml v1.8.0؛ مرجع کامل: src/types/api.d.ts) ── */

export type User = {
  id: number;
  name: string;
  email: string;
  role: string;
  google2fa_enabled: boolean;
  created_at?: string;
  created_at_jalali?: string | null;
};

export type TokenResponse = {
  token: string;
  token_type: "Bearer";
  two_factor_required: false;
  /** WF-M8 — نقش کاربر «۲FA اجباری» است ولی هنوز فعال نشده؛ به پروفایل هدایت شود. */
  requires_2fa_setup?: boolean;
  user: User;
};

export type ChallengeResponse = {
  two_factor_required: true;
  challenge: string;
  message: string;
};

export type LoginResult = TokenResponse | ChallengeResponse;

export function isChallenge(r: LoginResult): r is ChallengeResponse {
  return r.two_factor_required === true;
}

export type UiSettings = {
  dir: "rtl" | "ltr";
  preset: "sahar" | "amaliyat" | "arya" | "narm" | "divan";
  /** رنگِ درونِ جهت بصری. باید ذخیره شود وگرنه با رفرش به پیش‌فرضِ جهت برمی‌گردد. */
  accent?: "teal" | "indigo" | "violet" | "blue" | "amber" | "rose" | "clay";
  mode: "light" | "dark" | "system";
  radius: "sharp" | "default" | "rounded";
  density: "compact" | "comfortable" | "loose";
  font: "sm" | "md" | "lg";
  bottom_nav?: string[];
};

export type DashboardStats = {
  pages_count: number;
  media_count: number;
  open_tickets: number;
  /** دسته ۱: دلتای واقعی «جدید در ۷ روز اخیر» (افزودنی). */
  recent?: { pages_new_7d: number; media_new_7d: number; tickets_new_7d: number };
  /**
   * WF-H21 — وضعیتِ پنج گامِ راه‌اندازی اولین ورود. سرور آن را از تنظیمات و
   * صفحاتِ واقعی مشتق می‌کند؛ کلاینت فقط نمایش می‌دهد (بدون حدس).
   */
  checklist?: import("./onboarding").OnboardingChecklist;
};

export type DashboardAlert = {
  type: string;
  severity: "info" | "warning" | "critical";
  message: string;
  action: string;
};
