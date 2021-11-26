ALTER TABLE `0_sales_recurring` CHANGE `dt_end` `dt_end` DATE NULL;
ALTER TABLE `0_sales_recurring` CHANGE `dt_next` `dt_next` DATE NULL;
ALTER TABLE `0_sales_recurring` DROP INDEX `order_no`, ADD UNIQUE `order_no` (`trans_no`) USING BTREE;
UPDATE `0_sales_recurring` SET dt_end=NULL WHERE dt_end='0000-00-00';
UPDATE `0_sales_recurring` SET dt_next=NULL WHERE dt_next='0000-00-00';

# Upgrade helpers below here
SELECT trans_no,COUNT(trans_no) AS c FROM `0_sales_recurring` GROUP BY trans_no HAVING C>1
SELECT * FROM `0_sales_recurring` WHERE trans_no=720