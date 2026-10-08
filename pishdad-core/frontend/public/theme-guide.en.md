# Pishdad Theme Developer Guide

> Reference version: the current code contract of the repository (`pishdad-core/frontend/src/themes/**`, `pishdad-core/backend/app/...`). Every name and path in this document is extracted from the actual code. This document is rendered at `/admin/themes?tab=zip` and is designed for PDF export.

## 1) Concept: what a theme IS in Pishdad

A site theme in Pishdad is **a Next.js component that decides page structure** (header, footer, sidebars, content arrangement) plus a **declarative manifest** (`theme.json`). Content data (blocks) belongs to the page, not the theme; the theme only renders it. Themes differ in layout and skin, never in data.

Two distinct tracks exist and must not be confused:

| | **Built-in code theme (skin)** | **ZIP package** |
|---|---|---|
| Location | `pishdad-core/frontend/src/themes/{slug}/` with `theme.json` + `index.tsx` | A ZIP uploaded in the panel, stored on the `local` disk at `themes/shared/{slug}_{rand}.zip` |
| Registry | `src/themes/manifests.ts` (known slugs: `minimal`, `editorial`, `commerce`) + `src/themes/registry.tsx` (components) | The backend `themes` table; only `manifest.json` is read from inside the ZIP (`ThemeController::readManifest`) |
| Structural impact | Full — the component builds its own header/footer/sidebars | **None** — only tokens (`globals`), footer defaults (`footer`), and metadata. Site structure comes from the component of the active slug; an unknown slug falls back to `DEFAULT_THEME_SLUG = "minimal"` |
| Flow | Source is compiled with the frontend; no ZIP install/signing | Upload → activation (`review_status` follows the uploader: an operator's upload is `approved`, a normal admin's is `pending`) |

**How is the active theme chosen?** The backend, in `GET /v1/site/chrome`, returns `theme.slug` from `SiteThemeResolver::activeSlug()`: first the install-wide selection in the `site_theme_settings` table (`user_id = 0`, `key = 'default'`), then the `themes.active` column (the ZIP track), finally `FALLBACK_THEME = 'minimal'`. The frontend picks the component via `resolveTheme(slug)`; **any unknown slug falls back to minimal and never throws or white-screens** — this is the ECO1 contract, locked by `manifests.test.ts`.

**Panel theme ≠ site theme.** The admin panel appearance (`/admin/appearance`, `:root` tokens, `data-direction="..."` on `<html>`) is a separate system. The public site keeps its tokens in an independent `.site` scope with `--site-*` base names and an overridable `--theme-*` layer, so the panel skin never leaks into the site (decision F0.8 in `globals.css`). Within `/admin/themes` there are two tabs: skin (skin × colorway × mode selection) and ZIP packages. This document is about **site themes**, not panel appearance.

## 2) Folder and file contract

**Code theme** — exact path: `pishdad-core/frontend/src/themes/{slug}/` with exactly two files:

- `theme.json` — the manifest. Required keys: `slug`, `name`, `description`, `version`, `modes`, `slots`, `tokens`
- `index.tsx` — the theme component implementing `ThemeProps`

Scaffold: `php artisan pishdad-theme:make {slug}` (slug pattern: `^[a-z0-9][a-z0-9._-]{1,39}$`; default output path = `pishdad-core/frontend/src/themes`, overridable via the `THEME_SCAFFOLD_PATH` env in `config/theme.php`).

**Component signature** (`src/themes/types.ts`):

```ts
export interface ThemeProps {
  page: SitePage;              // published page (blocks + sidebars + meta)
  chrome: SiteChrome | null;   // public chrome + theme.globals
  schemas?: SiteBlockSchemas;  // plugin block schema registry (chrome.blocks) — for DeclaredBlock
  banner?: ReactNode;          // push opt-in banner — homepage only
  jsonLd?: ReactNode;          // JSON-LD script — deep pages only
  locale?: PublicLocale;       // "fa" | "en" — default fa
  switcher?: { locales: PublicLocale[]; primary: PublicLocale }; // only when the site is bilingual
}

export interface ThemeLayoutConfig {
  sidebarPlacement: "columns" | "stacked"; // sidebars beside or below content
  mainMaxInlineSize?: number;              // max content column width (px) — undefined = full width
}
```

