-- Remove any leftover duplicates
DELETE FROM web_pages WHERE id IN (SELECT id FROM web_pages GROUP BY uri HAVING COUNT(*) > 1);

-- Add unique index
CREATE UNIQUE INDEX IF NOT EXISTS web_pages_uri ON web_pages (uri);