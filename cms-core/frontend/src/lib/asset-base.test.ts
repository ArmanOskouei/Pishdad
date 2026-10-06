import { test } from "node:test";
import assert from "node:assert/strict";
import { applyAssetBase, normalizeAssetBase, resolveAssetBase } from "./asset-base.ts";

/**
 * WF-M13 — دامنهٔ دارایی/CDN: فقط فایل‌های ایستای محلی را می‌سازد.
 *
 * همراستا با CDN-ASSESSMENT: نشانی مطلقِ منبعِ دیگر (MinIO/S3) هرگز بازنویسی
 * نمی‌شود؛ و دامنهٔ خالی یعنی «بدون CDN» (رفتار قبلی).
 */

test("دامنهٔ خالی ⇒ null (بدون CDN)", () => {
  assert.equal(normalizeAssetBase(""), null);
  assert.equal(normalizeAssetBase("   "), null);
  assert.equal(normalizeAssetBase(undefined), null);
  assert.equal(resolveAssetBase(null), null);
  assert.equal(resolveAssetBase({ asset_domain: null }), null);
});

test("دامنه نرمال‌سازی می‌شود: trim + حذف اسلش پایانی", () => {
  assert.equal(normalizeAssetBase(" https://cdn.example.ir/ "), "https://cdn.example.ir");
  assert.equal(resolveAssetBase({ asset_domain: "https://cdn.example.ir//" }), "https://cdn.example.ir");
});

test("نشانی محلی با دامنه ساخته می‌شود", () => {
  assert.equal(applyAssetBase("https://cdn.example.ir", "/favicon.ico"), "https://cdn.example.ir/favicon.ico");
});

test("نشانی مطلق منبع دیگر دست‌نخورده می‌ماند", () => {
  const s3 = "https://minio.example.ir/media/1/a.png";
  assert.equal(applyAssetBase("https://cdn.example.ir", s3), s3);
});

test("بدون دامنه، همه‌چیز دست‌نخورده می‌ماند", () => {
  assert.equal(applyAssetBase(null, "/favicon.ico"), "/favicon.ico");
  assert.equal(applyAssetBase(null, null), null);
});
