"use client";

import { useCallback, useEffect, useState } from "react";
import { authed } from "@/lib/auth";
import { useLang } from "@/lib/i18n";
import { useToast } from "@/components/ui/Toast";
import { CHANNELS, isChannel, type Channel, type PrefMatrix, type PrefResponse } from "@/lib/notifications";
import { Alert, Skeleton } from "@/components/ui/primitives";

/**
 * F4.2.F — ماتریسِ ترجیحِ اعلان: ۵ گروه × ۳ کانال + کلیدِ مادر.
 *
 * ## ⭐ چرا کلیدِ مادر (F4.2.U) «خاموش» است و نه «روشن»
 *
 * حالتِ پیش‌فرضِ بک‌اند (`groupDefault`) یعنی «هیچ کانالی برای این گروه خاموش
 * نیست» مگر آنکه کاتالوگ خلافش بگوید — یعنی سکوت باید **فعال‌سازیِ صریح** باشد.
 * اگر کلیدِ مادر «روشن» بود، هر گروهی که کاربر هرگز ندیده بود خاموش به نظر
 * می‌رسید و یک اعلانِ امنیتی گم می‌شد.
 *
 * پس کلیدِ مادر از روی داده **مشتق** می‌شود: هر کارت شمارهٔ کانال‌های فعالِ
 * خودش را می‌دهد، و مادر «روشن» است اگر **همهٔ** کارت‌ها کامل باشند. یعنی
 * وضعیتِ واقعی در DB، تنها منبعِ حقیقت می‌ماند و کلیدِ مادر چیزی برای خراب‌شدن
 * ندارد.
 *
 * ## نوشتنِ امیدوارانه
 *
 * هر کلید، یک `PUT` مستقل است و پاسخ، **ماتریسِ کامل تازه** را برمی‌گرداند. پس
 * state از پاسخ ساخته می‌شود، نه از حدسِ محلی — وگرنه دو کلیدِ پشت‌سرهم
 * (کاربر سریع می‌زند) با هم می‌جنگند و آخرش یکی بی‌صدا گم می‌شود.
 */

type GroupLabels = Record<string, string>;

/**
 * نگاشتِ کانال به کلیدِ فرهنگِ لغت.
 *
 * نگاشتِ صریح و نه ساختنِ کلید با `toUpperCase()`: کانال‌های `Catalog` تازه
 * اضافه می‌شوند و کلیدِ ساخته‌شده (`inbox.channelWebhook`) در `fa.ts` نیست ⇒
 * `t()` رشتهٔ خام برمی‌گرداند و UI یک کلیدِ فنی نشان می‌دهد. اینجا یک کانالِ
 * تازه یعنی یک خطِ صریح، نه یک bug خاموش.
 */
const CHANNEL_LABEL: Record<Channel, "inbox.channelEmail" | "inbox.channelSms" | "inbox.channelPush"> = {
  email: "inbox.channelEmail",
  sms: "inbox.channelSms",
  push: "inbox.channelPush",
};

