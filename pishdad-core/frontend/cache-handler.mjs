/**
 * F3 — cacheHandler سفارشی Next 15 برای ISR چابکانِ اِفمِرال (یافته PLAN.md):
 * `.next/cache` با هر restart/deploy می‌پرد، پس کش ISR روی Redis می‌رود.
 *
 * فعال‌سازی (پیش‌فرض repo خاموش است تا بدون Redis هم بیلد/ران شود):
 *   1) `npm i redis` (وابستگی اختیاری — اگر نصب نباشد، خودکار fallback حافظه)
 *   2) ست کردن `REDIS_URL=redis://...` (و اختیاری `PISHDAD_CACHE_PREFIX=tenantA:`)
 *   3) باز کردن کامنت `cacheHandler` در next.config.ts
 *
 * رفتار:
 * - بدون REDIS_URL یا بدون پکیج redis → حافظه process-local (مثل رفتار پیش‌فرض Next، امن برای dev/build).
 * - با Redis → get/set/تگ ایندکس (SET per tag) برای revalidateTag واقعی + namespace per-deploy.
 * - در فاز بیلد (PHASE_PRODUCTION_BUILD) هرگز به Redis وصل نمی‌شود تا `next build` آفلاین سبز بماند.
 */

const PREFIX = process.env.PISHDAD_CACHE_PREFIX ?? "pishdad-cache:";
const BUILD_PHASE = process.env.NEXT_PHASE === "phase-production-build";

let warnedOnce = false;
function warnOnce(msg) {
  if (!warnedOnce) {
    warnedOnce = true;
    // eslint-disable-next-line no-console
    console.warn(`[cache-handler] ${msg}`);
  }
}

class MemoryBackend {
  constructor() {
    this.store = new Map(); // key -> { value, expiresAt }
    this.tags = new Map(); // tag -> Set<key>
  }
  async get(key) {
    const e = this.store.get(key);
    if (!e) return null;
    if (e.expiresAt && e.expiresAt < Date.now()) {
      this.store.delete(key);
      return null;
    }
    return e.value;
  }
  async set(key, data, ctx) {
    const secs = typeof data?.revalidate === "number" ? data.revalidate : 0;
    this.store.set(key, { value: data, expiresAt: secs > 0 ? Date.now() + secs * 1000 : 0 });
    for (const tag of ctx?.tags ?? []) {
      if (!this.tags.has(tag)) this.tags.set(tag, new Set());
      this.tags.get(tag).add(key);
    }
  }
  async revalidateTag(tags) {
    for (const tag of Array.isArray(tags) ? tags : [tags]) {
      const keys = this.tags.get(tag);
      if (!keys) continue;
      for (const k of keys) this.store.delete(k);
      this.tags.delete(tag);
    }
  }
}

class RedisBackend {
  constructor(client) {
    this.client = client;
  }
  k(key) {
    return `${PREFIX}${key}`;
  }
  t(tag) {
    return `${PREFIX}tag:${tag}`;
  }
  async get(key) {
    try {
      const raw = await this.client.get(this.k(key));
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null; // خطای Redis = miss (fail-open برای خواندن)
    }
  }
  async set(key, data, ctx) {
    try {
      const secs = typeof data?.revalidate === "number" ? data.revalidate : 0;
      const raw = JSON.stringify(data);
      if (secs > 0) await this.client.set(this.k(key), raw, { EX: secs });
      else await this.client.set(this.k(key), raw);
      for (const tag of ctx?.tags ?? []) {
        await this.client.sAdd(this.t(tag), key);
        await this.client.expire(this.t(tag), 86400);
      }
    } catch {
      /* نوشتن کش نباید ریکوئست را بشکند */
    }
  }
  async revalidateTag(tags) {
    const list = Array.isArray(tags) ? tags : [tags];
    for (const tag of list) {
      try {
        const keys = await this.client.sMembers(this.t(tag));
        if (keys.length > 0) await this.client.del(keys.map((k) => this.k(k)));
        await this.client.del(this.t(tag));
      } catch {
        /* ignore */
      }
    }
  }
}

let backendPromise = null;
async function backend() {
  if (backendPromise) return backendPromise;
  backendPromise = (async () => {
    if (BUILD_PHASE || !process.env.REDIS_URL) {
      if (!BUILD_PHASE && !process.env.REDIS_URL) warnOnce("REDIS_URL ست نیست — fallback حافظه فعال است.");
      return new MemoryBackend();
    }
    try {
      const { createClient } = await import("redis");
      const client = createClient({ url: process.env.REDIS_URL });
      client.on("error", () => {});
      await client.connect();
      return new RedisBackend(client);
    } catch {
      warnOnce("اتصال Redis ناموفق بود (یا پکیج redis نصب نیست — `npm i redis`) — fallback حافظه فعال است.");
      return new MemoryBackend();
    }
  })();
  return backendPromise;
}

module.exports = class CacheHandler {
  constructor(_options) {}
  async get(key) {
    return (await backend()).get(key);
  }
  async set(key, data, ctx) {
    return (await backend()).set(key, data, ctx);
  }
  async revalidateTag(tag) {
    return (await backend()).revalidateTag(tag);
  }
  resetRequestCache() {}
};
