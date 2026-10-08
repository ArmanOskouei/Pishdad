#!/usr/bin/env bash
#
# E10 — استقرار تولید «پیشداد» روی VPS با Docker Compose.
#
# زنجیرهٔ گام‌های DEPLOY-CHECKLIST را به‌ترتیب اجرا می‌کند و روی «doctor --strict»
# گیتِ شکست می‌گذارد: اگر چیزی در محیط کم باشد، استقرار با exit≠0 می‌ایستد.
#
# پیش‌نیازها (خارج از این اسکریپت، باید دستی یک‌بار انجام شود):
#   1) Docker + Docker Compose روی سرور
#   2) کپی `deploy/env.production.example` به `pishdad-core/backend/.env` و پر کردن مقادیر
#   3) DNS دامنه به سرور + TLS (reverse proxy یا TLS در لبه)
#   4) cron:  * * * * * cd <root>/pishdad-core/backend && php artisan schedule:run
#
# اجرا از ریشهٔ پروژه:  bash deploy/deploy.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BE="$ROOT/pishdad-core/backend"
FE="$ROOT/pishdad-core/frontend"

echo "==> Pishdad deploy — $ROOT"
command -v docker >/dev/null || { echo "docker یافت نشد"; exit 1; }
[ -f "$BE/.env" ] || { echo "pishdad-core/backend/.env وجود ندارد — از deploy/env.production.example بساز."; exit 1; }

echo "==> [1/7] بالا آوردن سرویس‌ها (db/redis/minio/app/web)"
docker compose -f "$BE/docker-compose.yml" up -d --build

echo "==> [2/7] مهاجرت‌ها"
docker compose -f "$BE/docker-compose.yml" exec -T app php artisan migrate --force

echo "==> [3/7] نقش DDL افزونه (K5.7)"
docker compose -f "$BE/docker-compose.yml" exec -T app php artisan plugin-ddl:reset-password --check \
  || docker compose -f "$BE/docker-compose.yml" exec -T app php artisan plugin-ddl:reset-password

echo "==> [4/7] مهر یکپارچگی افزونه‌ها (K5.9/K5.2)"
docker compose -f "$BE/docker-compose.yml" exec -T app php artisan plugin-seal --all --reseal

echo "==> [5/7] تخلیهٔ outbox"
docker compose -f "$BE/docker-compose.yml" exec -T app php artisan outbox:drain || true

echo "==> [6/7] گیتِ سلامت (doctor --strict)"
docker compose -f "$BE/docker-compose.yml" exec -T app php artisan pishdad:doctor --strict

echo "==> [7/7] بیلد فرانت (standalone)"
( cd "$FE" && npm ci && npm run build )

echo "✅ استقرار کامل شد. cron مربوط به schedule:run را تنظیم کن و دامنه/TLS را بررسی کن."
