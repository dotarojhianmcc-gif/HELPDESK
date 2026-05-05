# Features & Changelog

## Latest Updates (2026-04-13)

### 📄 File Viewing and Access
- Added broad file preview support in `view_file.php`.
- Added preview handling for:
  - `.docx` (text extraction)
  - `.xlsx` (sheet table preview)
  - text-like files (`.txt`, `.csv`, `.log`, `.json`, etc.)
  - legacy office files (`.doc`, `.xls`) with fallback text extraction
  - `.zip` archive file listing
- Fixed preview warning issue (`preg_replace(): Null byte in regex`) in `view_file.php`.

### 📬 Notifications and Role Routing
- Updated notification routing so users and admins do not notify themselves.
- Enforced cross-role notifications:
  - admin uploads notify users
  - user uploads notify admins
- Updated notification labels/messages for clearer source and target context.
- Added direct action links for notifications:
  - `View`
  - `Download`
- Notification dropdown items now open related documents when matched.

### 👤 User Dashboard Improvements
- User notice/update sections now include direct file actions.
- Recent updates and notification cards can open or download related documents.
- Existing status tracking and banner behavior remain active.

### 🛠️ Admin Dashboard Improvements
- Admin notification dropdown now includes actionable file links (`View`, `Download`).
- Dashboard side card button text changed from `Check Archive` to `View All`.
- `View All` now opens a popup modal listing all files (instead of jumping to Archive).
- Added modal features:
  - searchable list
  - status badges
  - direct View/Download actions

### 🧩 View-All Popup Stability Fixes
- Reworked modal visibility logic to class-based toggling (`va-modal-hidden`) for reliable interaction.
- Added cache-busting query parameters to assets in `admin.php`:
  - `style.css?v=filemtime(...)`
  - `script.js?v=filemtime(...)`
- This ensures latest JS/CSS is loaded after updates.

### 🗂️ Sections UI Note
- Screenshot-style sections redesign was reverted.
- Current sections management is back to the previous layout:
  - `Add Main Section`
  - `Add Inside Section`

---

## Core Features (Still Active)

### User Side
- Upload documents to assigned sections/topics.
- Track document status (Pending, Approved, Rejected/Disapproved, Archived).
- Receive in-app notices when admins take action.
- View/download own documents and admin-shared documents.

### Admin Side
- Review pending uploads.
- Approve, reject/disapprove, and archive documents.
- View all files, users, and tracking history.
- Use filters/search in tracking and listing pages.

### Tracking and Audit
- Stores upload and review history with timestamps.
- Tracks acting admin (`approved_by`) and rejection reason notes.

---

## Status Legend

| Status | Meaning |
|--------|---------|
| pending | Waiting for admin review |
| approved | Accepted by admin |
| rejected / disapproved | Declined with reason |
| archived | Moved to archive state |

---

## Database Notes

### `files` table (key fields)
- `id`, `user_id`
- `file_name`, `file_path`, `file_size`, `file_type`
- `category`, `status`
- `uploaded_at`, `approved_at`, `approved_by`
- `rejection_reason`

### `notifications` table (key fields)
- `id`, `user_id`
- `message`, `type`
- `is_read`, `created_at`

---

## URLs

```
http://localhost/helpdesk/index.php
http://localhost/helpdesk/admin.php
http://localhost/helpdesk/user.php
```
