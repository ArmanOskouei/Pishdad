FROM node:22-alpine AS base
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci || npm install

# J3 — دو بیلدِ جدا: مشتری و مرکز.
#
# `docker build --target client .`  →  بدون پنلِ مرکزی (پیش‌فرضِ نصبِ مشتری)
# `docker build --target central .` →  با پنلِ مرکزی (توسعه و سرورِ مرکز)
#
# ⚠️ **چرا این‌طوری و نه `rm -rf` بعد از `COPY`** (یادداشتِ K7.x که نزدیک بود
# فراموش شود)
#
# نسخهٔ قبل `COPY . .` و بعد `rm -rf "src/app/(central)"` داشت. برای باندل درست بود
# و Next چیزی نمی‌ساخت — ولی ۲۱۴۷ خط TSXِ پنلِ مرکزی **در لایهٔ ایمیج می‌ماندند**:
# روی دیسکِ هر مشتری، و در `docker history` خوانا. یعنی «جدا کردن» در ظاهر بود.
#
# اینجا پوشه اصلاً **وارد context نمی‌شود**. `.dockerignore` ثابت است و به
# `build-arg` وابسته نمی‌شود، پس راهش `COPY --exclude` در مرحلهٔ جداگانه است.

# ---- بیلدِ مشتری: پنلِ مرکزی اصلاً کپی نمی‌شود --------------------------------
FROM base AS client
COPY --exclude='src/app/(central)' . .
# نگهبان: اگر الگوی بالا روزی خراب شد، اینجا لو می‌دهد — نه در باندلِ مشتری.
RUN test ! -e 'src/app/(central)' || (echo 'ERROR: central panel leaked into a client build' >&2; exit 1)
ENV CMS_CLIENT_ONLY_BUILD=1
RUN npm run build

# ---- بیلدِ کامل: پنلِ مرکزی هم داخل است --------------------------------------
FROM base AS central
COPY . .
ENV CMS_CLIENT_ONLY_BUILD=0
RUN npm run build

# نامِ stage پیش‌فرض = چیزی که `docker build .` بدون `--target` می‌سازد.
FROM central AS default-target