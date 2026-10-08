"use client";
import { useState } from "react";
import { publicConfig } from "@/lib/runtime-config";

const API_BASE = publicConfig().apiUrl;

/** فرم تماس عمومی → POST /v1/contact/tickets (honeypot + rate-limit سمت سرور).
 * subject = عنوان بلوک؛ تلفن (در صورت نمایش) به انتهای متن پیام الصاق می‌شود.
 * ایمیل مقصد در قرارداد فعلی تیکت مسیریابی ندارد و فقط در تنظیمات بلوک ذخیره می‌شود. */
export function ContactForm({ title, showPhone = true, destinationEmail }: { title: string; showPhone?: boolean; destinationEmail?: string }) {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [body, setBody] = useState("");
  const [website, setWebsite] = useState(""); // honeypot — انسان خالی می‌گذارد
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const send = async () => {
    if (!name.trim() || !email.trim() || !body.trim()) {
      setError("نام، ایمیل و متن پیام الزامی است.");
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const fullBody = showPhone && phone.trim() ? `${body.trim()}\n\nتلفن: ${phone.trim()}` : body.trim();
      const res = await fetch(`${API_BASE}/v1/contact/tickets`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ name: name.trim(), email: email.trim(), subject: title, body: fullBody, website: website || undefined }),
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(json?.message ?? "ارسال ناموفق بود.");
      setDone(json?.message ?? "پیام شما ثبت شد. به‌زودی پاسخ می‌دهیم.");
      setName(""); setEmail(""); setPhone(""); setBody("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "ارسال ناموفق بود.");
    } finally {
      setBusy(false);
    }
  };

  if (done) return <div className="alert a-green" role="status">{done}</div>;

  return (
    <section className="card card-pad" aria-label={title}>
      <div className="card-title">{title}</div>
      <div className="grid c2">
        <div className="field"><label>نام *</label><input className="input" value={name} onChange={(e) => setName(e.target.value)} /></div>
        <div className="field"><label>ایمیل *</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={email} onChange={(e) => setEmail(e.target.value)} /></div>
      </div>
      {showPhone ? (
        <div className="field"><label>تلفن</label><input className="input" dir="ltr" style={{ textAlign: "left" }} value={phone} onChange={(e) => setPhone(e.target.value)} /></div>
      ) : null}
      <div className="field"><label>متن پیام *</label><textarea className="input" rows={4} value={body} onChange={(e) => setBody(e.target.value)} /></div>
      {/* honeypot ضداسپم — مخفی از انسان */}
      <input type="text" name="website" autoComplete="off" tabIndex={-1} aria-hidden value={website} onChange={(e) => setWebsite(e.target.value)} style={{ display: "none" }} />
      {error ? <div className="alert a-red" role="alert">{error}</div> : null}
      <button className="btn btn-primary" onClick={() => void send()} disabled={busy}>{busy ? "…" : "ارسال پیام"}</button>
    </section>
  );
}