**Complete copy-pasteable example (the real `minimal` in this repo):**

`theme.json`:
```json
{
  "slug": "minimal",
  "name": "مینیمال",
  "description": "Airy, undecorated layout; sidebars stack below content. Good for blogs and light corporate sites.",
  "version": "1.0.0",
  "modes": ["light", "dark", "system"],
  "slots": ["header", "footer", "main", "left", "right", "banner"],
  "tokens": {
    "radius-sm": "2px", "radius-md": "4px", "radius-lg": "6px", "radius-xl": "8px",
    "fs-body": "15px", "fs-small": "13px", "fs-h": "19px", "density": "1"
  }
}
```

`index.tsx`:
```tsx
import { ThemeShell } from "../shared";
import type { ThemeProps } from "../types";

export function MinimalTheme(props: ThemeProps) {
  return <ThemeShell {...props} variant="minimal" layout={{ sidebarPlacement: "stacked" }} />;
}

export default MinimalTheme;
```

**Registration:** the slug is registered in `THEME_MANIFESTS` in `src/themes/manifests.ts` and `{ manifest, Component }` in `DEFINITIONS` in `src/themes/registry.tsx`. `variant` produces the `theme-{slug}` and `theme-body-{slug}` classes on the wrapper.

**ZIP package** — a single `manifest.json` at the ZIP root:

```json
{
  "name": "My Theme",
  "slug": "my-theme",
  "version": "1.0.0",
  "description": "Short description",
  "author": "Your name",
  "requires": { "core": ">=1.6.0" },
  "globals": { "primary": "#0d9488", "bg": "#ffffff", "surface": "#ffffff", "text": "#16181d" },
  "layout": "stacked",
  "builtin": false,
  "footer": {
    "columns": 3,
    "widgets": [
      { "type": "about", "settings": {} },
      { "type": "links", "settings": { "heading": "Quick links" } },
      { "type": "copyright", "settings": {} }
    ]
  },
  "signature": "BASE64-Ed25519-SIGNATURE"
}
```

Fields actually consumed by the backend: `name` (required — 422 otherwise), `slug` (optional; `Str::slug(name)`; duplicate = 422), `version` (default `1.0.0`), `signature` (optional; invalid = 422 upload rejection with a `theme.upload_rejected` log), `globals` (base tokens, merged into `chrome.theme.globals`), `footer` (`columns` between 1 and 4 + `widgets` with a `type` from the core vocabulary or the footer widgets declared in the same manifest; max 30 items; invalid entries are ignored fail-soft — `ManifestRegistry::themeFooterDefaults`), `layout` and `builtin` (returned only by `GET /v1/site/theme/{slug}`). Max package size **20 MB**, `mimes:zip`.

## 3) Theme tokens

Source of truth: `SiteThemeResolver` in `pishdad-core/backend/app/Services/Themes/SiteThemeResolver.php`.

**Color roles (`COLOR_ROLES` — 11):**
`primary`, `primary-hover`, `primary-soft`, `accent`, `accent-soft`, `bg`, `surface`, `surface-2`, `text`, `text-muted`, `border`
— the allowed value for user overrides is **hex only**: `#rgb` / `#rgba` / `#rrggbb` / `#rrggbbaa`. `var()`, `url()`, `;` and CSS color names are deliberately rejected (the CSS attack surface stays closed).

**Layout roles (`LAYOUT_ROLES` — 8):**
`radius-sm`, `radius-md`, `radius-lg`, `radius-xl`, `fs-body`, `fs-small`, `fs-h`, `density`
— allowed values contain only the characters `#a-z0-9(),.%-_ ` (e.g. `14px`, `1.15`).

**Merge order:** `layout_tokens` (skin) ← `preset.tokens` (colorway) ← `overrides` (per-key user edits). Each layer overrides the previous one.

**`mode` is a selector, not a color:** `light` picks the colorway's `tokens.light` half, `dark` picks `tokens.dark`, and `system` returns no half — the decision is left to the browser's CSS (`prefers-color-scheme`). Colorways live in the `site_theme_presets` table with two halves, `light`/`dark`, and come out of `GET /v1/site/theme/{slug}` as `colorways: [{key, name, light, dark}]` + `default_colorway` (switched live by `PreviewControls` on `/preview/{slug}`).

