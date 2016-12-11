<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_GreenMoney
 * @copyright  Copyright (c)  LiftMode (Synaptent LLC).
 */

class Liftmode_GreenMoney_Model_Async extends Mage_Core_Model_Abstract
{
    /**
     * Poll Amazon API to receive order status and update Magento order.
     */
    public function syncOrderStatus(Mage_Sales_Model_Order $order, $isManualSync = false)
    {
        try {
            $greenMoney = Mage::getModel('greenmoney/method_greenMoney');

            $orderTransactionId = $greenMoney->_getParentTransactionId($order->getPayment());
            if ($orderTransactionId) {
                $data = $greenMoney->_doGetStatus($orderTransactionId);

                Mage::log(array('syncOrderStatus------>>>', $order->getIncrementId(), $orderTransactionId, $data), null, 'GreenMoney.log');

                if ($data['status'] == 'Received') {
                    $this->putOrderOnProcessing($order);

                    Mage::getSingleton('adminhtml/session')->addSuccess('Payment has been sent for orderId: ' . $order->getIncrementId());
                } elseif (in_array($data['status'], array('Deleted', 'Rejected'))) {
                    $this->putOrderOnHold($order);
                }
            } else {
                $this->putOrderOnHold($order, 'No transaction found, you should manually make invoice');
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
    }

    /**
     * Magento cron to sync Amazon orders
     */
    public function cron()
    {
        if(Mage::getStoreConfig('payment/greenmoney/async')) {
            $orderCollection = Mage::getModel('sales/order_payment')
                ->getCollection()
                ->join(array('order'=>'sales/order'), 'main_table.parent_id=order.entity_id', 'state')
                ->addFieldToFilter('method', 'greenmoney')
//                ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
                ->addFieldToFilter('status', Mage_Index_Model_Process::STATUS_PENDING)
            ;

//            echo $orderCollection->getSelect()->__toString();
            foreach ($orderCollection as $orderRow) {
                $order = Mage::getModel('sales/order')->load($orderRow->getParentId());
                $this->syncOrderStatus($order);
            }
        }
    }

    public function putOrderOnProcessing(Mage_Sales_Model_Order $order)
    {
        Mage::log(array('putOrderOnProcessing------>>>', $order->getIncrementId(), $order->canShip()), null, 'GreenMoney.log');

        // Change order to "On Process"
        if ($order->canShip()) {
            // Save the payment changes
            try {
                $payment = $order->getPayment();
                $payment->setIsTransactionClosed(1);
                $payment->save();

                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
                $order->setStatus('processing');

                $order->addStatusToHistory($order->getStatus(), 'We recieved your payment, thank you!', true);
                $order->save();

                Mage::getSingleton('adminhtml/session')->addSuccess(Mage::helper('greenmoney')->__('We recieved your payment for order id: %s. Order was paid by GreenMoney', $order->getIncrementId()));
            } catch (Exception $e) {
                Mage::log(array('putOrderOnProcessing---->>>>', $e->getMessage()), null, 'GreenMoney.log');
            }
        }
    }

    public function putOrderOnHold(Mage_Sales_Model_Order $order, $reason)
    {
        Mage::log(array('putOrderOnHold------>>>', $order->getIncrementId()), null, 'GreenMoney.log');

        // Change order to "On Hold"
        try {
            $order->hold();
            $order->addStatusToHistory($order->getStatus(), $reason, false);
            $order->save();
        } catch (Exception $e) {
            Mage::log(array('putOrderOnProcessing---->>>>', $e->getMessage()), null, 'GreenMoney.log');
        }
    }
}
