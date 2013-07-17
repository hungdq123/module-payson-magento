<?php

class Payson_Payson_Helper_Api {
    /*
     * Constants
     */

    const DEBUG_MODE = false;
    const API_CALL_PAY = '%s://%sapi.payson.%s/%s/Pay/';
    const API_CALL_VALIDATE = '%s://%sapi.payson.%s/%s/Validate/';
    const API_CALL_PAYMENT_DETAILS = '%s://%sapi.payson.%s/%s/%sDetails/';
    const API_CALL_PAYMENT_UPDATE = '%s://%sapi.payson.%s/%s/%sUpdate/';
    const PAY_FORWARD_URL = '%s://%s%s.payson.%s/paySecure/';
    const APPLICATION_ID = 'Magento Module 0.1.5';
    const MODULE_NAME = 'payson_magento';
    const MODULE_VERSION = '0.1.5';
    const DEBUG_MODE_MAIL = 'testagent-1@payson.se';
    const DEBUG_MODE_AGENT_ID = '1';
    const DEBUG_MODE_MD5 = 'fddb19ac-7470-42b6-a91d-072cb1495f0a';
    const STATUS_CREATED = 'CREATED';
    const STATUS_PENDING = 'PENDING';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_CREDITED = 'CREDITED';
    const STATUS_INCOMPLETE = 'INCOMPLETE';
    const STATUS_ERROR = 'ERROR';
    const STATUS_EXPIRED = 'EXPIRED';
    const STATUS_REVERSALERROR = 'REVERSALERROR';
    const PAYMENT_METHOD_BANK = 'BANK';
    const PAYMENT_METHOD_CREDITCARD = 'CREDITCARD';
    const PAYMENT_METHOD_INVOICE = 'INVOICE';
    const GUARANTEE_STATUS_WAITINGFORSEND = 'WAITINGFORSEND';
    const GUARANTEE_STATUS_WAITINGFORACCEPTANCE = 'WAITINGFORACCEPTANCE';
    const GUARANTEE_STATUS_WAITINGFORRETURN = 'WAITINGFORRETURN';
    const GUARANTEE_STATUS_WAITINGFORRETURNACCEPTANCE = 'WAITINGFORRETURNACCEPTANCE';
    const GUARANTEE_STATUS_RETURNNOTACCEPTED = 'RETURNNOTACCEPTED';
    const GUARANTEE_STATUS_NOTRECEIVED = 'NOTRECEIVED';
    const GUARANTEE_STATUS_RETURNNOTRECEIVED = 'RETURNNOTRECEIVED';
    const GUARANTEE_STATUS_MONEYRETURNEDTOSENDER = 'MONEYRETURNEDTOSENDER';
    const GUARANTEE_STATUS_RETURNACCEPTED = 'RETURNACCEPTED';
    const INVOICE_STATUS_PENDING = 'PENDING';
    const INVOICE_STATUS_ORDERCREATED = 'ORDERCREATED';
    const INVOICE_STATUS_ORDERCANCELLED = 'ORDERCANCELLED';
    const INVOICE_STATUS_SHIPPED = 'SHIPPED';
    const INVOICE_STATUS_DONE = 'DONE';
    const UPDATE_ACTION_CANCELORDER = 'CANCELORDER';
    const UPDATE_ACTION_SHIPORDER = 'SHIPORDER';
    const UPDATE_ACTION_CREDITORDER = 'CREDITORDER';
    const UPDATE_ACTION_REFUNDORDER = 'REFUND';
    const GUARANTEE_REQUIRED = 'REQUIRED';
    const GUARANTEE_OPTIONAL = 'OPTIONAL';
    const GUARANTEE_NO = 'NO';

    /*
     * Private properties
     */

    private $response;
    private $order_discount_item = 0.0;

    /*
     * Private methods
     */

    private function GetHttpClient($url) {
        $config = Mage::getModel('payson/config');

        $http_client = new Zend_Http_Client($url);

        $http_client->setMethod(Zend_Http_Client::POST)
                ->setHeaders(array
                    (
                    'PAYSON-SECURITY-USERID' => $config->get('test_mode') ? self::DEBUG_MODE_AGENT_ID : $config->Get('agent_id'),
                    'PAYSON-SECURITY-PASSWORD' => $config->get('test_mode') ? self::DEBUG_MODE_MD5 : $config->Get('md5_key'),
                    'PAYSON-APPLICATION-ID' => self::APPLICATION_ID,
                    'PAYSON-MODULE-INFO' => self::MODULE_NAME . '|' . self::MODULE_VERSION . '|' . Mage::getVersion()
        ));

        return $http_client->resetParameters();
    }

