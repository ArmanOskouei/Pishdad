import { test, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
  effectivePublicConfig,
  effectiveValue,
  isInstalled,
  readSiteConfig,
  resetSiteConfigCache,
  writeSiteConfig,
} from "./site-config.ts";

/**
 * لایهٔ نصب: فایلِ JSON ← متغیرِ محیط ← زمانِ بیلد.
 *
 * این ترتیب مهم است و بی‌آزمون رها کردنش خطرناک: اگر محیط بر فایل بچربد،
 * نصب‌کنندهٔ وب بی‌اثر می‌شود؛ اگر فایل بر محیط نچربد، کاربر نمی‌تواند از
 * داخلِ سایت نصب کند. هر دو جهت اینجا قفل شده‌اند.
 */

let dir = "";
const MANAGED = ["PISHDAD_PUBLIC_API_URL", "PISHDAD_SITE_LOCALES", "PISHDAD_SITE_PRIMARY_LOCALE", "REVALIDATE_SECRET"];
let saved: Record<string, string | undefined> = {};

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), "pishdad-cfg-"));
  process.env.PISHDAD_CONFIG_FILE = join(dir, "runtime.json");
  resetSiteConfigCache();

  saved = {};
  for (const v of MANAGED) {
    saved[v] = process.env[v];
    delete process.env[v];
  }
});

afterEach(() => {
  resetSiteConfigCache();
  delete process.env.PISHDAD_CONFIG_FILE;
  for (const v of MANAGED) {
    if (saved[v] === undefined) delete process.env[v];
    else process.env[v] = saved[v];
  }
  rmSync(dir, { recursive: true, force: true });
});

test("a missing config file is normal, not an error", () => {
  assert.deepEqual(readSiteConfig(), {});
});

test("write then read round-trips", () => {
  writeSiteConfig({ apiUrl: "https://api.example/api", locales: "fa", installedAt: "2026-01-01T00:00:00.000Z" });

  const config = readSiteConfig();
  assert.equal(config.apiUrl, "https://api.example/api");
  assert.equal(config.locales, "fa");
});

test("the file beats the runtime environment", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://from-env/api";
  writeSiteConfig({ apiUrl: "https://from-file/api" });

  assert.equal(effectiveValue("PISHDAD_PUBLIC_API_URL", undefined), "https://from-file/api");
});

test("the environment is used for keys the file does not set", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://from-env/api";
  writeSiteConfig({ locales: "fa" });

  assert.equal(effectiveValue("PISHDAD_PUBLIC_API_URL", undefined), "https://from-env/api");
});

test("with no file at all the environment still wins over build time", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://from-env/api";
  assert.equal(effectiveValue("PISHDAD_PUBLIC_API_URL", "https://from-build/api"), "https://from-env/api");
});

test("a blank value in the file does not shadow the environment", () => {
  process.env.PISHDAD_PUBLIC_API_URL = "https://from-env/api";
  writeSiteConfig({ apiUrl: "   " });

  assert.equal(effectiveValue("PISHDAD_PUBLIC_API_URL", undefined), "https://from-env/api");
});

test("the revalidate secret is read from the file", () => {
  writeSiteConfig({ revalidateSecret: "s3cret" });
  assert.equal(effectiveValue("REVALIDATE_SECRET", undefined), "s3cret");
});

test("a corrupt file degrades to the environment instead of throwing", () => {
  writeFileSync(process.env.PISHDAD_CONFIG_FILE!, "{ not json", "utf8");
  resetSiteConfigCache();

  process.env.PISHDAD_SITE_LOCALES = "fa,en";

  assert.deepEqual(readSiteConfig(), {});
  assert.equal(effectiveValue("PISHDAD_SITE_LOCALES", undefined), "fa,en");
});

test("isInstalled requires the api url, the secret and the timestamp", () => {
  assert.equal(isInstalled(), false);

  writeSiteConfig({ apiUrl: "https://api.example/api", revalidateSecret: "s" });
  assert.equal(isInstalled(), false, "no installedAt yet");

  writeSiteConfig({
    apiUrl: "https://api.example/api",
    revalidateSecret: "s",
    installedAt: new Date().toISOString(),
  });
  assert.equal(isInstalled(), true);
});

test("the public config never leaks the revalidate secret", () => {
  writeSiteConfig({
    apiUrl: "https://api.example/api",
    revalidateSecret: "MUST-NOT-LEAK",
    mediaUrl: "https://media.example/",
  });

  const publicConfig = effectivePublicConfig();

  assert.deepEqual(Object.keys(publicConfig).sort(), ["apiUrl", "mediaUrl", "vapidPublicKey"]);
  assert.equal(publicConfig.apiUrl, "https://api.example/api");
  assert.equal(publicConfig.mediaUrl, "https://media.example", "trailing slash is trimmed");
  assert.ok(!JSON.stringify(publicConfig).includes("MUST-NOT-LEAK"));
});
