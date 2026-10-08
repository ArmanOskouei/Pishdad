# postgres:18-alpine ships minimal ICU data (English only), which makes
# the fa-IR collation warn and fall back to root sorting rules.
# icu-data-full enables real Persian collation (PLAN.md: ICU fa-IR).
#
# U9: bumped 16 -> 18. The icu-data-full line is not optional: without it
# the app silently loses Persian sort order, and nothing errors — the
# collation just quietly falls back to root rules.
FROM postgres:18-alpine
RUN apk add --no-cache icu-data-full
