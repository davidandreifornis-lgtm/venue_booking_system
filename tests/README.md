# Running the pre-deployment tests

**Status: written but NOT yet executed.** The environment they were written in had no PHP and no SQL Server, so nothing
here has been run or even syntax-checked. First step on your machine: `php -l` on each file in `tests/`, then run the suite.

## One-time setup
1. PHP 8.1+ with `pdo_sqlsrv` and `curl`; Composer; then `composer install` in the project root.
2. Create an **empty database named `VenueBooking_Test`** (the tests refuse to run if the name does not end in `_Test`,
   because they delete bookings, notifications and activity logs).
3. Build its schema. The SQL files hard-code `USE VenueBooking;`, so swap the name while running them
   (PowerShell, run files in this order; **skip `03_seed_demo_data.sql`**):

   ```powershell
   foreach ($f in '01_schema','02_seed','04_fix_settings','05_workflow','06_requirement_options','07_stock','08_booking_rules','09_equipment_rules') {
     (Get-Content "database\$f.sql" -Raw) -replace 'USE VenueBooking;','USE VenueBooking_Test;' |
       sqlcmd -S VMAPPS2 -U <login> -P <password> -b
   }
   ```
   (`sqlcmd` splits on `GO`; if a file has none, pass it with `-i` instead.)
4. Point the app at it with environment variables (no credentials in files):

   ```powershell
   $env:VBS_DB_SERVER='VMAPPS2'; $env:VBS_DB_NAME='VenueBooking_Test'
   $env:VBS_DB_USER='<test login>'; $env:VBS_DB_PASSWORD='<password>'
   ```

## Run
```
vendor\bin\phpunit                      # everything
vendor\bin\phpunit --filter test01      # one checklist item
```

## Reading the results
Each `ChecklistTest` test asserts what the checklist says SHOULD happen. A red test = that checklist item is not met yet.
`Incomplete` = the feature does not exist so there is nothing to test; `Skipped` = manual drill (below).
Concurrency tests (`test01a`, `test01b`) start separate PHP processes that fire at the same instant. A pass proves less than a
fail: the race window is small, so `test01c` (database-level guard) is the deterministic one.

## Manual drills
**Item 7 - restore a backup:** run `tests/manual/restore_drill.sql` (instructions in its header) on a non-production copy.
It backs up, verifies, restores into `VenueBooking_Restore`, compares row counts for Users, Bookings, BookingStatusHistory etc.,
and prints elapsed seconds. Compare those with your recovery targets (agree numbers first: how long may restore take, and how
much data may be lost). Nothing in the repo defines these targets yet.

**Item 10 - phone (375px wide; use a real phone or browser device mode), logged in as an Employee:**
1. Log in. Is the menu reachable and every page free of sideways scrolling?
2. Book a venue: pick date/time, fill the form, submit. Can you tap every field and button without zooming?
3. Open "My requests": can you read status and the current approval stage? Open one booking's details.
4. Cancel a booking: is the confirmation dialog fully visible and the reason box usable with the on-screen keyboard?
5. Calendar: switch views and open a booking. Note any clipped tables or hidden buttons.
Pass = steps 1-5 completable one-handed with no horizontal scrolling. Record failures with screenshots.
(Static check `test10a` only confirms the viewport meta tag; the CSS has very few responsive rules, so expect findings.)
