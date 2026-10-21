# Face Enrollment UI Availability

As of 7 October 2026, the face enrollment interface is intentionally hidden on
the attendance page. This is a presentation change: the FaceID-style camera
screen, its Manage Face Setup entry point, per-child setup controls, and the
administrator review panel are commented out in `pf/attendance.php`.

The enrollment JavaScript and backend remain in the repository. This change does
not alter enrollment routes, capture validation, templates, feature settings,
or attendance scanning. Teachers can continue to use the attendance scan and
manual attendance paths exposed on that page.

To restore enrollment, remove the temporary HTML comments around the Manage
Face Setup button, the enrollment experience section, the administrator review
section, the roster Face setup table header, and the per-child Face setup cell
in `pf/attendance.php`. Then run the PHP syntax check and the enrollment UI
contract tests before making enrollment available again.

The UI is hidden in the template rather than by an environment flag, so restoring
it requires a code change and page refresh. No Nginx reload or Python service
restart is needed for this template-only switch.
