import test from "node:test";
import assert from "node:assert/strict";
import {
  classifyVital,
  formatVitalValue,
  VITAL_LABELS,
  VITAL_RATING_LABELS,
  VITAL_THRESHOLDS,
  vitalBarWidth,
} from "./vitals.ts";

test("classifyVital مرزهای LCP را درست می‌بندد", () => {
  assert.equal(classifyVital("lcp", 2500), "good");
  assert.equal(classifyVital("lcp", 2501), "needs-improvement");
  assert.equal(classifyVital("lcp", 4000), "needs-improvement");
  assert.equal(classifyVital("lcp", 4001), "poor");
});

test("classifyVital مرزهای INP را درست می‌بندد", () => {
  assert.equal(classifyVital("inp", 200), "good");
  assert.equal(classifyVital("inp", 201), "needs-improvement");
  assert.equal(classifyVital("inp", 500), "needs-improvement");
  assert.equal(classifyVital("inp", 501), "poor");
});

test("classifyVital مرزهای CLS را درست می‌بندد", () => {
  assert.equal(classifyVital("cls", 0.1), "good");
  assert.equal(classifyVital("cls", 0.11), "needs-improvement");
  assert.equal(classifyVital("cls", 0.25), "needs-improvement");
  assert.equal(classifyVital("cls", 0.26), "poor");
});

test("classifyVital روی مقدار نامعلوم null می‌دهد نه خوب", () => {
  assert.equal(classifyVital("lcp", null), null);
  assert.equal(classifyVital("lcp", undefined), null);
  assert.equal(classifyVital("lcp", Number.NaN), null);
  assert.equal(classifyVital("lcp", Number.POSITIVE_INFINITY), null);
});

test("formatVitalValue اعشار CLS و صحیح LCP/INP را می‌دهد", () => {
  assert.equal(formatVitalValue("cls", 0.05), "0.05");
  assert.equal(formatVitalValue("lcp", 2400.6), "2401");
  assert.equal(formatVitalValue("inp", 180.2), "180");
  assert.equal(formatVitalValue("lcp", Number.NaN), "—");
});

test("vitalBarWidth نسبت به سقف ضعیف مقیاس می‌شود", () => {
  assert.equal(vitalBarWidth("lcp", 2000), 50);
  assert.equal(vitalBarWidth("lcp", 9000), 100);
  assert.equal(vitalBarWidth("cls", 0), 0);
  assert.equal(vitalBarWidth("inp", -5), 0);
});

test("برچسب‌های فارسی و آستانه‌ها کامل‌اند", () => {
  for (const metric of ["lcp", "inp", "cls"] as const) {
    assert.ok(VITAL_LABELS[metric].length > 0);
    assert.ok(VITAL_THRESHOLDS[metric].good < VITAL_THRESHOLDS[metric].poor);
  }
  assert.equal(VITAL_RATING_LABELS.poor, "ضعیف");
});
