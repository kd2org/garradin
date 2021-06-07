ALTER TABLE services_reminders_sent RENAME TO srs_old;

-- Missing acc_years_delete trigger, again, because of missing symlink in previous release
-- Also add new column in services_reminders_sent

.read schema.sql

INSERT INTO services_reminders_sent SELECT id, id_user, id_service, id_reminder, date, date FROM srs_old;
DROP TABLE srs_old;
