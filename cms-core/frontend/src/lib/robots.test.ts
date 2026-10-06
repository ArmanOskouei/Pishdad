import { test } from "node:test";
import assert from "node:assert/strict";
import { buildRobotsTxt, defaultRobotsTxt } from "./robots.ts";

/**
 * WF-H1 — نگهبانِ robots.txt قابل ویرایش.
 *
 * سه حالتِ حساس: مسدودسازیِ سراسری بر متنِ سفارشی مقدم است (سوئیچِ ایمنی)،
 * متنِ سفارشی باید عیناً سرو شود، و در نبودِ سفارشی، پیش‌فرض sitemap دارد.
 */

test("در نبود تنظیم، پیش‌فرض اجازه + sitemap است", () => {
  const body = buildRobotsTxt({ robotsIndex: true, robotsTxt: null, base: "https://example.ir" });
  assert.match(body, /^User-agent: \*\nAllow: \/\n/);
  assert.match(body, /Sitemap: https:\/\/example\.ir\/sitemap\.xml/);
  assert.match(body, /llms\.txt/);
});

test("index خاموش = مسدودسازی کامل، حتی با متن سفارشی", () => {
  const body = buildRobotsTxt({
    robotsIndex: false,
    robotsTxt: "User-agent: *\nAllow: /",
    base: "https://example.ir",
  });
  assert.equal(body, "User-agent: *\nDisallow: /\n");
});

test("متن سفارشی عیناً سرو می‌شود (با تضمین خط پایانی)", () => {
  const custom = "User-agent: *\nDisallow: /admin";
  const body = buildRobotsTxt({ robotsIndex: true, robotsTxt: custom, base: "https://example.ir" });
  assert.equal(body, `${custom}\n`);
  // متن خالی/فقط فاصله ⇒ پیش‌فرض، نه سرو متن خالی.
  const blank = buildRobotsTxt({ robotsIndex: true, robotsTxt: "   ", base: "https://example.ir" });
  assert.equal(blank, defaultRobotsTxt("https://example.ir"));
});

test("پیش‌فرض اسلش پایانیِ base را حذف می‌کند", () => {
  assert.match(defaultRobotsTxt("https://example.ir///"), /Sitemap: https:\/\/example\.ir\/sitemap\.xml/);
});
