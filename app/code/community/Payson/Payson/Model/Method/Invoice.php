<?php

class Payson_Payson_Model_Method_Invoice extends Payson_Payson_Model_Method_Abstract {
    /*
     * Protected properties
     */

    /**
     * @inheritDoc
     */
    protected $_code = 'payson_invoice';
    protected $_formBlockType = 'payson/invoice_form';

    /**
     * @inheritDoc
     */
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    //protected $_canUseCheckout     = true;
    private $invoiceAmountMinLimit = 30;

    /*
     * Public methods
     */

    /**
     * @inheritDoc
     */
    public function capture(Varien_Object $payment, $amount) {
        $order = $payment->getOrder();
        $order_id = $order->getData('increment_id');

        $api = Mage::helper('payson/api');
        $helper = Mage::helper('payson');
        $api->PaymentDetails($order_id);
        $details = $api->GetResponse();

        if (($details->type ===
                Payson_Payson_Helper_Api::PAYMENT_METHOD_INVOICE) ||
                ($details->invoiceStatus ===
                Payson_Payson_Helper_Api::INVOICE_STATUS_ORDERCREATED)) {
            $api->PaymentUpdate($order_id, Payson_Payson_Helper_Api::UPDATE_ACTION_SHIPORDER);

            $order->addStatusHistoryComment($helper->__(
                            'Order was activated at Payson'));
        } else {
            Mage::throwException($helper->__('Payson is not ready to create an invoice. Please try again later.'));
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function authorize(Varien_Object $payment, $amount) {
        $payment->setTransactionId('auth')->setIsTransactionClosed(0);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTitle() {
        //if(Mage::getSingleton('checkout/cart')->getQuote()->getGrandTotal() >> 30)
        // echo Mage::getSingleton('checkout/cart')->getQuote()->getGrandTotal(); //$this->_canUseCheckout = false;
        $order = Mage::registry('current_order');

        if (!isset($order) && ($invoice = Mage::registry('current_invoice'))) {
            $order = $invoice->getOrder();
        }

        if (isset($order)) {
            $invoice_fee = $order->getPaysonInvoiceFee();

            if ($invoice_fee) {
                $invoice_fee = $order->formatPrice($invoice_fee);
            }
        } else {
            $invoice_fee = Mage::getModel('payson/config')
                    ->GetInvoiceFeeInclTax($this->getQuote());

            if ($invoice_fee) {
                $invoice_fee = Mage::app()->getStore()
                        ->formatPrice($invoice_fee);
            }
        }

        $invoice_fee = strip_tags($invoice_fee);

        return sprintf(Mage::helper('payson')
                        ->__('Checkout with Payson invoice %s invoice fee'), ($invoice_fee ? '+' . $invoice_fee : ''));
    }

    public function canUseCheckout() {
        if ($this->isSweden()) {
            if (strtoupper(Mage::app()->getStore()->getCurrentCurrencyCode()) != 'SEK')
                return false;
            if (Mage::getSingleton('checkout/cart')->getQuote()->getGrandTotal() < $this->invoiceAmountMinLimit)
                return false;
            else
                return true;
        }
        return false;
    }

    public function isSweden() {
        $checkout = Mage::getSingleton('checkout/session')->getQuote();
        $billing = $checkout->getBillingAddress();
        if (strtoupper($billing->getCountry()) != 'SE')
            return false;
        else
            return true;
    }

}
