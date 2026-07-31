<div align="center">

# 📦 SRIMS
### Stationery Requisition & Inventory Management System

**A full-stack PHP + MySQL system for requesting, approving, issuing, and tracking office stationery — end to end.**

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-CDN-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)](https://tailwindcss.com/)
[![License](https://img.shields.io/badge/License-Proprietary-lightgrey?style=flat-square)]()
[![Status](https://img.shields.io/badge/Status-Active-success?style=flat-square)]()

[Features](#-features) •
[Screenshots](#-screenshots) •
[Getting Started](#-getting-started) •
[Project Structure](#-project-structure) •
[Roles](#-roles--permissions) •
[Tech Stack](#-tech-stack)

</div>

---

## 📖 About

SRIMS replaces the paper trail of office stationery requests with a single, role-aware
web app. Employees raise requisitions from a live item catalog, approvers review and
sign off (or auto-approve low-priority requests), inventory managers issue stock and
watch levels in real time, and admins get a full audit trail of who did what, when.

Everything you see — dashboards, charts, icons, colors, and workflows — runs on plain
**PHP + PDO + MySQL**, no framework, no build step. Clone it, point it at a database, go.

---

## ✨ Features

<table>
<tr>
<td width="33%" valign="top">

### 📝 Requisitions
- Browse the item catalog by category
- Cart-based multi-item requests
- Draft, save, and resume later
- Priority levels (Low / Normal / Urgent)
- Auto-approval rules by priority

</td>
<td width="33%" valign="top">

### ✅ Approvals
- Live pending queue with filters
- Approve, Reject, or **Approve & Issue** in one step
- Partial-issue support with reasons
- Send-back-to-draft workflow
- Full rejection reason trail

</td>
<td width="33%" valign="top">

### 📦 Inventory
- Real-time stock overview & valuation
- Stock Inward via GRN (goods receipt)
- Manual Outward & Adjustments
- Low-stock / critical alerts
- Complete transaction ledger

</td>
</tr>
<tr>
<td width="33%" valign="top">

### 🚚 Issuance
- Issue Queue for approved requests
- Guided issue wizard
- Auto stock deduction
- Issued history with references

</td>
<td width="33%" valign="top">

### 🗂️ Masters
- Categories, Items, Departments
- Suppliers & Auto-Approval Rules
- User & Role management
- Role-permission matrix

</td>
<td width="33%" valign="top">

### 📊 Reports & System
- Inventory / requisition / audit reports
- Requisition analytics dashboard
- CSV export everywhere
- Full audit log, profile, settings

</td>
</tr>
</table>

---

## 🖼️ Screenshots

<div align="center">
<table>
<tr>
<td align="center" width="50%">
<img src="docs/screenshots/dashboard.png" alt="Dashboard" width="100%"><br>
<sub><b>Dashboard</b> — live stats, trend chart, low-stock alerts</sub>
</td>
<td align="center" width="50%">
<img src="docs/screenshots/approvals.png" alt="Approvals" width="100%"><br>
<sub><b>Pending Approvals</b> — approve, reject, or approve & issue</sub>
</td>
</tr>
<tr>
<td align="center" width="50%">
<img src="docs/screenshots/inventory.png" alt="Inventory" width="100%"><br>
<sub><b>Inventory Overview</b> — stock levels & valuation</sub>
</td>
<td align="center" width="50%">
<img src="docs/screenshots/new-requisition.png" alt="New Requisition" width="100%"><br>
<sub><b>New Requisition</b> — catalog, cart, and checkout</sub>
</td>
</tr>
</table>

<sub>Drop your own screenshots into <code>docs/screenshots/</code> with these filenames and they'll show up here.</sub>
</div>

---

## 🚀 Getting Started

### Prerequisites

- PHP **8.0+** with the `pdo_mysql` extension
- MySQL **5.7+** / MariaDB equivalent
- Apache, Nginx, or PHP's built-in server

### Installation

```bash
# 1. Clone the repo
git clone https://github.com/<your-org>/srims-php.git
cd srims-php

# 2. Create a database and import the schema + seed data
mysql -u root -p -e "CREATE DATABASE srims"
mysql -u root -p srims < install.sql

# 3. Configure your database connection
cp dbcon.php.example dbcon.php   # or edit dbcon.php directly
```

```php
// dbcon.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'srims');
define('DB_USER', 'root');
define('DB_PASS', '');
```

```bash
# 4. Serve it
php -S localhost:8000
# → visit http://localhost:8000/login.php
```

### 🔑 Demo Credentials

| Role | Email | Password |
|---|---|---|
| 👑 Admin | `rahul@srims.com` | `Admin@123` |
| 🙋 Employee | `priya@srims.com` | `User@123` |
| ✅ Approver | `amit@srims.com` | `Approver@123` |
| 📦 Inventory Manager | `sandeep@srims.com` | `Inventory@123` |

> **Deploying to shared hosting / XAMPP / WAMP?** Just drop the project into your
> web root (e.g. `htdocs/srims`) — no Composer, no `npm install`, no build step.

---

## 🗂️ Project Structure

```
srims/
├── dbcon.php               # Database connection (edit this)
├── install.sql             # Schema + seed data
├── config.php               # Bootstrap: session, DB, helpers
├── index.php / login.php    # Entry points & auth
│
├── includes/                 # Shared layout, auth, helper functions
│   ├── header.php · footer.php
│   ├── sidebar.php · topbar.php
│   └── functions.php
│
├── requisitions/             # New · My Requisitions · Drafts
├── approvals/                # Pending · Approved · Rejected
├── inventory/                 # Overview · Inward · Outward · Adjust · Transactions · Low Stock
├── issue/                     # Queue · Issue Items · History
├── masters/                   # Categories · Items · Departments · Suppliers · Users · Roles
├── reports/                   # Reports & Requisition Analytics
├── system/                    # Profile · Settings · Audit Logs · Help
│
├── api/                       # Small AJAX endpoints (toggles, notifications)
└── assets/                    # CSS, fonts
```

---

## 👥 Roles & Permissions

| Capability | Admin | Employee | Approver | Inventory Mgr |
|---|:---:|:---:|:---:|:---:|
| Raise requisitions | ✅ | ✅ | ✅ | — |
| View all requisitions | ✅ | own only | — | — |
| Approve / reject | ✅ | — | ✅ | — |
| Issue stock | ✅ | — | — | ✅ |
| Manage inventory | ✅ | — | — | ✅ |
| Manage users | ✅ | — | — | — |
| View audit logs | ✅ | — | — | — |

---

## 🛠️ Tech Stack

| Layer | Choice |
|---|---|
| Language | PHP 8 (procedural, PDO) |
| Database | MySQL / MariaDB |
| Styling | Tailwind CSS (CDN, custom design tokens) |
| Icons | [Lucide](https://lucide.dev/) |
| Charts | [Chart.js](https://www.chartjs.org/) |
| Auth | PHP sessions + `password_hash()` (bcrypt) |
| Fonts | Inter |

No Composer, no Node, no build pipeline — just PHP files and a database.

---

## 🗺️ Roadmap

- [ ] PDF export for issuance vouchers and reports
- [ ] Email notifications (SMTP integration)
- [ ] REST API layer for mobile clients
- [ ] Multi-language support

---

## 📄 License

This project is proprietary / internal software. All rights reserved.

<div align="center">
<sub>Built with ☕ and a lot of stationery requisitions.</sub>
</div>
