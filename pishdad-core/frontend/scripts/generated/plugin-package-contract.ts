/* eslint-disable */
/**
 * تولید خودکار — **دستی ویرایش نکن**.
 *
 * منبع حقیقت: `pishdad-core/backend/app/Services/Plugins/PluginPackageContract.php`
 * تولیدکننده:  `pishdad-core/frontend/scripts/gen-plugin-contract.mjs`
 * بازتولید:    `npm run gen:plugin-contract`
 * بررسی drift: `npm run gen:plugin-contract:check`  ← روی drift غیرصفر می‌دهد
 *
 * اثر انگشت قرارداد: `c538c27ebe1d094b`
 * (روی تغییر معنایی قرارداد عوض می‌شود؛ روی تغییر کامنت نه)
 */

export type PluginPointStatus = "live" | "declared_only" | "deferred" | "deprecated";

export type PluginPointOpenness = "closed" | "schema_defined" | "open_vocabulary";

export interface PluginFieldSpec {
  type: string;
  required: boolean;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  pattern?: string;
  is_path?: boolean;
  maxItems?: number;
  itemMaxLength?: number;
  itemPattern?: string;
}

export interface PluginAllowedPath {
  path: string;
  label_fa: string;
  label_en?: string;
}

export interface PluginErrorCodeGroup {
  label_fa: string;
  label_en: string;
  remedy_fa: string;
  remedy_en: string;
}

export interface PluginApiExample {
  id: string;
  title_fa: string;
  title_en: string;
  method: string;
  path: string;
  request: unknown;
  response: unknown;
  note_fa: string;
  note_en: string;
}

export interface PluginBadExample {
  why?: string;
  decl: Record<string, unknown>;
}

export interface PluginExtensionPoint {
  key: string;
  label_fa: string;
  label_en?: string;
  desc_fa: string;
  desc_en?: string;
  status: PluginPointStatus;
  openness: PluginPointOpenness;
  openness_why: string;
  openness_why_en?: string;
  since: string;
  /**
   * فقط برای نقطه‌های منسوخ: نسخه‌ای که منسوخ شدند، جایگزینشان چیست، و چرا.
   * نگهبان بالا اجازه نمی‌دهد حالت deprecated بدون replaced_by باشد.
   */
  deprecated?: { since: string; replaced_by: string; reason_fa: string };
  schema_version: number;
  max: { declarations: number; bytes: number; depth: number; properties: number };
  schema: { fields: Record<string, PluginFieldSpec>; cross: string[] };
  open_schema: boolean;
  example_ok: Record<string, unknown>[];
  example_bad: PluginBadExample[];
  forbidden: string[];
}

export interface PluginPackageContractData {
  contractFingerprint: string;
  source: string;
  package: {
    manifestName: string;
    backendRoot: string;
    frontendRoot: string;
    pluginNamespacePrefix: string;
  };
  limits: {
    maxZipBytes: number;
    maxFiles: number;
    maxUncompressedBytes: number;
    maxCompressionRatio: number;
  };
  allowedPaths: PluginAllowedPath[];
  forbidden: string[];
  reservedTypeNames: string[];
  overridableInterfaces: string[];
  grammar: {
    limits: Record<string, number>;
    types: string[];
  };
  deferredExtensionPoints: Record<string, string>;
  extensionPoints: PluginExtensionPoint[];
  errorCodeGroups: Record<string, PluginErrorCodeGroup>;
  errorCodes: Record<string, string>;
  apiExamples: PluginApiExample[];
}

