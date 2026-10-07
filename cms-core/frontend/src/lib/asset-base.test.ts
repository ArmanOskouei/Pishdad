import { test } from "node:test";
import assert from "node:assert/strict";
import {
  applyAssetBase,
  applyMediaBase,
  applySrcsetBase,
  normalizeAssetBase,
  rebaseBlockMedia,
  resolveAssetBase,
} from "./asset-base.ts";

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

/** E64 — `//host` منبعِ دیگری است؛ چسباندنِ پایه به آن میزبانِ ناشناس می‌سازد. */
test("نشانیِ protocol-relative هرگز بازنویسی نمی‌شود", () => {
  assert.equal(applyAssetBase("https://cdn.example.ir", "//evil.example/x.png"), "//evil.example/x.png");
});

/** E64 — `srcset` چند نشانی با توصیف‌گر دارد؛ هر بخش باید جدا ساخته شود. */
test("srcset بازنویسی می‌شود و توصیف‌گرها حفظ می‌شوند", () => {
  assert.equal(
    applySrcsetBase(
      "http://localhost:8080",
      "/storage/a.webp 640w, /storage/b.webp 1280w",
    ),
    "http://localhost:8080/storage/a.webp 640w, http://localhost:8080/storage/b.webp 1280w",
  );
  // نشانیِ مطلقِ MinIO/S3 و توصیف‌گرِ چگالی دست‌نخورده می‌مانند.
  assert.equal(
    applySrcsetBase("http://localhost:8080", "https://minio.example/a.avif 2x"),
    "https://minio.example/a.avif 2x",
  );
  // بدون پایه، رفتار قبلی (بدون تغییر).
  assert.equal(applySrcsetBase(null, "/a.webp 640w"), "/a.webp 640w");
  assert.equal(applySrcsetBase("http://localhost:8080", ""), "");
});

/**
 * E64 — بازنویسیِ پیلود بلوک: فقط کلیدهای رسانه لمس می‌شوند.
 * `href`/`slug` نشانیِ سایتِ فرانت‌اند، نه بک‌اند؛ بازنویسی‌شان لینک را می‌شکند.
 */
test("بلوک‌ها: رسانه بازنویسی می‌شود، لینک/slug نه", () => {
  const base = "http://localhost:8080";
  const input = [
    {
      type: "image",
      data: {
        media_id: 1,
        url: "/storage/media/shared/a.svg",
        srcset: "/storage/a-640.webp 640w, /storage/a-1280.webp 1280w",
        sources: [{ type: "image/avif", srcset: "/storage/a-640.avif 640w" }],
        alt: "توضیح",
      },
    },
    {
      type: "gallery",
      data: {
        media_ids: [1, 2],
        url_0: "/storage/media/shared/a.svg",
        url_1: "https://minio.example/b.svg",
        focal_x_0: 0.5,
      },
    },
    { type: "cta", data: { label: "برو", href: "/about", slug: "about" } },
    { type: "video", data: { poster_url: "/storage/media/shared/p.jpg" } },
  ];

  const out = rebaseBlockMedia(base, input);

  assert.equal(out[0].data.url, `${base}/storage/media/shared/a.svg`);
  assert.equal(
    out[0].data.srcset,
    `${base}/storage/a-640.webp 640w, ${base}/storage/a-1280.webp 1280w`,
  );
  assert.equal(out[0].data.sources[0].srcset, `${base}/storage/a-640.avif 640w`);
  assert.equal(out[0].data.alt, "توضیح");

  assert.equal(out[1].data.url_0, `${base}/storage/media/shared/a.svg`);
  assert.equal(out[1].data.url_1, "https://minio.example/b.svg", "مطلقِ MinIO نباید لمس شود");

  assert.equal(out[2].data.href, "/about", "لینک باید دست‌نخورده بماند");
  assert.equal(out[2].data.slug, "about");

  assert.equal(out[3].data.poster_url, `${base}/storage/media/shared/p.jpg`);

  // ورودی نباید تغییر کند (بدون mutation، برای امنیتِ کش/رندر).
  assert.equal(input[0].data.url, "/storage/media/shared/a.svg");
  assert.equal(input[1].data.url_0, "/storage/media/shared/a.svg");
});

test("بدون پایه، بلوک‌ها دقیقاً همان ورودی برمی‌گردند", () => {
  const input = [{ type: "image", data: { url: "/storage/a.svg" } }];
  assert.equal(rebaseBlockMedia(null, input), input);
  // ورودیِ غیرِآرایه بی‌خطر برمی‌گردد (بک‌اند می‌تواند blocks ندهد).
  assert.equal(rebaseBlockMedia("http://x", null), null);
});

/**
 * E71 — فقط `/storage/…` پایه می‌گیرد؛ داراییِ فرانت نه.
 *
 * رگرسیونِ ثبت‌شده: `applyAssetBase` هر نشانیِ محلی را می‌ساخت و لوگوی سایت
 * (`/pishdad-logo.png` که روی خودِ فرانت سرو می‌شود) به میزبانِ بک‌اند رفت و
 * ۴۰۴ گرفت. `applyMediaBase` فقط پیشوندِ رسانهٔ بک‌اند را لمس می‌کند.
 */
test("پایهٔ رسانه فقط /storage/ را می‌سازد", () => {
  const base = "http://localhost:8080";
  assert.equal(applyMediaBase(base, "/storage/media/shared/a.svg"), `${base}/storage/media/shared/a.svg`);
  assert.equal(applyMediaBase(base, "/pishdad-logo.png"), "/pishdad-logo.png", "دارایی فرانت باید نسبی بماند");
  assert.equal(applyMediaBase(base, "/favicon.ico"), "/favicon.ico");
  assert.equal(applyMediaBase(base, "/icons/icon-512.png"), "/icons/icon-512.png");
  assert.equal(applyMediaBase(base, "https://minio.example/a.svg"), "https://minio.example/a.svg");
  assert.equal(applyMediaBase(base, "//cdn.example/a.svg"), "//cdn.example/a.svg");
  assert.equal(applyMediaBase(null, "/storage/a.svg"), "/storage/a.svg");
});

test("بلوک‌ها: داراییِ فرانت در کلیدِ رسانه هم دست‌نخورده می‌ماند", () => {
  const base = "http://localhost:8080";
  const input = [
    { type: "image", data: { url: "/storage/media/shared/a.svg" } },
    { type: "hero", data: { url: "/pishdad-logo.png" } },
  ];

  const out = rebaseBlockMedia(base, input);

  assert.equal(out[0].data.url, `${base}/storage/media/shared/a.svg`);
  assert.equal(out[1].data.url, "/pishdad-logo.png");
});

test("srcset مختلط: فقط بخشِ /storage/ ساخته می‌شود", () => {
  assert.equal(
    applySrcsetBase("http://localhost:8080", "/pishdad-logo.png 1x, /storage/a-640.webp 640w"),
    "/pishdad-logo.png 1x, http://localhost:8080/storage/a-640.webp 640w",
  );
});
