import { test } from "node:test";
import assert from "node:assert/strict";

import {
  buildRssFeed,
  feedItemUrl,
  isFeedItemPublic,
  rfc2822,
  xmlEscape,
  type FeedItem,
} from "./rss-feed.ts";

/** WF-M19 — سازندهٔ فید RSS از همهٔ محتوای منتشرشده. */

const BASE = "https://armanaskouei.ir";

const blog: FeedItem = {
  title: "نوشتهٔ بلاگ",
  slug: "post-a",
  path: "/blog/post-a",
  url: "http://app:8080/blog/post-a",
  summary: "خلاصهٔ نوشته",
  page_type: "blog",
  published_at: "2026-03-02T10:00:00+00:00",
};

const about: FeedItem = {
  title: "دربارهٔ ما",
  slug: "about",
  path: "/about",
  url: "http://app:8080/about",
  summary: "معرفی مجموعه",
  published_at: "2026-03-05T08:30:00+00:00",
};

function itemTitles(xml: string): string[] {
  return [...xml.matchAll(/<item>[\s\S]*?<title>([\s\S]*?)<\/title>/g)].map((m) => m[1] as string);
}

function itemLinks(xml: string): string[] {
  return [...xml.matchAll(/<item>[\s\S]*?<link>([\s\S]*?)<\/link>/g)].map((m) => m[1] as string);
}

test("xmlEscape neutralizes every XML metacharacter and drops illegal chars", () => {
  assert.equal(xmlEscape(`a&b<c>d"e'f`), "a&amp;b&lt;c&gt;d&quot;e&apos;f");
  assert.equal(xmlEscape(null), "");
  assert.equal(xmlEscape(undefined), "");
  assert.equal(xmlEscape("پاک\u0000\u0007 بماند"), "پاک بماند");
  assert.equal(xmlEscape("سلام & خداحافظ"), "سلام &amp; خداحافظ");
});

test("feed orders items newest first and keeps ties in input order", () => {
  const xml = buildRssFeed({
    baseUrl: BASE,
    items: [blog, about, { title: "قدیمی", path: "/old", published_at: "2020-01-01T00:00:00+00:00" }],
  });

  assert.deepEqual(itemTitles(xml), ["دربارهٔ ما", "نوشتهٔ بلاگ", "قدیمی"]);

  const tied = buildRssFeed({
    baseUrl: BASE,
    items: [
      { title: "یک", path: "/a", published_at: "2026-01-01T00:00:00+00:00" },
      { title: "دو", path: "/b", published_at: "2026-01-01T00:00:00+00:00" },
    ],
  });
  assert.deepEqual(itemTitles(tied), ["یک", "دو"]);

  const byUpdate = buildRssFeed({
    baseUrl: BASE,
    items: [
      { title: "الف", path: "/a", updated_at: "2026-02-01T00:00:00+00:00" },
      { title: "ب", path: "/b", published_at: "2026-01-01T00:00:00+00:00" },
    ],
  });
  assert.deepEqual(itemTitles(byUpdate), ["الف", "ب"]);
});

test("feed includes blog and non-blog items and drops anything not published", () => {
  const xml = buildRssFeed({
    baseUrl: BASE,
    items: [
      blog,
      about,
      { title: "پیش‌نویس", path: "/draft", status: "draft" },
      { title: "خصوصی", path: "/private", is_private: true },
      { title: "بدون لینک", status: "published" },
      { title: "با میزبانِ غریبه", path: null, url: "http://app:8080/x" },
    ],
  });

  assert.deepEqual(itemTitles(xml), ["دربارهٔ ما", "نوشتهٔ بلاگ"]);
  assert.deepEqual(itemLinks(xml), [`${BASE}/about`, `${BASE}/blog/post-a`]);
  assert.ok(!xml.includes("app:8080"));
});

test("feed builds absolute URLs from the site URL, never from the upstream host", () => {
  assert.equal(feedItemUrl(blog, BASE), `${BASE}/blog/post-a`);
  assert.equal(feedItemUrl(blog, `${BASE}/`), `${BASE}/blog/post-a`);
  assert.equal(feedItemUrl({ path: "/" }, BASE), `${BASE}/`);
  assert.equal(feedItemUrl({ url: "/about" }, BASE), `${BASE}/about`);

  // دامنهٔ تنظیم‌شده همیشه برنده است؛ `url` خامِ بک‌اند (میزبان داخلیِ API) نه.
  assert.equal(feedItemUrl(blog, null), "http://app:8080/blog/post-a");
  assert.equal(feedItemUrl({ url: "http://app:8080/x" }, null), "http://app:8080/x");
  assert.equal(feedItemUrl({ path: "/about" }, null), null);

  // بی‌مسیر و بی‌لینک ⇒ حذف، نه قلاب به ریشهٔ سایت.
  assert.equal(feedItemUrl({}, BASE), null);
  assert.equal(feedItemUrl({ url: "http://app:8080/x" }, BASE), null);

  const leaked = buildRssFeed({
    baseUrl: BASE,
    items: [{ title: "فقط لینکِ بک‌اند", url: "http://app:8080/only-url" }],
  });
  assert.ok(!leaked.includes("app:8080"));
  assert.ok(!leaked.includes("<item>"));

  const relative = buildRssFeed({
    baseUrl: BASE,
    items: [{ title: "مسیرِ نسبی", url: "/only-url" }],
  });
  assert.ok(relative.includes(`<link>${BASE}/only-url</link>`));
  assert.ok(!relative.includes("app:8080"));
});