    private function SetResponse(
    Payson_Payson_Helper_Api_Response_Interface $response) {
        $this->response = $response;

        return $this;
    }

    private function SetOrderDiscountItem($item, &$total) {
        $total -= $item->getData('discount_amount');
        $this->order_discount_item += $item->getData('discount_amount');
    }

    /**
     * Helper for Pay()
     *
     * @param	object	$item
     * @param	int		$i
     * @param	int		$total
     * @return	array
     */
    private function GetOrderItemInfo($item, $i, &$total) {
        $product = Mage::getModel('catalog/product')
                ->load($item->getProductId());
        $children = $item->getChildrenItems();

        if (!$product->isConfigurable() && count($children) &&
                !$item->isChildrenCalculated()) {
            $args = array();
            foreach ($children as $child) {
                $args += $this->GetOrderItemInfo($child, $i++, $total);
            }
            return $args;
        }

        if ($product->isConfigurable() && (count($children) === 1)) {
            $name = $children[0]->getData('name');
            $sku = $children[0]->getData('sku');
        } else {
            $name = $product->getData('name');
            $sku = $item->getData('sku');
        }

        /* TODO: If tax_percent is not set?
          Mage::getSingleton('tax/calculation')->getRate(
          $tax_rate_req->setProductClassId($product->getTaxClassId()))
         */
        $tax_mod = (float) $item->getData('tax_percent');
        $tax_mod /= 100;
        $tax_mod = round($tax_mod, 5);

        $qty = (float) $item->getData('qty_ordered');
        $qty = round($qty, 2);

        $price = (float) $item->getData('row_total_incl_tax');

        $base_price = (($price / (1 + $tax_mod)) / $qty);
        $base_price = round($base_price, 3);

        $total += (($base_price * (1 + $tax_mod)) * $qty);

        return array
            (
            'orderItemList.orderItem(' . $i . ').description' =>
            $name,
            'orderItemList.orderItem(' . $i . ').sku' =>
            $sku,
            'orderItemList.orderItem(' . $i . ').quantity' =>
            $qty,
            'orderItemList.orderItem(' . $i . ').unitPrice' =>
            $base_price,
            'orderItemList.orderItem(' . $i . ').taxPercentage' =>
            $tax_mod
        );
    }

    /**
     * Helper for Pay()
     *
     * @param	object	$order
     * @param	object	$customer
     * @param	object	$store
     * @param	int		$i
     * @param	int		$total
     * @return	array
     */
    private function GetOrderShippingInfo($order, $customer, $store, $i, &$total) {
        $tax_calc = Mage::getSingleton('tax/calculation');

        $tax_rate_req = $tax_calc->getRateRequest(
                $order->getShippingAddress(), $order->getBillingAddress(), $customer->getTaxClassId(), $store);

        $args = array();

        if (($price = (float) $order->getData('shipping_incl_tax')) > 0) {
            $tax_mod = $tax_calc->getRate($tax_rate_req->setProductClassId(
                            Mage::getStoreConfig('tax/classes/shipping_tax_class')));
            $tax_mod /= 100;
            $tax_mod = round($tax_mod, 5);

            $price -= (float) $order->getData('shipping_discount_amount');

            $base_price = ($price / (1 + $tax_mod));
            $base_price = round($base_price, 3);

            $total += ($base_price * (1 + $tax_mod));

            $args = array
                (
                'orderItemList.orderItem(' . $i . ').description' =>
                $order->getData('shipping_description'),
                'orderItemList.orderItem(' . $i . ').sku' =>
                $order->getData('shipping_method'),
                'orderItemList.orderItem(' . $i . ').quantity' =>
                1,
                'orderItemList.orderItem(' . $i . ').unitPrice' =>
                $base_price,
                'orderItemList.orderItem(' . $i . ').taxPercentage' =>
                $tax_mod
            );
        }
        return $args;
    }

    /*
     * Public methods
     */

    /**
     * Get API response
     *
     * @return	object
     */
    public function GetResponse() {
        return $this->response;
    }

    /**
     * Get forward/redirect url after a successful Pay() call
     *
     * @return	string
     */
    public function GetPayForwardUrl() {
        $url = vsprintf(self::PAY_FORWARD_URL . '?token=' . $this->GetResponse()->TOKEN, $this->GetFormatIfTest(null, true));
        return $url;
    }

