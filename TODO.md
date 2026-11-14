# TODO - Fix Admin Reservation

## Issues
1. Admin cannot select a client when creating/editing a reservation (no `client` field in form)
2. Controller always auto-sets logged-in user as client, even for admins
3. `edit.html.twig` has 6 missing closing `</div>` tags breaking page structure and button functionality

## Steps

- [x] Step 1: Update `src/Form/ReservationType.php` — add `is_admin` option and conditional `client` field
- [x] Step 2: Update `src/Controller/ReservationController.php` — pass `is_admin` option, handle client logic for admin vs user
- [x] Step 3: Update `templates/reservation/new.html.twig` — render `client` field conditionally for admin
- [x] Step 4: Fix `templates/reservation/edit.html.twig` — fix missing closing divs and add client field for admin
- [x] Step 5: Clear Symfony cache and test
