# postgres:16-alpine ships minimal ICU data (English only), which makes
# the fa-IR collation warn and fall back to root sorting rules.
# icu-data-full enables real Persian collation (PLAN.md: ICU fa-IR).
FROM postgres:16-alpine
RUN apk add --no-cache icu-data-full
