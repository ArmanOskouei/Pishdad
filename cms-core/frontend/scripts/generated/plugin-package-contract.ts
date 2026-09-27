/* eslint-disable */
/**
 * تولید خودکار — **دستی ویرایش نکن**.
 *
 * منبع حقیقت: `cms-core/backend/app/Services/Plugins/PluginPackageContract.php`
 * تولیدکننده:  `cms-core/frontend/scripts/gen-plugin-contract.mjs`
 * بازتولید:    `npm run gen:plugin-contract`
 * بررسی drift: `npm run gen:plugin-contract:check`  ← روی drift غیرصفر می‌دهد
 *
 * اثر انگشت قرارداد: `b666dbfc84d8198a`
 * (روی تغییر معنایی قرارداد عوض می‌شود؛ روی تغییر کامنت نه)
 */

export type PluginPointStatus = "live" | "declared_only" | "deferred";

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
}

export interface PluginBadExample {
  why?: string;
  decl: Record<string, unknown>;
}

export interface PluginExtensionPoint {
  key: string;
  label_fa: string;
  desc_fa: string;
  status: PluginPointStatus;
  openness: PluginPointOpenness;
  openness_why: string;
  since: string;
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
}

export const PLUGIN_PACKAGE_CONTRACT: PluginPackageContractData = {
  contractFingerprint: "b666dbfc84d8198a",
  source: "cms-core/backend/app/Services/Plugins/PluginPackageContract.php",
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
      label_fa: "مانیفست (الزامی — باید در ریشهٔ ZIP باشد)"
    },
    {
      path: "Laravel/src/",
      label_fa: "ریشهٔ PSR-4 — کلاس‌های پلاگین"
    },
    {
      path: "Laravel/routes/api.php",
      label_fa: "مسیرهای API پلاگین"
    },
    {
      path: "Laravel/database/migrations/",
      label_fa: "مهاجرت‌های دیتابیس (با پیشوند اجباری)"
    },
    {
      path: "Laravel/config/plugin.php",
      label_fa: "مقادیر پیش‌فرض تنظیمات"
    },
    {
      path: "Next.js/panel/",
      label_fa: "داده و دارایی رابط کاربری"
    },
    {
      path: "Next.js/blocks/",
      label_fa: "تعریف بلوک‌های سایت"
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
    "App\\Services\\Chabokan\\ChabokanClientInterface",
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
      desc_fa: "افزودن آیتم به منوی سمت راست پنل. هر پلاگینی معمولاً به این نیاز دارد.",
      status: "declared_only",
      openness: "closed",
      openness_why: "خروجی این نقطه دقیقاً MenuItem است با چهار فیلد ثابت؛ چیزی برای «باز کردن» وجود ندارد.",
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
      desc_fa: "افزودن کارت به صفحهٔ اصلی پنل.",
      status: "deferred",
      openness: "closed",
      openness_why: "نه primitive دارد نه مصرف‌کننده؛ نوشتن schema برایش دروغ ساختاریافته است.",
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
      desc_fa: "افزودن صفحهٔ تنظیمات که از روی JSON Schema رندر می‌شود (بدون کد فرانت).",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "SchemaForm آماده است و فقط یک map تخت را می‌چرخاند؛ پس نام فیلد قفل و ساختار بسته است.",
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
      example_ok: [
        {
          key: "general",
          title_fa: "تنظیمات عمومی",
          group: "عمومی"
        }
      ],
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
      desc_fa: "افزودن صفحهٔ فهرست/ویرایش برای دادهٔ اختصاصی پلاگین، از روی اعلان entity + schema.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "ستون DataTable امروز تابع می‌خواهد، پس تا اعلانی نشده این نقطه روی کاغذ است.",
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
      example_ok: [
        {
          entity: "blog_post",
          title_fa: "نوشته‌ها"
        }
      ],
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
      desc_fa: "افزودن بلوک به هدر صفحات عمومی سایت.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "نوع ویجت قفل است چون هسته رندرش می‌کند؛ تنظیماتش از block_type خودِ پلاگین می‌آید.",
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
      example_ok: [
        {
          type: "trust_bar",
          title_fa: "نوار اعتماد"
        }
      ],
      example_bad: [
        {
          why: "برخورد با نام هسته بی‌صدا رد می‌شود، پس باید خطا بدهد نه اینکه نصب شود و دیده نشود.",
          decl: {
            type: "nav",
            title_fa: "منوی من"
          }
        },
        {
          why: "نقطه خودش هدر است؛ `area` فیلد تکراری و فقط اختلاف‌ساز است.",
          decl: {
            type: "trust_bar",
            title_fa: "نوار اعتماد",
            area: "header"
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
      desc_fa: "افزودن بلوک به فوتر صفحات عمومی سایت.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "همان منطق هدر — نوع قفل، تنظیمات باز.",
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
      example_ok: [
        {
          type: "partners",
          title_fa: "همکاران"
        }
      ],
      example_bad: [
        {
          why: "برخورد با ویجت هسته نصب را سبز نشان می‌دهد ولی ویجت اصلاً رندر نمی‌شود.",
          decl: {
            type: "copyright",
            title_fa: "کپی‌رایت من"
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
      desc_fa: "تعریف نوع محتوایی جدید که صفحات عمومی سایت می‌توانند از آن استفاده کنند.",
      status: "live",
      openness: "open_vocabulary",
      openness_why: "خواستهٔ صریح کارفرما؛ داده در page.meta (ستون JSON آزاد) می‌نشیند و خودش رندر نمی‌شود.",
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
      desc_fa: "تعریف نوع بلوک جدید برای ویرایشگر صفحه. رندر از روی JSON Schema.",
      status: "declared_only",
      openness: "open_vocabulary",
      openness_why: "تنها جایی که «بی‌نهایت» واقعاً ارزش دارد؛ ولی گرامر بسته می‌ماند.",
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
      example_ok: [
        {
          type: "price_table",
          title_fa: "جدول قیمت"
        }
      ],
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
      desc_fa: "جایگزینی پیاده‌سازی یک interface هسته (درگاه پرداخت، چابکان، جستجو). فهرست interface‌ها بسته است و کلاس باید در ریشهٔ Pishdad\\Plugins\\ باشد. توجه: اینکه کلاس واقعاً آن رابط را پیاده می‌کند در لحظهٔ اعتبارسنجی بررسی نمی‌شود (کد افزونه هنوز بارگذاری نشده)؛ رجیستری آن را می‌سنجد و بی‌صدا نادیده نمی‌گیرد بلکه لاگ می‌کند.",
      status: "declared_only",
      openness: "schema_defined",
      openness_why: "فهرست interface‌های قابل‌جایگزینی بسته و کوچک است (سه مورد)، ولی نام کلاس پیاده‌سازی باز است تا افزونه بتواند کلاس خودش را بنویسد.",
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
      desc_fa: "یک کادر مستطیل مستقل در drawer ابزارهای هدر پنل. داده و schema، نه React.",
      status: "deferred",
      openness: "closed",
      openness_why: "خروجی یک کادر با عنوان و محتوای schema-driven است؛ هیچ آدرسی در آن نیست.",
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
          "no_href"
        ]
      },
      open_schema: false,
      example_ok: [],
      example_bad: [
        {
          why: "این نقطه deferred است — شکل نهایی بعد از ساخت drawer هدر تعریف می‌شود، ولی `href` در آن هیچ‌وقت مجاز نیست.",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            icon: "↻"
          }
        },
        {
          why: "`href` در این نقطه اصلاً وجود ندارد — اگر لازم شد `notification_id` بدهید که هسته به نشانی تبدیلش کند.",
          decl: {
            key: "sync",
            title_fa: "همگام‌سازی",
            href: "/admin/plugin/sync"
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
      desc_fa: "ثبت یک مسیر زیر /admin/ که از روی page descriptor رندر می‌شود.",
      status: "deferred",
      openness: "schema_defined",
      openness_why: "فقط مسیر + پرمیشن + layout؛ render از page descriptor می‌آید نه از پلاگین.",
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
  ]
};
