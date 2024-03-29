<?php
/**
 *
 * @category   Mage
 * @package    Liftmode_GreenMoney
 * @copyright  Copyright (c)  Dmitry Bashlov, contributors.
 */

class Liftmode_GreenMoney_Model_Method_GreenMoney extends Mage_Payment_Model_Method_Abstract
{
    const PAYMENT_METHOD_EDEBITDIRECT_CODE = 'greenmoney';

    protected $_code = self::PAYMENT_METHOD_EDEBITDIRECT_CODE;

    protected $_formBlockType = 'greenMoney/form_greenMoney';
    protected $_infoBlockType = 'greenMoney/info_greenMoney';

    protected $_isGateway                   = true;
    protected $_canOrder                    = true;
    protected $_canAuthorize                = true;
    protected $_canCapture                  = true;
    protected $_isInitializeNeeded          = false;
    protected $_canVoid                     = true;
    protected $_canRefund                   = true;


    /**
     * Authorize payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);

        $results = $this->_callSoapFunc(
            'WooCheck',
            $this->_doPrepareCheckData($payment),
            '_handleCheckResponse'
        );

        $payment->setTransactionId($results['Check_ID'])
            ->setIsTransactionClosed(0);

        return $this;
    }


    private function _doPrepareCheckData(Varien_Object $payment)
    {
        $order = $payment->getOrder();
        $billing = $order->getBillingAddress();

        return array (
            'Client_ID'              => Mage::helper('core')->decrypt($this->getConfigData('login')),
            'ApiPassword'            => Mage::helper('core')->decrypt($this->getConfigData('trans_key')),
            'Name'                   => strval($billing->getFirstname()) . ' ' . strval($billing->getLastname()),
            'EmailAddress'           => strval($order->getCustomerEmail()),
            'PhoneExtension'         => '',
            'Phone'                  => substr(str_replace(array(' ', '(', ')', '+', '-'), '', strval($billing->getTelephone())), -10),
            'Address1'               => strval($billing->getStreet(1)),
            'Address2'               => strval($billing->getStreet(2)),
            'City'                   => strval($billing->getCity()),
            'State'                  => strval($billing->getRegionCode()),
            'Zip'                    => strval($billing->getPostcode()),
            'Country'                => strval($billing->getCountryId()),
            'RoutingNumber'          => $payment->getRoutingNumber(),
            'AccountNumber'          => $payment->getAccountNumber(),
            'CheckMemo'              => 'Order ' . $order->getIncrementId() . ' at ' . Mage::app()->getStore()->getFrontendName() . '.',
            'CheckAmount'            => $payment->getAmount(),
            'CheckDate'              => $order->getCreatedAtStoreDate()->toString('MM/dd/yyyy'),
        );
    }


    private function _handleCheckResponse($resp)
    {
        $this->log(array('handleCheckResponse------>>>', $resp));

        return array (
            'CheckNumber' => $resp->CheckNumber,
            'Check_ID'    => $resp->Check_ID,
        );
    }


    /**
     * Check refund availability
     *
     * @return bool
     */
    public function canRefund()
    {
        return $this->_canRefund;
    }


    /**
     * Check void availability
     *
     * @param   Varien_Object $invoicePayment
     * @return  bool
     */
    public function canVoid(Varien_Object $payment)
    {
        return $this->_canVoid;
    }


