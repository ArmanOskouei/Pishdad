import test from "node:test";
import assert from "node:assert/strict";
import { peakSearches, totalSearches, type SearchQueryBucket } from "./search-report.ts";

test("peakSearches بیشینهٔ شمار جستجو را می‌دهد", () => {
  const buckets: SearchQueryBucket[] = [
    { query: "قیمت", searches: 3 },
    { query: "گارانتی", searches: 7 },
  ];
  assert.equal(peakSearches(buckets), 7);
});

test("peakSearches روی فهرست خالی صفر است", () => {
  assert.equal(peakSearches([]), 0);
});

test("totalSearches جمعِ شمار جستجوها را می‌دهد", () => {
  assert.equal(
    totalSearches([
      { query: "a", searches: 2 },
      { query: "b", searches: 5 },
    ]),
    7,
  );
  assert.equal(totalSearches([]), 0);
});
