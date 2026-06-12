--
-- Migration 003: Add indexes on time column for date-range query performance.
--
-- Run once against the production database:
--   mysql -u <user> -p quetoo_stats < migrate/003_add_time_indexes.sql
--

USE quetoo_stats;

ALTER TABLE frags    ADD INDEX idx_time (`time`);
ALTER TABLE captures ADD INDEX idx_time (`time`);
