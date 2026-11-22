CREATE TABLE cajeer_book.analytics_daily (
  installation text NOT NULL REFERENCES cajeer_book.content_installation(installation) ON DELETE CASCADE,
  scope text NOT NULL,
  day date NOT NULL,
  event text NOT NULL CHECK (event IN ('page_view','search','link_click','download','feedback')),
  page_path text NOT NULL CHECK (length(page_path) BETWEEN 1 AND 260),
  hits bigint NOT NULL DEFAULT 0 CHECK (hits >= 0),
  PRIMARY KEY (installation, scope, day, event, page_path)
);
