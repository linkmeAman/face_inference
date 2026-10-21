-- Apply ONLY to the verified existing ccc_database_face_pilot_<8 hex> database.
-- No source/production tables or original Practice attendance are changed.
CREATE TABLE IF NOT EXISTS face_testing_attendance LIKE attendance;
CREATE TABLE IF NOT EXISTS face_testing_attendance_event LIKE attendance_event;
