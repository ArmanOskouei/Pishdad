import { test, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import {
  PUBLIC_CONFIG_KEY,
  RUNTIME_VARS,
  publicConfig,
  publicConfigScript,
  runtimeValue,
  serverPublicConfig,
} from "./runtime-config.ts";

/**
 * ⚠️ چرا این تست وجود دارد — باگِ ظریفی که یک‌بار از دست رفت.
 *
 * نخستین پیاده‌سازی مقدارهای عمومی را با `process.env.NEXT_PUBLIC_*` در زمانِ
 * درخواست می‌خواند و «کار می‌کرد» تا وقتی واقعاً روی یک سرورِ زنده آزموده شد:
 * آنجا مشخص شد Next رشتهٔ `NEXT_PUBLIC_*` را حتی در کدِ سرور با ثابتِ زمانِ
 * بیلد جایگزین می‌کند، پس مقدارِ زمانِ اجرا هرگز نمی‌رسید. این تست آن
 * تفکیک را قفل می‌کند: **نامِ بدونِ پیشوند = زمانِ اجرا، `NEXT_PUBLIC_` =
 * زمانِ بیلد**.
 */

const MANAGED = [
  RUNTIME_VARS.apiUrl,
  RUNTIME_VARS.mediaUrl,
  RUNTIME_VARS.vapidPublicKey,
  "NEXT_PUBLIC_API_URL",
  "NEXT_PUBLIC_MEDIA_URL",
  "NEXT_PUBLIC_VAPID_PUBLIC_KEY",
  "CMS_TEST_LIVE",
  "CMS_TEST_BLANK",
];

let saved: Record<string, string | undefined> = {};

beforeEach(() => {
  saved = {};
  for (const v of MANAGED) {
    saved[v] = process.env[v];
    delete process.env[v];
  }
});

afterEach(() => {
  for (const v of MANAGED) {
    if (saved[v] === undefined) delete process.env[v];
    else process.env[v] = saved[v];
  }
});

test("runtimeValue prefers the runtime (non-public) variable", () => {
  process.env.CMS_TEST_LIVE = "live";
  assert.equal(runtimeValue("CMS_TEST_LIVE", "build"), "live");
});

test("runtimeValue falls back to the build-time value", () => {
  assert.equal(runtimeValue("CMS_TEST_LIVE", "  build  "), "build");
});

test("runtimeValue falls back to the default", () => {
  assert.equal(runtimeValue("CMS_TEST_LIVE", undefined, "fallback"), "fallback");
  assert.equal(runtimeValue("CMS_TEST_LIVE", undefined), "");
});

test("a blank runtime value does not beat the build-time value", () => {
  process.env.CMS_TEST_BLANK = "   ";
  assert.equal(runtimeValue("CMS_TEST_BLANK", "build"), "build");
});

test("serverPublicConfig reads the runtime variable names", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://live.example/api";
  process.env.PISHDAD_PUBLIC_MEDIA_URL = "https://live-media.example/";
  process.env.PISHDAD_PUBLIC_VAPID_PUBLIC_KEY = "LIVE_KEY";

  assert.deepEqual(serverPublicConfig(), {
    apiUrl: "https://live.example/api",
    mediaUrl: "https://live-media.example",
    vapidPublicKey: "LIVE_KEY",
  });
});

test("the runtime value wins over the build-time value", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://live.example/api";
  process.env.NEXT_PUBLIC_API_URL = "https://build.example/api";

  assert.equal(serverPublicConfig().apiUrl, "https://live.example/api");
});

test("serverPublicConfig falls back to the build-time values", () => {
  process.env.NEXT_PUBLIC_API_URL = "https://build.example/api";
  process.env.NEXT_PUBLIC_MEDIA_URL = "https://build-media.example";
  process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY = "BUILD_KEY";

  assert.deepEqual(serverPublicConfig(), {
    apiUrl: "https://build.example/api",
    mediaUrl: "https://build-media.example",
    vapidPublicKey: "BUILD_KEY",
  });
});

test("serverPublicConfig defaults the api url when nothing is configured", () => {
  assert.deepEqual(serverPublicConfig(), {
    apiUrl: "http://localhost:8080/api",
    mediaUrl: "",
    vapidPublicKey: "",
  });
});

test("publicConfig on the server delegates to the runtime environment", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://live.example/api";
  assert.equal(publicConfig().apiUrl, "https://live.example/api");
});

test("publicConfigScript carries the key and cannot close the script tag", () => {
  process.env.PISHDAD_PUBLIC_VAPID_PUBLIC_KEY = "a<b>c";

  const script = publicConfigScript();

  assert.ok(script.startsWith(`window.${PUBLIC_CONFIG_KEY}=`), "must define the documented global");
  assert.ok(script.includes("\\u003c"), "a < must be escaped");
  assert.ok(!script.includes("<"), "a raw < could terminate the surrounding <script> element");
});
