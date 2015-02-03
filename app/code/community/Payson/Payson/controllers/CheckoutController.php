<?php

class Payson_Payson_CheckoutController extends Mage_Core_Controller_Front_Action {
    /*
     * Private properties
     */

    private $_session;
    private $_order = null;
    /* @var $_config Payson_Payson_Model_Config */
    private $_config;
    /* @var $_helper Payson_Payson_Helper_Data */
    private $_helper;

    /*
     * Private methods
     */

    public function _construct() {
        $this->_config = Mage::getModel('payson/config');
        $this->_helper = Mage::helper('payson');
    }

    /*
     * Private methods
     */

    private function getSession() {
        if (!isset($this->_session)) {
            $this->_session = Mage::getSingleton('checkout/session');
        }

        return $this->_session;
    }

    /**
     * 
     * @return Mage_Sales_Model_Order
     */
    private function getOrder() {
        if (!isset($this->_order)) {
            $increment_id = $this->getSession()->getData('last_real_order_id');

            if ($increment_id) {
                $this->_order = Mage::getModel('sales/order')
                        ->loadByIncrementId($increment_id);

                if (is_null($this->_order->getId())) {
                    $this->_order = null;
                }
            }
        }

        return $this->_order;
    }

    private function cancelOrder($message = '') {

        $order = $this->getOrder();

        if (!is_null($order = $this->getOrder())) {
            $order->cancel();

            if ($message != '')
                $order->addStatusHistoryComment($message);
        }

        $order->save();
        return $this;
    }

    /*
     * Public methods
     */

    public function redirectAction() {

        $order = $this->getOrder();

        if (is_null($order)) {
            $this->_redirect('checkout/cart');

            return;
        }

        try {
            $api = Mage::helper('payson/api')->Pay($order);

            $order->addStatusHistoryComment(Mage::helper('payson')->__(
                                    'The customer was redirected to Payson'))
                    ->save();

            $this->GetResponse()->setRedirect($api->GetPayForwardUrl());
        } catch (Exception $e) {
            $this->cancelOrder($e->getMessage());

            if ($this->_config->restoreCartOnError()) {
                $this->_config->restoreCart();
            }

            Mage::logException($e);

            Mage::getSingleton('core/session')->addError($e->getMessage());
            $this->_redirect('checkout/cart');
        }
    }

    public function returnAction() {

        $order = $this->getOrder();
        
        $paymentDetailsResponse = Mage::helper('payson/api')->PaymentDetails(Mage::getSingleton('checkout/session')->getLastRealOrderId())->getResponse();
        $paymentStatus = $paymentDetailsResponse->status;
        switch ($paymentStatus) {
            case 'COMPLETED':
            case 'PENDING':
            case 'PROCESSING':
            case 'CREDITED': {
                    
                    if ($paymentDetailsResponse->type !== 'INVOICE' && $paymentDetailsResponse->status === 'COMPLETED') {
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
                        $order->sendNewOrderEmail()->save();
                        $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                        $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                        $invoice->register();
                        $transactionSave = Mage::getModel('core/resource_transaction')
                                ->addObject($invoice)
                                ->addObject($invoice->getOrder());
                        $transactionSave->save();

                        $this->_redirect('checkout/onepage/success');
                        break;
                    }


                    if ($paymentDetailsResponse->type !== 'INVOICE' && $paymentDetailsResponse->status === 'PROCESSING') {
                        $order->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, true);
                        Mage::getSingleton('core/session')->addError(sprintf(Mage::helper('payson')->__('Your payment is being processed by Payson')));
                        $this->_redirect('checkout/onepage/success');
                        break;
                    }
                    if ($paymentDetailsResponse->type !== 'INVOICE' && $paymentDetailsResponse->status === 'PENDING') {
                        Mage::getSingleton('core/session')->addError(sprintf(Mage::helper('payson')->__('Something went wrong with the payment. Please, try a different payment method')));
                        $this->_redirect('checkout/onepage/failure');
                        break;
                    }
                    if ($paymentDetailsResponse->type === 'INVOICE') {
                        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
                        $order->sendNewOrderEmail()->save();
                        $this->_redirect('checkout/onepage/success');
                        break;
                    }
                }
            case 'ERROR': {
                    $errorMessage = Mage::helper('payson')->__('The payment was denied by Payson. Please, try a different payment method');
                    $order->setState(Mage_Sales_Model_Order::STATE_PENDING, true);
                    Mage::getSingleton('core/session')->addError($errorMessage);
                    $this->cancelOrder($errorMessage);

                    $this->_redirect('checkout');
                    break;
                }
            default: {
                    Mage::getSingleton('core/session')->addError(sprintf(Mage::helper('payson')->__('Something went wrong with the payment. Please, try a different payment method')));
                    $this->_redirect('checkout');
                    break;
                }
        }
    }

    public function cancelAction() {

        $cancelMessage = Mage::helper('payson')->__('Order was canceled at Payson');

        $this->cancelOrder($cancelMessage);

        if ($this->_config->restoreCartOnCancel()) {
            $this->restoreCart();
        }

        $this->_redirect('checkout');
    }

    private function restoreCart() {

        $quoteId = $this->getOrder()->getQuoteId();
        $quote = Mage::getModel('sales/quote')->load($quoteId);
        $quote->setIsActive(true)->save();
    }

}
