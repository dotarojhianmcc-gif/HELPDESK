# helpdesk-system

Support HelpDesk Management System

## Setup Instructions

1. Place this folder in your Apache web root or copy files to your server document root.
2. Start Apache and MySQL from XAMPP.
3. Open `setup.php` in your browser or run it from the command line:
   - Browser: `http://localhost/helpdesk/setup.php` (if the folder is in `htdocs/helpdesk`)
   - CLI: `php setup.php`
4. Open `http://localhost/helpdesk/index.php` to log in, or use the built-in server URL below.

## Default Accounts

- Admin: `admin` / `admin123`
- Customer: `user` / `user123`

## Source Files

See `SOURCE_FILES.md` for the full source file list and purpose.

## Notes

- The app uses MySQL and PDO.
- Topics are stored in the database and managed from the admin dashboard.
- Uploads are saved to the `uploads/` folder.

## Run without XAMPP

If you prefer not to use XAMPP for Apache, you can use PHP built-in server from the `htdocs/helpdesk` folder:

```powershell
cd "c:\xampp\htdocs\helpdesk"
"c:\xampp\php\php.exe\php\php.exe" -S localhost:8000
```

Then open `http://localhost:8000/index.php`.

If you use Apache and the folder is inside `htdocs`, go to `http://localhost/helpdesk%20system/index.php` instead.

> MySQL still needs to be running separately for the app to work.

See `ACCESS_LINKS.md` for the direct URL list.