**Token → CSS — `themeVars()`:** the `themeVars()` function in `src/components/site/Chrome.tsx` takes each key of `chrome.theme.globals` matching `^[a-z0-9-]+$`, prefixes it to `--theme-{key}`, and applies it as inline style on the wrapper. `globals.css` then maps it inside the `.site` scope:

```css
.site {
  --primary: var(--theme-primary, var(--site-primary));
  --bg: var(--theme-bg, var(--site-bg));
  /* ... for all 11 color roles + 8 layout roles */
}
.site[data-mode="light"] { /* the site's own light palette */ }
```

Key rules:
- The base is defined separately as `--site-*` so nothing inherits from the panel's `:root` or `[data-direction]`.
- The `-soft` tokens (`--primary-soft`, `--accent-soft`) must always be declared **explicitly** by the theme; since `color-mix` cannot resolve dynamic variables, if you only provide the base color the buttons' soft color will mismatch the button color.
- `--font` is deliberately not themeable (site and panel share one font — Vazirmatn). Shadows and `--success/--warning/--danger/--info` also have no `--theme-*` hooks — do not promise them.
- Light mode activates only via `data-mode="light"` on **the `.site` element**, never `:root` — turning the site light must never turn the panel light.

## 4) Backend data the theme consumes

**`SiteChrome`** (response of `GET /v1/site/chrome`; backend cache 120 s):

| Key | Type | Notes |
|---|---|---|
| `title`, `description` | `string` | Site name and description |
| `logo_url`, `favicon_url` | `string?` | Public logo/favicon URLs (the logo always comes from site settings) |
| `phone`, `email`, `address` | `string?` | Contact — the `contact` widget and JSON-LD |
| `site_url`, `og_image_url`, `ai_summary`, `robots_index` | — | SEO/GEO |
| `locale`, `locales`, `primary_locale`, `timezone` | — | `locales` has the primary first; single-language = one member |
| `homepage_page_id`, `homepage_slug` | `number?/string?` | Configured homepage |
| `header`, `footer` | `LayoutData` | `{ widgets: WidgetValue[], layout }` — page items and `media_url` already resolved live |
| `socials` | `SocialItem[]` | Only `active` items: `{key, url, active, label?, icon_url?}` |
| `mode` | `"light"\|"dark"\|"system"\|null` | `/site/chrome` does not populate it yet; consume optionally/defensively |
| `theme` | `{name, slug, version, globals} \| null` | `globals` = theme manifest merged with resolver tokens (bare keys; `themeVars()` adds the prefix) |
| `blocks` | `SiteBlockSchemas` | Schema registry of core + active plugin blocks (schema-only, no JS) |

**`SitePage`** (response of `site/homepage` and `site/pages/{path}`):

```ts
{ title, slug, is_single, blocks: BlockValue[], meta?, published_at?, updated_at?, version?,
  sidebars?: { left?: { enabled, preset_id?, blocks }, right?: { ... } } }
```
Blocks are `{ type: string, data: Record<string, unknown> }` and the backend has already injected file URLs: `url` (for `media_id`/`image_id`), `url_0..N` (for `media_ids`), `poster_url`.

**Reading data (only via `src/lib/site.ts` — never raw fetch):**
`fetchSiteChrome()`, `fetchHomepage(locale?)`, `fetchSitePage(slug, locale?)`, `fetchSitePages(locale?)`, `fetchSiteTheme(slug)`, `fetchSiteStatus(siteId)`, `siteBaseUrl(chrome)`. All ISR with `revalidate: 300` and the `pages` tag (+ dedicated tags `page:{slug}`, `site-chrome`, `site-homepage`, `theme:{slug}`); errors resolve to `null` (never throw). Upstream address from `INTERNAL_API_URL ?? NEXT_PUBLIC_API_URL ?? http://localhost:8080/api`.

**What the route passes to the theme** — exactly `ThemeProps`: `page`, `chrome`, `schemas={chrome?.blocks}`, `locale`, `switcher` (only when `locales.length > 1`), plus `banner` (`<PushOptInBanner />` on the homepage only) or `jsonLd` (deep pages only, with `< > &` safely escaped).

