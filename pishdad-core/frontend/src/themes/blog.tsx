import { BlockRenderer } from "@/components/site/BlockRenderer";
import { SiteFooter, SiteHeader, themeVars } from "@/components/site/Chrome";
import { SiteBreadcrumbs } from "@/components/site/SiteBreadcrumbs";
import { siteModeBootstrapJs, type SiteMode } from "@/lib/site-mode";
import { jalaliDate } from "@/lib/fa";
import {
  blogArchiveHref,
  blogCategoryHref,
  blogFeedHref,
  blogPostHref,
  blogTagHref,
  paginationPages,
} from "@/lib/blog";
import { DEFAULT_PUBLIC_LOCALE, isPublicLocale, publicT, type PublicLocale } from "@/lib/i18n/public";
import type { BlogPostItem, SiteChrome } from "@/lib/site";
import { siteLazyLoadEnabled } from "@/lib/site";
import type { ThemeBlogProps } from "./types";

/**
 * WF-C7 — پیاده‌سازیِ پیش‌فرضِ اسلاتِ بلاگ.
 *
 * قالب‌ها می‌توانند `Blog` خودشان را بدهند؛ هر قالبی که ندهد با همین پوسته
 * رندر می‌شود — همان `theme-{variant}` و هدر/فوتر مشترک، پس ظاهر با بقیهٔ
 * سایت هم‌خوان می‌ماند. این فایل عمداً به `themes/registry` وابسته نیست تا
 * چرخهٔ import نسازد.
 */

function effectiveLocales(chrome: SiteChrome | null, fallback: PublicLocale) {
  const raw = Array.isArray(chrome?.locales) ? chrome.locales : [];
  const locales = raw.filter((x): x is PublicLocale => isPublicLocale(x));
  const primary = isPublicLocale(chrome?.primary_locale) ? chrome.primary_locale : DEFAULT_PUBLIC_LOCALE;
  const list = locales.length > 0 ? locales : [primary];
  return { locales: list, primary, current: list.includes(fallback) ? fallback : primary };
}

function blogDate(iso: string | null | undefined, locale: PublicLocale): string {
  if (!iso) return "";
  if (locale === "en") return iso.slice(0, 10);
  return jalaliDate(iso);
}

function Meta({
  post,
  locale,
  primary,
}: {
  post: BlogPostItem;
  locale: PublicLocale;
  primary: PublicLocale;
}) {
  const date = blogDate(post.published_at, locale);
  return (
    <p className="site-blog-meta">
      {post.author ? <span className="site-blog-author">{post.author}</span> : null}
      {post.author && date ? " · " : null}
      {date ? <time dateTime={post.published_at ?? undefined}>{date}</time> : null}
      {post.category ? (
        <>
          {" · "}
          <a className="site-blog-cat" href={blogCategoryHref(locale, primary, post.category)}>
            {post.category}
          </a>
        </>
      ) : null}
    </p>
  );
}

function PostCard({
  post,
  locale,
  primary,
}: {
  post: BlogPostItem;
  locale: PublicLocale;
  primary: PublicLocale;
}) {
  const href = blogPostHref(locale, primary, post.slug);
  return (
    <div className="site-blog-card">
      {post.image_url ? (
        <a className="site-blog-thumb" href={href} tabIndex={-1} aria-hidden="true">
          <img src={post.image_url} alt="" loading="lazy" />
        </a>
      ) : null}
      <div className="site-blog-card-body">
        <h2 className="site-blog-card-title">
          <a href={href}>{post.title}</a>
        </h2>
        <Meta post={post} locale={locale} primary={primary} />
        {post.excerpt ? <p className="site-blog-excerpt">{post.excerpt}</p> : null}
        <a className="site-blog-more" href={href}>
          {publicT(locale, "blog.readMore")}
        </a>
      </div>
    </div>
  );
}

function Pager({
  archive,
  locale,
  primary,
}: {
  archive: NonNullable<ThemeBlogProps["archive"]>;
  locale: PublicLocale;
  primary: PublicLocale;
}) {
  if (archive.lastPage <= 1) return null;
  const pages = paginationPages(archive.page, archive.lastPage);
  return (
    <nav className="site-blog-pager" aria-label={publicT(locale, "blog.pagination")}>
      {archive.page > 1 ? (
        <a className="site-blog-page" href={blogArchiveHref(locale, primary, archive.page - 1)} rel="prev">
          {publicT(locale, "blog.prev")}
        </a>
      ) : null}
      {pages.map((n) => (
        <a
          key={n}
          className={`site-blog-page${n === archive.page ? " is-current" : ""}`}
          href={blogArchiveHref(locale, primary, n)}
          aria-current={n === archive.page ? "page" : undefined}
        >
          {n}
        </a>
      ))}
      {archive.page < archive.lastPage ? (
        <a className="site-blog-page" href={blogArchiveHref(locale, primary, archive.page + 1)} rel="next">
          {publicT(locale, "blog.next")}
        </a>
      ) : null}
    </nav>
  );
}

