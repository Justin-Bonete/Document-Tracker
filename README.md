# DTS Phase 2: Document Registry

Adds: document types, server-generated tracking numbers (DTS-2026-0001042), register / list+search /
detail / update / void, private file upload with SHA-256 + versioning, authorized download,
per-document history, idempotent creation, and one central visibility rule (`Document::visibleTo`).

## Install (from C:\dev\document-tracker)
1. Copy this folder's contents over the project (app, config, database, routes). Say YES to replacing
   `routes/api.php`, `AuditLog.php` and `RbacSeeder.php`. Phase 1 routes are included in the new file.
2. `bootstrap/app.php`:
   - in `withMiddleware`, add to the existing alias array:
     `'idempotent' => \App\Http\Middleware\Idempotency::class,`
   - replace the `withExceptions` closure body so it reads:
     `->withExceptions(function (Exceptions $exceptions) { \App\Support\ApiExceptions::register($exceptions); })`
3. Run:
   ```
   php artisan migrate
   php artisan db:seed --class=RbacSeeder
   php artisan serve
   ```
   The seeder (env must be `local`, which is the default `.env`) creates `records@example.com`
   with the same password as `DTS_ADMIN_PASSWORD`. The admin cannot register documents on purpose
   (admins don't get content access by default).
4. In a second PowerShell window: `powershell -ExecutionPolicy Bypass -File .\phase2-test.ps1`

## Notes
- Files live in `storage/app/private/documents/<doc id>/<uuid>`: not web-accessible.
- Allowed uploads: pdf, docx, xlsx, png, jpg, jpeg, txt (max 20 MB, change in `.env` with DTS_UPLOAD_MAX_KB).
  PHP's own `upload_max_filesize` / `post_max_size` in `C:\xampp\php\php.ini` must be at least as large.
- Until Phase 4 adds a scanner, files stay `pending_scan` and download is allowed only while `DTS_MALWARE_SCAN=false`.
- Out-of-scope documents return 404 (not 403) so IDs cannot be probed.