## 5) Rendering engine

**Header/footer — use `SiteHeader`/`SiteFooter`/`ChromeWidget` (file `src/components/site/Chrome.tsx`); do not reimplement.** Full widget list (`CORE_CHROME_WIDGET_TYPES`, mirroring `config/widgets.php`):

| Widget | Area | Settings keys | Renderer behavior |
|---|---|---|---|
| `logo` | header | `show_title` (default `true`) | Image always from `chrome.logo_url` (the `media_id/size/media_url` keys are stripped server-side) |
| `nav` | header | `links` (max 12 items; each `{kind: "page"\|"custom", page_id?, label?, href?, children?[]}`; submenu ≤8, depth ≤2; kind=page gets live title/url from the backend) + `style: "horizontal"\|"mega"` | Rendered by `SiteNav` |
| `search` | header | `placeholder` | Rendered by `SiteSearchBox` |
| `cta` | header | `label`, `href` | Via `safeHref` — `javascript:` dies |
| `socials` | header | — | From `chrome.socials` (active only) |
| `about` | footer | `text` (≤500) | Fallback: `chrome.description` |
| `links` | footer | `heading`, `links` (same shape as nav) | Each links widget = one grid column |
| `contact` | footer | (`text` exists in the schema but the current renderer does not display it) | Shows `chrome.phone`/`chrome.email`; if neither, a support message |
| `newsletter` | footer | `text` (≤200) | Simple text |
| `copyright` | footer | `text` (≤200) | Fallback: `© year chrome.title` — always spans the full grid row |

Footer grid: column count = `footer.layout.columns` (1–4), otherwise the number of `links` widgets. `Socials` is appended at the end of the footer. Unknown `type` never throws: server-side log + visible notice only in diagnostics mode.

**Blocks — use `BlockRenderer` (file `src/components/site/BlockRenderer.tsx`).** Blocks with `data._enabled === false` are skipped; an empty list → neutral placeholder. Full list of core blocks (`BUILTIN_BLOCK_TYPES` + the keys of `config/blocks.php`) with the exact `data` keys:

| type | data keys | Render notes |
|---|---|---|
| `hero` | `title` (≤160, required), `subtitle` (≤300), `image_id` (→ injected `url`), `align: "right"\|"center"\|"left"` (default right) | Background `url(...) center/cover` else `var(--primary-soft)` |
| `text` | `body` (fallback: `html`) | **The only** HTML block — passes through `safeHtml`; your own HTML must too |
| `image` | `media_id` (→ `url`), or `url`/`src`/`image_url`/`path`, `alt` (≤200), `caption` (≤300) | Missing src → dashed "image unavailable" figure |
| `cta` | `label` (≤80), `href` (≤2048), `style: "primary"\|"secondary"\|"ghost"` | `safeHref` |
| `gallery` | `media_ids` (1..24; → injected `url_0..N`), `columns` (1..6, default 3) | Grid |
| `quote` | `quote` or `text` or `body`, `author` or `cite` | `blockquote` with a primary-colored rule |
| `video` | `url` or `src`, `caption` | `.mp4` → `<video controls>`; otherwise a link card via `safeHref` |
| `faq` | `items: [{q (≤200), a}]` — max 20 rows | `<details>/<summary>` with `site-faq` classes |
| `contact-form` / `contact` | `title` (≤120), `email`, `show_phone` (default `true`) | The `ContactForm` component (posts to `POST /v1/contact/tickets` + honeypot) |

**Declared/plugin blocks:** any `type` outside the core vocabulary → schema from `schemas` (fed from `chrome.blocks`) → `selectBlockPattern`: first the schema's explicit `x-pattern` (`default` or a single-member `enum`), then the synonym table, then the type itself. The 11 allowed patterns (`BLOCK_PATTERNS`): `hero, image, grid, list, gallery, form, rich-text, embed, faq, quote, cta` — rendered by `DeclaredBlock` with the same shared sanitizers. The `default` pattern means unknown → log + diagnostics-only notice; **never throws.**

**The correct full-page render pattern — mirroring `ThemeShell` exactly:**

