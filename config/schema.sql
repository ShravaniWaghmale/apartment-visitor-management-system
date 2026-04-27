-- ============================================================
--  ResidentGuard — Full Dynamic PostgreSQL Schema
--  Run once: psql -U postgres -d visitor_management -f schema.sql
-- ============================================================

DROP TABLE IF EXISTS notifications,parking_slots,visits,appointments,visitors,users,roles,settings CASCADE;

-- ROLES
CREATE TABLE roles (
    id   SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
);
INSERT INTO roles (name) VALUES ('admin'),('receptionist'),('guard'),('host');

-- USERS
CREATE TABLE users (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(120) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    role_id     INT  NOT NULL REFERENCES roles(id),
    flat_number VARCHAR(20),
    phone       VARCHAR(20),
    avatar      VARCHAR(255),
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Demo seed (passwords = bcrypt of password123; adjust via Settings)
INSERT INTO users (name,email,password,role_id,flat_number,phone) VALUES
  ('Society Admin',      'admin@society.com',     '$2y$10$u3a2oXZmWelzNswi1MsLf.v0EaGJPG6iKCM7eDq3q7LHmUX0xmXn2', 1, NULL,    '9000000001'),
  ('Rahul Receptionist', 'reception@society.com', '$2y$10$u3a2oXZmWelzNswi1MsLf.v0EaGJPG6iKCM7eDq3q7LHmUX0xmXn2', 2, NULL,    '9000000002'),
  ('Guard Kumar',        'guard@society.com',     '$2y$10$u3a2oXZmWelzNswi1MsLf.v0EaGJPG6iKCM7eDq3q7LHmUX0xmXn2', 3, NULL,    '9000000003'),
  ('Priya Sharma',       'host@society.com',      '$2y$10$u3a2oXZmWelzNswi1MsLf.v0EaGJPG6iKCM7eDq3q7LHmUX0xmXn2', 4, 'A-201', '9000000004'),
  ('Arjun Mehta',        'arjun@society.com',     '$2y$10$u3a2oXZmWelzNswi1MsLf.v0EaGJPG6iKCM7eDq3q7LHmUX0xmXn2', 4, 'B-304', '9000000005');
-- password123 for all demo accounts

-- VISITORS
CREATE TABLE visitors (
    id              SERIAL PRIMARY KEY,
    full_name       VARCHAR(120) NOT NULL,
    phone           VARCHAR(20),
    photo           VARCHAR(255),
    id_proof_type   VARCHAR(60),
    id_proof_file   VARCHAR(255),
    purpose         VARCHAR(120),
    host_id         INT REFERENCES users(id) ON DELETE SET NULL,
    flat_number     VARCHAR(20),
    vehicle_number  VARCHAR(20),
    qr_token        VARCHAR(60) UNIQUE NOT NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'active',
    is_blacklisted  BOOLEAN NOT NULL DEFAULT FALSE,
    blacklist_reason TEXT,
    blacklisted_by  INT REFERENCES users(id) ON DELETE SET NULL,
    blacklisted_at  TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- VISITS
CREATE TABLE visits (
    id                 SERIAL PRIMARY KEY,
    visitor_id         INT NOT NULL REFERENCES visitors(id) ON DELETE CASCADE,
    host_id            INT REFERENCES users(id) ON DELETE SET NULL,
    flat_number        VARCHAR(20),
    check_in           TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    check_out          TIMESTAMPTZ,
    guard_checkin_id   INT REFERENCES users(id) ON DELETE SET NULL,
    guard_checkout_id  INT REFERENCES users(id) ON DELETE SET NULL,
    parking_slot_id    INT,
    overstay_flagged   BOOLEAN NOT NULL DEFAULT FALSE,
    notes              TEXT,
    status             VARCHAR(20) NOT NULL DEFAULT 'inside'
);

-- APPOINTMENTS
CREATE TABLE appointments (
    id             SERIAL PRIMARY KEY,
    visitor_name   VARCHAR(120) NOT NULL,
    visitor_phone  VARCHAR(20),
    host_id        INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    flat_number    VARCHAR(20),
    expected_date  DATE NOT NULL,
    expected_time  TIME NOT NULL,
    purpose        VARCHAR(120),
    qr_token       VARCHAR(60) UNIQUE NOT NULL,
    status         VARCHAR(20) NOT NULL DEFAULT 'pending',
    notes          TEXT,
    created_by     INT REFERENCES users(id) ON DELETE SET NULL,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- PARKING SLOTS
CREATE TABLE parking_slots (
    id          SERIAL PRIMARY KEY,
    slot_number VARCHAR(15) NOT NULL UNIQUE,
    slot_type   VARCHAR(20) NOT NULL DEFAULT '4-wheeler',
    is_occupied BOOLEAN NOT NULL DEFAULT FALSE,
    visit_id    INT REFERENCES visits(id) ON DELETE SET NULL,
    assigned_at TIMESTAMPTZ
);

-- Seed slots (configurable count via settings)
INSERT INTO parking_slots (slot_number,slot_type)
SELECT 'P-' || LPAD(gs::TEXT,2,'0'), '4-wheeler' FROM generate_series(1,15) gs
UNION ALL
SELECT 'B-' || LPAD(gs::TEXT,2,'0'), '2-wheeler' FROM generate_series(1,15) gs;

ALTER TABLE visits ADD CONSTRAINT fk_visits_parking FOREIGN KEY (parking_slot_id) REFERENCES parking_slots(id) ON DELETE SET NULL;

-- NOTIFICATIONS
CREATE TABLE notifications (
    id         SERIAL PRIMARY KEY,
    user_id    INT REFERENCES users(id) ON DELETE CASCADE,
    type       VARCHAR(60),
    message    TEXT,
    ref_id     INT DEFAULT 0,
    is_read    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- SETTINGS (single-row, all dynamic)
CREATE TABLE settings (
    id                  SERIAL PRIMARY KEY,
    org_name            VARCHAR(120) NOT NULL DEFAULT 'ResidentGuard Society',
    org_address         TEXT,
    org_phone           VARCHAR(20),
    org_email           VARCHAR(120),
    logo                VARCHAR(255),
    max_visit_hours     INT NOT NULL DEFAULT 4,
    allowed_purposes    TEXT NOT NULL DEFAULT 'Family/Friend Visit,Delivery,Maintenance/Repair,Domestic Help,Business/Official,Medical,Guest,Other',
    allowed_id_types    TEXT NOT NULL DEFAULT 'Aadhaar Card,PAN Card,Driving License,Voter ID,Passport',
    smtp_host           VARCHAR(120),
    smtp_port           INT DEFAULT 587,
    smtp_user           VARCHAR(120),
    smtp_pass           VARCHAR(255),
    smtp_from_name      VARCHAR(120),
    allow_self_checkin  BOOLEAN NOT NULL DEFAULT FALSE,
    require_photo       BOOLEAN NOT NULL DEFAULT FALSE,
    require_id_proof    BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at          TIMESTAMPTZ DEFAULT NOW()
);
INSERT INTO settings (org_name, org_address, org_phone, org_email, max_visit_hours)
VALUES ('Sunshine Residency', '123 Society Road, Baner, Pune 411045', '+91 20 1234 5678', 'admin@sunshinechs.com', 4);

-- VISIT PURPOSE TRACKER (dynamic reporting)
CREATE TABLE visit_purpose_types (
    id      SERIAL PRIMARY KEY,
    label   VARCHAR(80) NOT NULL UNIQUE,
    sort    INT DEFAULT 0
);

-- INDEXES
CREATE INDEX idx_visits_checkin   ON visits(check_in);
CREATE INDEX idx_visits_status    ON visits(status);
CREATE INDEX idx_visits_visitor   ON visits(visitor_id);
CREATE INDEX idx_visitors_phone   ON visitors(phone);
CREATE INDEX idx_visitors_qr      ON visitors(qr_token);
CREATE INDEX idx_appointments_date ON appointments(expected_date);
CREATE INDEX idx_appointments_qr  ON appointments(qr_token);
CREATE INDEX idx_notif_user       ON notifications(user_id,is_read);
CREATE INDEX idx_notif_created    ON notifications(created_at DESC);