test("feed emits a permalink guid, a language and RFC 2822 dates", () => {
  const xml = buildRssFeed({
    baseUrl: BASE,
    locale: "en",
    channel: { title: "پیش‌نویس & اجرا", description: "توضیح", self: "/en/blog/feed.xml" },
    items: [blog],
  });

  assert.ok(xml.includes(`<guid isPermaLink="true">${BASE}/blog/post-a</guid>`));
  assert.ok(xml.includes("<language>en</language>"));
  assert.ok(xml.includes("<pubDate>Mon, 02 Mar 2026 10:00:00 GMT</pubDate>"));
  assert.ok(xml.includes('<atom:link href="https://armanaskouei.ir/en/blog/feed.xml" rel="self"'));
  assert.ok(xml.includes("<title>پیش‌نویس &amp; اجرا</title>"));
  assert.ok(xml.includes("<description>خلاصهٔ نوشته</description>"));
  assert.ok(xml.includes('<rss version="2.0"'));
  assert.ok(xml.startsWith('<?xml version="1.0" encoding="UTF-8"?>'));

  const fa = buildRssFeed({ baseUrl: BASE, locale: "fa", items: [] });
  assert.ok(fa.includes("<language>fa</language>"));
});

test("feed escapes hostile titles and summaries instead of emitting raw markup", () => {
  const xml = buildRssFeed({
    baseUrl: BASE,
    items: [
      {
        title: '<script>alert("x")</script> & بیشتر',
        path: "/x",
        summary: "a & b < c\n\nدوم",
        published_at: "2026-03-02T10:00:00+00:00",
      },
    ],
  });

  assert.ok(!xml.includes("<script>"));
  assert.ok(xml.includes("&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; بیشتر"));
  assert.ok(xml.includes("<description>a &amp; b &lt; c دوم</description>"));
});

test("feed honours the limit, accepts missing dates and tolerates bad data", () => {
  const many = Array.from({ length: 60 }, (_, i) => ({
    title: `صفحه ${i}`,
    path: `/p${i}`,
    published_at: `2026-01-${String((i % 28) + 1).padStart(2, "0")}T00:00:00+00:00`,
  }));

  assert.equal(buildRssFeed({ baseUrl: BASE, items: many }).match(/<item>/g)?.length, 50);
  assert.equal(buildRssFeed({ baseUrl: BASE, items: many, limit: 3 }).match(/<item>/g)?.length, 3);
  assert.equal(buildRssFeed({ baseUrl: BASE, items: many, limit: 0 }).match(/<item>/g), null);

  const noDate = buildRssFeed({ baseUrl: BASE, items: [{ title: "بی‌تاریخ", path: "/n" }] });
  assert.deepEqual(itemTitles(noDate), ["بی‌تاریخ"]);
  assert.ok(!noDate.includes("<pubDate>"));
  assert.ok(!noDate.includes("<lastBuildDate>"));

  const badDate = buildRssFeed({
    baseUrl: BASE,
    items: [{ title: "تاریخِ خراب", path: "/n", published_at: "نامعلوم" }],
  });
  assert.ok(!badDate.includes("<pubDate>"));

  const untitled = buildRssFeed({ baseUrl: BASE, items: [{ path: "/only-path" }] });
  assert.ok(untitled.includes(`<title>${BASE}/only-path</title>`));
});

test("empty and null input still produce a valid feed (fail-soft)", () => {
  for (const xml of [
    buildRssFeed({ baseUrl: BASE, items: [] }),
    buildRssFeed({ baseUrl: BASE, items: null }),
    buildRssFeed({ baseUrl: BASE }),
  ]) {
    assert.ok(xml.includes("<channel>"));
    assert.ok(xml.includes("<language>fa</language>"));
    assert.ok(!xml.includes("<item>"));
  }

  assert.ok(buildRssFeed({ items: [] }).includes("<title>وب‌سایت</title>"));
});

test("isFeedItemPublic treats a missing status as published", () => {
  assert.equal(isFeedItemPublic({}), true);
  assert.equal(isFeedItemPublic({ status: "published" }), true);
  assert.equal(isFeedItemPublic({ status: "PUBLISHED " }), true);
  assert.equal(isFeedItemPublic({ status: "draft" }), false);
  assert.equal(isFeedItemPublic({ status: "archived" }), false);
  assert.equal(isFeedItemPublic({ is_private: true }), false);
  assert.equal(isFeedItemPublic({ is_private: false }), true);
});

test("rfc2822 converts ISO dates and rejects junk", () => {
  assert.equal(rfc2822("2026-03-02T10:00:00+00:00"), "Mon, 02 Mar 2026 10:00:00 GMT");
  assert.equal(rfc2822(""), null);
  assert.equal(rfc2822(null), null);
  assert.equal(rfc2822("نامعلوم"), null);
});