    /**
     * http://api.payson.se/#title8
     *
     * @param	object	$order
     * @return	object					$this
     */
    public function Pay(Mage_Sales_Model_Order $order) {
        $payment_method = $order->getPayment()->getMethod();
        $helper = Mage::helper('payson');
        $config = Mage::getModel('payson/config');
        $store = Mage::app()->getStore($order->getStoreId());
        $customer = Mage::getModel('customer/customer')
                ->load($order->getCustomerId());
        $billing_address = $order->getBillingAddress();

        // Need a two character locale code
        $locale_code = Mage::getSingleton('core/locale')->getLocaleCode();
        $locale_code = strtoupper(substr($locale_code, 0, 2));

        if (!in_array($locale_code, array('SV', 'FI', 'EN'))) {
            switch ($locale_code) {
                case 'DA':
                case 'NO': {
                        $locale_code = 'SV';
                        break;
                    }
                default: {
                        $locale_code = 'EN';
                    }
            }
        }

        // $args will contain the arguments of the API request to Payson
        $args = array
            (
            'returnUrl' =>
            Mage::getUrl('payson/checkout/return', array('_secure' => true)),
            'cancelUrl' =>
            Mage::getUrl('payson/checkout/cancel', array('_secure' => true)),
            'ipnNotificationUrl' =>
            Mage::getUrl('payson/ipn/notify', array('_secure' => true)),
            'localeCode' =>
            $locale_code,
            'currencyCode' =>
            //$order->getOrderCurrency()->getCode(),
            //$order->getBaseCurrencyCode(),
            strtoupper(substr($order->getOrderCurrency()->getCode(), 0, 3)),
            'memo' =>
            sprintf($helper->__('Order from %s'), $store->getUrl()),
            'senderEmail' =>
            $order->getCustomerEmail(),
            'senderFirstName' =>
            $billing_address->getFirstname(),
            'senderLastName' =>
            $billing_address->getLastname(),
            'receiverList.receiver(0).email' =>
            $config->get('test_mode') ? self::DEBUG_MODE_MAIL : $config->Get('email'),
            'trackingId' => $order->getRealOrderId()
        );

        if (!$config->CanPaymentGuarantee()) {
            $args['guaranteeOffered'] = self::GUARANTEE_NO;
        }

        if (($payment_method === 'payson_invoice')) {
            if (!$config->CanInvoicePayment()) {
                Mage::throwException('Invoice payment is disabled');
            }

            $args['fundingList.fundingConstraint(0).constraint'] =
                    self::PAYMENT_METHOD_INVOICE;
        }else {
            switch ($config->get('paysondirect_method')) {
                case 1:
                    $args['fundingList.fundingConstraint(0).constraint'] = 'CREDITCARD';
                    break;
                 case 2:
                     $args['fundingList.fundingConstraint(0).constraint'] = 'BANK';
                    break;
                default:
                    break;
            }
        }

        $i = 0;
        $total = 0;

        // Calculate price of each item in the order
        foreach ($order->getAllVisibleItems() as $item) {
            $args += $this->GetOrderItemInfo($item, $i++, $total);
        }

        foreach ($order->getAllVisibleItems() as $item) {
            $this->SetOrderDiscountItem($item, $total);
        }
        if ($this->order_discount_item >> 0) {
            $args += array
                (
                'orderItemList.orderItem(' . $i . ').description' =>
                'discount',
                'orderItemList.orderItem(' . $i . ').sku' =>
                'discount',
                'orderItemList.orderItem(' . $i . ').quantity' =>
                1,
                'orderItemList.orderItem(' . $i . ').unitPrice' =>
                -$this->order_discount_item,
                'orderItemList.orderItem(' . $i . ').taxPercentage' =>
                0.0
            );
        }
        // Calculate price for shipping
        $args += $this->GetOrderShippingInfo($order, $customer, $store, $i++, $total);
        /*  echo '<pre>';
          print_r($args);
          echo '</pre>';exit; */
        if ($order->getPaysonInvoiceFee() > 0) {
            $fee = $order->getPaysonInvoiceFee();
            /* $args += array
              (
              'orderItemList.orderItem(' . $i . ').description'	=>
              $helper->__('Invoice fee'),
              'orderItemList.orderItem(' . $i . ').sku'			=>
              'invoice_fee',
              'orderItemList.orderItem(' . $i . ').quantity'		=>
              1,
              'orderItemList.orderItem(' . $i . ').unitPrice'		=>
              $config->GetInvoiceFee($order),
              'orderItemList.orderItem(' . $i . ').taxPercentage'	=>
              $config->GetInvoiceFeeTaxMod($order)
              );

              $total += $config->GetInvoiceFeeInclTax($order); */

            $args['invoiceFee'] = round((float) $fee, 3);
            $total += $fee;
        }

        $total = round($total, 2);

        $args['receiverList.receiver(0).amount'] = $total;

        //echo('<pre>');print_r($args);exit(PHP_EOL . $order->getData('grand_total') . '</pre>');
        $url = vsprintf(self::API_CALL_PAY, $this->GetFormatIfTest($order->getStoreId()));
        $client = $this->GetHttpClient($url)
                ->setParameterPost($args);

        $response = Payson_Payson_Helper_Api_Response_Standard
                ::FromHttpBody($client->request()->getBody());

        $this->SetResponse($response);

        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');

        $order_table = $resource->getTableName('payson_order');
        $order_log_table = $resource->getTableName('payson_order_log');

        $db->insert($order_table, array
            (
            'order_id' => $order->getRealOrderId(),
            'added' => new Zend_Db_Expr('NOW()'),
            'updated' => new Zend_Db_Expr('NOW()'),
            'valid' => (int) $response->IsValid(),
            'token' => (isset($response->TOKEN) ? $response->TOKEN :
                    new Zend_Db_Expr('NULL')),
            'store_id' => $order->getStoreId()
        ));

        $payson_order_id = $db->lastInsertId();

        $db->insert($order_log_table, array
            (
            'payson_order_id' => $payson_order_id,
            'added' => new Zend_Db_Expr('NOW()'),
            'api_call' => 'pay',
            'valid' => (int) $response->IsValid(),
            'response' => serialize($response->ToArray())
        ));

        if (!$response->IsValid()) {

            throw new Mage_Core_Exception(sprintf($helper->__(
                            'Failed to initialize payment. Payson replied: %s'), $response->GetError()), $response->GetErrorId());
        }

        return $this;
    }

