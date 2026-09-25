-- 02-testdb.sql — separate database for `php artisan test` (RefreshDatabase
-- wipes whatever it migrates, so tests must NOT run on the dev `cms` db).
-- NOTE: uses \gexec (psql meta-command); keep this file LF-only, no BOM.
SELECT 'CREATE DATABASE cms_test' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'cms_test')\gexec
