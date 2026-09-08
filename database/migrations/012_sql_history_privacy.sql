UPDATE sql_history
SET query_encrypted = NULL
WHERE query_encrypted IS NOT NULL;
