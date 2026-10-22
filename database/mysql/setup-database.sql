-- Creates one StoSyncRad database pair (@app_db and @app_db_reporting) and grants the accounts on it.
-- Sourced by setup.sql; expects @app_db to be set.

SET @reporting_db = CONCAT(@app_db, '_reporting');

SET @s = CONCAT('CREATE DATABASE IF NOT EXISTS `', @app_db, '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('CREATE DATABASE IF NOT EXISTS `', @reporting_db, '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('GRANT ALL PRIVILEGES ON `', @app_db, '`.* TO ''stosyncrad_deploy''@''%'' WITH GRANT OPTION');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('GRANT ALL PRIVILEGES ON `', @reporting_db, '`.* TO ''stosyncrad_deploy''@''%'' WITH GRANT OPTION');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('GRANT SELECT, INSERT, LOCK TABLES ON `', @app_db, '`.* TO ''stosyncrad_app''@''%''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('GRANT SELECT ON `', @reporting_db, '`.* TO ''stosyncrad_reporting''@''%''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
-- The runtime account computes the report cost estimate (EXPLAIN needs SHOW VIEW plus access to the
-- tables beneath a view, which the reporting account must not have). It can already read those tables.
SET @s = CONCAT('GRANT SELECT, SHOW VIEW ON `', @reporting_db, '`.* TO ''stosyncrad_app''@''%''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = CONCAT('GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON `', @app_db, '`.* TO ''stosyncrad_backup''@''%''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s = CONCAT('GRANT SELECT, SHOW VIEW, LOCK TABLES ON `', @reporting_db, '`.* TO ''stosyncrad_backup''@''%''');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
