<?php

class Payson_Payson_Model_Order_Invoice_Total_Invoice extends
Mage_Sales_Model_Order_Invoice_Total_Abstract {

    protected $_code = 'payson_invoice';

    public function collect(Mage_Sales_Model_Order_Invoice $invoice) {
        $order = $invoice->getOrder();

        $method = $order->getPayment()->getMethodInstance()->getCode(); /* Should be getMethod() */

        if ($method !== 'payson_invoice') {
            return $this;
        }

        if ($order->hasInvoices() == 0) {
            return $this;
        }

        $base_fee = $order->getBasePaysonInvoiceFee();
        $fee = $order->getPaysonInvoiceFee();

        if (!$base_fee || !$fee) {
            return $this;
        }

        $base_grand_total = $invoice->getBaseGrandTotal();
        $base_grand_total += $base_fee;
        $grand_total = $invoice->getGrandTotal();
        $grand_total += $fee;

        $invoice->setBasePaysonInvoiceFee($base_fee);
        $invoice->setPaysonInvoiceFee($fee);

        $invoice->setBaseGrandTotal($base_grand_total);
        $invoice->setGrandTotal($grand_total);

        return $this;
    }

}

