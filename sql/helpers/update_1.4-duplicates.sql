# Before update_1.4.sql on a company with data: it makes trans_no unique, and fails
# if an order has two schedules. Find them with the first query, look at one with
# the second (replace 720), and remove the extra rows by hand. Not imported by
# activate_extension() — FrontAccounting's db_import() would run these.
SELECT trans_no, COUNT(trans_no) AS c FROM `0_sales_recurring` GROUP BY trans_no HAVING c > 1;
SELECT * FROM `0_sales_recurring` WHERE trans_no = 720;
