INSERT IGNORE INTO stores (id, name)
SELECT DISTINCT CAST(store AS UNSIGNED), CONCAT('Store ', store)
FROM transactions
WHERE store IS NOT NULL AND store REGEXP '^[0-9]+$';