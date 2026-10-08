import { serverOne } from "@/lib/server-api";
import { type PluginPackageContract } from "@/lib/plugin-contract";
import { Alert } from "@/components/ui/primitives";
import { DeveloperDocsClient } from "./DeveloperDocsClient";

/**
 * ECO3 — صفحهٔ «مستندات توسعه‌دهندگان».
 *
 * چرا در بک‌اند (server) خوانده می‌شود: `serverApi` توکن را از کوکی
 * httpOnly برمی‌دارد و کاربر واردشده را می‌شناسد، پس قرارداد در همان رندر
 * سرور می‌آید — بدون FOUC و بدون یک round-trip خالی از مرورگر. اگر پرمیشن
 * نداشته باشد یا خطا شود، به‌جای کرش یک پیام می‌دهیم.
 *
 * ⚠️ هیچ مقدارِ قراردادی اینجا hardcode نیست: تنها منبع،
 * `GET /v1/admin/plugins/contract` است (هم همان مسیری که راهنمای پلاگین‌ها
 * می‌خواند). پارسرِ کاملِ کمبودها در کلاینت اجرا می‌شود چون `serverApi`
 * فقط `data` را برمی‌گرداند و ما نمی‌خواهیم دو نسخهٔ اعتبارسنجی داشته باشیم.
 */
export default async function DeveloperDocsPage() {
  let contract: unknown = null;
  let error: string | null = null;
  try {
    contract = await serverOne<unknown>("/v1/admin/plugins/contract");
  } catch (e) {
    error = e instanceof Error ? e.message : "خطا در بارگذاری قرارداد توسعه‌دهندگان.";
  }

  return (
    <div className="dev-docs-page">
      {error ? (
        <Alert tone="red">{error}</Alert>
      ) : (
        <DeveloperDocsClient raw={contract as PluginPackageContract} />
      )}
    </div>
  );
}
