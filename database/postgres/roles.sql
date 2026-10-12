-- StoSyncRad PostgreSQL role bootstrap (run once as a superuser; idempotent).
-- Development passwords below are placeholders. Production must override them via
-- psql -v deploy_pw=... -v app_pw=... -v reporting_pw=... -v backup_pw=...
\set deploy_pw    :deploy_pw
\set app_pw       :app_pw
\set reporting_pw :reporting_pw
\set backup_pw    :backup_pw

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stosyncrad_deploy') THEN
        EXECUTE format('CREATE ROLE stosyncrad_deploy LOGIN PASSWORD %L', current_setting('stosyncrad.deploy_pw'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stosyncrad_app') THEN
        EXECUTE format('CREATE ROLE stosyncrad_app LOGIN PASSWORD %L', current_setting('stosyncrad.app_pw'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stosyncrad_reporting') THEN
        EXECUTE format('CREATE ROLE stosyncrad_reporting LOGIN PASSWORD %L', current_setting('stosyncrad.reporting_pw'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stosyncrad_backup') THEN
        EXECUTE format('CREATE ROLE stosyncrad_backup LOGIN PASSWORD %L', current_setting('stosyncrad.backup_pw'));
    END IF;
END $$;

-- Role-level safety settings (Chapter 14 §14.5)
ALTER ROLE stosyncrad_app SET statement_timeout = '15s';
ALTER ROLE stosyncrad_app SET lock_timeout = '5s';
ALTER ROLE stosyncrad_app SET idle_in_transaction_session_timeout = '30s';
ALTER ROLE stosyncrad_app CONNECTION LIMIT 60;

ALTER ROLE stosyncrad_reporting SET default_transaction_read_only = on;
ALTER ROLE stosyncrad_reporting SET statement_timeout = '15s';
ALTER ROLE stosyncrad_reporting SET lock_timeout = '2s';
ALTER ROLE stosyncrad_reporting SET idle_in_transaction_session_timeout = '180s';
ALTER ROLE stosyncrad_reporting CONNECTION LIMIT 8;

GRANT pg_read_all_data TO stosyncrad_backup;

-- Databases owned by the deployment role
SELECT 'CREATE DATABASE stosyncrad OWNER stosyncrad_deploy ENCODING ''UTF8'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'stosyncrad') \gexec
SELECT 'CREATE DATABASE stosyncrad_test OWNER stosyncrad_deploy ENCODING ''UTF8'''
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'stosyncrad_test') \gexec
