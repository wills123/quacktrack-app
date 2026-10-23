USE parts_shop;

CREATE UNIQUE INDEX uk_van_stock ON van_stock (product_id, user_id);