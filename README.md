# ResidentGuard — Visitor Management System
## Residential Society / Apartment Edition

---

## 📋 Tech Stack
- **Frontend:** HTML5, CSS3, Vanilla JavaScript
- **Backend:** PHP 8.1+
- **Database:** PostgreSQL 13+
- **Libraries:** Chart.js 4, Html5-QrCode, QRCodeJS, Google Fonts

---

## 🚀 Installation

### 1. Requirements
- PHP 8.1+ with `pdo_pgsql` extension
- PostgreSQL 13+
- Apache / Nginx web server

### 2. Database Setup
```sql
-- Create database
CREATE DATABASE visitor_management;

-- Connect and run schema
\c visitor_management
\i config/schema.sql
```

### 3. Configure DB Connection
Edit `config/db.php` — update these constants OR set environment variables:
```php
define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'visitor_management');
define('DB_USER', 'postgres');
define('DB_PASS', 'your_password');
```

Or set ENV vars: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`

### 4. File Permissions
```bash
chmod 755 assets/uploads/
chmod 755 assets/
```

### 5. Virtual Host (Apache)
```apache
<VirtualHost *:80>
    DocumentRoot /var/www/visitor-management
    DirectoryIndex index.php
    <Directory /var/www/visitor-management>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 6. .htaccess (project root)
```apache
Options -Indexes
<FilesMatch "\.(sql|log|env)$">
    Require all denied
</FilesMatch>
```

---

## 🔑 Demo Login Credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@society.com | password123 |
| Receptionist | reception@society.com | password123 |
| Guard | guard@society.com | password123 |
| Host/Resident | host@society.com | password123 |

> ⚠️ Change all passwords immediately after first login via Settings > User Management.

---

## 📁 Project Structure
```
visitor-management/
├── index.php                         # Login page
├── dashboard.php                     # Main dashboard
├── config/
│   ├── db.php                        # DB config + ALL helper functions
│   └── schema.sql                    # PostgreSQL schema + seed data
├── auth/
│   ├── login.php                     # Login handler
│   └── logout.php                    # Logout handler
├── modules/
│   ├── visitors/
│   │   ├── register.php              # Register visitor + check-in
│   │   ├── checkin.php               # QR scan check-in/out
│   │   ├── history.php               # Visitor history + search
│   │   └── blacklist.php             # Blacklist management
│   ├── preregistration/
│   │   └── appointments.php          # Pre-registration / appointments
│   ├── badge/
│   │   └── print_badge.php           # Badge printing with QR
│   ├── reports/
│   │   ├── analytics.php             # Charts & analytics
│   │   └── export.php                # CSV export
│   ├── parking/
│   │   └── parking.php               # Parking slot management
│   ├── users/
│   │   └── manage_users.php          # User CRUD
│   └── settings/
│       └── settings.php              # System configuration
├── api/
│   ├── visitor_api.php               # AJAX visitor endpoints
│   └── notifications.php             # AJAX notification endpoints
└── assets/
    ├── css/style.css                 # Global stylesheet
    └── uploads/                      # Visitor photos + ID proofs
```

---

## ✅ 20 Features Implemented

| # | Feature | File |
|---|---------|------|
| 1 | Role-based login (Admin/Receptionist/Guard/Host) | `auth/login.php` |
| 2 | Full visitor registration with photo & ID upload | `modules/visitors/register.php` |
| 3 | Pre-registration & appointments | `modules/preregistration/appointments.php` |
| 4 | QR code pass generation (auto on register) | `config/db.php` → `genToken()` |
| 5 | Check-in / Check-out with timestamps | `modules/visitors/checkin.php` |
| 6 | QR code scanner (html5-qrcode) | `modules/visitors/checkin.php` |
| 7 | Live dashboard with auto-refresh | `dashboard.php` |
| 8 | Webcam photo capture | `modules/visitors/register.php` |
| 9 | Government ID upload & storage | `modules/visitors/register.php` |
| 10 | Host email notification system | `config/db.php` → `notifyUser()` |
| 11 | Visitor badge / pass printing | `modules/badge/print_badge.php` |
| 12 | Blacklist management | `modules/visitors/blacklist.php` |
| 13 | Overstay alerts (auto-flag) | `config/db.php` → `checkOverstays()` |
| 14 | Search & filter visitors | `modules/visitors/history.php` |
| 15 | Full visit history & audit trail | `modules/visitors/history.php` |
| 16 | Analytics with interactive charts | `modules/reports/analytics.php` |
| 17 | CSV export (visits/appointments/blacklist) | `modules/reports/export.php` |
| 18 | User management (CRUD + password reset) | `modules/users/manage_users.php` |
| 19 | Dynamic settings & configuration | `modules/settings/settings.php` |
| 20 | Parking slot assignment & tracking | `modules/parking/parking.php` |

---

## 🔒 Security Notes
- All queries use PDO prepared statements (SQL injection proof)
- Passwords stored as bcrypt hashes
- Session regenerated on login
- Role-based access enforced on every page
- Inactive users cannot log in
- File uploads restricted by extension whitelist

---

## 📞 Support
Configure org contact details via **Settings** page after login.
