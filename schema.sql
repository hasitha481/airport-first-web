-- Airport First — MySQL schema (MySQL 8 / MariaDB 10.4+)
-- ---------------------------------------------------------------------------
-- Run once in cPanel → phpMyAdmin → (your database) → SQL. Safe to re-run:
-- every table is created only if it does not exist yet.
--
-- Direct web access to this file is blocked by .htaccess.

SET NAMES utf8mb4;

-- Booking inquiries, written by send-mail.php after validation.
CREATE TABLE IF NOT EXISTS airport_inquiries (
  id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ref_code         VARCHAR(12)      NOT NULL,                 -- e.g. AF-7KQ3XM, quoted to the guest
  guest_name       VARCHAR(120)     NOT NULL,
  phone            VARCHAR(60)      NOT NULL DEFAULT '',
  email            VARCHAR(160)     NOT NULL DEFAULT '',
  pickup           VARCHAR(200)     NOT NULL DEFAULT '',
  dropoff          VARCHAR(200)     NOT NULL DEFAULT '',
  route_stops      VARCHAR(600)     NOT NULL DEFAULT '',      -- multi-stop custom route, in order
  route_legs       VARCHAR(600)     NOT NULL DEFAULT '',      -- km of each leg
  distance_km      DECIMAL(7,1)     NULL,                     -- total estimated road distance
  distance_text    VARCHAR(400)     NOT NULL DEFAULT '',      -- the full estimate line shown to the desk
  travel_date      DATE             NULL,
  travel_time      VARCHAR(10)      NOT NULL DEFAULT '',
  flight_number    VARCHAR(40)      NOT NULL DEFAULT '',
  vehicle          VARCHAR(120)     NOT NULL DEFAULT '',
  passengers       TINYINT UNSIGNED NULL,
  special_requests TEXT             NULL,
  source           VARCHAR(60)      NOT NULL DEFAULT 'website',
  ip_hash          CHAR(64)         NOT NULL DEFAULT '',      -- salted SHA-256, never the IP itself
  email_sent       TINYINT(1)       NOT NULL DEFAULT 0,
  handled          TINYINT(1)       NOT NULL DEFAULT 0,       -- ticked by the desk in admin-reviews.php
  created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inquiries_ref (ref_code),
  KEY idx_inquiries_created (created_at),
  KEY idx_inquiries_handled (handled, created_at),
  KEY idx_inquiries_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guest reviews. New submissions arrive with is_approved = 0 and only appear
-- on the website once approved in admin-reviews.php.
CREATE TABLE IF NOT EXISTS guest_reviews (
  id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  guest_name   VARCHAR(80)      NOT NULL,
  country      VARCHAR(80)      NOT NULL DEFAULT '',
  rating       TINYINT UNSIGNED NOT NULL,
  trip_type    VARCHAR(60)      NOT NULL DEFAULT '',
  review_text  VARCHAR(600)     NOT NULL,
  avatar_data  MEDIUMTEXT       NULL,                         -- small inline JPEG made in the browser (optional)
  is_approved  TINYINT(1)       NOT NULL DEFAULT 0,
  ip_hash      CHAR(64)         NOT NULL DEFAULT '',
  created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reviews_approved (is_approved, created_at),
  KEY idx_reviews_ip (ip_hash, created_at),
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed admin sign-ins, for the brute-force throttle in admin-reviews.php.
CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  ip_hash      CHAR(64)         NOT NULL,
  attempted_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