    /**
     * Capture payment abstract method
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for authorization.'));
        }

        $payment->setAmount($amount);

        $results = $this->_callSoapFunc(
            'WooCheck',
            $this->_doPrepareCheckData($payment),
            '_handleCheckResponse'
        );

        $payment->setTransactionId($results['Check_ID'])
            ->setIsTransactionClosed(0);

        return $this;
    }


    /**
     * Void payment abstract method
     *
     * @param Varien_Object $payment
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function cancel(Varien_Object $payment)
    {
        return $this->void($payment);
    }


    /**
     * Void payment abstract method
     *
     * @param Varien_Object $payment
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function void(Varien_Object $payment)
    {
        $orderTransactionId = $this->_getParentTransactionId($payment);

        if (!$orderTransactionId) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid transaction ID.'));
        }

        $results = $this->_callSoapFunc(
            'WooCheckCancel',
            array (
                'Client_ID'              => Mage::helper('core')->decrypt($this->getConfigData('login')),
                'ApiPassword'            => Mage::helper('core')->decrypt($this->getConfigData('trans_key')),
                'Check_ID'               => $orderTransactionId,
            ),
            '_handleCancelResponse'
        );

        $payment->setStatus(self::STATUS_DECLINED)
                ->setShouldCloseParentTransaction(1)
                ->setIsTransactionClosed(1);

        return $this;
    }


    private function _handleCancelResponse($resp)
    {
        $this->log(array('handleCancelResponse------>>>', $resp));

        return array(
//            'RefundCheck_ID'    => $resp->RefundCheck_ID,
//            'RefundCheckNumber' => $resp->RefundCheckNumber,
        );
    }


    /**
     * Refund specified amount for payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     *
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid amount for refund.'));
        }

        $orderTransactionId = $this->_getParentTransactionId($payment);

        if (!$orderTransactionId) {
            Mage::throwException(Mage::helper($this->_code)->__('Invalid transaction ID.'));
        }


        $results = $this->_callSoapFunc(
            'WooCheckRefund',
            array (
                'Client_ID'              => Mage::helper('core')->decrypt($this->getConfigData('login')),
                'ApiPassword'            => Mage::helper('core')->decrypt($this->getConfigData('trans_key')),
                'Check_ID'               => $orderTransactionId,
                'RefundMemo'             => 'Refund for Order ' . $payment->getOrder()->getIncrementId() . ' at ' . Mage::app()->getStore()->getFrontendName() . '. Thank you.',
                'RefundAmount'           => $amount,
            ),
            '_handleRefundResponse'
        );

        $payment->setIsTransactionClosed(1);

        return $this;
    }


    private function _handleRefundResponse($resp)
    {
        $this->log(array('handleRefundResponse------>>>', $resp));

        return array(
            'RefundCheck_ID'    => $resp->RefundCheck_ID,
            'RefundCheckNumber' => $resp->RefundCheckNumber,
        );
    }


    /**
     * Parent transaction id getter
     *
     * @param Varien_Object $payment
     * @return string
     */
    public function _getParentTransactionId(Varien_Object $payment)
    {
        return $payment->getParentTransactionId() ? $payment->getParentTransactionId() : $payment->getLastTransId();
    }


    /**
     * Assign data to info model instance
     *
     * @param   mixed $data
     * @return  Liftmode_GreenMoney_Model_GreenMoney
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        $info = $this->getInfoInstance();

        $info->setRoutingNumber($data->getRoutingNumber())
            ->setAccountNumber($data->getAccountNumber());

        return $this;
    }


    /**
     * Return url of payment method
     *
     * @return string
     */
    public function getUrl()
    {
        return 'https://greenbyphone.com/ecart.asmx?wsdl';
    }


    private function _callSoapFunc($action = '', $data = array(), $callBackFn)
    {
        $result = array();

        try {
            $client = new SoapClient(
                $this->getUrl(),
                array(
                    'wsdl_cache'             => 0,
                    'trace'                  => 1,

                    'exception'              => 1,
                    'style'                  => SOAP_DOCUMENT,
                    'use'                    => SOAP_LITERAL,
                    'soap _version'          => SOAP_1_2,
                    'encoding'               => 'UTF-8',

                    'verifypeer'             => false,
                    'verifyhost'             => false,
                    'connection_timeout'     => 180,
                )
            );

            $resp = $client->__soapCall($action, array($action => $data));

            $resultMethod = $action . 'Result';

            $this->log(array('callSoapFunc------>>>', $resultMethod, $resp, $data, $client->__getLastRequest()));

            if ($resp->{$resultMethod}->Result) {
                Mage::throwException(Mage::helper($this->_code)->__("Error during process payment: response code: %s %s", $resp->{$resultMethod}->Result, $resp->{$resultMethod}->ResultDescription));
            } else {
                $result = call_user_func(array($this, $callBackFn), $resp->{$resultMethod});
            }
         } catch (SoapFault $fault) {
            $this->log(array('callSoapFuncErr------>>>', $fault->faultstring));

            Mage::throwException(Mage::helper($this->_code)->__("Error during process payment: response code: %s %s", '', $fault->faultstring));
         }

         return $result;
    }


    public function _doGetStatus($check_id)
    {
        return  $this->_callSoapFunc(
            'WooCheckStatus',
            array (
                'Client_ID'              => Mage::helper('core')->decrypt($this->getConfigData('login')),
                'ApiPassword'            => Mage::helper('core')->decrypt($this->getConfigData('trans_key')),
                'Check_ID'               => $check_id,
            ),
            '_handleStatusResponse'
        );
    }



    public function log($data)
    {
        Mage::log($data, null, 'GreenMoney.log');
    }


    private function _handleStatusResponse($resp)
    {
        $this->log(array('handleStatusResponse------>>>', $resp));

        $res['status'] = 'Received';

        $res['found']         = $resp->Result;
        $res['verifystatus']  = $resp->VerifyResult;
        $res['verifydescr']   = $resp->VerifyResultDescription;

        if ('True' === (string) $resp->Deleted) {
            $res['status'] = 'Deleted';
        } elseif ('True' === (string) $resp->Rejected) {
            $res['status'] = 'Rejected';
        } elseif ('True' === (string) $resp->Processed) {
            $res['status'] = 'Processed';
        }

        return $res;
    }
}
