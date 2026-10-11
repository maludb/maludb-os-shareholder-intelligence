-- 025: Alpha Vantage is the default price source (the owner, 2026-10-11 — D13 re-decided after stooq refused machines; a key added later).
--
-- The source stays paused until the admin sets its key through the credential door (`credential_set`, /admin/sources/{id}/credential): a pause
-- made for want of a key carries `settings.paused_for_key`, and the key's arrival lifts it (`source.resume`, "the key arrived"); Polygon's too.
-- Alpha Vantage's free plan, as its answers say (checked live 2026-10-11): TIME_SERIES_DAILY `compact` (the last 100 trading days — `full` is a
-- premium feature), 25 requests a day; the connector counts its requests per market day (`settings.daily_requests`, `settings.used`) and leaves the
-- rest for the next day rather than spend the key's day; one request every five seconds at most. Its host has no robots.txt (404 — no rules).
BEGIN;

UPDATE sources SET name = 'Prices — Alpha Vantage (the default; a key)', rate_per_second = 0.2,
       settings = settings || '{"paused_for_key": true, "daily_requests": 25, "default": true}'::jsonb,
       paused_reason = 'Needs an Alpha Vantage key: the admin sets it on the source (Sources and settings → the source → Credential). Free keys at alphavantage.co: the last 100 days, 25 requests a day.'
 WHERE connector = 'prices_alphavantage' AND credential_id IS NULL;
UPDATE sources SET settings = settings || '{"paused_for_key": true}'::jsonb WHERE connector = 'prices_polygon' AND credential_id IS NULL AND paused_at IS NOT NULL;

COMMIT;
