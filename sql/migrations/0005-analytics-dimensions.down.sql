DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM cajeer_book.analytics_dimensions_daily LIMIT 1) THEN
    RAISE EXCEPTION 'Analytics search/conversion data exists. Export or explicitly purge it before rollback.';
  END IF;
END $$;
DROP TABLE cajeer_book.analytics_dimensions_daily;
