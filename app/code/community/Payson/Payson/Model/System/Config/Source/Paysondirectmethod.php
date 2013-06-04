<?php
/**
 * My own options
 *
 */
class Payson_Payson_Model_System_Config_Source_Paysondirectmethod
{

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return array(
            array('value' => 0, 'label'=>Mage::helper('adminhtml')->__('CREDITCARD / BANK')),
            array('value' => 1, 'label'=>Mage::helper('adminhtml')->__('CREDITCARD')),
            array('value' => 2, 'label'=>Mage::helper('adminhtml')->__('BANK')),
        );
    }

}


