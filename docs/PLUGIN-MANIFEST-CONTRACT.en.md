# Plugin manifest contract: access, widgets, page types, database tables

**English** · **[فارسی / Persian](PLUGIN-MANIFEST-CONTRACT.md)**

> Implementation reference: `pishdad-core/backend/app/Services/Plugins/ManifestRegistry.php`
> (the single source that merges core + manifest) — controllers:
> `ManagerController::permissionsMatrix`, `WidgetSchemaController`,
> `LayoutController::pageTypes` — frontend: `admin/managers?tab=roles` (matrix),
> `admin/header-footer` (settings modal), `admin/blocks` (tabs).
> The `db` section: `PluginDbContract` (declaration validation) and
> `PluginMigrationRunner` (verification and migration rewriting) — the rules come from
> `PLUGIN-ECOSYSTEM-ARCHITECTURE.md`.

A plugin is **declarative**: put one of the keys below in `manifest.json`, and the core reads it
at **activation** time and makes it configurable right there in the panel.
Deactivate/uninstall = disappear from the matrix/palette/tabs (previously stored data is left
untouched).

## 1. `permissions` — a new access module

```json
{
  "permissions": [
    { "module": "blog", "title_fa": "بلاگ", "actions": ["view", "edit", "delete"] }
  ]
}
```

- `module`: required, `^[a-z0-9_-]{2,40}$` (e.g. `blog`). **Write it raw** — the core adds the
  slug prefix itself.
- `title_fa`: nominally required — the Persian title of the "module" column in the role matrix
  (`GET /api/v1/admin/permissions` → `modules[].{name,title_fa,source}`).
- `actions`: optional, a subset of `view/edit/delete` (default: all three).
- On activation, rows `plugin:{slug}:blog.view` etc. are created in the permissions table
  (`PluginController::ensurePermissions`) so the role can be ticked right there.
  **Use the final name in code**:
  `->middleware('perm:plugin:{slug}:blog.view')` (superadmin always passes).

  Note: if you write the prefix yourself, it gets prefixed again
  (`plugin:slug:plugin:slug:blog.view`). So write `module` **without** the prefix.
- Because the final name is namespaced, a plugin module never collides with a core module, and
  two plugins with the same `module` name do not share a permission.
  In `admin.menu` it is the opposite: there `permission` is the **full** name and does need the
  prefix.

## 2. `widgets` — a new header/footer widget

```json
{
  "widgets": {
    "header": {
      "promo": {
        "title": "بنر تخفیف",
        "description": "نوار تخفیف بالای هدر.",
        "schema": {
          "type": "object",
          "properties": {
            "text": { "type": "string", "maxLength": 120 }
          }
        },
        "ui": { "labels": { "text": "متن بنر" } }
      }
    }
  }
}
```

- Each entry has exactly the shape of `config/widgets.php`: `title` + `description` +
  lightweight JSON Schema + `ui.labels` (the Persian label of each field) and `ui.media_field`
  (the key picked with the MediaPicker, such as `media_id` in the `logo` widget).
- Only a **new** `type` is accepted (overriding a core type is forbidden).
- `GET /api/v1/admin/widgets/schema` returns them all with `source: "plugin:{slug}"`, and
  `PUT layouts/{area}` accepts those same types; the frontend builds the settings modal with
  `SchemaForm` and falls back to raw JSON for an unknown type (not deletion).
- Theme packages follow the same contract: the active theme's `manifest` is read with the same
  `widgets` shape (`source: "theme:{slug}"`).

## 3. `page_types` — a new page type (block tabs)

```json
{
  "page_types": {
    "faq": {
      "title": "سوالات پرتکرار",
      "description": "صفحه FAQ پلاگین.",
      "default_blocks": [{ "type": "hero", "data": { "title": "سوالات پرتکرار" } }]
    }
  }
}
```

- The `admin/blocks` tabs come only from `GET layouts/page-types` (no hardcoding, not even
  `blog`), and only for types with `enabled !== false`; an override of each type is stored in
  `page_blocks:global:{type}`.
- `default_blocks` only accepts types from `config/blocks.php`.

## 4. `db` — plugin database tables

```json
{
  "db": {
    "tables": [
      { "name": "posts", "indexes": ["author_id", "slug"] },
      { "name": "comments" }
    ]
  }
}
```

The plugin declares which tables it owns, and the core verifies that declaration against the text
of the package's own migrations (`PluginMigrationRunner`). Without this declaration the core
would have to guess from the code text which tables are involved — and a wrong guess silently
writes into core tables. So declare it.

- **Write the name raw, without a prefix.** In the example above the plugin's `slug` is
  `my_plugin` and the created table is `my_plugin_posts`; the core adds the `{slug}_` prefix
  itself and rewrites it the same way in the migration.
- **If you write the prefix yourself you get an error** (`db.table_already_prefixed`).
  A table declared as `my_plugin_posts` becomes `my_plugin_my_plugin_posts`.
