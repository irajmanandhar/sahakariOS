-- Runs once, only when the MySQL data volume is first created.
-- Creates the separate database used by the automated test suite so tests
-- never touch the development database.
CREATE DATABASE IF NOT EXISTS sahakarios_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON sahakarios_test.* TO 'sahakarios'@'%';
