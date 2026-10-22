-- StoSyncRad MySQL 8.4 bootstrap: databases and least-privilege accounts (run once as an
-- administrative user; idempotent). Passwords are never stored here; set them first:
--
--   mysql -u root -p -e "SET @deploy_pw='…', @app_pw='…', @reporting_pw='…', @backup_pw='…'; SOURCE database/mysql/setup.sql"
--
-- Optional: @db (default 'stosyncrad'), @with_test_db (default 1, also creates <db>_test).
-- Every application database <db> has a companion <db>_reporting that holds only the reporting views.
--
-- Accounts:
--   stosyncrad_deploy     owns the schema: migrations, views, triggers, grants (WITH GRANT OPTION)
--   stosyncrad_app        runtime: SELECT, INSERT, LOCK TABLES on the database; UPDATE and DELETE are
--                         granted per table by RuntimeGrants::sync(), so append-only tables never get them
--   stosyncrad_reporting  SELECT on <db>_reporting only (the views run with the deploy account's rights)
--   stosyncrad_backup     read-only dump account

SET @db = COALESCE(@db, 'stosyncrad');
-- With @with_test_db = 0 the "test" statements simply repeat the main database's (idempotent).
SET @test_db = IF(COALESCE(@with_test_db, 1) = 1, CONCAT(@db, '_test'), @db);

-- Triggers are created by the deploy account while binary logging is on.
SET PERSIST log_bin_trust_function_creators = ON;

-- Accounts --------------------------------------------------------------------------------------
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''stosyncrad_deploy''@''%'' IDENTIFIED BY ', QUOTE(@deploy_pw));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''stosyncrad_app''@''%'' IDENTIFIED BY ', QUOTE(@app_pw), ' WITH MAX_USER_CONNECTIONS 60');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''stosyncrad_reporting''@''%'' IDENTIFIED BY ', QUOTE(@reporting_pw), ' WITH MAX_USER_CONNECTIONS 8');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('CREATE USER IF NOT EXISTS ''stosyncrad_backup''@''%'' IDENTIFIED BY ', QUOTE(@backup_pw));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Databases and grants (application database, then test database) ------------------------------
SET @app_db = @db;
SOURCE database/mysql/setup-database.sql
SET @app_db = @test_db;
SOURCE database/mysql/setup-database.sql
