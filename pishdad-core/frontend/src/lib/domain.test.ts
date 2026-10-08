import { test } from "node:test";
import assert from "node:assert/strict";
import { mediaUrl } from "./domain.ts";

/**
 * E71 — `url` نسبیِ بک‌اند (`/storage/…` از دیسکِ `public`) در پنلِ ادمین
 * نسبت به میزبانِ فرانت باز می‌شد و ۴۰۴ می‌گرفت (پیش‌نمایش و آدرسِ جزئیات
 * در `/admin/media`). پس نشانیِ نسبی با پایهٔ رسانهٔ زمانِ اجرا مطلق می‌شود.
 *
 * پایه از متغیرِ محیطِ زمانِ اجرا می‌آید (`PISHDAD_PUBLIC_MEDIA_URL`) چون این
 * تست بیرونِ Next اجرا می‌شود و `window` ندارد — همان مسیرِ سمتِ سرور.
 */

function withMediaBase(base: string | undefined, fn: () => void): void {
  const prev = process.env.PISHDAD_PUBLIC_MEDIA_URL;
  const prevBuild = process.env.NEXT_PUBLIC_MEDIA_URL;
  try {
    if (base === undefined) {
      delete process.env.PISHDAD_PUBLIC_MEDIA_URL;
    } else {
      process.env.PISHDAD_PUBLIC_MEDIA_URL = base;
    }
    delete process.env.NEXT_PUBLIC_MEDIA_URL;
    fn();
  } finally {
    if (prev === undefined) delete process.env.PISHDAD_PUBLIC_MEDIA_URL;
    else process.env.PISHDAD_PUBLIC_MEDIA_URL = prev;
    if (prevBuild === undefined) delete process.env.NEXT_PUBLIC_MEDIA_URL;
    else process.env.NEXT_PUBLIC_MEDIA_URL = prevBuild;
  }
}

test("نشانیِ مطلقِ منبع دیگر دست‌نخورده می‌ماند", () => {
  withMediaBase("http://localhost:8080", () => {
    assert.equal(
      mediaUrl({ path: "media/shared/a.svg", url: "https://minio.example/media/1/a.svg" }),
      "https://minio.example/media/1/a.svg",
    );
  });
});

test("نشانیِ نسبیِ /storage/ با پایهٔ رسانه مطلق می‌شود", () => {
  withMediaBase("http://localhost:8080", () => {
    assert.equal(
      mediaUrl({ path: "media/shared/a.svg", url: "/storage/media/shared/a.svg" }),
      "http://localhost:8080/storage/media/shared/a.svg",
    );
  });
});

test("نشانیِ protocol-relative هرگز بازنویسی نمی‌شود", () => {
  withMediaBase("http://localhost:8080", () => {
    assert.equal(mediaUrl({ path: "x", url: "//cdn.example/a.svg" }), "//cdn.example/a.svg");
  });
});

test("بدون پایه، نشانیِ نسبی دست‌نخورده می‌ماند (رفتارِ قبلی)", () => {
  withMediaBase(undefined, () => {
    assert.equal(
      mediaUrl({ path: "media/shared/a.svg", url: "/storage/media/shared/a.svg" }),
      "/storage/media/shared/a.svg",
    );
  });
});

test("بدون url از path با پایه ساخته می‌شود (رفتارِ قبلی)", () => {
  withMediaBase("http://localhost:8080", () => {
    assert.equal(
      mediaUrl({ path: "media/shared/a.svg", url: null }),
      "http://localhost:8080/media/shared/a.svg",
    );
  });
});
