-- Destructive rollback: removes the complete calendar-date audit history.
DROP TABLE IF EXISTS calendar_date_change_history_dates;
DROP TABLE IF EXISTS calendar_date_change_history;
