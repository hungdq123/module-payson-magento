<?php

class Payson_Payson_Model_Method_Standard extends Payson_Payson_Model_Method_Abstract {
    /*
     * Protected properties
     */

    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canVoid = true;

    /**
     * @inheritDoc
     */
    protected $_code = 'payson_standard';
    protected $_formBlockType = 'payson/standard_form';

    /*
     * Public methods
     */

    /**
     * @inheritDoc
     */
    public function getTitle() {
        return Mage::helper('payson')->__('Checkout with Payson');
    }

    /**
     * @inheritDoc
     */
    public function authorize(Varien_Object $payment, $amount) {
        $payment->setTransactionId('auth')->setIsTransactionClosed(0);

        return $this;
    }

}

