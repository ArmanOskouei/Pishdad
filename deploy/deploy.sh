#!/usr/bin/env bash
#
# CP-1/CP-6 — استقرار تولید «پیشداد» روی VPS با Docker Compose.
#
# ترتیب ایند congenial از قبل غلط بود: `up -d --build` هم می‌ساخت و هم
# کانتینرها را عوض می‌کرد (شکست در نیمهٔ بیلد ⇒ استکِ نیمه‌به‌روز)، و مهاجرت
# پیش از آماده‌شدنِ اپ اجرا می‌شد. حالا:
#
#   build (بی‌اثر اگر شکست) → زیرساخت → اپ → بکاپ → مهاجرت → لبه → تأیید
#
# و گیت واقعی: تا وقتی `doctor --strict` پاس نشده، **لبهٔ عمومی دست‌نخورده**
# است و نسخهٔ قبلی سایت را سرو می‌کند. این سازوکارِ صفر-وقتهٔ صادقانه است.
#
# ⚠️ فرانت دیگر روی هاست بیلد نمی‌شود: سرویسِ `frontend` در compose است
# (گام ۷ قدیمی `npm ci && npm run build` را روی هاست می‌زد و هیچ‌چیز اجرا
# نمی‌شد، بعد ✅ چاپ می‌کرد).
#
# ⛔ cron هاستی نگذارید: سرویسِ `scheduler` با `schedule:work` بالا می‌آید
# (E76) و یک کرونِ `* * * * * schedule:run` هر job را دو بار در دقیقه اجرا
# می‌کند — از جمله دو pg_dump در شب.
#
# پیش‌نیازها: Docker+Compose · `pishdad-core/backend/.env` · DNS+TLS لبه.
#
# اجرا از ریشهٔ پروژه:  bash deploy/deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BE="$ROOT/pishdad-core/backend"
FE="$ROOT/pishdad-core/frontend"
DC=(docker compose -f "$BE/docker-compose.yml")

dump_logs() {
  local rc=$?
  echo "" >&2
  echo "❌ استقرار ناکام (exit=$rc). آخرین لاگ‌ها:" >&2
  for s in web frontend app scheduler db redis; do
    "${DC[@]}" logs --tail=40 "$s" 2>&1 | sed 's/^/  /' >&2 || true
  done
  echo "بازگشت: git checkout <sha> && docker compose up -d" >&2
  exit "$rc"
}
trap dump_logs ERR

echo "==> Pishdad deploy — $ROOT"
command -v docker >/dev/null || { echo "docker یافت نشد"; exit 1; }
[ -f "$BE/.env" ] || { echo "pishdad-core/backend/.env وجود ندارد — از deploy/env.production.example بساز."; exit 1; }
mkdir -p "$FE/config"

echo "==> [1/9] ساخت (بی‌اثر در صورت شکست)"
"${DC[@]}" build

echo "==> [2/9] زیرساخت"
"${DC[@]}" up -d --wait db redis minio minio-init

echo "==> [3/9] اپ و زمان‌بند"
"${DC[@]}" up -d --wait app scheduler

echo "==> [4/9] بکاپ پیش از مهاجرت (تنها تور ایمنیِ اسکیما)"
mkdir -p "$BE/backups"
"${DC[@]}" exec -T db pg_dump -U "${PISHDAD_DB_USER:-cms}" cms \
  | gzip > "$BE/backups/predeploy-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"

echo "==> [5/9] مهاجرت و گیت‌های وضعیت"
"${DC[@]}" exec -T app php artisan migrate --force
"${DC[@]}" exec -T app php artisan plugin-ddl:reset-password --check \
  || "${DC[@]}" exec -T app php artisan plugin-ddl:reset-password
"${DC[@]}" exec -T app php artisan outbox:drain || true
"${DC[@]}" exec -T app php artisan pishdad:doctor --strict

echo "==> [6/9] لبه و فرانت (فقط بعد از سبز شدن مهاجرت)"
"${DC[@]}" up -d --wait web frontend

echo "==> [7/9] تأییدِ لبه"
port="${PISHDAD_WEB_PORT:-8080}"
code=""
for i in $(seq 1 20); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/healthz" || true)
  [ "$code" = "200" ] && break
  sleep 3
done
[ "$code" = "200" ] || { echo "healthz=$code"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/up")" = "200" ] \
  || { echo "Laravel /up بد"; exit 1; }

# ⚠️ ۳۰۷ روی نصبِ تازه درست است: فرانت کاربر را به /setup می‌برد.
root_code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/")
case "$root_code" in
  200|307) ;;
  *) echo "سایت: HTTP $root_code"; exit 1 ;;
esac

font=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/fonts/Vazirmatn-Regular.woff2")
[ "$font" = "200" ] || { echo "فونت Vazirmatn: HTTP $font"; exit 1; }

# CP-1: پیکربندیِ زمانِ اجرای فرانت باید از مسیرِ خودش سرو شود.
cfg=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/runtime-config.js")
[ "$cfg" = "200" ] || { echo "runtime-config.js: HTTP $cfg"; exit 1; }

echo "✅ استقرار کامل شد. لبه سبز است و مهاجرت‌ها اجرا شده‌اند."
