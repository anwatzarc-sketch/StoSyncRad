-- Per-database initialization (run as superuser against each StoSyncRad database; idempotent).
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_stat_statements;

GRANT USAGE ON SCHEMA public TO stosyncrad_app, stosyncrad_reporting;
GRANT CREATE ON SCHEMA public TO stosyncrad_deploy;
ALTER SCHEMA public OWNER TO stosyncrad_deploy;

-- Runtime role: ordinary DML on tables created by the deployment role. Append-only tables
-- REVOKE UPDATE/DELETE inside their own migrations (Chapter 13 §13.9).
ALTER DEFAULT PRIVILEGES FOR ROLE stosyncrad_deploy IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO stosyncrad_app;
ALTER DEFAULT PRIVILEGES FOR ROLE stosyncrad_deploy IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO stosyncrad_app;
ALTER DEFAULT PRIVILEGES FOR ROLE stosyncrad_deploy IN SCHEMA public
    GRANT EXECUTE ON FUNCTIONS TO stosyncrad_app;

-- Reporting role gets nothing in public by default; migrations grant SELECT on reporting.* views.
