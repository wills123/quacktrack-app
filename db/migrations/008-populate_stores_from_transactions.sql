INSERT IGNORE INTO stores (name)
SELECT DISTINCT store FROM transactions WHERE store IS NOT NULL AND store != '';