```tsx
<div className={`site theme-${variant}`} style={themeVars(chrome)} dir={lang === "en" ? "ltr" : "rtl"} lang={lang}>
  {jsonLd}
  <SiteHeader chrome={chrome} locale={lang} switcher={switcher} />
  <div className={`site-body ${cols} theme-body-${variant} theme-place-${layout.sidebarPlacement}`}>
    {/* columns: sidebars before main · stacked: after main */}
    <main style={layout.mainMaxInlineSize ? { maxInlineSize: layout.mainMaxInlineSize } : undefined}>
      <article><BlockRenderer blocks={page.blocks ?? []} schemas={schemas} /></article>
      {banner}
    </main>
  </div>
  <SiteFooter chrome={chrome} locale={lang} />
</div>
```

Sidebars render only when `enabled` and `blocks.length > 0` (`visibleSidebar`). Preserve the sidebars' `aria-label`s.

**Adding a theme-specific structural twist without breaking a11y/security:**
- Use the `theme-{variant}` class + CSS scoped to it (logical properties, no `margin-left`) to change container width, header-inner ordering, cards, and typography.
- Use `layout={{ sidebarPlacement, mainMaxInlineSize }}` to set the sidebar arrangement (editorial: `columns` + ~720px width; minimal: `stacked`).
- **Never fork `BlockRenderer` or `SiteHeader/SiteFooter`** — the `safeHref/safeSrc/safeHtml` sanitizers, ARIA labels, diagnostics behavior, and the vocabulary-locking tests live only in the shared versions. A security fix must land once, not be copied three times.

## 6) APIs available to a theme

All **public and unauthenticated** (prefix `/api/v1`):

| Method + path | Returns | Notes |
|---|---|---|
| `GET /site/chrome?site_id=` | `SiteChrome` | throttle `60,1`; `Cache-Control: public, max-age=60` |
| `GET /site/homepage?site_id=&locale=` | `SitePage` \| 404 | Resolution: `homepage_page_id` → slug `home` → newest published |
| `GET /site/pages?locale=` | `SitePageIndex[]` (≤500) | Lightweight list for sitemap/llms.txt — no blocks |
| `GET /site/pages/{path}?locale=` | `SitePage` \| 404 | Published only |
| `GET /site/theme/{slug}` | `SiteThemeTokens` \| 404 | `name, slug, version, layout, globals, builtin, layout_tokens, colorways[{key,name,light,dark}], default_colorway` — the basis of `/preview/{slug}` |
| `GET /site/search?q=&per_page=` | `{data, meta:{q,total,per_page}}` | `q` required, 2..200 chars; `per_page` ≤50; throttle `site-search` |
| `GET /site/status?site_id=` | `{status: "active", message}` | Lightweight public site status; the message carries the site brand |
| `POST /contact/tickets` | `{ticket_id}` | Public contact form (honeypot + rate limit) — invoked by `ContactForm`, not by themes |

Rule: the theme reads only through the wrappers in `src/lib/site.ts`. Admin routes (`/v1/admin/themes/*`) sit behind Sanctum and are never called from the public site.

## 7) Absolute prohibitions and their consequences

| Forbidden | Why — the real consequence |
|---|---|
| Creating/overwriting anything under `src/app/**` — including public routes (`[locale]/...`, `preview/...`), **the whole panel** (`(client)/admin/**`, `admin/(auth)/**`) and the `src/proxy.ts` middleware | The app tree owns routing for both panel and site; a bad merge = losing the login/panel pages or the site → **the panel breaks with a white screen until a manual reset** |
| Writing into `src/components/**` or `src/lib/**` | Overwriting `BlockRenderer`/`site.ts` means bypassing the sanitizers and ISR; core security behavior silently changes |
| Writing into `next.config.ts`, `package.json`, `.env*` | Build/dependency/env changes = crash at build → the whole frontend goes down |
| Adding a Route Handler or server API to the frontend | The API layer belongs to the backend; a parallel endpoint means bypassing throttle/permissions — a new attack surface |
| `import`ing from `next/server` in theme code | Theme code must render in RSC/client without depending on the middleware runtime |
| Mutating the DB from a theme | The theme is a read-only consumer |
| Raw `<script>` or `dangerouslySetInnerHTML` from untrusted data | Only `text` via `safeHtml` is allowed; the `jsonLd` slot is built by the route, which escapes `< > &` itself |
| Using panel tokens (`.topbar`, `data-direction`, `--sidebar-w`, ...) | The site has its own tokens (`--theme-*`/`--site-*`); panel tokens are either inert or cause theme leakage |
| Relying on your own slug without a fallback | Unknown slugs fall back to `minimal`; the theme must render with any `chrome`, including null/partial (never throw) |

