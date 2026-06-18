# Lead8X — Lead Management & Distribution Platform

**Version:** 1.0.0 | **Domain:** www.digital8x.site

## 🚀 Tech Stack
- **Frontend:** React 18 + Vite + Vanilla CSS (Dark Mode)
- **Backend:** PHP 8.0+ REST API
- **Database:** MySQL (InnoDB) - `a1679hju_leadpro`
- **Auth:** JWT (firebase/php-jwt)
- **Excel:** PhpSpreadsheet

## 📁 Project Structure
```
Leadbees/
├── .env                    # DB + JWT config
├── .htaccess               # SPA routing + API proxy
├── setup.php               # First-run DB installer
├── composer.json           # PHP dependencies
├── database/schema.sql     # MySQL schema
├── backend/
│   ├── config/database.php
│   ├── core/Auth.php       # JWT + RBAC
│   ├── core/ExcelHandler.php
│   ├── core/DuplicateDetector.php
│   ├── utils/Response.php
│   └── api/
│       ├── auth/login.php
│       ├── leads/upload.php, list.php, download.php, feedback.php, timeline.php
│       ├── distribution/distribute.php
│       ├── users/list.php, create.php, update.php, delete.php
│       └── admin/stats.php, activity-log.php, backup.php
└── frontend/
    ├── index.html
    ├── vite.config.js
    ├── package.json
    └── src/
        ├── main.jsx
        ├── App.jsx
        ├── index.css
        ├── api/axios.js
        ├── pages/Login, Dashboard, Leads, Distribution, Users, Admin
        └── components/Sidebar.jsx
```

## 👤 Default Admin
- **Email:** admin@digital8x.site
- **Password:** Admin@Lead8X

> ⚠️ Change password immediately after first login.

## 📖 Deployment
See [DEPLOYMENT.md](./DEPLOYMENT.md) for full server setup instructions.
