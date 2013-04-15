<?php
class Payson_Payson_Model_Quote_Address_Total_Invoice extends 
	Mage_Sales_Model_Quote_Address_Total_Abstract
{
	protected $_code = 'payson_invoice';

	public function collect(Mage_Sales_Model_Quote_Address $address)
	{
		if($address->getAddressType() !== 'shipping')
		{
			return $this;
		}

		$address->setBasePaysonInvoiceFee(0);
		$address->setPaysonInvoiceFee(0);

		$quote = $address->getQuote();

		if(is_null($quote->getId()))
		{
			return $this;
		}

		$method = $address->getQuote()->getPayment()->getMethod();

		if($method !== 'payson_invoice')
		{
			return $this;
		}

		$store = $quote->getStore();
		$config = Mage::getModel('payson/config');

		$fee = $config->GetInvoiceFeeInclTax($quote);

		$base_grand_total = $address->getBaseGrandTotal();
		$base_grand_total += $fee;

		// TODO: update tax in another model?

		$address->setBasePaysonInvoiceFee($fee);
		$address->setPaysonInvoiceFee($store->convertPrice($fee, false));

		$address->setBaseGrandTotal($base_grand_total);
		$address->setGrandTotal($store->convertPrice($base_grand_total, false));

		//$this->_addBaseAmount($fee);
		//$this->_addAmount($fee);

		return $this;
	}

	public function fetch(Mage_Sales_Model_Quote_Address $address)
	{
		if(($fee = $address->getPaysonInvoiceFee()) > 0)
		{
			$address->addTotal(array
				(
					'code'	=> $this->getCode(),
					'title'	=> Mage::helper('payson')->__('Invoice fee'),
					'value'	=> $fee
				));
		}

		return $this;
	}
}

