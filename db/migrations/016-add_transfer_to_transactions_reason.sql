
ALTER TABLE transactions 
MODIFY COLUMN reason ENUM('add', 'use', 'adjust', 'transfer') NOT NULL;

ALTER TABLE transactions 
COMMENT = 'Stock transactions including transfers between stores';