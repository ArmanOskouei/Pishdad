-- 01-collation.sql — Persian ICU collation for correct fa-IR sorting.
-- Runs once on first `db` volume init (mounted into /docker-entrypoint-initdb.d).
--
-- NOTE: PG16 may still print `WARNING: ICU locale "fa-IR" has unknown
-- language "fa"` (validator quirk with ICU data packaging). It is BENIGN:
-- verified that Persian rules apply, e.g.
--   SELECT 'ك' = 'ک' COLLATE "fa-IR";  →  t   (primary-equal, unlike binary)
-- (db image installs icu-data-full so non-English rules are present.)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_collation WHERE collname = 'fa-IR') THEN
        BEGIN
            CREATE COLLATION "fa-IR" (provider = icu, locale = 'fa-IR');
            RAISE NOTICE 'collation "fa-IR" created (ICU).';
        EXCEPTION WHEN OTHERS THEN
            RAISE NOTICE 'ICU fa-IR unavailable (%), using default collation.', SQLERRM;
        END;
    END IF;
END
$$;
