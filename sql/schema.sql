-- sql/schema.sql
-- Run this once to create the demo database & table

CREATE DATABASE IF NOT EXISTS excel_import_demo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE excel_import_demo;

CREATE TABLE IF NOT EXISTS employees (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    email      VARCHAR(191) NOT NULL,
    phone      VARCHAR(50)  NULL,
    department VARCHAR(100) NULL,
    joined_at  DATE         NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB;