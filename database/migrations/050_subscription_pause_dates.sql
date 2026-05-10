ALTER TABLE subscriptions
    ADD COLUMN pause_start_date DATE NULL AFTER start_date,
    ADD COLUMN pause_end_date DATE NULL AFTER pause_start_date;
