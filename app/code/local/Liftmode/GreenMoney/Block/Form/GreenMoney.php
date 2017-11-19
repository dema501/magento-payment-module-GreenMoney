<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_GreenMoney
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_GreenMoney_Block_Form_GreenMoney extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('payment/form/greenmoney.phtml');
    }
}
