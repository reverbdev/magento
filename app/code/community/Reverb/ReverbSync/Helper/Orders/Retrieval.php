<?php
/**
 * Author: Sean Dunagan
 * Created: 8/22/15
 */

abstract class Reverb_ReverbSync_Helper_Orders_Retrieval extends Reverb_ReverbSync_Helper_Data
{
    const EXCEPTION_QUEUE_MAGENTO_ORDER_ACTION = "Error attempting to queue Magento order %s for Reverb order: %s.\nThe json_encoded order data object was: %s";
    const ORDER_NUMBER_EMPTY = 'An attempt was made to create a Reverb order in Magento without specifying a valid Reverb order number. This order can not be synced.';
    const EXCEPTION_QUEUE_ORDER_ACTION = 'Error trying to queue order creation for Reverb order with number %s: %s';
    const ERROR_NO_ORDER_ACTION_QUEUE_ROWS_INSERTED = 'No order creation queue rows were inserted for Reverb order with number %s';

    const NEXT_PAGE_OF_RESULTS_FIELD = '_links.next.href';

    protected $_moduleName = 'ReverbSync';

    protected $_logModel = null;
    protected $_orderTaskResourceSingleton = null;
    protected $_orderSyncHelper = null;

    abstract public function getOrderSyncAction();

    abstract public function queueOrderActionByReverbOrderDataObject(stdClass $orderDataObject);

    abstract protected function _getAPICallUrlPathTemplate();

    abstract protected function _getMinutesInPastForAPICall();

    abstract public function getAPICallDescription();

    public function queueReverbOrderSyncActions()
    {
        if (!Mage::helper('ReverbSync/orders_sync')->isOrderSyncEnabled())
        {
            $exception_message = Mage::helper('ReverbSync/orders_sync')->getOrderSyncIsDisabledMessage();
            throw new Reverb_ReverbSync_Model_Exception_Deactivated_Order_Sync($exception_message);
        }

        return $this->_retrieveAndQueueOrders();
    }

    protected function _retrieveAndQueueOrders()
    {
        $api_url_path_to_call = $this->_getDefaultOrderRetrievalApiUrlPath();

        do
        {
            $reverbOrdersJsonObject = $this->_retrieveOrdersJsonFromReverb($api_url_path_to_call);

            if (!is_object($reverbOrdersJsonObject))
            {
                return false;
            }

            $orders_array = $reverbOrdersJsonObject->orders;

            if (!is_array($orders_array))
            {
                return false;
            }

            foreach ($orders_array as $orderDataObject)
            {
                try
                {
                    $this->_attemptToQueueMagentoOrderActions($orderDataObject);
                }
                catch(Exception $e)
                {
                    $order_sync_action = $this->getOrderSyncAction();
                    $error_message = $this->__(self::EXCEPTION_QUEUE_MAGENTO_ORDER_ACTION, $order_sync_action, $e->getMessage(), json_encode($orderDataObject));
                    $this->_logError($error_message);
                    $exceptionToLog = new Exception($error_message);
                    Mage::logException($exceptionToLog);
                }
            }

            $api_url_path_to_call = $this->_getNextPageOfResultsUrlPath($reverbOrdersJsonObject);
        }
        while(!empty($api_url_path_to_call));

        return true;
    }

    /**
     * Returns the url path for the next page of orders results if more results exist
     *
     * @param stdClass $reverbOrdersJsonObject
     * @return string
     */
    protected function _getNextPageOfResultsUrlPath($reverbOrdersJsonObject)
    {
        $next_page_of_results_field = self::NEXT_PAGE_OF_RESULTS_FIELD;
        if(property_exists($reverbOrdersJsonObject, $next_page_of_results_field))
        {
            $next_page_of_results_url_path = $reverbOrdersJsonObject->$next_page_of_results_field;
            return $next_page_of_results_url_path;
        }
        return null;
    }

    protected function _attemptToQueueMagentoOrderActions(stdClass $orderDataObject)
    {
        $order_number = $orderDataObject->order_number;
        if (empty($order_number))
        {
            $error_message = $this->__(self::ORDER_NUMBER_EMPTY);
            throw new Exception($error_message);
        }

        try
        {
            $row_was_inserted = $this->queueOrderActionByReverbOrderDataObject($orderDataObject);

            if (empty($row_was_inserted))
            {
                $error_message = $this->__(self::ERROR_NO_ORDER_ACTION_QUEUE_ROWS_INSERTED, $order_number);
                throw new Exception($error_message);
            }
        }
        catch(Exception $e)
        {
            $error_message = $this->__(self::EXCEPTION_QUEUE_ORDER_ACTION, $order_number, $e->getMessage());
            throw new Exception($error_message);
        }

        return true;
    }

    protected function _retrieveOrdersJsonFromReverb($api_url_path)
    {
        $base_url = $this->_getReverbAPIBaseUrl();
        $api_url = $base_url . $api_url_path;

        $curlResource = $this->_getCurlResource($api_url);
        //Execute the API call
        $json_response = $curlResource->read();
        $status = $curlResource->getRequestHttpCode();
        // Need to grab any potential errors before closing the resource
        $curl_error_message = $curlResource->getCurlErrorMessage();
        // Log the Response
        $curlResource->logRequest();
        $curlResource->close();
        $this->_logApiCall($api_url_path, $json_response, $this->getAPICallDescription(), $status);

        if (!empty($curl_error_message))
        {
            throw new Exception($curl_error_message);
        }

        $json_decoded_response = json_decode($json_response);

        return $json_decoded_response;
    }

    protected function _getDefaultOrderRetrievalApiUrlPath()
    {
        $api_call_url_path_template = $this->_getAPICallUrlPathTemplate();

        $local_timezone_timestamp = Mage::getModel('core/date')->timestamp();
        $minutes_in_past_for_api_call = $this->_getMinutesInPastForAPICall();
        $past_timestamp_local_timezone = $local_timezone_timestamp - (60 * $minutes_in_past_for_api_call);
        $past_gmt_datetime = Mage::getModel('core/date')->gmtDate('c', $past_timestamp_local_timezone);

        $api_url_path = sprintf($api_call_url_path_template, $past_gmt_datetime);
        $api_url_path = str_replace('+', '-', $api_url_path);

        return $api_url_path;
    }

    protected function _getOrderTaskResourceSingleton()
    {
        if (is_null($this->_orderTaskResourceSingleton))
        {
            $this->_orderTaskResourceSingleton = Mage::getResourceSingleton('reverbSync/task_order');
        }

        return $this->_orderTaskResourceSingleton;
    }

    protected function _logError($error_message)
    {
        $this->_getLogModel()->logOrderSyncError($error_message);
    }

    protected function _getOrderSyncHelper()
    {
        if (is_null($this->_orderSyncHelper))
        {
            $this->_orderSyncHelper = Mage::helper('ReverbSync/orders_sync');
        }

        return $this->_orderSyncHelper;
    }

    protected function _getLogModel()
    {
        if (is_null($this->_logModel))
        {
            $this->_logModel = Mage::getModel('reverbSync/log');
        }

        return $this->_logModel;
    }
}