    /**
     * Implements the IPN procedure
     *
     * http://api.payson.se/#title11
     *
     * @param	string	$http_body
     * @param	string	$content_type
     * @return	object					$this
     */
    public function Validate($http_body, $content_type) {
        $helper = Mage::helper('payson');
        $config = Mage::getModel('payson/config');
        // Parse request done by Payson to our IPN controller
        $ipn_response = Payson_Payson_Helper_Api_Response_Standard
                ::FromHttpBody($http_body);

        // Get the database connection
        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');

        $order_table = $resource->getTableName('payson_order');
        $order_log_table = $resource->getTableName('payson_order_log');

        /* Save data sent by Payson, log entry as invalid by default, this
          value will be changed later in this method if successful. No payson
          order id is set, because we dont have one yet */
        $db->insert($order_log_table, array
            (
            'added' => new Zend_Db_Expr('NOW()'),
            'api_call' => 'validate',
            'valid' => 0,
            'response' => serialize($ipn_response->ToArray())
        ));

        $order_log_id = $db->lastInsertId();

        /* $ipn_response will never contain responseEnvelope.ack, as I first
          thought it would */
        /* if(!$ipn_response->IsValid())
          {
          Mage::throwException('Invalid request');
          } */

        /* Save fetch mode so that we can reset it and not mess up Magento
          functionality */
        $old_fetch_mode = $db->getFetchMode();
        $db->setFetchMode(Zend_Db::FETCH_OBJ);

        // Get payson order information and validate token
        $payson_order = $db->fetchRow(
                'SELECT
	id,
	order_id,
        store_id
FROM
	`' . $order_table . '`
WHERE
	valid = 1
AND
	token = ?
LIMIT
	0,1', $ipn_response->token);

        if ($payson_order === false) {
            Mage::throwException('Invalid token');
        }

        // Do the validate API call
        $client = $this->GetHttpClient(vsprintf(self::API_CALL_VALIDATE, $this->GetFormatIfTest($payson_order->store_id)))
                ->setRawData($http_body, $content_type);

        $response = Payson_Payson_Helper_Api_Response_Validate
                ::FromHttpBody($client->request()->getBody());

        $this->SetResponse($response);

        if (!$response->IsValid()) {
            Mage::throwException('Validate call was unsuccessful');
        }



        // Update order log with payson order id
        $db->update($order_log_table, array
            (
            'payson_order_id' => $payson_order->id
                ), array
            (
            'id = ?' => $order_log_id
        ));

        // the order model does not expect FETCH_OBJ!
        $db->setFetchMode($old_fetch_mode);

        /**
         * @var Mage_Sales_Model_Order
         */
        $order = Mage::getModel('sales/order')
                ->loadByIncrementId($payson_order->order_id);

        // Stop if order dont exist
        if (is_null($order->getId())) {
            Mage::throwException('Invalid order');
        }

        if ($order->getState() === Mage_Sales_Model_Order::STATE_COMPLETE) {
            Mage::throwException('Order is no longer active');
        }

        $receivers = $ipn_response->receiverList->receiver->ToArray();

        /* Verify payment amount. floor() since there might be a precision
          difference */
        if (floor((float) $receivers[0]['amount']) !==
                floor((float) $order->getTotalDue())) {
            Mage::throwException('Invalid amount');
        }

        switch ($ipn_response->status) {
            case self::STATUS_COMPLETED: {
                    //Changes the status of the order from pending_payment to processing
                    $order->setState(
                            Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING,
                            //$helper->__('Payson completed the order payment XXXXXXX'));
                            $config->get('test_mode') ? $helper->__('Payson test completed the order payment') : $helper->__('Payson completed the order payment'));

                    //It creates the invoice to the order
                    $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $transactionSave = Mage::getModel('core/resource_transaction')
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());
                    $transactionSave->save();

                    break;
                }
            case self::STATUS_CREATED:
            case self::STATUS_PENDING:
            case self::STATUS_PROCESSING:
            case self::STATUS_CREDITED: {
                    if (($ipn_response->status === self::STATUS_PENDING) &&
                            ($ipn_response->type === self::PAYMENT_METHOD_INVOICE) &&
                            ($ipn_response->invoiceStatus ===
                            self::INVOICE_STATUS_ORDERCREATED)) {
                        //Changes the status of the order from pending to processing
                        $order->setState(
                                Mage_Sales_Model_Order::STATE_PROCESSING, Mage_Sales_Model_Order::STATE_PROCESSING,
                                //$helper->__('Payson created an invoice'));
                                $config->get('test_mode') ? $helper->__('Payson test created an invoice') : $helper->__('Payson created an invoice'));



                        if (isset($ipn_response->shippingAddress)) {
                            $address_info = $ipn_response->shippingAddress
                                    ->ToArray();

                            $address = $order->getShippingAddress();

                            if (isset($address_info['name'])) {
                                $address->setFirstname($address_info['name']);
                                $address->setMiddlename('');
                                $address->setLastname('');
                            }

                            if (isset($address_info['streetAddress'])) {
                                $address->setStreet($address_info['streetAddress']);
                            }

                            if (isset($address_info['postalCode'])) {
                                $address->setPostcode($address_info['streetAddress']);
                            }

                            if (isset($address_info['city'])) {
                                $address->setCity($address_info['city']);
                            }

                            if (isset($address_info['country'])) {
                                // :(
                                $foo = array
                                    (
                                    'afghanistan' => 'AF',
                                    'albania' => 'AL',
                                    'algeria' => 'DZ',
                                    'american samoa' => 'AS',
                                    'andorra' => 'AD',
                                    'angola' => 'AO',
                                    'anguilla' => 'AI',
                                    'antarctica' => 'AQ',
                                    'antigua and barbuda' => 'AG',
                                    'argentina' => 'AR',
                                    'armenia' => 'AM',
                                    'aruba' => 'AW',
                                    'australia' => 'AU',
                                    'austria' => 'AT',
                                    'azerbaijan' => 'AZ',
                                    'bahamas' => 'BS',
                                    'bahrain' => 'BH',
                                    'bangladesh' => 'BD',
                                    'barbados' => 'BB',
                                    'belarus' => 'BY',
                                    'belgium' => 'BE',
                                    'belize' => 'BZ',
                                    'benin' => 'BJ',
                                    'bermuda' => 'BM',
                                    'bhutan' => 'BT',
                                    'bolivia' => 'BO',
                                    'bosnia and herzegovina' => 'BA',
                                    'botswana' => 'BW',
                                    'bouvet island' => 'BV',
                                    'brazil' => 'BR',
                                    'british indian ocean territory' => 'IO',
                                    'brunei darussalam' => 'BN',
                                    'bulgaria' => 'BG',
                                    'burkina faso' => 'BF',
                                    'burundi' => 'BI',
                                    'cambodia' => 'KH',
                                    'cameroon' => 'CM',
                                    'canada' => 'CA',
                                    'cape verde' => 'CV',
                                    'cayman islands' => 'KY',
                                    'central african republic' => 'CF',
                                    'chad' => 'TD',
                                    'chile' => 'CL',
                                    'china' => 'CN',
                                    'christmas island' => 'CX',
                                    'cocos (keeling) islands' => 'CC',
                                    'colombia' => 'CO',
                                    'comoros' => 'KM',
                                    'congo' => 'CG',
                                    'congo, the democratic republic of the' => 'CD',
                                    'cook islands' => 'CK',
                                    'costa rica' => 'CR',
                                    'cote d ivoire' => 'CI',
                                    'croatia' => 'HR',
                                    'cuba' => 'CU',
                                    'cyprus' => 'CY',
                                    'czech republic' => 'CZ',
                                    'denmark' => 'DK',
                                    'djibouti' => 'DJ',
                                    'dominica' => 'DM',
                                    'dominican republic' => 'DO',
                                    'east timor' => 'TP',
                                    'ecuador' => 'EC',
                                    'egypt' => 'EG',
                                    'el salvador' => 'SV',
                                    'equatorial guinea' => 'GQ',
                                    'eritrea' => 'ER',
                                    'estonia' => 'EE',
                                    'ethiopia' => 'ET',
                                    'falkland islands (malvinas)' => 'FK',
                                    'faroe islands' => 'FO',
                                    'fiji' => 'FJ',
                                    'finland' => 'FI',
                                    'france' => 'FR',
                                    'french guiana' => 'GF',
                                    'french polynesia' => 'PF',
                                    'french southern territories' => 'TF',
                                    'gabon' => 'GA',
                                    'gambia' => 'GM',
                                    'georgia' => 'GE',
                                    'germany' => 'DE',
                                    'ghana' => 'GH',
                                    'gibraltar' => 'GI',
                                    'greece' => 'GR',
                                    'greenland' => 'GL',
                                    'grenada' => 'GD',
                                    'guadeloupe' => 'GP',
                                    'guam' => 'GU',
                                    'guatemala' => 'GT',
                                    'guinea' => 'GN',
                                    'guinea-bissau' => 'GW',
                                    'guyana' => 'GY',
                                    'haiti' => 'HT',
                                    'heard island and mcdonald islands' => 'HM',
                                    'holy see (vatican city state)' => 'VA',
                                    'honduras' => 'HN',
                                    'hong kong' => 'HK',
                                    'hungary' => 'HU',
                                    'iceland' => 'IS',
                                    'india' => 'IN',
                                    'indonesia' => 'ID',
                                    'iran, islamic republic of' => 'IR',
                                    'iraq' => 'IQ',
                                    'ireland' => 'IE',
                                    'israel' => 'IL',
                                    'italy' => 'IT',
                                    'jamaica' => 'JM',
                                    'japan' => 'JP',
                                    'jordan' => 'JO',
                                    'kazakstan' => 'KZ',
                                    'kenya' => 'KE',
                                    'kiribati' => 'KI',
                                    'korea democratic peoples republic of' => 'KP',
                                    'korea republic of' => 'KR',
                                    'kuwait' => 'KW',
                                    'kyrgyzstan' => 'KG',
                                    'lao peoples democratic republic' => 'LA',
                                    'latvia' => 'LV',
                                    'lebanon' => 'LB',
                                    'lesotho' => 'LS',
                                    'liberia' => 'LR',
                                    'libyan arab jamahiriya' => 'LY',
                                    'liechtenstein' => 'LI',
                                    'lithuania' => 'LT',
                                    'luxembourg' => 'LU',
                                    'macau' => 'MO',
                                    'macedonia, the former yugoslav republic of' => 'MK',
                                    'madagascar' => 'MG',
                                    'malawi' => 'MW',
                                    'malaysia' => 'MY',
                                    'maldives' => 'MV',
                                    'mali' => 'ML',
                                    'malta' => 'MT',
                                    'marshall islands' => 'MH',
                                    'martinique' => 'MQ',
                                    'mauritania' => 'MR',
                                    'mauritius' => 'MU',
                                    'mayotte' => 'YT',
                                    'mexico' => 'MX',
                                    'micronesia, federated states of' => 'FM',
                                    'moldova, republic of' => 'MD',
                                    'monaco' => 'MC',
                                    'mongolia' => 'MN',
                                    'montserrat' => 'MS',
                                    'morocco' => 'MA',
                                    'mozambique' => 'MZ',
                                    'myanmar' => 'MM',
                                    'namibia' => 'NA',
                                    'nauru' => 'NR',
                                    'nepal' => 'NP',
                                    'netherlands' => 'NL',
                                    'netherlands antilles' => 'AN',
                                    'new caledonia' => 'NC',
                                    'new zealand' => 'NZ',
                                    'nicaragua' => 'NI',
                                    'niger' => 'NE',
                                    'nigeria' => 'NG',
                                    'niue' => 'NU',
                                    'norfolk island' => 'NF',
                                    'northern mariana islands' => 'MP',
                                    'norway' => 'NO',
                                    'oman' => 'OM',
                                    'pakistan' => 'PK',
                                    'palau' => 'PW',
                                    'palestinian territory, occupied' => 'PS',
                                    'panama' => 'PA',
                                    'papua new guinea' => 'PG',
                                    'paraguay' => 'PY',
                                    'peru' => 'PE',
                                    'philippines' => 'PH',
                                    'pitcairn' => 'PN',
                                    'poland' => 'PL',
                                    'portugal' => 'PT',
                                    'puerto rico' => 'PR',
                                    'qatar' => 'QA',
                                    'reunion' => 'RE',
                                    'romania' => 'RO',
                                    'russian federation' => 'RU',
                                    'rwanda' => 'RW',
                                    'saint helena' => 'SH',
                                    'saint kitts and nevis' => 'KN',
                                    'saint lucia' => 'LC',
                                    'saint pierre and miquelon' => 'PM',
                                    'saint vincent and the grenadines' => 'VC',
                                    'samoa' => 'WS',
                                    'san marino' => 'SM',
                                    'sao tome and principe' => 'ST',
                                    'saudi arabia' => 'SA',
                                    'senegal' => 'SN',
                                    'seychelles' => 'SC',
                                    'sierra leone' => 'SL',
                                    'singapore' => 'SG',
                                    'slovakia' => 'SK',
                                    'slovenia' => 'SI',
                                    'solomon islands' => 'SB',
                                    'somalia' => 'SO',
                                    'south africa' => 'ZA',
                                    'south georgia and the south sandwich islands' => 'GS',
                                    'spain' => 'ES',
                                    'sri lanka' => 'LK',
                                    'sudan' => 'SD',
                                    'suriname' => 'SR',
                                    'svalbard and jan mayen' => 'SJ',
                                    'swaziland' => 'SZ',
                                    'sweden' => 'SE',
                                    'switzerland' => 'CH',
                                    'syrian arab republic' => 'SY',
                                    'taiwan, province of china' => 'TW',
                                    'tajikistan' => 'TJ',
                                    'tanzania, united republic of' => 'TZ',
                                    'thailand' => 'TH',
                                    'togo' => 'TG',
                                    'tokelau' => 'TK',
                                    'tonga' => 'TO',
                                    'trinidad and tobago' => 'TT',
                                    'tunisia' => 'TN',
                                    'turkey' => 'TR',
                                    'turkmenistan' => 'TM',
                                    'turks and caicos islands' => 'TC',
                                    'tuvalu' => 'TV',
                                    'uganda' => 'UG',
                                    'ukraine' => 'UA',
                                    'united arab emirates' => 'AE',
                                    'united kingdom' => 'GB',
                                    'united states' => 'US',
                                    'united states minor outlying islands' => 'UM',
                                    'uruguay' => 'UY',
                                    'uzbekistan' => 'UZ',
                                    'vanuatu' => 'VU',
                                    'venezuela' => 'VE',
                                    'viet nam' => 'VN',
                                    'virgin islands, british' => 'VG',
                                    'virgin islands, u.s.' => 'VI',
                                    'wallis and futuna' => 'WF',
                                    'western sahara' => 'EH',
                                    'yemen' => 'YE',
                                    'yugoslavia' => 'YU',
                                    'zambia' => 'ZM',
                                    'zimbabwe' => 'ZW'
                                );

                                $address_info['country'] = strtolower(
                                        $address_info['country']);

                                if (isset($foo[$address_info['country']])) {
                                    $address->setCountryId(
                                            $foo[$address_info['country']]);
                                }
                            }

                            $address->save();
                            $order->addStatusHistoryComment(sprintf($helper->__(
                                                    'Payson updated the shipping address')));
                        }
                    } else {
                        $order->addStatusHistoryComment(sprintf(
                                        $helper->__('Payson pinged the order with status %s'), $ipn_response->status));
                    }

                    break;
                }

            case self::STATUS_ERROR:

                $order->cancel();

                $order->addStatusHistoryComment($helper->__('The order was denied by Payson.'));

                break;

            case self::STATUS_INCOMPLETE:
            case self::STATUS_EXPIRED:
            case self::STATUS_REVERSALERROR:
            default: {
                    $order->cancel();
                }
        }

        $order->save();

        // Update the database tables
        $db->update($order_log_table, array
            (
            'valid' => 1
                ), array
            (
            'id = ?' => $order_log_id
        ));

        $db->update($order_table, array
            (
            'ipn_status' => $ipn_response->status
                ), array
            (
            'id = ?' => $payson_order->id
        ));

        return $this;
    }

