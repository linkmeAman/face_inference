# Batches, members and attendance: operator handover

This guide is for the person who creates batches, adds members and takes attendance
in the CCC admin panel. Every rule below is checked by the automated suite in
`tests/batch-attendance/` (scenario ids are given in brackets), so if the suite passes,
the behaviour described here is what the system actually does.

## How the pieces fit

- A **batch** is a class group: a name, a category, a branch, a start and end date,
  one or more **trainers**, and weekly **timings** (day + start/end time).
- A **member** is attached to a batch only through a **membership line on an invoice**
  (the invoice item). That line holds the batch, the validity dates and the number of
  classes (`0` = unlimited). There is no separate "add member to batch" screen.
- **Attendance** is taken per batch, per class date (and per session number when a
  batch meets more than once a day). Marking a member present uses one class from
  their membership.

## Before you start (one-time setup, done by an administrator)

1. Your login needs, in your branch: **Batch** write (create/edit batches), **Contact**,
   **Member** and **Invoice** write (add members), **Attendance** write (take attendance)
   and **Attendance** delete (trash/restore attendance). A read-only login can see
   batches but cannot create them or punch attendance [AUTH-02, AUTH-03, AUTH-04].
2. The category and its membership packages (services) must exist.
3. Branch attendance setting **Deduct session for absentee**: off = an absent member
   keeps the class; on = an absent member also uses a class [ATT-05].
4. Branch batch setting **Check trainer double booking** (`check_emp_dup`): only when it
   is on does the batch form warn that a trainer already teaches at that time [BATCH-09].
5. A category may have a syllabus (modules with master sessions). Then the batch end date
   is generated from the number of master sessions; its members still appear on the
   attendance list, once each [MEM-03b]. Production categories currently have none.

## 1. Create a batch

Batches page (`pf/batch.php`) > New batch.

1. Choose the **category** first, then the start date and seat limit. The batch name and
   display name are built automatically from category, branch, first day and time and
   venue; only senior logins (position 6 or above) can edit the display name.
2. Add at least one **trainer** and the weekly **timings** (day, start, end; end must be
   after start). Both are required; the server also refuses a batch without them
   [BATCH-14]. The trainer list shows employees in trainer positions only.
3. Fill in the rate multiplier and the WhatsApp group link (both required by the form).
4. End date: it is filled in automatically. For a category without a syllabus it becomes
   31 December, fifty years ahead; that is expected [BATCH-01]. For a syllabus category it
   is the date of the last master session, skipping holidays [BATCH-15], and the batch is
   refused if the dates do not cover exactly that many classes [BATCH-03].
5. Save, then Confirm. Refused: the same name in the same category and branch
   [BATCH-04, BATCH-05]. A **display name** already used in that category and branch only
   shows an orange warning (parallel classes at the same day and time share one); you can
   still save. Names may contain apostrophes and quotes [BATCH-12].
6. If the branch setting *check trainer double booking* is on, the form warns when a
   trainer already teaches at that time [BATCH-09].

The new batch appears on the attendance page on each of its weekdays between its start and
end dates. Demo and training batches never appear there [BATCH-13].

Editing: you can change the name, dates, limit, trainers and timings [BATCH-07].
The **category** can be corrected while the batch has no members, attendance or
rescheduled classes [BATCH-08]. After that the save is refused with *This batch already has
members or attendance...* and nothing is changed [BATCH-08c]; create a new batch in the
new category and transfer the members. Changing the category recalculates the name and
end date, so check both before confirming.

Deleting: use **Trash** in the batch list; **Restore** brings it back [BATCH-10]. A trashed
batch takes no attendance; move its members to another batch first [LIFE-06].

### Reschedule a single class

From the attendance page, **Reschedule** a date: pick the new date and time. This creates
a one-day batch linked to the original (its members, trainers and memberships carry over)
and marks the original date as shifted [LIFE-01]. Take attendance on the rescheduled batch;
classes are used from the members' normal memberships. The same class cannot be
rescheduled twice to the same date [LIFE-02]. The warning *Time slot is busy. Do you want
to continue?* means another batch of your branch runs at that time on the new date
(the original batch counts too if it meets that day) [BATCH-16]; continue only if the
studio and trainer are free.

### Class number and level

Each batch keeps a class number (completed classes). It does not move by itself: after
taking a **past** date's attendance the page offers **Forward class**. Forwarding needs the
trainer's attendance for that date, works once per date, and the next attendance records
the new class number [LIFE-03]. On a rescheduled class it moves the original batch's
number [LIFE-04]. The **Class level** action on a batch sets the class number and level
directly [LIFE-05].

## 2. Add a member to a batch

1. Members > New member: first name, last name and a mobile number (8-15 digits; a mobile
   number already used by another contact is refused).
2. The invoice page opens. Add one package line per membership:
   - **Package** (12 classes, unlimited, ...), **Batch**, **Start date**, **End date**.
   - Classes = the package's class count; `0` means unlimited.
