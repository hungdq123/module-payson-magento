Payson Payment Module - Magento

-------------------------------------- 
2013-09-25 v1.1.6

* Updated: The order status changes when customers returned to the store.

-------------------------------------- 
2013-07-17 v1.1.5

* Updated currency (SEK for Payson invoice and SEK/EUR for Payson Direct). 

--------------------------------------
2013-05-28 v1.0.0

* When order is cancelled the cart items are returned to stock 
 * When user either cancels the payment or Payson denies it the cart is restored 

--------------------------------------
2013-04-09 v0.1.3

* Invoices will no longer be actived automatically. To do this from now on the store owner has to create either an invoice or a shipment in Magento Admin or mark the order as shipped from Payson Admin. 
 * Invoice will not be available in checkout on purchases below 30SEK or for customers with a delivery address outside of sweden. 
 * An issue with the cancel URL has been fixed 
 * When selecting test mode the module will be using test credentials automatically 

--------------------------------------
2013-02-19 v0.1.2

* Fixed an issue were order email wasnt sent * Added an option to chose wether the invoice should be activated when accepted or not * Added test mode as an option * Added the possibility to refund payson direct payments.
 
-------------------------------------- 
2012-04-18 v1.5

* Bugfix: the module didn't return the products to the stock after Rejected payment (Card/Bank).


-------------------------------------- 
2012-03-22 v1.4

* Bugfix: the module didn't return the products to the stock, when the customers didn't complete the purchases (Card/Bank).

* The order with Payson Direct changes status from payment_pending to process when the payment is complete. 

* The module creates a Magento invoice once the order is complete when the customer pays by Payson Invoice.

* The status changes in Payson when the customer pays by Payson Invoice.


--------------------------------------
2011-12-15 v1.3 

* Bugfix: a couple of problems with the cancel button, when the customers didn't complete the purchases. 

* Bugfix: the module didn't return the products to the stock, when the customers didn't complete the purchases (Invoice).

* Feature for Paysod guarantee has been removed from the module .

--------------------------------------
2011-09-07 v1.2 

