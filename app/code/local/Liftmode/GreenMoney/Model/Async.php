<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_GreenMoney
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_GreenMoney_Model_Async extends Mage_Core_Model_Abstract
{

    public function __construct()
    {
        parent::__construct();
        $this->_model = Mage::getModel('greenmoney/method_greenMoney');
    }


    /**
     * Poll Amazon API to receive order status and update Magento order.
     */
    public function syncOrderStatus(Mage_Sales_Model_Order $order, $isManualSync = false)
    {
        try {
            $orderTransactionId = $this->_model->_getParentTransactionId($order->getPayment());

            if ($orderTransactionId) {
                $data = $this->_model->_doGetStatus($orderTransactionId);

                $this->_model->log(array('syncOrderStatus------>>>', $order->getIncrementId(), $orderTransactionId, $data));

                if (isset($data['found']) && (int) $data['found'] === 0 && isset($data['verifystatus']) && (int) $data['verifystatus'] === 0) {
                    $this->putOrderOnProcessing($order);
                    return;
                } elseif (isset($data['found']) && (int) $data['found'] === 0 && isset($data['verifystatus']) && (int) $data['verifystatus'] === 3) {
                    $this->putOrderOnHold($order, $data['verifydescr']);
                } elseif (in_array($data['status'], array('Deleted', 'Rejected'))) {
                    $this->putOrderOnHold($order, $data['verifydescr']);
                }

                if (Mage::getStoreConfig('slack/general/enable_notification')) {
                    $notificationModel   = Mage::getSingleton('mhauri_slack/notification');
                    $notificationModel->setMessage(
                        Mage::helper($this->_model->_code)->__("*GreenMoney veryfication payment failed with data:*\nGreenMoney response ```%s```\n\nTransaction Data: ```%s```\n\nOrderId ```%s```", json_encode($data), json_encode($orderTransactionId), $order->getIncrementId())
                    )->send(array('icon_emoji' => ':cop::skin-tone-4:'));
                }
            } else {
                $this->putOrderOnHold($order, 'No transaction found, you should manually make invoice');
            }
        } catch (Exception $e) {
            Mage::logException($e);
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
                ->addFieldToFilter('method', $this->_model->_code)
                ->addFieldToFilter('state',  array('in' => array(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT, Mage_Sales_Model_Order::STATE_PROCESSING)))
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
        $this->_model->log(array('putOrderOnProcessing------>>>', $order->getIncrementId(), $order->canShip()));

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
            } catch (Exception $e) {
                $this->_model->log(array('putOrderOnProcessing---->>>>', $e->getMessage()));
            }
        }
    }

    public function putOrderOnHold(Mage_Sales_Model_Order $order, $reason)
    {
        $this->_model->log(array('putOrderOnHold------>>>', $order->getIncrementId()));

        // Change order to "On Hold"
        try {
            $order->hold();
            $order->addStatusToHistory($order->getStatus(), $reason, false);
            $order->save();
        } catch (Exception $e) {
            $this->_model->log(array('putOrderOnProcessing---->>>>', $e->getMessage()));
        }
    }
}
