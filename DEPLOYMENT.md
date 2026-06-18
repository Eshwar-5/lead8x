# Lead8X Platform — Deployment Guide

## Server Details
- **Server IP:** 66.116.226.193
- **cPanel User:** a1679hju
- **Database:** a1679hju_leadpro
- **DB User:** a1679hju_leaduser
- **Domain:** www.digital8x.site
- **Root Path:** /home/a1679hju/public_html/digital8x.site

---

## Step 1 — Build the React Frontend (on your local PC)

You need **Node.js** installed. Download from https://nodejs.org (LTS version).

After installing Node.js, open PowerShell and run:

```powershell
cd c:\Leadbees\frontend
npm install
npm run build
```

This produces a `c:\Leadbees\dist\` folder — the compiled frontend.

---

## Step 2 — Install Composer Dependencies (on your local PC)

Download and install **Composer** from https://getcomposer.org/download/

Then run:
```powershell
cd c:\Leadbees
composer install --no-dev --optimize-autoloader
```

---

## Step 3 — Upload Files to BigRock Server

### Files to Upload (via cPanel File Manager or FTP):

Upload all of the following into `/home/a1679hju/public_html/digital8x.site/`:

| Local Path | Upload to Server |
|---|---|
| `c:\Leadbees\dist\*` | `/home/a1679hju/public_html/digital8x.site/` (root) |
| `c:\Leadbees\.htaccess` | `/home/a1679hju/public_html/digital8x.site/` |
| `c:\Leadbees\.env` | `/home/a1679hju/public_html/digital8x.site/` |
| `c:\Leadbees\setup.php` | `/home/a1679hju/public_html/digital8x.site/` |
| `c:\Leadbees\backend\` | `/home/a1679hju/public_html/digital8x.site/backend/` |
| `c:\Leadbees\vendor\` | `/home/a1679hju/public_html/digital8x.site/vendor/` |
| `c:\Leadbees\database\schema.sql` | (just keep locally — run from cPanel phpMyAdmin) |

> ⚠️ **Do NOT upload** `frontend/` or `node_modules/` — only upload the built `dist/` contents.

---

## Step 4 — Create the MySQL Database (in BigRock cPanel)

1. Login to BigRock cPanel → **MySQL Databases**
2. The database `a1679hju_leadpro` and user `a1679hju_leaduser` should already exist from your earlier setup.
   - If not, create them and grant ALL PRIVILEGES.
3. Go to **phpMyAdmin** → select `a1679hju_leadpro`
4. Click **Import** → upload `database/schema.sql`
5. Click **Go** — all tables will be created.

---

## Step 5 — Run the Setup Script

Open in your browser:

```
https://www.digital8x.site/setup?token=L3adBees_Setup_T0k3n_2024
```

- Click **"Run Database Setup"**
- You will see: ✅ Database schema installed successfully!

---

## Step 6 — DISABLE Setup (Important!)

Edit `.env` on the server and change:
```
SETUP_ENABLED=false
```

---

## Step 7 — Login

Go to: **https://www.digital8x.site**

- **Email:** `admin@digital8x.site`  
- **Password:** `Admin@Lead8X`

> Change the admin password immediately after first login.

---

## Directory Structure on Server

```
/home/a1679hju/public_html/digital8x.site/
├── index.html          ← React app entry (from dist/)
├── assets/             ← JS/CSS bundles (from dist/)
├── .htaccess           ← SPA routing + API proxy
├── .env                ← DB credentials (keep secret!)
├── setup.php           ← First-run setup script
├── backend/
│   ├── api/auth/
│   ├── api/leads/
│   ├── api/distribution/
│   ├── api/users/
│   ├── api/admin/
│   ├── config/
│   ├── core/
│   └── utils/
└── vendor/             ← Composer packages (JWT, PhpSpreadsheet)
```

---

## Troubleshooting

| Problem | Fix |
|---|---|
| White screen / 404 on refresh | Check `.htaccess` is uploaded and mod_rewrite is enabled |
| API returns 500 | Check `backend/config/database.php` DB credentials |
| Excel upload fails | Increase `upload_max_filesize` in php.ini or .htaccess |
| JWT token error | Ensure `JWT_SECRET` in `.env` matches on server |
| Composer vendor missing | Run `composer install` locally and re-upload `vendor/` |