export const PLUGIN_PACKAGE_CONTRACT: PluginPackageContractData = {
  contractFingerprint: "c538c27ebe1d094b",
  source: "pishdad-core/backend/app/Services/Plugins/PluginPackageContract.php",
  package: {
    manifestName: "manifest.json",
    backendRoot: "Laravel",
    frontendRoot: "Next.js",
    pluginNamespacePrefix: "Pishdad\\Plugins\\"
  },
  limits: {
    maxZipBytes: 20971520,
    maxFiles: 5000,
    maxUncompressedBytes: 209715200,
    maxCompressionRatio: 100
  },
  allowedPaths: [
    {
      path: "manifest.json",
      label_fa: "مانیفست (الزامی — باید در ریشهٔ ZIP باشد)",
      label_en: "Manifest (required — must be at the ZIP root)"
    },
    {
      path: "Laravel/src/",
      label_fa: "ریشهٔ PSR-4 — کلاس‌های پلاگین",
      label_en: "PSR-4 root — plugin classes"
    },
    {
      path: "Laravel/routes/api.php",
      label_fa: "مسیرهای API پلاگین",
      label_en: "Plugin API routes"
    },
    {
      path: "Laravel/database/migrations/",
      label_fa: "مهاجرت‌های دیتابیس (با پیشوند اجباری)",
      label_en: "Database migrations (mandatory prefix)"
    },
    {
      path: "Laravel/config/plugin.php",
      label_fa: "مقادیر پیش‌فرض تنظیمات",
      label_en: "Default settings values"
    },
    {
      path: "Next.js/panel/",
      label_fa: "داده و دارایی رابط کاربری",
      label_en: "UI data and assets"
    },
    {
      path: "Next.js/blocks/",
      label_fa: "تعریف بلوک‌های سایت",
      label_en: "Site block definitions"
    }
  ],
  forbidden: [
    "component",
    "render",
    "js",
    "import",
    "endpoint",
    "html",
    "style",
    "path",
    "template"
  ],
  reservedTypeNames: [
    "hero",
    "text",
    "image",
    "cta",
    "gallery",
    "quote",
    "video",
    "contact-form",
    "faq",
    "form",
    "logo",
    "nav",
    "search",
    "socials",
    "about",
    "links",
    "contact",
    "newsletter",
    "copyright"
  ],
  overridableInterfaces: [
    "App\\Services\\Billing\\PaymentGatewayInterface",
    "App\\Search\\SearchableProvider"
  ],
  grammar: {
    limits: {
      max_depth: 1,
      max_total_bytes: 65536,
      max_enum_members: 50,
      max_string_length: 4000
    },
    types: [
      "string",
      "integer",
      "number",
      "boolean",
      "enum",
      "media_id",
      "media_ids",
      "richtext",
      "string_list"
    ]
  },
  deferredExtensionPoints: {
    "admin.component": "کامپوننت React — در v1 رد شد (در مرورگر مدیر اجرا می‌شود ⇒ تصاحب پنل)"
  },
  extensionPoints: [
    {
      key: "admin.menu",
      label_fa: "منوی کناری پنل مدیریت",
      label_en: "Admin panel sidebar menu",
      desc_fa: "افزودن آیتم به منوی سمت راست پنل. هر پلاگینی معمولاً به این نیاز دارد.",
      desc_en: "Add an item to the panel sidebar menu. Almost every plugin needs this.",
      status: "declared_only",
      openness: "closed",
      openness_why: "خروجی این نقطه دقیقاً MenuItem است با چهار فیلد ثابت؛ چیزی برای «باز کردن» وجود ندارد.",
      openness_why_en: "This point outputs exactly a MenuItem with four fixed fields; there is nothing to open.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {
          key: {
            type: "string",
            required: true,
            maxLength: 40,
            pattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          },
          label: {
            type: "string",
            required: true,
            maxLength: 80
          },
          href: {
            type: "string",
            required: true,
            maxLength: 200,
            pattern: "^/admin(/[a-z0-9._-]+)*$",
            is_path: true
          },
          icon: {
            type: "string",
            required: false,
            maxLength: 16
          },
          order: {
            type: "integer",
            required: false,
            minimum: 0,
            maximum: 999
          },
          permission: {
            type: "string",
            required: false,
            maxLength: 120
          }
        },
        cross: [
          "permission_prefix"
        ]
      },
      open_schema: false,
      example_ok: [
        {
          key: "blog",
          label: "نوشته‌ها",
          href: "/admin/blog",
          icon: "✎",
          order: 10,
          permission: "plugin:blog:posts.view"
        }
      ],
      example_bad: [
        {
          why: "href بیرونی یا javascript: هم باز-redirect است هم XSS — یک regex تنها هر دو را می‌کُشد.",
          decl: {
            key: "blog",
            label: "نوشته‌ها",
            href: "javascript:alert(1)"
          }
        },
        {
          why: "این نقطه شکل MenuItem دارد: href/label/icon/key. نام فیلدهای دیگر یاد دادن خطای کاربر است، نه محدود کردن او.",
          decl: {
            title: "نوشته‌ها",
            path: "/admin/blog",
            icon: "pencil",
            permission: "posts.view"
          }
        },
        {
          why: "اینجا prefix لازم است چون نام پرمیشن **کامل** در مانیفست نوشته می‌شود. (در `permissions[].module` برعکس است: فقط بخش آخر را بنویسید، چون هسته prefix اسلاگ را خودش اضافه می‌کند.)",
          decl: {
            key: "blog",
            label: "نوشته‌ها",
            href: "/admin/blog",
            permission: "blog.view"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "admin.dashboard_slot",
      label_fa: "ویجت داشبورد مدیریت",
      label_en: "Admin dashboard widget",
      desc_fa: "افزودن کارت به صفحهٔ اصلی پنل.",
      desc_en: "Add a card to the admin dashboard home page.",
      status: "deferred",
      openness: "closed",
      openness_why: "نه primitive دارد نه مصرف‌کننده؛ نوشتن schema برایش دروغ ساختاریافته است.",
      openness_why_en: "It has neither a primitive nor a consumer; writing a schema for it would be a structured lie.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {},
        cross: []
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "این نقطه deferred است — اعلامش در مانیفست خطاست تا وقتی renderer واقعی ساخته شود.",
          decl: {
            key: "sales_summary",
            title_fa: "خلاصهٔ فروش"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "admin.settings_schema",
      label_fa: "فرم تنظیمات اختصاصی",
      label_en: "Custom settings form",
      desc_fa: "افزودن صفحهٔ تنظیمات که از روی JSON Schema رندر می‌شود (بدون کد فرنت).",
      desc_en: "Add a settings page rendered from JSON Schema (no frontend code).",
      status: "deprecated",
      openness: "schema_defined",
      openness_why: "حذف‌شده: به کلید سطح‌بالای `settings` در مانیفست منتقل شد (`ManifestRegistry::pluginSettingsSchemas()`)، چون میکرو-اسکیمای اینجا با `max_depth => 1` اسکیمای JSON تودرتو را حمل نمی‌کند.",
      openness_why_en: "Removed: moved to the top-level `settings` manifest key (`ManifestRegistry::pluginSettingsSchemas()`), because the micro-schema here cannot carry a nested JSON schema with `max_depth => 1`.",
      since: "1.2.0",
      deprecated: {
        since: "1.6.0",
        replaced_by: "manifest.settings",
        reason_fa: "اعلان از راه `panel.extensions` برای اسکیمای تودرتو ناممکن بود. از `1.6.0` به‌جای این نقطه، کلید سطح‌بالای `settings` را در مانیفست بنویسید."
      },
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {},
        cross: [
          "no_core_name",
          "title_fa"
        ]
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "هر چیزی که به کاربر نشان داده می‌شود `title_fa` لازم دارد، وگرنه UI متن خالی می‌خواند.",
          decl: {
            key: "general",
            group: "عمومی"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "admin.data_collection",
      label_fa: "جدول دادهٔ اختصاصی",
      label_en: "Custom data table",
      desc_fa: "افزودن صفحهٔ فهرست/ویرایش برای دادهٔ اختصاصی پلاگین، از روی اعلان entity + schema.",
      desc_en: "Add a list/edit page for plugin-specific data from an entity + schema declaration.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "ستون DataTable امروز تابع می‌خواهد، پس تا اعلانی نشده این نقطه روی کاغذ است.",
      openness_why_en: "Today DataTable columns need a function, so until declared this point exists only on paper.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {},
        cross: [
          "entity_prefix",
          "title_fa"
        ]
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "نام جدول باید دقیقاً {manifest.slug}_{x} باشد، وگرنه نقض K1.5.3 می‌شود.",
          decl: {
            entity: "post",
            title_fa: "نوشته‌ها"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "site.header_widget",
      label_fa: "ویجت هدر سایت",
      label_en: "Site header widget",
      desc_fa: "افزودن بلوک به هدر صفحات عمومی سایت.",
      desc_en: "Add a block to the public site header.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "نوع ویجت قفل است چون هسته رندرش می‌کند؛ تنظیماتش از block_type خودِ پلاگین می‌آید.",
      openness_why_en: "The widget type is locked because the core renders it; its settings come from the plugin's own block_type.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {
          type: {
            type: "string",
            required: true,
            maxLength: 40,
            pattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          },
          title: {
            type: "string",
            required: false,
            maxLength: 80
          },
          description: {
            type: "string",
            required: false,
            maxLength: 200
          }
        },
        cross: [
          "no_core_name"
        ]
      },
      open_schema: false,
      example_ok: [
        {
          type: "trust_bar",
          title: "نوار اعتماد",
          description: "نمادهای اعتماد و شمار تماس"
        }
      ],
      example_bad: [
        {
          why: "برخورد با نام هسته بی‌صدا رد می‌شود، پس باید خطا بدهد نه اینکه نصب شود و دیده نشود.",
          decl: {
            type: "nav",
            title: "منوی من"
          }
        },
        {
          why: "نقطه خودش هدر است؛ `area` فیلد تکراری و فقط اختلاف‌ساز است.",
          decl: {
            type: "trust_bar",
            title: "نوار اعتماد",
            area: "header"
          }
        },
        {
          why: "⛔ `schema` آبجکت JSON تودرتو است و micro-grammar چنین چیزی ندارد؛ برای فرمِ واقعی از کانال سطح‌بالای `manifest.widgets.header.<type>.schema` استفاده کنید. اگر این‌جا پذیرفته می‌شد، بسته سبز می‌شد ولی `SchemaForm` هیچ inputی برایش نمی‌ساخت.",
          decl: {
            type: "shop_cart",
            title: "سبد خرید",
            schema: {
              type: "object",
              properties: {
                coupon: {
                  type: "string"
                }
              }
            }
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "site.footer_widget",
      label_fa: "ویجت فوتر سایت",
      label_en: "Site footer widget",
      desc_fa: "افزودن بلوک به فوتر صفحات عمومی سایت.",
      desc_en: "Add a block to the public site footer.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "همان منطق هدر — نوع قفل، تنظیمات باز.",
      openness_why_en: "Same logic as the header — locked type, open settings.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {
          type: {
            type: "string",
            required: true,
            maxLength: 40,
            pattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          },
          title: {
            type: "string",
            required: false,
            maxLength: 80
          },
          description: {
            type: "string",
            required: false,
            maxLength: 200
          }
        },
        cross: [
          "no_core_name"
        ]
      },
      open_schema: false,
      example_ok: [
        {
          type: "shop_news",
          title: "تازه‌های فروشگاه",
          description: "چهار خبر آخر"
        }
      ],
      example_bad: [
        {
          why: "برخورد با ویجت هسته نصب را سبز نشان می‌دهد ولی ویجت اصلاً رندر نمی‌شود.",
          decl: {
            type: "copyright",
            title: "کپی‌رایت من"
          }
        },
        {
          why: "ناحیه از خودِ نقطه خوانده می‌شود؛ `area` فقط اختلاف‌ساز است و رجیستری آن را نادیده می‌گیرد.",
          decl: {
            type: "shop_news",
            title: "تازه‌های فروشگاه",
            area: "footer"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "site.page_type",
      label_fa: "نوع صفحهٔ سایت",
      label_en: "Site page type",
      desc_fa: "تعریف نوع محتوایی جدید که صفحات عمومی سایت می‌توانند از آن استفاده کنند.",
      desc_en: "Define a new content type that public site pages can use.",
      status: "live",
      openness: "open_vocabulary",
      openness_why: "خواستهٔ صریح کارفرما؛ داده در page.meta (ستون JSON آزاد) می‌نشیند و خودش رندر نمی‌شود.",
      openness_why_en: "Explicit owner request; data lives in page.meta (a free JSON column) and is not rendered by itself.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {
          slug: {
            type: "string",
            required: true,
            maxLength: 40,
            pattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          },
          title_fa: {
            type: "string",
            required: true,
            maxLength: 120
          },
          desc_fa: {
            type: "string",
            required: false,
            maxLength: 400
          },
          default_blocks: {
            type: "string_list",
            required: false,
            maxItems: 20,
            itemMaxLength: 40,
            itemPattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          }
        },
        cross: [
          "title_fa"
        ]
      },
      open_schema: true,
      example_ok: [
        {
          slug: "product",
          title_fa: "محصول",
          desc_fa: "صفحهٔ تک‌محصول",
          default_blocks: [
            "hero",
            "text",
            "cta"
          ],
          price_label: "قیمت",
          badge: "تخفیف"
        }
      ],
      example_bad: [
        {
          why: "گرامر micro-schema آبجکت تودرتو ندارد؛ SchemaForm هم فرمی برایش نمی‌سازد و نویسنده یک input متنی می‌بیند.",
          decl: {
            slug: "product",
            title_fa: "محصول",
            specs: {
              weight: 10
            }
          }
        },
        {
          why: "markup آزاد ممنوع است — HTML خام در همان سطح خطر JS است.",
          decl: {
            slug: "product",
            title_fa: "<b>محصول</b>"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "site.block_type",
      label_fa: "نوع بلوک صفحه",
      label_en: "Page block type",
      desc_fa: "تعریف نوع بلوک جدید برای ویرایشگر صفحه. رندر از روی JSON Schema.",
      desc_en: "Define a new block type for the page editor. Rendering is driven by JSON Schema.",
      status: "declared_only",
      openness: "open_vocabulary",
      openness_why: "تنها جایی که «بی‌نهایت» واقعاً ارزش دارد؛ ولی گرامر بسته می‌ماند.",
      openness_why_en: "The only place where \"infinite\" is truly worth it; but the grammar stays closed.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {},
        cross: [
          "no_core_name",
          "title_fa"
        ]
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "BlockRenderer برخورد `type` را بی‌صدا `continue` می‌کند؛ باید خطا بدهد.",
          decl: {
            type: "hero",
            title_fa: "هیروی من"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "core.service_provider",
      label_fa: "پیاده‌سازی رابط هسته",
      label_en: "Core interface implementation",
      desc_fa: "جایگزینی پیاده‌سازی یک interface هسته (درگاه پرداخت، چابکان، جستجو). فهرست interface‌ها بسته است و کلاس باید در ریشهٔ Pishdad\\Plugins\\ باشد. توجه: اینکه کلاس واقعاً آن رابط را پیاده می‌کند در لحظهٔ اعتبارسنجی بررسی نمی‌شود (کد افزونه هنوز بارگذاری نشده)؛ رجیستری آن را می‌سنجد و بی‌صدا نادیده نمی‌گیرد بلکه لاگ می‌کند.",
      desc_en: "Replace the implementation of a core interface (payment gateway, hosting, search). The interface list is closed and the class must live under the Pishdad\\Plugins\\ root. Note: whether the class actually implements that interface is not checked at validation time (the plugin code is not loaded yet); the registry checks it and logs instead of silently ignoring it.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "فهرست interface‌های قابل‌جایگزینی بسته و کوچک است (سه مورد)، ولی نام کلاس پیاده‌سازی باز است تا افزونه بتواند کلاس خودش را بنویسد.",
      openness_why_en: "The list of overridable interfaces is closed and small (three), but the implementation class name is open so the plugin can write its own class.",
      since: "1.5.0",
      schema_version: 1,
      max: {
        declarations: 3,
        bytes: 1024,
        depth: 1,
        properties: 4
      },
      schema: {
        fields: {
          interface: {
            type: "string",
            required: true,
            maxLength: 200
          },
          class: {
            type: "string",
            required: true,
            maxLength: 200
          }
        },
        cross: [
          "title_fa"
        ]
      },
      open_schema: false,
      example_ok: [
        {
          interface: "App\\Services\\Billing\\PaymentGatewayInterface",
          class: "Pishdad\\Plugins\\Zarinpal\\Gateway"
        }
      ],
      example_bad: [
        {
          why: "کلاس باید داخل ریشهٔ اجباری افزونه باشد. کلاسی که در ریشهٔ `App\\` بنشیند مال هسته است و رجیستری آن را رد می‌کند.",
          decl: {
            interface: "App\\Services\\Billing\\PaymentGatewayInterface",
            class: "App\\Services\\Billing\\ZarinpalStubDriver"
          }
        },
        {
          why: "فقط سه interface قابل جایگزینی وجود دارد. هر چیز دیگری — از جمله کلاس‌های داخلی لاراول — قابل override نیست.",
          decl: {
            interface: "Illuminate\\Contracts\\Container\\Container",
            class: "Pishdad\\Plugins\\Evil\\Container"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "admin.plugin_tools",
      label_fa: "ابزارهای پلاگین در هدر پنل",
      label_en: "Plugin tools in the panel header",
      desc_fa: "یک کادر مستطیل مستقل در drawer ابزارهای هدر پنل. داده و schema، نه React.",
      desc_en: "An independent rectangular card in the panel header tools drawer. Data and schema, not React.",
      status: "declared_only",
      openness: "closed",
      openness_why: "خروجی یک کادر با عنوان و محتوای schema-driven است؛ هیچ آدرسی در آن نیست. فهرست فیلدها بسته است چون هر فیلد تازه باید اول در رندرر مصرف شود — غیر از آن، نقطه دوباره «قول بی‌پشتوانه» می‌شود.",
      openness_why_en: "The output is a card with a title and schema-driven content; there is no address in it. The field list is closed because every new field must first be consumed by the renderer — otherwise the point becomes an unbacked promise again.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 2048,
        depth: 1,
        properties: 8
      },
      schema: {
        fields: {
          key: {
            type: "string",
            required: true,
            maxLength: 40,
            pattern: "^[a-z0-9][a-z0-9._-]{0,39}$"
          },
          title_fa: {
            type: "string",
            required: true,
            maxLength: 80
          },
          icon: {
            type: "string",
            required: false,
            maxLength: 16
          },
          description: {
            type: "string",
            required: false,
            maxLength: 200
          },
          notification_id: {
            type: "integer",
            required: false,
            minimum: 1,
            maximum: 2147483647
          },
          permission: {
            type: "string",
            required: false,
            maxLength: 120
          }
        },
        cross: [
          "no_href",
          "title_fa",
          "permission_prefix"
        ]
      },
      open_schema: false,
      example_ok: [
        {
          key: "sync",
          title_fa: "همگام‌سازی",
          icon: "↻",
          description: "کشورها و موجودی را با مرکز هم‌گام کن",
          notification_id: 12,
          permission: "plugin:shop:sync.run"
        }
      ],
      example_bad: [
        {
          why: "این نقطه آدرس ندارد. اگر لینک لازم است `notification_id` بدهید تا هسته خودش از راه `pageRegistry()` نشانی بسازد — وگرنه افزونه می‌تواند به هر مسیری لینک بدهد، از جمله مسیری که خودش ثبت نکرده است.",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            href: "/admin/plugin/sync"
          }
        },
        {
          why: "⛔ **`order` و `body` وجود ندارند.** نه در رجیستری خوانده می‌شوند و نه در drawer. نوشتنشان یعنی بسته سبز می‌شود و هیچ اثری ندارد — همان «نصب شد ولی دیده نشد». ترتیب از merge رجیستری می‌آید و متن کارت از `description`.",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            order: 10,
            body: {
              text: "سلام"
            }
          }
        },
        {
          why: "بدون `title_fa` رجیستری سطر را بی‌صدا حذف می‌کند (`normalizePluginTool`)، پس باید خطا بدهد نه اینکه نصب موفق باشد و کارت غیب بماند.",
          decl: {
            key: "sync",
            icon: "↻"
          }
        },
        {
          why: "`notification_id` در URL می‌رود، پس باید عدد صحیح باشد. رشتهٔ `\"12\"` در رجیستری `null` می‌شود و ابزار بی‌مقصد نمایش داده می‌شود — «کار کرد ولی جایی نمی‌رسد».",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            notification_id: "12"
          }
        },
        {
          why: "پرمیشن باید کامل نوشته شود، یعنی با `plugin:{اسلاگ}:` شروع شود. (در `permissions[].module` برعکس است: فقط بخش آخر را بنویسید، چون هسته prefix اسلاگ را خودش اضافه می‌کند.)",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            permission: "sync.run"
          }
        },
        {
          why: "کلید drawer در جدول و در React key می‌نشیند، پس باید الگوی کلیدِ رایج پروژه را داشته باشد. حرف بزرگ و فاصله در نامِ فایل/کلید CSS می‌شکند.",
          decl: {
            key: "Sync Jobs",
            title_fa: "همگام‌سازی"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    },
    {
      key: "admin.page_registry",
      label_fa: "صفحهٔ اختصاصی پلاگین",
      label_en: "Plugin-specific page",
      desc_fa: "ثبت یک مسیر زیر /admin/ که از روی page descriptor رندر می‌شود.",
      desc_en: "Register a path under /admin/ that renders from a page descriptor.",
      status: "deferred",
      openness: "schema_defined",
      openness_why: "فقط مسیر + پرمیشن + layout؛ render از page descriptor می‌آید نه از پلاگین.",
      openness_why_en: "Path + permission + layout only; rendering comes from the page descriptor, not the plugin.",
      since: "1.2.0",
      schema_version: 1,
      max: {
        declarations: 10,
        bytes: 4096,
        depth: 1,
        properties: 30
      },
      schema: {
        fields: {},
        cross: [
          "no_href",
          "permission_prefix",
          "title_fa"
        ]
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "این نقطه deferred است تا تکلیف رفتارش با next/dynamic روشن شود.",
          decl: {
            title_fa: "سفارش‌ها",
            permission: "plugin:shop:orders.view",
            layout: "default"
          }
        }
      ],
      forbidden: [
        "component",
        "render",
        "js",
        "import",
        "endpoint",
        "html",
        "style",
        "path",
        "template"
      ]
    }
  ],
  errorCodeGroups: {
    zip: {
      label_fa: "بسته و فشرده‌سازی",
      label_en: "Archive & compression",
      remedy_fa: "ZIP استاندارد بسازید، سقف حجم/تعداد فایل را رعایت کنید و از آرشیو دوبارهٔ فایل‌های تکراری بپرهیزید.",
      remedy_en: "Build a standard ZIP, respect the size/file-count limits, and avoid re-archiving duplicate entries."
    },
    path: {
      label_fa: "مسیرهای داخل بسته",
      label_en: "Package paths",
      remedy_fa: "فقط مقصدهای فهرست‌شده را بگذارید؛ نام مجاز، بدون symlink و بدون «..» و بدون تکرارِ حساس‌به‌حروف.",
      remedy_en: "Keep only the listed destinations; safe names, no symlinks, no \"..\", and no case-insensitive duplicates."
    },
    manifest: {
      label_fa: "مانیفست و امضا",
      label_en: "Manifest & signature",
      remedy_fa: "manifest.json را دقیقاً در ریشهٔ ZIP بگذارید و فیلدهای اجباری و امضای معتبر را کامل کنید.",
      remedy_en: "Place manifest.json exactly at the ZIP root and complete the required fields and a valid signature."
    },
    signature: {
      label_fa: "امضای Ed25519",
      label_en: "Ed25519 signature",
      remedy_fa: "امضای detached Ed25519 مانیفست (بدون خودِ فیلد signature) را با کلید ثبت‌شده بازتولید کنید.",
      remedy_en: "Re-create the detached Ed25519 signature of the manifest (without the signature field itself) using the registered key."
    },
    package: {
      label_fa: "اندازهٔ بسته",
      label_en: "Package size",
      remedy_fa: "فایل‌های غیرضروری را حذف یا فشرده کنید.",
      remedy_en: "Remove or compress unnecessary files."
    },
    panel: {
      label_fa: "نقاط اتصال پنل",
      label_en: "Panel extension points",
      remedy_fa: "نقطهٔ اتصال را از فهرست مجاز انتخاب کنید و اعلان را با شکل درست بنویسید.",
      remedy_en: "Pick an extension point from the allowed list and write the declaration in the correct shape."
    },
    pages: {
      label_fa: "اعلان صفحه",
      label_en: "Page declarations",
      remedy_fa: "صفحه را با path/title/permission معتبر و layout مجاز اعلام کنید.",
      remedy_en: "Declare the page with a valid path/title/permission and an allowed layout."
    },
    blocks: {
      label_fa: "اعلان بلوک",
      label_en: "Block declarations",
      remedy_fa: "بلوک را با type مجاز و data معتبر اعلام کنید.",
      remedy_en: "Declare the block with an allowed type and valid data."
    },
    db: {
      label_fa: "جدول‌های دیتابیس",
      label_en: "Database tables",
      remedy_fa: "نام جدول را خام و بدون پیشوند بدهید؛ پیشوند slug_ را خودتان ننویسید و سقف‌ها را رعایت کنید.",
      remedy_en: "Give raw unprefixed table names; do not write the slug_ prefix yourself and respect the limits."
    },
    requires: {
      label_fa: "شرط نسخهٔ هسته",
      label_en: "Core version requirement",
      remedy_fa: "شرط requires.core را در شکل پذیرفته‌شده بنویسید و با نسخهٔ نصب هم‌خوان کنید.",
      remedy_en: "Write requires.core in an accepted form and match it to the installed core version."
    },
    core: {
      label_fa: "نسخهٔ هسته",
      label_en: "Core version",
      remedy_fa: "نسخهٔ هسته را به‌روز کنید یا requires.core را تنظیم کنید.",
      remedy_en: "Update the core version or adjust requires.core."
    },
    core_contract: {
      label_fa: "سازگاری با قرارداد هسته",
      label_en: "Core contract compatibility",
      remedy_fa: "فیلدهای since و schema_version را نسبت به core_contract_version همین نصب درست بدهید.",
      remedy_en: "Set since and schema_version correctly against this install's core_contract_version."
    },
    install: {
      label_fa: "نصب روی سرور",
      label_en: "Server-side installation",
      remedy_fa: "بسته را روی سرور بررسی کنید: دسترسی مسیر، فضای ذخیره، و درستی امضا/هش.",
      remedy_en: "Inspect the package on the server: path permissions, storage space, and signature/hash integrity."
    },
    devmode: {
      label_fa: "حالت توسعه‌دهنده",
      label_en: "Developer mode",
      remedy_fa: "در حالت توسعه‌دهنده امضای گم‌شده فقط هشدار است؛ پیش از انتشار امضا را اضافه کنید.",
      remedy_en: "In developer mode a missing signature is only a warning; add the signature before release."
    }
  },
  errorCodes: {
    "zip.unreadable": "zip",
    "zip.too_large": "zip",
    "zip.too_many_files": "zip",
    "zip.bomb_size": "zip",
    "zip.bomb_ratio": "zip",
    "path.unsafe": "path",
    "path.symlink": "path",
    "path.special_entry": "path",
    "path.duplicate": "path",
    "path.case_collision": "path",
    "path.not_allowed": "path",
    "manifest.missing": "manifest",
    "manifest.misplaced": "manifest",
    "manifest.missing_field": "manifest",
    "manifest.bad_slug": "manifest",
    "manifest.system_forbidden": "manifest",
    "manifest.leaks_key": "manifest",
    "signature.invalid": "signature",
    "signature.anonymous_publisher": "signature",
    "package.large": "package",
    "panel.unknown_point": "panel",
    "panel.bad_point": "panel",
    "panel.deferred_point": "panel",
    "pages.not_object": "pages",
    "pages.entry_not_object": "pages",
    "pages.unknown_key": "pages",
    "pages.bad_key": "pages",
    "pages.no_title": "pages",
    "pages.title_too_long": "pages",
    "pages.bad_path": "pages",
    "pages.path_not_allowed": "pages",
    "pages.path_too_long": "pages",
    "pages.bad_permission": "pages",
    "pages.permission_charset": "pages",
    "pages.permission_too_long": "pages",
    "pages.layout_not_allowed": "pages",
    "pages.duplicate_path": "pages",
    "pages.too_many": "pages",
    "blocks.not_list": "blocks",
    "blocks.entry_not_object": "blocks",
    "blocks.no_type": "blocks",
    "blocks.type_not_allowed": "blocks",
    "blocks.data_not_object": "blocks",
    "blocks.unknown_key": "blocks",
    "blocks.no_core_types": "blocks",
    "blocks.too_many": "blocks",
    "db.not_object": "db",
    "db.unknown_key": "db",
    "db.no_tables": "db",
    "db.tables_not_list": "db",
    "db.too_many_tables": "db",
    "db.slug_not_table_safe": "db",
    "db.table_not_object": "db",
    "db.table_unknown_key": "db",
    "db.table_no_name": "db",
    "db.table_bad_name": "db",
    "db.table_already_prefixed": "db",
    "db.table_collides_core": "db",
    "db.table_duplicate": "db",
    "db.table_name_too_long": "db",
    "db.prefixed_collides_core": "db",
    "db.indexes_not_list": "db",
    "db.too_many_indexes": "db",
    "db.index_bad_name": "db",
    "requires.invalid": "requires",
    "requires.core.invalid": "requires",
    "requires.core.unsatisfied": "requires",
    "core.version_invalid": "core",
    "core_contract.bad_since": "core_contract",
    "core_contract.bad_schema_version": "core_contract",
    "core_contract.schema_too_new": "core_contract",
    "core_contract.too_new": "core_contract",
    "core_contract.point_newer_than_core": "core_contract",
    "install.zip_unreadable": "install",
    "install.manifest_missing": "install",
    "install.manifest_invalid": "install",
    "install.manifest_mismatch": "install",
    "install.too_large": "install",
    "install.too_many_files": "install",
    "install.too_many_entries": "install",
    "install.compression_bomb": "install",
    "install.path_traversal": "install",
    "install.escaped_root": "install",
    "install.symlink_entry": "install",
    "install.special_entry": "install",
    "install.duplicate_entry": "install",
    "install.case_collision": "install",
    "install.write_failed": "install",
    "install.incomplete": "install",
    "install.target_occupied": "install",
    "install.active_release_corrupt": "install",
    "install.locked": "install",
    "install.hash_mismatch": "install",
    "install.storage_unavailable": "install",
    "install.rename_failed": "install",
    "install.failed": "install",
    "devmode.unsigned_allowed": "devmode"
  },
  apiExamples: [
    {
      id: "validate-package",
      title_fa: "اعتبارسنجی بسته پیش از نصب",
      title_en: "Validate a package before install",
      method: "POST",
      path: "/v1/admin/plugins/validate",
      request: {
        "Content-Type": "multipart/form-data",
        file: "my-plugin.zip (binary)"
      },
      response: {
        analysis: {
          ok: false,
          errors: [
            {
              severity: "error",
              code: "zip.too_large",
              message: "Package exceeds the size limit."
            }
          ],
          warnings: [
            {
              severity: "warning",
              code: "package.large",
              message: "Package is close to the size limit."
            }
          ]
        }
      },
      note_fa: "این endpoint هرگز ۴xx نمی‌دهد؛ همیشه ۲۰۰ برمی‌گرداند تا حتی بستهٔ خراب هم قابل نمایش باشد.",
      note_en: "This endpoint never returns 4xx; it always returns 200 so even a broken package is displayable."
    },
    {
      id: "activate-plugin",
      title_fa: "فعال‌سازی پلاگین",
      title_en: "Activate a plugin",
      method: "POST",
      path: "/v1/admin/plugins/{slug}/activate",
      request: [],
      response: {
        data: {
          slug: "my-plugin",
          active: true,
          review_status: "approved"
        },
        warning: null
      },
      note_fa: "پیش از فعال‌سازی باید بسته با امضای معتبر نصب شده باشد؛ فعال‌سازی خارج از حالت توسعه‌دهنده بدون امضا رد می‌شود.",
      note_en: "The package must already be installed with a valid signature; outside developer mode activation without a signature is rejected."
    },
    {
      id: "read-contract",
      title_fa: "خواندن قرارداد هسته",
      title_en: "Read the core contract",
      method: "GET",
      path: "/v1/admin/plugins/contract",
      request: [],
      response: {
        data: {
          core_contract_version: "1.6.0",
          extension_points: [
            "…"
          ],
          error_codes: {
            "zip.unreadable": "zip"
          },
          allowed_paths: [
            "manifest.json"
          ]
        }
      },
      note_fa: "همین endpoint منبعِ حقیقتِ همین صفحه است؛ هیچ مقدار اینجا دستی کپی نشده.",
      note_en: "This is the source of truth for this very page; no value here is hand-copied."
    },
    {
      id: "plugin-route",
      title_fa: "مصرف API افزونه (از مانیفست خودش)",
      title_en: "Consume a plugin API (from its own manifest)",
      method: "GET",
      path: "/v1/p/{slug}/{path}",
      request: {
        Authorization: "Bearer <manager-token>",
        Accept: "application/json"
      },
      response: {
        data: [
          "…"
        ]
      },
      note_fa: "هر افزونه می‌تواند مستندات API خودش را در فیلد «docs» مانیفست اعلام کند؛ همان‌ها پایین همین صفحه ادغام می‌شوند.",
      note_en: "Each plugin can declare its own API docs in the manifest \"docs\" field; those are merged below on this page."
    }
  ]
};