    /**
     * http://api.payson.se/#title12
     *
     * @params	int		$order_id	Real order id
     * @return	object				$this
     */
    public function PaymentDetails($order_id) {
        $helper = Mage::helper('payson');

        // Get the database connection
        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');

        $order_table = $resource->getTableName('payson_order');
        $order_log_table = $resource->getTableName('payson_order_log');

        /* Save fetch mode so that we can reset it and not mess up Magento
          functionality */
        $old_fetch_mode = $db->getFetchMode();
        $db->setFetchMode(Zend_Db::FETCH_OBJ);

        // Get payson order information and validate token
        $payson_order = $db->fetchRow(
                'SELECT
	id,
	token,
        store_id
FROM
	`' . $order_table . '`
WHERE
	valid = 1
AND
	order_id = ?
LIMIT
	0,1', $order_id);

        if ($payson_order === false) {
            Mage::throwException('Invalid order id (' . $order_id . ')');
        }

        $db->setFetchMode($old_fetch_mode);

        $args = array
            (
            'token' => $payson_order->token
        );
        $url = vsprintf(self::API_CALL_PAYMENT_DETAILS, $this->GetFormatIfTest($payson_order->store_id));

        $client = $this->GetHttpClient($url)
                ->setParameterPost($args);

        $response = Payson_Payson_Helper_Api_Response_Standard
                ::FromHttpBody($client->request()->getBody());

        $this->SetResponse($response);

        $db->insert($order_log_table, array
            (
            'payson_order_id' => $payson_order->id,
            'added' => new Zend_Db_Expr('NOW()'),
            'api_call' => 'payment_details',
            'valid' => (int) $response->IsValid(),
            'response' => serialize($response->ToArray())
        ));

        if (!$response->IsValid()) {
            throw new Mage_Core_Exception(sprintf($helper->__(
                            'Failed to retrieve payment details. Payson replied: %s'), $response->GetError()), $response->GetErrorId());
        }

        return $this;
    }

    /**
     * http://api.payson.se/#title13
     *
     * @params	int		$order_id	Real order id
     * @params	string	$action
     * @return	object				$this
     */
    public function PaymentUpdate($order_id, $action) {
        $helper = Mage::helper('payson');

        // Get the database connection
        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');

        $order_table = $resource->getTableName('payson_order');
        $order_log_table = $resource->getTableName('payson_order_log');

        /* Save fetch mode so that we can reset it and not mess up Magento
          functionality */
        $old_fetch_mode = $db->getFetchMode();
        $db->setFetchMode(Zend_Db::FETCH_OBJ);

        // Get payson order information and validate token
        $payson_order = $db->fetchRow(
                'SELECT
	id,
	token,
        store_id
FROM
	`' . $order_table . '`
WHERE
	valid = 1
AND
	order_id = ?
LIMIT
	0,1', $order_id);

        if ($payson_order === false) {
            Mage::throwException('Invalid order id (' . $order_id . ')');
        }

        $db->setFetchMode($old_fetch_mode);

        $args = array
            (
            'token' => $payson_order->token,
            'action' => $action
        );

        $client = $this->GetHttpClient(vsprintf(self::API_CALL_PAYMENT_UPDATE, $this->GetFormatIfTest($payson_order->store_id)))
                ->setParameterPost($args);

        $response = Payson_Payson_Helper_Api_Response_Standard
                ::FromHttpBody($client->request()->getBody());

        $this->SetResponse($response);

        $db->insert($order_log_table, array
            (
            'payson_order_id' => $payson_order->id,
            'added' => new Zend_Db_Expr('NOW()'),
            'api_call' => 'payment_update',
            'valid' => (int) $response->IsValid(),
            'response' => serialize($response->ToArray())
        ));

        /* if(!$response->IsValid())
          {
          // TODO: don't seem to return an errorList
          throw new Mage_Core_Exception(sprintf($helper->__(
          'Failed to update payment. Payson replied: %s'),
          $response->GetError()), $response->GetErrorId());
          } */

        return $this;
    }

    private function GetFormatIfTest($storeID = null, $isForwardURL = FALSE) {
        $config = Mage::getModel('payson/config');
        $stack = array();
        /* @var $isTest bool */
        $isTest = ($config->get('test_mode', $storeID) == "1");

        array_push($stack, self::DEBUG_MODE ? "http" : "https");
        array_push($stack, $isTest && !self::DEBUG_MODE ? "test-" : (self::DEBUG_MODE && !$isForwardURL ? "mvc" : ""));

        if ($isForwardURL == true) {
            array_push($stack, self::DEBUG_MODE ? "app" : "www");
        }

        array_push($stack, self::DEBUG_MODE ? "local" : "se");
        array_push($stack, self::DEBUG_MODE ? "Payment" : "1.0");

        array_push($stack, self::DEBUG_MODE ? "" : "Payment");
        //print_r($stack);exit;
        return $stack;
    }

    public function getIpnStatus($order_id) {
        $resource = Mage::getSingleton('core/resource');
        $db = $resource->getConnection('core_write');
        $order_table = $resource->getTableName('payson_order');
        $query = 'SELECT ipn_status FROM `' . $order_table . '` WHERE order_id = ' . $order_id;
        return $db->fetchRow($query);
    }

    public function paysonApiError($error) {
        $error_code = '<html>
                            <head>
                            <meta http-equiv="Content-Type" content="text/html" charset="utf-8" />
				<script type="text/javascript"> 
                                    alert("' . $error . '");
                                    window.location="' . ('/index.php') . '";
				</script>
                            </head>
                           </html>';
        echo $error_code;
        exit;
    }

}

