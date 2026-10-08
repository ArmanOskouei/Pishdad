# Admin panel search contract — ⛔ superseded (B5)

**English** · **[فارسی / Persian](SEARCH-PLUGIN-CONTRACT.md)**

> ## This document is no longer a contract
>
> **`fetchItems` was removed.** That function called `/api/proxy/v1/admin/...` from inside the
> browser, which meant any plugin could call **any core API route** from the browser — exactly
> what API separation was supposed to prevent.
>
> **The correct path:** declare a class that implements `App\Search\SearchableProvider` through
> the `core.service_provider` extension point so it runs **on the server**.
>
> ```json
> {
>   "point": "core.service_provider",
>   "interface": "App\\Search\\SearchableProvider",
>   "class": "Pishdad\\Plugins\\Blog\\SearchProvider"
> }
> ```
>
> The server calls `GET /api/v1/admin/search?q=…` and merges the providers. Implementation:
> `AdminSearchController` + `ServiceProviderRegistry`.
>
> What is still alive from the old contract: the **static page index**. It carries no sensitive
> data and only feeds the shortcut hints. But even that is no longer needed for plugins.

---

<details>
<summary>Old text (for reference only)</summary>

> Implementation reference: `pishdad-core/frontend/src/lib/search-registry.ts`
> Components: `pishdad-core/frontend/src/components/search/` — results page: `admin/search?q=…`

## 1. Global registry

```ts
window.__ADMIN_SEARCH__ = {
  sources: SearchSource[],
  registerSearchSource: (p: RegisterPayload) => () => void, // return value = unregister
};
```

Registering from anywhere in the client (a plugin component, an injected script) is allowed;
registering again with the same `plugin` replaces the previous version. The returned function
removes the registration (always clean it up in `useEffect`).

## 2. Input shape

```ts
registerSearchSource({
  plugin: "blog",                 // required, unique
  pages: [                        // static pages (optional)
    {
      title: "Blog posts",        // required
      path: "/admin/blog/posts",   // required — internal panel path
      icon: "✎",                   // required — emoji/character (no external SVG)
      group: "content",            // required: pages | content | files | other
      subtitle: "Manage posts and categories",   // optional
      capabilities: ["New post", "Blog categories", "Publish post"], // 2-4 synonyms
    },
  ],
  capabilities: ["Manage posts"], // optional — overall plugin capabilities
  fetchItems: async (q) => [...],    // optional — dynamic source (records)
});
```

### Dynamic item (`SearchHit`)

```ts
{ id: "blog:12", plugin: "blog", title: "…", subtitle: "Blog post",
  path: "/admin/blog/posts/12/edit", icon: "✎", group: "content" }
```

- `id` must be unique across the whole panel (prefix it with the plugin name).
- `path` is a direct link to the record (edit/view).
- Catch errors/401 inside `fetchItems` yourself and return `[]`; the search engine swallows
  errors too, but the login redirect from `authed` can be annoying.

## 3. Full example 1 — blog plugin (posts in results)

```tsx
"use client";
import { useEffect } from "react";
import { registerSearchSource } from "@/lib/search-registry";
import { authed } from "@/lib/auth";

export function BlogSearchSource() {
  useEffect(() => {
    return registerSearchSource({
      plugin: "blog",
      pages: [
        {
          title: "Blog posts", path: "/admin/blog/posts", icon: "✎",
          group: "content", subtitle: "Manage posts and categories",
          capabilities: ["New post", "Blog categories", "Publish post"],
        },
      ],
      capabilities: ["Manage posts"],
      fetchItems: async (q) => {
        try {
          const posts = await authed<Array<{ id: number; title: string }>>(
            `/v1/admin/blog/posts?per_page=8&search=${encodeURIComponent(q)}`,
          );
          return posts.map((p) => ({
            id: `blog:${p.id}`, plugin: "blog", title: p.title,
            subtitle: "Blog post", path: `/admin/blog/posts/${p.id}/edit`,
            icon: "✎", group: "content" as const,
          }));
        } catch {
          return [];
        }
      },
    });
  }, []);
  return null;
}
```

## 4. Full example 2 — members plugin (members in results)

```tsx
"use client";
import { useEffect } from "react";
import { registerSearchSource } from "@/lib/search-registry";
import { authed } from "@/lib/auth";

export function MembersSearchSource() {
  useEffect(() => {
    return registerSearchSource({
      plugin: "members",
      pages: [
        {
          title: "Members", path: "/admin/members", icon: "◍",
          group: "other", subtitle: "Site member list",
          capabilities: ["New member", "Search members", "Member access level"],
        },
      ],
      capabilities: ["Manage members"],
      fetchItems: async (q) => {
        try {
          const members = await authed<Array<{ id: number; name: string }>>(
            `/v1/admin/members?per_page=8&search=${encodeURIComponent(q)}`,
          );
          return members.map((m) => ({
            id: `members:${m.id}`, plugin: "members", title: m.name,
            subtitle: "Site member", path: `/admin/members/${m.id}`,
            icon: "◍", group: "other" as const,
          }));
        } catch {
          return [];
        }
      },
    });
  }, []);
  return null;
}
```

## 5. Rules

1. **Normalization is the engine's job** — send raw Persian text; Arabic ي/ك, diacritics and the
   zero-width non-joiner are unified automatically. Matching = includes + word-start across all
   tokens (AND).
2. **The dynamic cache is yours** — at most one call per query; a 60-second client-side cache is
   recommended (the core does the same).
3. **No sensitive data in the static index** — no email, no amount, no token. Dynamic records
   return only `title/subtitle/path`.
4. Pick `group` correctly so results land under the right heading:
   `pages` (pages) • `content` (site content) • `files` (files) • `other` (everything else).
5. The Topbar dropdown shows at most **8** results; Enter opens the full results page
   (`/admin/search?q=…`) with grouping and highlighting.

## 6. Known TODOs

- Deeper typo tolerance (Levenshtein distance) is not there yet — only includes + word-start.
- Weighted priority between sources (e.g. boosting an active plugin) is undefined.

</details>

---

## Known differences (docs vs. code)

- The `fetchItems` browser path is dead by design (B5). The live channel is the server-side
  `App\Search\SearchableProvider` interface declared via `core.service_provider`, resolved by
  `ServiceProviderRegistry` and served by `AdminSearchController` at `GET /api/v1/admin/search`.
- The old `registerSearchSource` client registry may still exist for the static page index, but
  it is no longer part of the plugin contract.