- `name` is required and must match `^[a-z][a-z0-9_]{0,29}$` (1 to 30 characters, start with a
  lowercase letter, then only `a-z`, `0-9` and underscore). A core table name is also rejected
  (`db.table_collides_core`) — anything becomes your own name after prefixing and does not touch
  the core.
- The final prefixed name must not be longer than **40 characters**
  (`db.table_name_too_long`).
- `indexes` is optional and its limit is **10 indexes per table** — not per plugin. A global
  limit would mean 10 indexes for 20 tables, i.e. a plugin with twenty tables could only index
  half of them.
- The overall limit is **20 tables per plugin** (`db.too_many_tables`).
- **A slug with a dot or hyphen cannot have tables at all** (`db.slug_not_table_safe`). The
  reason is simple: the final name becomes a SQL identifier and `^[a-z0-9_]+$` must fit it;
  unlike `slug`, which accepts dots and hyphens, a table name does not. Either fix the slug or
  have no tables.
- **Every table touched by a migration must be declared.** A reference to an undeclared table
  rejects the whole install **fail-closed** (`migration.undeclared_table`) — not a warning, not
  a partial install. This also includes `->constrained('users')` and `->index()->on('x')`,
  because the core counts those as table references too; so a `foreignId` constraint to a core
  table rejects the install. Because of this, your migration must never reference a core table.
- For the same reason, write raw names in the migration too: `Schema::create('posts', …)` is
  rewritten to `my_plugin_posts`. Any form the core cannot compute reliably (raw SQL,
  `DB::raw`, dynamic names, `DB::connection`) also rejects the install — an incomplete rewrite
  means one unprefixed table that silently writes into the core.
- **The whole `db` section is optional.** A plugin with no tables drops `db` entirely; `db`
  without `tables` is meaningless and errors (`db.no_tables`). An unknown key in `db` or in any
  `tables` item is rejected.

## 5. Authentication on plugin routes

> This section exists because **moving a stateful handler into a plugin** revealed it — and it
> was documented nowhere.

### The core catch-all has no `auth:sanctum`

Plugin routes come from a single catch-all:

```
/api/v1/p/{slug}/{any?}
```

This catch-all only calls `PluginRouter`. **There is no authentication middleware on it**,
because any plugin might want a public route (e.g. a service-status page that should not require
a token).

That means `$request->user()` **can be `null`**, and the decision is the plugin handler's.

### ⚠️ Why this is a silent trap

```php
// ❌ if the user is not logged in, this throws an ErrorException
$request->user()->id
```

`PluginRouter` catches the exception and returns a **generic 500** — with no hint. So instead of
"please log in" the user sees a meaningless error, and **the plugin author gets no message to fix
it**.

This is a real failure mode, not a hypothetical: a fixture handler written exactly like this
returned 500 where the test expected 401.

### The contract

Every handler that needs a user **must check it itself**:

```php
$user = $request->user();

if ($user === null) {
    return response()->json(['message' => 'وارد نشده‌اید.'], 401);
}
```

And `manifest.api.routes[].middleware` can also declare `auth:sanctum`. **Both layers are good:**

| Layer | What it gives | What it does not |
|---|---|---|
| `middleware` in the manifest | Declared protection, readable by a reviewer | Nothing if the author forgets |
| A check in the handler | Always works | Redundant if both are present |

### User-facing errors must come from the handler

`PluginRouter` passes these through and does not turn them into 500:

- `ValidationException` ⇒ **422** with the error list
- `HttpExceptionInterface` ⇒ that same code (403, 404, …)

So if you want your own message, `abort(403, '…')` is enough.

---

## 6. Shared rules

1. All hooks are optional; a manifest without them is valid.
2. Ed25519 signing and the review flow are still in place (item 6) — this contract only
   applies **after activation**.
3. A plugin's data is shared within a single-site install; `user_id` is only creator/audit
   metadata and is not filtered on read.
4. A plugin **can read and write** core tables — but it cannot declare them in `db.tables`.
   Working on core data from a plugin is a correct pattern, not a workaround.
5. **Plugin DDL** runs on a separate role and the new table names are registered in the core
   registry. The database guard rejects any unregistered name.

---

## Known differences (docs vs. code)

- **Permissions field name.** The live primary key is the top-level `access` (constant
  `PluginPackageContract::PERMISSIONS_FIELD = 'access'`); `permissions` is still read as a
  legacy fallback (`ManifestRegistry::manifestPermissions()`), so the examples above still work.
  New plugins should prefer `access`.
- **`hooks` is gone.** The `hooks` manifest field and its table/model were removed; it is
  neither registered nor dispatched. Do not put it in the manifest.
- **Settings moved.** `admin.settings_schema` under `panel.extensions` is deprecated; use the
  top-level `settings` key instead (`ManifestRegistry::pluginSettingsSchemas()`).
- **Other live top-level manifest keys** read by `ManifestRegistry` (in addition to the four
  sections above): `menu`, `pages` / `admin.pages`, `blocks`, `tools`, `docs`, and `footer`
  (theme footer defaults).