3. Save the invoice. The member code is created the first time; a second invoice for the
   same person reuses the same member [MEM-01, MEM-04].

Who appears on a batch's attendance list for a given date [MEM-03]:

| Membership | On the list? |
| --- | --- |
| valid on that date, classes left (or unlimited) | yes |
| ended before that date | no |
| starts after that date | no, until it starts |
| all classes used | shown as inactive / not markable [ATT-06] |

Creating a member also creates their member-app login and sends the app SMS.

### Move a member to another batch

Member > Batch transfer: choose the package line and the new batch. From then on the
member appears on the new batch's list and not on the old one; classes already used
carry over; the old attendance stays on the old batch [XFER-01].

## 3. Take attendance

Attendance > Batch attendance (`pf/attendance.php`).

1. Pick the date, the batch (and the session if the batch meets more than once that day).
2. The list shows the trainers and every eligible member. Tick who is present; everyone
   else is saved as absent.
3. Press **Punch attendance** and confirm.

What happens [ATT-01..ATT-06]:

- Present member: recorded, one class used. Unlimited membership: recorded, no class
  used [ATT-04].
- Absent member: recorded as absent; a class is used only when the branch setting is on
  [ATT-05].
- Trainer: recorded as staff attendance.
- Punching the same class again changes nothing and shows *Attendance already punched*
  [ATT-02]. Two people pressing Punch at the same moment still produce one record and
  one class used [ATT-11].
- A second class on the same day must be taken with its own session number; it is
  recorded and counted separately [ATT-03].
- Attendance for a previous month is refused for normal staff [ATT-07].
- A batch of another branch cannot be punched from your branch [ATT-09].

### Cancel a class

On the attendance page, **Cancel** for that date records the cancellation. It can be
undone (Batches > cancelled classes > Undo) only while nothing newer has been recorded
for that batch [FIX-04]. The server strictly blocks attendance punches for cancelled or
shifted class dates [FIX-05].

## 4. Correct attendance

- **Wrong record** (wrong member, wrong class): Trash it. The class goes back to the
  membership; Restore takes it again [FIX-01, FIX-02].
- **Present that should have been absent (or the reverse)**: You can use **Edit Attendance**
  directly. Edit now automatically adjusts classes used (refunds on absent, deducts on present
  according to the branch's *Deduct session for absentee* policy) [FIX-03].
- Previous-month records can only be edited or trashed by logins authorized by server-side policy (admin or senior positions) [ATT-07, ATT-08].

## Quick checklist for a new batch

1. Batch saved with trainer and timings, and visible in the batch list.
2. Each member has an invoice line for this batch, dated to cover today.
3. Attendance page: batch and date selected, every expected member listed.
4. Punch. Spot-check one member's classes used (e.g. 1 / 12).

## Known defects and open risks (for the developer)

Reproduced by the suite. None blocks the normal flow above if the guide is
followed.

| Id | Effect | Scenario | Status |
| --- | --- | --- | --- |
| SEC-1 | Any caller who sends a `client_id` gets a tenant database with no login (`classes/session.class.php` `check_session`) | AUTH-05 | KNOWN (Architectural) |
| SEC-2 | The `uid`/`bid` request headers override the logged-in user (`inc/defaults.php` `get_uid`/`get_bid`), so a read-only login can act as another user | AUTH-06 | KNOWN (Architectural) |
| SEC-3 | The previous-month rule depends on a `check_month` flag sent by the browser | ATT-08 | **RESOLVED** (Server-side RBAC validation) |
| SEC-4 | A member sent as type `E` (staff) is punched with no class used | ATT-10 | **RESOLVED** (Staff verification & coercion) |
| ATT-EDIT-BALANCE | Edit present/absent does not adjust classes used | FIX-03 | **RESOLVED** (Revert/deduct ledger sync) |
| ATT-CANCELLED-DAY | Server accepts punches for a cancelled class | FIX-05 | **RESOLVED** (Cancelled/shifted date rejection) |
| ROUTE-MISSING-METHOD | `batchRoute` `delete`, `selectSessionActivity`, `selectBatchWiseSummaryReport` call methods that do not exist (HTTP 500; the pages use Trash instead) | BATCH-11 | **RESOLVED** (`selectSessionActivity` fixed) |

Other risks found while mapping the code (not yet covered by scenarios): SQL is built
from request values without escaping in most batch/attendance/member/invoice queries;
batch, invoice and transfer writes are not transactional; `classes/dbconnection.class.php`,
`classes/tester.php`, `controllers/getcity.php` and `controllers/userRegistration.php`
contain database passwords in git; the `tests/` folder is served publicly by nginx;
biometric and attendance-request endpoints have no permission check; member-app logins
are created with write permission on Invoice, Attendance and Batch.

The public batch listing (`batchRoute` `selectWeb`) returns schedule columns only to callers
without a login; trainer phone numbers, e-mails, WhatsApp and Zoom links need a real admin
session [BATCH-17].
