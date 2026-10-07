"use client";

import { useCallback, useEffect, useState } from "react";
import { ApiError, authed } from "@/lib/auth";
import { useLang } from "@/lib/i18n";
import { useToast } from "@/components/ui/Toast";
import {
  CHANNELS,
  isChannel,
  type Channel,
  type NotificationChannelSettings,
  type PrefMatrix,
  type PrefResponse,
  type TelegramBotStatus,
} from "@/lib/notifications";
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
const CHANNEL_LABEL: Record<
  Channel,
  "inbox.channelEmail" | "inbox.channelSms" | "inbox.channelPush" | "inbox.channelTelegram"
> = {
  email: "inbox.channelEmail",
  sms: "inbox.channelSms",
  push: "inbox.channelPush",
  telegram: "inbox.channelTelegram",
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
  /** WF-M15 — تنظیماتِ کانالِ کاربر (chat_id تلگرام + خلاصهٔ روزانه). */
  const [settings, setSettings] = useState<NotificationChannelSettings | null>(null);
  const [chatId, setChatId] = useState("");
  const [digest, setDigest] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  /**
   * E73 — رباتِ تلگرامِ نصب. `null` = هنوز خوانده نشده؛ `hidden` یعنی ۴۰۳
   * (مدیر این پرمیشن را ندارد) و کارت کلاً رندر نمی‌شود — fail-closed، چون
   * نبودِ پرمیشن نباید شکلِ «توکن ذخیره نشد» بگیرد.
   */
  const [bot, setBot] = useState<TelegramBotStatus | null>(null);
  const [botHidden, setBotHidden] = useState(false);
  const [botToken, setBotToken] = useState("");
  const [botBusy, setBotBusy] = useState(false);

  const applySettings = useCallback((s: NotificationChannelSettings) => {
    setSettings(s);
    setChatId(s.telegram_chat_id ?? "");
    setDigest(Boolean(s.daily_digest));
  }, []);

  const load = useCallback(async () => {
    try {
      const json = await authed<PrefResponse>("/v1/admin/notification-preferences");
      setLabels(json?.groups && typeof json.groups === "object" ? json.groups : {});
      setMatrix(json?.matrix ?? null);
      // کانال‌ها از بک‌اند می‌آیند (تک‌منبعِ حقیقت)، ولی اگر لیست خراب بود
      // `CHANNELS` محلی جایگزین می‌شود تا ماتریس هرگز خالی رندر نشود.
      const cs = Array.isArray(json?.channels) ? json.channels.filter(isChannel) : [];
      setChannels(cs.length > 0 ? cs : [...CHANNELS]);
      if (json?.settings) applySettings(json.settings);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : t("inbox.loadError"));
    }
    // E73 — وضعیتِ ربات جدا خوانده می‌شود چون پرمیشنش جداست (`settings.edit`):
    // خطای آن نباید کلِ صفحه را قرمز کند و ۴۰۳ یعنی کارت پنهان می‌شود.
    try {
      const b = await authed<TelegramBotStatus>("/v1/admin/notification-preferences/telegram-bot");
      setBot({ configured: b?.configured === true, masked: typeof b?.masked === "string" ? b.masked : null });
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) setBotHidden(true);
    }
  }, [applySettings, t]);

  useEffect(() => {
    void load();
  }, [load]);

  const saveSettings = useCallback(
    async (next: { telegram_chat_id: string | null; daily_digest: boolean }) => {
      setSavingSettings(true);
      try {
        const json = await authed<{ settings: NotificationChannelSettings }>(
          "/v1/admin/notification-preferences/settings",
          { method: "PUT", body: next },
        );
        if (json?.settings) applySettings(json.settings);
        toast(t("inbox.channelsSaved"), "ok");
      } catch (e) {
        toast(e instanceof Error ? e.message : t("inbox.channelsFailed"), "err");
      } finally {
        setSavingSettings(false);
      }
    },
    [applySettings, toast, t],
  );

  /**
   * E73 — ذخیره/پاک‌سازیِ توکنِ ربات. خالی یعنی پاک‌سازی (برگشت به `.env`).
   * پاسخ، وضعیتِ تازه را می‌دهد و همان state می‌شود — نه حدسِ محلی.
   */
  const saveBotToken = useCallback(
    async (token: string | null) => {
      setBotBusy(true);
      try {
        const json = await authed<TelegramBotStatus>("/v1/admin/notification-preferences/telegram-bot", {
          method: "PUT",
          body: { token },
        });
        setBot({ configured: json?.configured === true, masked: typeof json?.masked === "string" ? json.masked : null });
        if (token) setBotToken("");
        toast(t("inbox.telegramBotSaved"), "ok");
      } catch (e) {
        toast(e instanceof Error ? e.message : t("inbox.telegramBotFailed"), "err");
      } finally {
        setBotBusy(false);
      }
    },
    [toast, t],
  );

  const testBotToken = useCallback(async () => {
    const chat = chatId.trim();
    if (!chat) {
      toast(t("inbox.telegramBotNeedChat"), "err");
      return;
    }
    setBotBusy(true);
    try {
      await authed("/v1/admin/notification-preferences/telegram-bot/test", {
        method: "POST",
        body: { chat_id: chat },
      });
      toast(t("inbox.telegramBotTestOk"), "ok");
    } catch (e) {
      toast(e instanceof Error ? e.message : t("inbox.telegramBotTestFailed"), "err");
    } finally {
      setBotBusy(false);
    }
  }, [chatId, toast, t]);

  const write = useCallback(    async (group: string, channel: Channel, enabled: boolean) => {
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

      {settings ? (
        <div className="card card-pad" style={{ marginBlockStart: 12 }}>
          <b>{t("inbox.channelsTitle")}</b>
          <div className="muted" style={{ fontSize: 12, marginBlockStart: 4 }}>
            {t("inbox.channelsHint")}
          </div>

          {!settings.telegram_configured ? (
            <div style={{ marginBlockStart: 10 }}>
              <Alert tone="amber">{t("inbox.telegramNotConfigured")}</Alert>
            </div>
          ) : null}

          <div style={{ display: "grid", gap: 6, marginBlockStart: 12 }}>
            <label className="ver-title" style={{ fontSize: 13 }} htmlFor="tg-chat-id">
              {t("inbox.telegramChatId")}
            </label>
            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
              <input
                id="tg-chat-id"
                className="input"
                dir="ltr"
                value={chatId}
                placeholder="@my_channel"
                onChange={(e) => setChatId(e.target.value)}
                style={{ flex: 1, minInlineSize: 160 }}
              />
              <button
                type="button"
                className="btn"
                disabled={savingSettings}
                onClick={() =>
                  void saveSettings({ telegram_chat_id: chatId.trim() || null, daily_digest: digest })
                }
              >
                {t("inbox.saveChannels")}
              </button>
            </div>
            <div className="muted" style={{ fontSize: 12 }}>{t("inbox.telegramChatIdHint")}</div>
          </div>

          <div style={{ display: "grid", gap: 6, marginBlockStart: 12 }}>
            <label className="ver-row" style={{ cursor: "pointer" }}>
              <span className="ver-title" style={{ fontSize: 13 }}>{t("inbox.dailyDigest")}</span>
              <span className={`dot ${digest ? "on" : "mute"}`} aria-hidden style={{ marginInlineEnd: 6 }} />
              <button
                type="button"
                role="switch"
                aria-checked={digest}
                className="switch"
                aria-label={t("inbox.dailyDigest")}
                disabled={savingSettings}
                onClick={() => {
                  const next = !digest;
                  setDigest(next);
                  void saveSettings({ telegram_chat_id: chatId.trim() || null, daily_digest: next });
                }}
              />
            </label>
            <div className="muted" style={{ fontSize: 12 }}>{t("inbox.dailyDigestHint")}</div>
          </div>
        </div>
      ) : null}

      {!botHidden && bot ? (
        <div className="card card-pad" style={{ marginBlockStart: 12 }}>
          <b>{t("inbox.telegramBotTitle")}</b>
          <div className="muted" style={{ fontSize: 12, marginBlockStart: 4 }}>
            {t("inbox.telegramBotHint")}
          </div>
          <div style={{ marginBlockStart: 8, fontSize: 13 }}>
            {bot.configured && bot.masked ? (
              <span>{t("inbox.telegramBotConnected", { masked: bot.masked })}</span>
            ) : (
              <Alert tone="amber">{t("inbox.telegramNotConfigured")}</Alert>
            )}
          </div>
          <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap", marginBlockStart: 12 }}>
            <input
              id="tg-bot-token"
              className="input"
              dir="ltr"
              type="password"
              autoComplete="new-password"
              value={botToken}
              placeholder={t("inbox.telegramBotTokenPlaceholder")}
              aria-label={t("inbox.telegramBotToken")}
              onChange={(e) => setBotToken(e.target.value)}
              style={{ flex: 1, minInlineSize: 200 }}
            />
            <button
              type="button"
              className="btn"
              disabled={botBusy}
              onClick={() => void saveBotToken(botToken.trim() || null)}
            >
              {t("inbox.telegramBotSave")}
            </button>
            {bot.configured ? (
              <button
                type="button"
                className="btn btn-ghost"
                disabled={botBusy}
                onClick={() => void saveBotToken(null)}
              >
                {t("inbox.telegramBotClear")}
              </button>
            ) : null}
            <button
              type="button"
              className="btn btn-ghost"
              disabled={botBusy || !bot.configured}
              onClick={() => void testBotToken()}
            >
              {t("inbox.telegramBotTest")}
            </button>
          </div>
        </div>
      ) : null}

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