export function NotificationPreferences() {
  const { t, tGroup } = useLang();
  const toast = useToast();
  const [labels, setLabels] = useState<GroupLabels>({});
  const [matrix, setMatrix] = useState<PrefMatrix | null>(null);
  const [channels, setChannels] = useState<Channel[]>([...CHANNELS]);
  const [error, setError] = useState<string | null>(null);
  /** کلیدِ در حال ذخیره: `${group}|${channel}` یا `null` برای خواندن. */
  const [saving, setSaving] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const json = await authed<PrefResponse>("/v1/admin/notification-preferences");
      setLabels(json?.groups && typeof json.groups === "object" ? json.groups : {});
      setMatrix(json?.matrix ?? null);
      // کانال‌ها از بک‌اند می‌آیند (تک‌منبعِ حقیقت)، ولی اگر لیست خراب بود
      // `CHANNELS` محلی جایگزین می‌شود تا ماتریس هرگز خالی رندر نشود.
      const cs = Array.isArray(json?.channels) ? json.channels.filter(isChannel) : [];
      setChannels(cs.length > 0 ? cs : [...CHANNELS]);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("inbox.loadError"));
    }
  }, [t]);

  useEffect(() => {
    void load();
  }, [load]);

  const write = useCallback(
    async (group: string, channel: Channel, enabled: boolean) => {
      setSaving(`${group}|${channel}`);
      // خوش‌بینانه: UI بی‌درنگ واکنش نشان می‌دهد و اگر سرور رد کرد، پاسخِ
      // تازه برمی‌گردد و کاربر می‌بیند واقعاً اعمال نشده.
      setMatrix((m) => (m ? { ...m, [group]: { ...m[group], [channel]: enabled } } : m));
      try {
        const json = await authed<{ matrix: PrefMatrix }>("/v1/admin/notification-preferences", {
          method: "PUT",
          body: { group_key: group, channel, enabled },
        });
        if (json?.matrix) setMatrix(json.matrix);
        else await load();
        setError(null);
      } catch (e) {
        toast(e instanceof Error ? e.message : t("inbox.prefFailed"), "err");
        await load();
      } finally {
        setSaving(null);
      }
    },
    [load, toast, t],
  );

  if (error && !matrix) {
    return <Alert tone="red">{error}</Alert>;
  }
  if (!matrix) return <Skeleton lines={5} />;

  const groups = Object.keys(matrix);
  const rows = groups.map((g) => ({
    key: g,
    label: tGroup(g, labels[g] ?? g),
    on: channels.filter((c) => matrix[g]?.[c]).length,
    total: channels.length,
  }));

  const masterOn = rows.length > 0 && rows.every((r) => r.on === r.total);
  const masterMixed = !masterOn && rows.some((r) => r.on > 0);

  return (
    <div>
      <MasterSwitch
        on={masterOn}
        mixed={masterMixed}
        onToggle={(next) => {
          // کلیدِ مادر فقط کارت‌هایی را عوض می‌کند که با وضعیتِ هدف فرق دارند،
          // تا کمترین تعداد ممکن `PUT` برود.
          void Promise.all(
            rows.flatMap((r) =>
              channels
                .filter((c) => Boolean(matrix[r.key]?.[c]) !== next)
                .map((c) => write(r.key, c, next)),
            ),
          );
        }}
      />

      <div className="grid c2" style={{ marginBlockStart: 12 }}>
        {rows.map((r) => (
          <div key={r.key} className="card card-pad">
            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <b>{r.label}</b>
              <div style={{ flex: 1 }} />
              <span className="muted" style={{ fontSize: 12 }}>
                {new Intl.NumberFormat("fa-IR").format(r.on)}/{new Intl.NumberFormat("fa-IR").format(r.total)}
              </span>
              {r.on === 0 ? <span className="dot mute" aria-hidden /> : null}
            </div>
            <div style={{ display: "grid", gap: 6, marginBlockStart: 10 }}>
              {channels.map((c) => {
                const on_ = Boolean(matrix[r.key]?.[c]);
                const label = CHANNEL_LABEL[c];
                return (
                  <label key={c} className="ver-row" style={{ cursor: "pointer" }}>
                    <span className="ver-title" style={{ fontSize: 13 }}>{t(label)}</span>
                    <span className={`dot ${on_ ? "on" : "mute"}`} aria-hidden style={{ marginInlineEnd: 6 }} />
                    <button
                      type="button"
                      role="switch"
                      aria-checked={on_}
                      className="switch"
                      aria-label={`${r.label} — ${t(label)}`}
                      disabled={saving === `${r.key}|${c}`}
                      onClick={() => void write(r.key, c, !on_)}
                    />
                  </label>
                );
              })}
            </div>
          </div>
        ))}
      </div>

      {error ? (
        <div style={{ marginBlockStart: 12 }}>
          <Alert tone="amber">{error}</Alert>
        </div>
      ) : null}
    </div>
  );
}

/**
 * کلیدِ مادر.
 *
 * ⭐ `role="checkbox"` و نه `role="switch"` — چون این کلید **سه‌حالته** است:
 * روشن، خاموش، و «بعضی روشن». فقط `checkbox` مقدارِ `aria-checked="mixed"` را
 * می‌پذیرد؛ `switch` آن را رد می‌کند. یعنی با `switch` وضعیتِ واقعیِ سوم
 * برای صفحه‌خوان کاملاً گم می‌شد و فقط با رنگ گفته می‌شد.
 *
 * `indeterminate` لازم نیست چون با `aria-checked="mixed"` همان معنا داده
 * می‌شود و نیازی به دستکاریِ DOM از راه ref دارد.
 */
function MasterSwitch({ on, mixed, onToggle }: { on: boolean; mixed: boolean; onToggle: (next: boolean) => void }) {
  const { t } = useLang();
  return (
    <div className="card card-pad">
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <button
          type="button"
          role="checkbox"
          aria-checked={mixed ? "mixed" : on}
          className="switch"
          aria-label={t("inbox.master")}
          onClick={() => onToggle(!on)}
        />
        <div style={{ flex: 1, minInlineSize: 0 }}>
          <b>{t("inbox.master")}</b>
          <div className="muted" style={{ fontSize: 12 }}>{t("inbox.masterHint")}</div>
        </div>
      </div>
    </div>
  );
}
