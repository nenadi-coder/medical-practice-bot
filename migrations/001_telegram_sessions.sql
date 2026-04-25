-- Migration: Create telegram_sessions table for /askappointment multi-step conversation flow
-- Run this once against your database before deploying the updated telegram_bot.php

CREATE TABLE IF NOT EXISTS `telegram_sessions` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `telegram_user_id` BIGINT           NOT NULL,
    `step`             VARCHAR(50)      NOT NULL DEFAULT '',
    `data_json`        TEXT             NULL,
    `updated_at`       TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                 ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_telegram_user` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
