<?php

class Payson_Payson_CheckoutController extends Mage_Core_Controller_Front_Action {
    /*
     * Private properties
     */

    private $session;
    private $order = null;

    /*
     * Private methods
     */

    private function GetSession() {
        if (!isset($this->session)) {
            $this->session = Mage::getSingleton('checkout/session');
        }

        return $this->session;
    }

    /**
     * 
     * @return Mage_Sales_Model_Order
     */
    private function GetOrder() {
        if (!isset($this->order)) {
            $increment_id = $this->GetSession()->getData('last_real_order_id');

            if ($increment_id) {
                $this->order = Mage::getModel('sales/order')
                        ->loadByIncrementId($increment_id);

                if (is_null($this->order->getId())) {
                    $this->order = null;
                }
            }
        }

        return $this->order;
    }

    private function CancelOrder($message = '') {
        $order = $this->GetOrder();

        if (!is_null($order = $this->GetOrder())) {
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
        $order = $this->GetOrder();

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
            $this->CancelOrder($e->getMessage());

            Mage::logException($e);

            Mage::getSingleton('core/session')->addError($e->getMessage());
            $this->_redirect('checkout/cart');
        }
    }

    public function returnAction() {

        $ipnStatus = Mage::helper('payson/api')->getIpnStatus(Mage::getSingleton('checkout/session')->getLastRealOrderId());
        //print_r($ipnStatus);exit;
        switch ($ipnStatus['ipn_status']) {
            case 'COMPLETED':
            case 'PENDING':
            case 'PROCESSING':
            case 'CREDITED': {
                    $this->GetOrder()->sendNewOrderEmail();
                    $this->_redirect('checkout/onepage/success');
                    break;
                }
            case 'ERROR': {
                    Mage::helper('payson/api')->paysonApiError(sprintf(Mage::helper('payson')->__('The payment was denied by Payson. Please, try a different payment method')));
                    break;
                }
            default: {
                    Mage::helper('payson/api')->paysonApiError(sprintf(Mage::helper('payson')->__('Something went wrong with the payment. Please, try a different payment method')));
                    break;
                }
        }
    }

    public function cancelAction() {
        $this->CancelOrder()->_redirect('checkout/cart');
    }

}