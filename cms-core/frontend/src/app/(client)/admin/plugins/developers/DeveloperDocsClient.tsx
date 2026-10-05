"use client";

import { useMemo } from "react";

import { useLang, type MessageKey } from "@/lib/i18n";
import {
  parsePluginContract,
  type PluginApiExample,
  type PluginDeveloperDocs,
  type PluginExtensionPoint,
  type PluginPackageContract,
} from "@/lib/plugin-contract";
import { pick, prettyJson } from "@/lib/developer-docs";

/**
 * ECO3 — UI مستندات توسعه‌دهندگان.
 *
 * همه‌چیز از قرارداد زنده می‌آید (نقاط اتصال، شِماها، مسیرهای ZIP، کدهای
 * خطا و نمونه‌ها). زبان از `useLang` می‌آید و همان مکانیزم پنل است.
 * `parsePluginContract` بدنه را اعتبارسنجی می‌کند و اگر ناقص بود **فهرست
 * کمبودها** را نشان می‌دهیم، نه یک صفحهٔ سبزِ ساکت.
 */

const pre: React.CSSProperties = {
  background: "var(--surface-2)",
  border: "1px solid var(--border)",
  borderRadius: 8,
  padding: 12,
  overflowX: "auto",
  fontSize: 12,
  direction: "ltr",
  textAlign: "left",
};

const code: React.CSSProperties = {
  background: "var(--surface-2)",
  border: "1px solid var(--border)",
  borderRadius: 4,
  padding: "1px 5px",
  fontSize: 12,
  direction: "ltr",
  display: "inline-block",
};

const STATUS_KEY = {
  live: "devdocs.statusLive",
  declared_only: "devdocs.statusDeclared",
  deferred: "devdocs.statusDeferred",
  deprecated: "devdocs.statusDeprecated",
} as const satisfies Record<PluginExtensionPoint["status"], MessageKey>;

type T = (key: MessageKey) => string;

export function DeveloperDocsClient({ raw }: { raw: PluginPackageContract }) {
  const { lang, t } = useLang();
  const parsed = useMemo(() => parsePluginContract(raw), [raw]);

  if (!parsed.ok) {
    // قراردادِ ناقص = مستنداتِ ناقص. عمداً پنهان نمی‌شود.
    return (
      <div className="dev-docs-incomplete" role="alert">
        <h1>{t("devdocs.title")}</h1>
        <p>{t("devdocs.loadError")}</p>
        <p className="muted">
          missing: <code>{parsed.missing.join(" · ")}</code>
        </p>
      </div>
    );
  }

  return <DeveloperDocsBody contract={parsed.value} lang={lang} t={t} />;
}

function DeveloperDocsBody({
  contract,
  lang,
  t,
}: {
  contract: PluginPackageContract;
  lang: "fa" | "en";
  t: T;
}) {
  return (
    <div style={{ display: "grid", gap: 20 }}>
      <div className="page-head">
        <div>
          <h1>{t("devdocs.title")}</h1>
          <p>{t("devdocs.subtitle")}</p>
        </div>
        <code style={code}>{contract.package.manifest_name}</code>
      </div>

      <p className="muted" style={{ fontSize: 13, marginBlock: 0 }}>
        {t("devdocs.contractVersion")}: <code style={code}>{contract.core_contract_version}</code>
      </p>

      <AllowedPaths contract={contract} lang={lang} t={t} />
      <ExtensionPoints points={contract.extension_points} lang={lang} t={t} />
      {contract.api_examples.length > 0 && (
        <ApiExamples examples={contract.api_examples} lang={lang} t={t} />
      )}
      <ErrorCatalog contract={contract} lang={lang} t={t} />
      <PluginDocs docs={contract.plugin_docs} lang={lang} t={t} />
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="card card-pad">
      <h2 style={{ fontSize: 15, marginBlockEnd: 10 }}>{title}</h2>
      {children}
    </section>
  );
}

function AllowedPaths({
  contract,
  lang,
  t,
}: {
  contract: PluginPackageContract;
  lang: "fa" | "en";
  t: T;
}) {
  return (
    <Section title={t("devdocs.allowedPaths")}>
      <ul style={{ paddingInlineStart: 18, display: "grid", gap: 4 }}>
        {contract.allowed_paths.map((p) => (
          <li key={p.path}>
            <code style={code}>{p.path}</code>
            {" — "}
            {pick(lang, p.label_fa, p.label_en)}
          </li>
        ))}
      </ul>
      <p className="muted" style={{ fontSize: 12.5 }}>
        max_zip_bytes: {contract.limits.max_zip_bytes} · max_files: {contract.limits.max_files} ·
        max_compression_ratio: {contract.limits.max_compression_ratio}
      </p>
    </Section>
  );
}

