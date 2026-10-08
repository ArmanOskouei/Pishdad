import { test } from "node:test";
import assert from "node:assert/strict";

import { loginGate } from "./login-gate.ts";

/**
 * رگرسیونِ «بعد از ورود، صفحهٔ لاگین باز هم فرم نشان می‌داد».
 *
 * گزارشِ کاربر: بعد از لاگین کردن، اگر `/login` باز می‌شد، فرم ورود دوباره
 * دیده می‌شد. ریشه: صفحه اصلاً نمی‌پرسید «نشستِ معتبری هست؟».
 */

test("an existing session redirects instead of showing the form", () => {
  assert.equal(loginGate({ loading: false, user: { id: 1 } }), "redirect");
});

test("no session shows the form", () => {
  assert.equal(loginGate({ loading: false, user: null }), "form");
});

test("while the session is unknown, nothing is rendered", () => {
  // ⭐ همین حالت باعثِ باگ بود. `user` هنوز `null` است چون درخواستِ
  // `/profile` تمام نشده — پس «نبودن کاربر» به‌تنهایی **نشانهٔ بی‌نشستی
  // نیست** و نباید فرم را نشان دهد.
  assert.equal(loginGate({ loading: true, user: null }), "wait");
});

test("loading wins over a user that is somehow already present", () => {
  // حتی اگر کاربری در state باشد، تا تمام‌شدن بررسی نباید رندر کنیم.
  assert.equal(loginGate({ loading: true, user: { id: 1 } }), "wait");
});

test("undefined user is treated as no session", () => {
  assert.equal(loginGate({ loading: false, user: undefined }), "form");
});

test("the three states are exactly and only these", () => {
  const seen = new Set([
    loginGate({ loading: true, user: null }),
    loginGate({ loading: false, user: null }),
    loginGate({ loading: false, user: { id: 1 } }),
  ]);

  assert.deepEqual([...seen].sort(), ["form", "redirect", "wait"]);
});

test("the form is only reachable when the session check finished and found nothing", () => {
  // نگهبانِ ترتیب: هر مسیری که به `form` می‌رسد باید از هر دو شرط رد شده باشد.
  const inputs = [
    { loading: true, user: null },
    { loading: true, user: undefined },
    { loading: true, user: { id: 1 } },
    { loading: false, user: null },
    { loading: false, user: undefined },
    { loading: false, user: { id: 1 } },
  ];

  for (const input of inputs) {
    const gate = loginGate(input);
    const wasLoading = input.loading;
    const hasUser = input.user !== null && input.user !== undefined;

    if (gate === "form") {
      assert.equal(wasLoading, false, "form نباید هنگام loading باز شود");
      assert.equal(hasUser, false, "form نباید با کاربر باز شود");
    }

    if (gate === "redirect") {
      assert.equal(hasUser, true, "redirect فقط با کاربر");
      assert.equal(wasLoading, false, "redirect بعد از loading");
    }
  }
});
