# FileVault — File Sharing Website

A complete file sharing platform built with pure **PHP 8.2+**, **MySQL (PDO)**,
**HTML5**, **CSS3**, **vanilla JavaScript**, **Font Awesome**, and
**qr-code-styling**. No frameworks, no build tools, no npm, no Composer.

Runs unchanged on **InfinityFree** and **XAMPP (localhost)**.

---

## Quick start

### On XAMPP (localhost)

1. Copy the project folder into `htdocs/` (e.g. `htdocs/filevault/`).
2. Open `http://localhost/phpmyadmin` and create a database named `filevault`.
3. Edit `includes/config.php` — set `$DB_NAME`, `$DB_USER`, `$DB_PASS`
   (XAMPP defaults: user `root`, password empty).
4. Visit `http://localhost/filevault/install.php` to create the tables and
   seed the default admin.
5. Open `http://localhost/filevault/` — start uploading.
6. **Delete `install.php`** after setup.

### On InfinityFree

1. Upload all files via the File Manager or FTP into `htdocs/`.
2. In the control panel, create a MySQL database and note the **database
   name**, **username**, **password**, and **hostname** (not `localhost`).
3. Edit `includes/config.php` with those four values.
4. Import `sql/schema.sql` through phpMyAdmin, **or** visit
   `install.php` once (then delete it).
5. The site is live at your InfinityFree domain.

---

## Default admin

- URL: `/admin.php`  (there is **no visible admin button** anywhere)
- Login: `/login.php`
- Username: `admin`
- Password: `admin123`

**Change the password immediately** from the admin panel → Account tab.

---

## Features

- Drag & drop + file picker, **multiple files**, **any extension**
- Chunked uploads with live **progress, speed, and percentage**
- Random stored filenames (original name kept in the database)
- Package name + optional expiration
- Unique shareable download code
- Download page with **QR code** (scannable on iOS / Android / Google Lens),
  copy link, copy code, share button
- **Download all as ZIP** or download individual files
- Search page — enter a code, redirect or friendly "not found" error
- Admin panel: dashboard, stats, package & file management, site settings,
  maintenance mode, log viewer, password change
- Security: `password_hash`, CSRF tokens, prepared statements, XSS escaping,
  path-traversal protection, forced-download headers on uploads,
  `php_flag engine off` in the uploads folder

---

## Folders

```
/                     index, upload, download, search, admin, login, logout, install
/includes/            config, db, helpers, auth, zip, header, footer
/assets/css/          style.css
/assets/js/           app.js
/assets/img/          favicon.svg
/uploads/             stored files (protected by .htaccess)
/temp/                chunk staging + logs
/sql/                 schema.sql
```

---

## QR code library

Uses [qr-code-styling](https://github.com/kozakdenya/qr-code-styling) v1.6
via unpkg CDN. Generates black-on-white SVG QR codes with **error correction
level H** (~30% recovery) so they scan reliably on all phones.