## 8) Build → publish → preview workflow

**A) Code theme (skin):**
1. `php artisan pishdad-theme:make my-theme --name="…" --description="…"`
2. Complete `theme.json` and `index.tsx` (tokens only from `LAYOUT_ROLES`).
3. Add to `THEME_MANIFESTS` in `src/themes/manifests.ts` and to `DEFINITIONS` in `src/themes/registry.tsx` (on the server: the skin folder + the `site_themes` registry).
4. Colorways in `site_theme_presets` (each with `tokens.light`/`tokens.dark` using the `COLOR_ROLES` keys).

**B) ZIP package:**
1. Build a ZIP with `manifest.json` at the **root** of the ZIP (max 20 MB).
2. Panel → `/admin/themes` → the ZIP tab → upload → `POST /v1/admin/themes/upload` (field `file`). 201 response. A theme uploaded by a normal admin is stored as `pending` and cannot be activated; a theme uploaded by an operator is stored as `approved`. The `review_status` is therefore a property of who uploaded it, not a separate approval step.
3. Activate → `POST /v1/admin/themes/{id}/activate`. Activation: the previous theme is deactivated, the `site_chrome` cache (120 s), the token cache (`SiteThemeResolver::flush`), and the frontend ISR (`site-theme`, `theme`, `site-chrome` tags) are invalidated. If the slug is a built-in skin, the resolver is synced too (colorway/mode are preserved).
4. Preview button → `POST /v1/admin/themes/{id}/preview` → `preview_url = /preview/{slug}`. The `/preview/{slug}` page renders with that theme's tokens and the resolved component (`force-dynamic`, noindex); inactive themes are previewable too. The public `/preview` page also shows the active theme.
5. The active theme cannot be deleted; `DELETE /v1/admin/themes/{id}` works only for inactive ones.

## 9) Final checklist + common mistakes

**Checklist:**
- [ ] `theme.json` has all 7 required keys; `tokens` only from the 8 layout roles; no colors inside `tokens` (color belongs to the preset/resolver).
- [ ] Slug matches `^[a-z0-9][a-z0-9._-]{1,39}$` and is unique.
- [ ] The component compiles against `ThemeProps` and renders even without `chrome` (null).
- [ ] Wrapper: `className="site theme-{slug}"` + `style={themeVars(chrome)}` + correct `dir`/`lang`.
- [ ] Header/footer/blocks only via `SiteHeader`/`SiteFooter`/`BlockRenderer`/`ChromeWidget`.
- [ ] No file created outside `src/themes/{slug}/`; registered in `manifests.ts` + `registry.tsx`.
- [ ] CSS only with logical properties, only `var(--theme-*)`/`var(--site-*)`/`site-*` classes.
- [ ] For ZIP: `manifest.json` at root, ≤20 MB, `name` present, colors in `globals` hex-only.
- [ ] `/preview/{slug}` checked in both light and dark modes.

| Common mistake | Result |
|---|---|
| Putting colors in `theme.json.tokens` | Inert — `tokens` accepts layout roles only |
| Keys already prefixed with `--theme-` inside `globals` | `themeVars()` prefixes again → dead key; always write **bare** keys (`primary`, not `--theme-primary`) |
| Omitting the `-soft` tokens | Button soft-colors mismatch the base color — always declare them alongside the base |
| `data-mode` on `:root` | The panel goes light too; only on `.site` |
| Copying `BlockRenderer` "for a better style" | Loses sanitizers/A11Y and fights the vocabulary-locking tests |
| Assuming `chrome.mode` is guaranteed | `/site/chrome` does not populate it yet — read defensively |
| Uploading a ZIP with `manifest.json` in a subfolder | `FL_NODIR` takes the first occurrence, but the formal contract is the root; review will reject it |
| Expecting JSX code from a ZIP | A ZIP carries no executable code — only manifest/tokens/footer defaults |
