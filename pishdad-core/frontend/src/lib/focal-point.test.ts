import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { clampFocal, focalPosition } from "./focal-point.ts";

/* ───────────────────────── تبدیل به object-position ───────────────────────── */

test("مختصات نرمال به درصد تبدیل می‌شوند", () => {
  assert.equal(focalPosition(0.25, 0.75), "25% 75%");
  assert.equal(focalPosition(0.3, 0.7), "30% 70%");
  assert.equal(focalPosition(0.333, 0.666), "33.3% 66.6%");
});

test("مرزهای ۰ و ۱ دقیق می‌مانند", () => {
  assert.equal(focalPosition(0, 0), "0% 0%");
  assert.equal(focalPosition(1, 1), "100% 100%");
  assert.equal(focalPosition(0, 1), "0% 100%");
});

test("عدد رشته‌ای هم پذیرفته می‌شود (payload ممکن است رشته بدهد)", () => {
  assert.equal(focalPosition("0.5", "0.5"), "50% 50%");
});

test("مقدار ست‌نشده یا نامعتبر ⇒ undefined (یعنی «وسط»، بدون شکستن رندر)", () => {
  for (const bad of [null, undefined, "", "   ", "کج", Number.NaN, Infinity, {} as unknown]) {
    assert.equal(focalPosition(bad, 0.5), undefined, `محور افقی «${String(bad)}» باید undefined می‌داد.`);
    assert.equal(focalPosition(0.5, bad), undefined, `محور عمودی «${String(bad)}» باید undefined می‌داد.`);
  }
});

test("بیرون از بازه به ۰..۱ محدود می‌شود", () => {
  assert.equal(focalPosition(1.5, -0.2), "100% 0%");
  assert.equal(focalPosition(-3, 4), "0% 100%");
});

/* ───────────────────────────── clamp خام ───────────────────────────── */

test("clampFocal مرزها را می‌بندد و نامعتبر را null می‌کند", () => {
  assert.equal(clampFocal(0.42), 0.42);
  assert.equal(clampFocal(-1), 0);
  assert.equal(clampFocal(2), 1);
  assert.equal(clampFocal("0.1"), 0.1);
  assert.equal(clampFocal(""), null);
  assert.equal(clampFocal(null), null);
  assert.equal(clampFocal(Number.NaN), null);
});

/* ─────────── قفلِ اتصال: رندررها واقعاً از این ماژول استفاده کنند ─────────── */

const blockRendererPath = fileURLToPath(
  new URL("../components/site/BlockRenderer.tsx", import.meta.url),
);
const responsiveImagePath = fileURLToPath(
  new URL("../components/site/ResponsiveImage.tsx", import.meta.url),
);
const mediaLibraryPath = fileURLToPath(
  new URL("../app/(client)/admin/media/MediaLibrary.tsx", import.meta.url),
);

const blockSource = readFileSync(blockRendererPath, "utf8");
const imageSource = readFileSync(responsiveImagePath, "utf8");
const librarySource = readFileSync(mediaLibraryPath, "utf8");

test("BlockRenderer نقطهٔ کانونی را به objectPosition تصویر/گالری می‌دهد", () => {
  assert.match(blockSource, /from "@\/lib\/focal-point"/, "بلوک‌رندر باید از `lib/focal-point` ایمپورت کند.");
  assert.match(blockSource, /objectPosition=\{mediaFocal\(/, "تصویر/گالری باید `objectPosition` را پاس دهد.");
  assert.match(blockSource, /background: bg \? `url\(\$\{bg\}\) \$\{heroPosition\}\/cover`/, "هیرو باید موقعیتِ کانون را روی background بگذارد.");
});

test("ResponsiveImage پراپ objectPosition را روی style اعمال می‌کند", () => {
  assert.match(imageSource, /objectPosition\?: string/, "ResponsiveImage باید پراپ objectPosition بگیرد.");
  assert.match(imageSource, /const imgStyle = objectPosition \? \{ \.\.\.style, objectPosition \}/, "مقدار باید در style تصویر بنشیند.");
});

test("Drawer مدیا نقطهٔ کانونی را ست می‌کند و در PUT می‌فرستد", () => {
  assert.match(librarySource, /from "@\/lib\/focal-point"/, "MediaLibrary باید از `lib/focal-point` استفاده کند.");
  assert.match(librarySource, /focalPosition\(focal\?\.x, focal\?\.y\)/, "پیش‌نمایش باید objectPosition را اعمال کند.");
  assert.match(librarySource, /focal_x: focal\?\.x \?\? null/, "ذخیره باید focal_x/focal_y را بفرستد.");
  assert.match(librarySource, /بازنشانی/, "دکمهٔ بازنشانی فارسی باید موجود باشد.");
});
