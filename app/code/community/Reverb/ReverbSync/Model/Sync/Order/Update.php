<?php
/**
 * Author: Sean Dunagan
 * Created: 9/10/15
 */

class Reverb_ReverbSync_Model_Sync_Order_Update extends Reverb_ProcessQueue_Model_Task
{
    const ERROR_ORDER_NOT_CREATED = 'Reverb Order with id %s has not been created in the Magento system yet';
    const EXCEPTION_EXECUTING_STATUS_UPDATE = 'Exception occurred while executing the status update for order with magento entity id %s to status %s: %s';
    const SUCCESS_ORDER_STATUS_UPDATED = 'The order\'s status has been updated to %s';

    public function updateReverbOrderInMagento(stdClass $argumentsObject)
    {
        if (!Mage::helper('ReverbSync/orders_sync')->isOrderSyncEnabled())
        {
            $error_message = Mage::helper('ReverbSync/orders_sync')->logOrderSyncDisabledMessage();
            Mage::getModel('reverbSync/log')->logOrderSyncError($error_message);
            return $this->_returnAbortCallbackResult($error_message);
        }

        $reverb_order_number = $argumentsObject->order_number;
        // Check to ensure the order has been created
        $magento_order_entity_id = Mage::getResourceSingleton('reverbSync/order')
                                ->getMagentoOrderEntityIdByReverbOrderNumber($reverb_order_number);

        if (empty($magento_order_entity_id))
        {
            // Need to wait for the order to be created
            $error_message = Mage::helper('ReverbSync')->__(self::ERROR_ORDER_NOT_CREATED, $reverb_order_number);
            // Set this task to be processed again
            return $this->_returnErrorCallbackResult($error_message);
        }

        $reverb_order_status = $argumentsObject->status;

        return $this->_executeStatusUpdate($magento_order_entity_id, $reverb_order_status);
    }

    protected function _executeStatusUpdate($magento_order_entity_id, $reverb_order_status)
    {
        try
        {




            $reverb_order_status = 'paid';








            // Start a database transaction
            Mage::getResourceSingleton('sales/order')->beginTransaction();

            $event_name = 'reverb_order_status_update_' . $reverb_order_status;

            // Fire event to allow for executing functionality upon order cancels/refunds
            Mage::dispatchEvent($event_name,
                                    array('order_entity_id' => $magento_order_entity_id,
                                          'reverb_order_status' => $reverb_order_status)
            );
            // Update the reverb_order_status field on the sales_flat_order table
            $updated_rows = Mage::getResourceSingleton('reverbSync/order')
                                ->updateReverbOrderStatusByMagentoEntityId($magento_order_entity_id, $reverb_order_status);

            Mage::getResourceSingleton('sales/order')->commit();
        }
        catch(Reverb_ReverbSync_Model_Exception_Order_Update_Status_Redundant $e)
        {
            // Assume we have already processed this order update
            Mage::getResourceSingleton('sales/order')->rollBack();
            return $this->_returnSuccessCallbackResult('The order has been updated');
        }
        catch(Exception $e)
        {
            Mage::getResourceSingleton('sales/order')->rollBack();

            $error_message = Mage::helper('ReverbSync')
                                ->__(self::EXCEPTION_EXECUTING_STATUS_UPDATE, $magento_order_entity_id,
                                        $reverb_order_status, $e->getMessage());
            Mage::getSingleton('reverbSync/log')->logOrderSyncError($error_message);

            return $this->_returnAbortCallbackResult($error_message);
        }

        $success_message = Mage::helper('ReverbSync')->__(self::SUCCESS_ORDER_STATUS_UPDATED, $reverb_order_status);
        return $this->_returnSuccessCallbackResult($success_message);
    }
}
