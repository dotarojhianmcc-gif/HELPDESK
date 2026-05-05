# Source Files

This file lists the main source code files for the HelpDesk system.

- `index.php` — Login page and authentication logic.
- `db.php` — Database connection, session handling, and topic utilities.
- `setup.php` — Create database tables, default topics, and default accounts.
- `update-schema.sql` — Full database schema for tables and seed data.
- `import-schema.php` — Import SQL schema from `update-schema.sql` via PHP.
- `admin.php` — Admin dashboard with document management, topic management, and user management.
- `user.php` — Customer dashboard with document submission, document list, and notifications.
- `upload.php` — Handles file uploads and document creation.
- `approve_file.php` — Admin endpoint to approve or close documents.
- `delete_file.php` — Delete documents by user or admin.
- `delete_user.php` — Admin endpoint to remove a customer account.
- `topics.php` — Admin endpoint for managing document topics.
- `download.php` — Secure document download endpoint for admin and user access.
- `mark_notifications_read.php` — Endpoint to mark all user notifications as read.
- `auth.css` — Styling for the login page.
- `style.css` — Main application styling for admin and user dashboards.
- `script.js` — Frontend JavaScript for navigation, uploads, document actions, and topic management.
- `topics.json` — Topic fallback storage when the database is unavailable.
- `README.md` — Project overview and instructions.
- `inspect-db.php` — Simple diagnostic script showing current database tables.
- `test-db.php` — Database connection test script.
