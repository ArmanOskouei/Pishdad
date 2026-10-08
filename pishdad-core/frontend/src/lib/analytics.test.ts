import test from "node:test";
import assert from "node:assert/strict";
import { barWidth, peakBucket, peakViews, totalViews, type AnalyticsBucket } from "./analytics.ts";

test("barWidth مقیاس را به درصد ۰..۱۰۰ می‌برد", () => {
  assert.equal(barWidth(5, 10), 50);
  assert.equal(barWidth(10, 10), 100);
  assert.equal(barWidth(20, 10), 100);
});

test("barWidth روی مقادیر مرزی امن می‌ماند", () => {
  assert.equal(barWidth(0, 10), 0);
  assert.equal(barWidth(-3, 10), 0);
  assert.equal(barWidth(5, 0), 0);
  assert.equal(barWidth(Number.NaN, 10), 0);
});

test("peakViews بیشینهٔ سری را می‌دهد و روی سری خالی صفر است", () => {
  assert.equal(peakViews([{ date: "a", views: 1 }, { date: "b", views: 7 }, { date: "c", views: 3 }]), 7);
  assert.equal(peakViews([]), 0);
});

test("totalViews جمعِ سری را می‌دهد", () => {
  assert.equal(totalViews([{ date: "a", views: 2 }, { date: "b", views: 3 }]), 5);
  assert.equal(totalViews([]), 0);
});

test("peakBucket بیشینهٔ جدول تجمعی را می‌دهد", () => {
  const buckets: AnalyticsBucket[] = [{ key: "/", views: 4 }, { key: "/about", views: 9 }];
  assert.equal(peakBucket(buckets), 9);
  assert.equal(peakBucket([]), 0);
});
