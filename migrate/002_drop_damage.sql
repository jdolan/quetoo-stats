--
-- Migration 002: Drop the frags.damage column.
--
-- Run once against the production database:
--   mysql -u <user> -p quetoo_stats < migrate/002_drop_damage.sql
--

USE quetoo_stats;

ALTER TABLE frags DROP COLUMN damage;
