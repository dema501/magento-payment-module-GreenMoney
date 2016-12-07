<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_GreenMoney
 * @copyright  Copyright (c)  LiftMode (Synaptent LLC).
 */

class Liftmode_GreenMoney_Block_Info_GreenMoney extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('payment/info/greenmoney.phtml');
    }

    public function getAccountNumber()
    {
        return ('XXXX' . substr($this->getInfo()->getAccountNumber(), -4));
    }
}
