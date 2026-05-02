ALTER TABLE order_support_messages
    ADD COLUMN attachments JSON NULL AFTER message;