function BlogIndex({
  archive,
  locale,
  primary,
}: {
  archive: NonNullable<ThemeBlogProps["archive"]>;
  locale: PublicLocale;
  primary: PublicLocale;
}) {
  const title = archive.category
    ? publicT(locale, "blog.categoryTitle", { name: archive.category })
    : archive.tag
      ? publicT(locale, "blog.tagTitle", { name: archive.tag })
      : publicT(locale, "blog.archiveTitle");
  return (
    <div className="site-blog">
      <header className="site-blog-head">
        <h1>{title}</h1>
        <a className="site-blog-feed" href={blogFeedHref(locale, primary)}>
          {publicT(locale, "blog.feed")}
        </a>
      </header>
      {archive.posts.length === 0 ? (
        <p className="site-blog-empty">{publicT(locale, "blog.empty")}</p>
      ) : (
        <div className="site-blog-list">
          {archive.posts.map((post) => (
            <PostCard key={post.slug} post={post} locale={locale} primary={primary} />
          ))}
        </div>
      )}
      <Pager archive={archive} locale={locale} primary={primary} />
    </div>
  );
}

function BlogPost({
  page,
  locale,
  primary,
  schemas,
  author,
  tags,
  imageUrl,
  imageLoading,
}: {
  page: NonNullable<ThemeBlogProps["page"]>;
  locale: PublicLocale;
  primary: PublicLocale;
  schemas: ThemeBlogProps["schemas"];
  author: string | null | undefined;
  tags: string[];
  imageUrl: string | null | undefined;
  imageLoading: "lazy" | "eager";
}) {
  const date = blogDate(page.published_at ?? undefined, locale);
  return (
    <article className="site-blog-post">
      <SiteBreadcrumbs slug={page.slug} locale={locale} primary={primary} lastLabel={page.title} />
      <header className="site-blog-post-head">
        <h1>{page.title}</h1>
        <p className="site-blog-meta">
          {author ? <span className="site-blog-author">{author}</span> : null}
          {author && date ? " · " : null}
          {date ? <time dateTime={page.published_at ?? undefined}>{date}</time> : null}
        </p>
      </header>
      {imageUrl ? <img className="site-blog-cover" src={imageUrl} alt="" loading={imageLoading} /> : null}
      <BlockRenderer blocks={page.blocks ?? []} schemas={schemas} locale={locale} imageLoading={imageLoading} />
      {tags.length > 0 ? (
        <div className="site-blog-tags">
          {tags.map((tag) => (
            <a key={tag} className="site-blog-tag" href={blogTagHref(locale, primary, tag)}>
              #{tag}
            </a>
          ))}
        </div>
      ) : null}
      <p className="site-blog-back">
        <a href={blogArchiveHref(locale, primary)}>{publicT(locale, "blog.backToBlog")}</a>
      </p>
    </article>
  );
}

export function DefaultBlogTheme({ variant, ...props }: ThemeBlogProps & { variant: string }) {
  const { kind, locale, chrome, schemas, switcher, jsonLd, archive, page, author, tags, imageUrl } = props;
  const { primary } = effectiveLocales(chrome, locale);
  const defaultMode: SiteMode = chrome?.mode === "light" ? "light" : "dark";
  const imageLoading: "lazy" | "eager" = siteLazyLoadEnabled(chrome) ? "lazy" : "eager";

  return (
    <div
      className={`site theme-${variant}`}
      data-mode={defaultMode}
      style={themeVars(chrome)}
      dir={locale === "en" ? "ltr" : "rtl"}
      lang={locale}
      suppressHydrationWarning
    >
      <script dangerouslySetInnerHTML={{ __html: siteModeBootstrapJs() }} />
      {jsonLd}
      <SiteHeader chrome={chrome} locale={locale} switcher={switcher} defaultMode={defaultMode} />
      <div className={`site-body theme-body-${variant} theme-place-stacked`}>
        <main>
          {kind === "index" && archive ? (
            <BlogIndex archive={archive} locale={locale} primary={primary} />
          ) : null}
          {kind === "post" && page ? (
            <BlogPost
              page={page}
              locale={locale}
              primary={primary}
              schemas={schemas}
              author={author}
              tags={tags ?? []}
              imageUrl={imageUrl}
              imageLoading={imageLoading}
            />
          ) : null}
        </main>
      </div>
      <SiteFooter chrome={chrome} locale={locale} />
    </div>
  );
}
