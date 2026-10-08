import { test } from "node:test";
import assert from "node:assert/strict";

import {
  blogArchiveHref,
  blogBasePath,
  blogCategoryHref,
  blogFeedHref,
  blogPostHref,
  blogSlug,
  blogTagHref,
  paginationPages,
  parseBlogPage,
} from "./blog.ts";

/** WF-C7 — کمک‌های خالصِ مسیر/صفحه‌بندی بایگانی بلاگ. */

test("parseBlogPage only accepts positive integers", () => {
  assert.equal(parseBlogPage(undefined), 1);
  assert.equal(parseBlogPage(""), 1);
  assert.equal(parseBlogPage("0"), 1);
  assert.equal(parseBlogPage("-2"), 1);
  assert.equal(parseBlogPage("abc"), 1);
  assert.equal(parseBlogPage("2.7"), 2);
  assert.equal(parseBlogPage("7"), 7);
  assert.equal(parseBlogPage(["4", "9"]), 4);
});

test("paginationPages returns a window around the current page", () => {
  assert.deepEqual(paginationPages(1, 10), [1, 2, 3]);
  assert.deepEqual(paginationPages(5, 10), [3, 4, 5, 6, 7]);
  assert.deepEqual(paginationPages(9, 10), [7, 8, 9, 10]);
  assert.deepEqual(paginationPages(1, 1), [1]);
  assert.deepEqual(paginationPages(3, 1), [1]);
});

test("blog paths honor the primary-locale model (no prefix for primary)", () => {
  assert.equal(blogBasePath("fa", "fa"), "/blog");
  assert.equal(blogBasePath("en", "fa"), "/en/blog");

  assert.equal(blogArchiveHref("fa", "fa", 1), "/blog");
  assert.equal(blogArchiveHref("fa", "fa", 2), "/blog?page=2");
  assert.equal(blogArchiveHref("en", "fa", 3), "/en/blog?page=3");

  assert.equal(blogPostHref("fa", "fa", "hello world"), "/blog/hello%20world");
  assert.equal(blogPostHref("en", "fa", "post"), "/en/blog/post");
  assert.equal(blogSlug("/blog/my-post"), "my-post");
  assert.equal(blogPostHref("fa", "fa", "blog/my-post"), "/blog/my-post");

  assert.equal(blogCategoryHref("fa", "fa", "tech"), "/blog/category/tech");
  assert.equal(blogTagHref("en", "fa", "php"), "/en/blog/tag/php");
  assert.equal(blogFeedHref("fa", "fa"), "/blog/feed.xml");
  assert.equal(blogFeedHref("en", "fa"), "/en/blog/feed.xml");
});
