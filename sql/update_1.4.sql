ALTER TABLE `0_sales_recurring` CHANGE `dt_end` `dt_end` DATE NULL;
ALTER TABLE `0_sales_recurring` CHANGE `dt_next` `dt_next` DATE NULL;
ALTER TABLE `0_sales_recurring` DROP INDEX `order_no`, ADD UNIQUE `order_no` (`trans_no`) USING BTREE;
UPDATE `0_sales_recurring` SET dt_end=NULL WHERE dt_end='0000-00-00';
UPDATE `0_sales_recurring` SET dt_next=NULL WHERE dt_next='0000-00-00';

