-- Add per-variant tags for merchandising/filtering (e.g. BREAKFAST, BEST).
ALTER TABLE variants
    ADD COLUMN tags JSON NULL AFTER discount_tag;

