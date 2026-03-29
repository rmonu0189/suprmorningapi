-- Run if `pages` was created with UNIQUE KEY uq_pages_page_card (older schema).

ALTER TABLE pages DROP INDEX uq_pages_page_card;