function ExtensionPoints({
  points,
  lang,
  t,
}: {
  points: PluginExtensionPoint[];
  lang: "fa" | "en";
  t: T;
}) {
  return (
    <Section title={t("devdocs.extensionPoints")}>
      <div style={{ display: "grid", gap: 8 }}>
        {points.map((point) => (
          <details key={point.key} style={{ border: "1px solid var(--border)", borderRadius: 8, padding: 10 }}>
            <summary style={{ cursor: "pointer", display: "flex", flexWrap: "wrap", gap: 8, alignItems: "baseline" }}>
              <code style={code}>{point.key}</code>
              <b>{pick(lang, point.label_fa, point.label_en)}</b>
              <span className="muted" style={{ fontSize: 12 }}>
                {t(STATUS_KEY[point.status])} · since {point.since}
              </span>
            </summary>

            <div style={{ display: "grid", gap: 8, marginBlockStart: 8, fontSize: 12.5, lineHeight: 1.9 }}>
              <p>{pick(lang, point.desc_fa, point.desc_en)}</p>
              <p className="muted">{pick(lang, point.openness_why, point.openness_why_en)}</p>
              <b>{t("devdocs.schemas")}</b>
              <pre style={pre}>{prettyJson({ fields: point.schema.fields, cross: point.schema.cross })}</pre>
              {point.example_ok.length > 0 && (
                <>
                  <b>{t("devdocs.examples")}</b>
                  <pre style={pre}>{prettyJson(point.example_ok)}</pre>
                </>
              )}
            </div>
          </details>
        ))}
      </div>
    </Section>
  );
}

function ApiExamples({
  examples,
  lang,
  t,
}: {
  examples: PluginApiExample[];
  lang: "fa" | "en";
  t: T;
}) {
  return (
    <Section title={t("devdocs.examples")}>
      <div style={{ display: "grid", gap: 14 }}>
        {examples.map((ex) => (
          <div key={ex.id} style={{ display: "grid", gap: 8 }}>
            <p style={{ margin: 0 }}>
              <b>{pick(lang, ex.title_fa, ex.title_en)}</b>{" "}
              <code style={code}>
                {ex.method} {ex.path}
              </code>
            </p>
            <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>
              {pick(lang, ex.note_fa, ex.note_en)}
            </p>
            <b style={{ fontSize: 12.5 }}>{t("devdocs.request")}</b>
            <pre style={pre}>{prettyJson(ex.request)}</pre>
            <b style={{ fontSize: 12.5 }}>{t("devdocs.response")}</b>
            <pre style={pre}>{prettyJson(ex.response)}</pre>
          </div>
        ))}
      </div>
    </Section>
  );
}

function ErrorCatalog({
  contract,
  lang,
  t,
}: {
  contract: PluginPackageContract;
  lang: "fa" | "en";
  t: T;
}) {
  const grouped = useMemo(() => {
    const map = new Map<string, string[]>();
    for (const [errorCode, group] of Object.entries(contract.error_codes)) {
      const list = map.get(group) ?? [];
      list.push(errorCode);
      map.set(group, list);
    }
    for (const list of map.values()) list.sort();
    return map;
  }, [contract.error_codes]);

  return (
    <Section title={t("devdocs.errorCodes")}>
      <div style={{ display: "grid", gap: 10 }}>
        {[...grouped.entries()].map(([group, codes]) => {
          const meta = contract.error_code_groups[group];
          return (
            <details key={group} style={{ border: "1px solid var(--border)", borderRadius: 8, padding: 10 }}>
              <summary style={{ cursor: "pointer", display: "flex", gap: 8, alignItems: "baseline" }}>
                <code style={code}>{group}.*</code>
                <b>{meta ? pick(lang, meta.label_fa, meta.label_en) : group}</b>
                <span className="muted" style={{ fontSize: 12 }}>({codes.length})</span>
              </summary>
              <p style={{ marginBlock: 8 }}>
                <b>{t("devdocs.remedy")}:</b>{" "}
                {meta ? pick(lang, meta.remedy_fa, meta.remedy_en) : "—"}
              </p>
              <ul style={{ paddingInlineStart: 18, display: "grid", gap: 2 }}>
                {codes.map((c) => (
                  <li key={c}>
                    <code style={code}>{c}</code>
                  </li>
                ))}
              </ul>
            </details>
          );
        })}
      </div>
    </Section>
  );
}

function PluginDocs({
  docs,
  lang,
  t,
}: {
  docs: PluginDeveloperDocs[];
  lang: "fa" | "en";
  t: T;
}) {
  return (
    <Section title={t("devdocs.pluginDocs")}>
      {docs.length === 0 ? (
        <p className="muted">{t("devdocs.noPluginDocs")}</p>
      ) : (
        <div style={{ display: "grid", gap: 12 }}>
          {docs.map((plugin) => (
            <div key={plugin.slug}>
              <p style={{ margin: 0 }}>
                <b>{plugin.name}</b> <code style={code}>{plugin.slug}</code>{" "}
                <span className="muted" style={{ fontSize: 12 }}>
                  {plugin.version}
                </span>
              </p>
              <div style={{ display: "grid", gap: 8, marginBlockStart: 8 }}>
                {plugin.entries.map((entry, i) => (
                  <div key={`${plugin.slug}-${i}`} style={{ display: "grid", gap: 6 }}>
                    <p style={{ margin: 0 }}>
                      <b>{pick(lang, entry.title_fa, entry.title_en)}</b>{" "}
                      <code style={code}>
                        {entry.method} {entry.path}
                      </code>
                    </p>
                    <p className="muted" style={{ margin: 0, fontSize: 12.5 }}>
                      {pick(lang, entry.description_fa, entry.description_en)}
                      {entry.auth ? ` · auth: ${entry.auth}` : ""}
                    </p>
                    {entry.request !== undefined && <pre style={pre}>{prettyJson(entry.request)}</pre>}
                    {entry.response !== undefined && <pre style={pre}>{prettyJson(entry.response)}</pre>}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}
    </Section>
  );
}
