<?php

class Reverb_ReverbSync_Model_Observer
{
    protected $_logSingleton = null;

    //function to create the product in reverb
    public function productSave($observer)
    {
      try
      {
          $product = $observer->getProduct();
          $product_id = $product->getId();

          $syncToReverb = $product->getData('reverb_sync');
          if (is_null($syncToReverb)) {
              // TODO: This will potentially generate one SQL query per product saved.  Revamp this to queue the check and process in async batches.
              $syncToReverb = Mage::getResourceModel('catalog/product')->getAttributeRawValue($product_id, 'reverb_sync');
          }

          if (!$syncToReverb) {
            // Sync To Reverb is disabled for this product
            return;
          }

          $productSyncHelper = Mage::helper('ReverbSync/sync_product');
          $listingWrapper = $productSyncHelper->executeIndividualProductDataSync($product_id);
        }
        catch(Reverb_ReverbSync_Model_Exception_Product_Excluded $e)
        {
            // If the product has been listed as being excluded from the sync, don't prevent product save
            $this->_getLogSingleton()->setSessionErrorIfAdminIsLoggedIn($e->getMessage());
            return;
        }
        catch(Reverb_ReverbSync_Model_Exception_Deactivated $e)
        {
            // If the module is deactivated, don't prevent product save
            $this->_getLogSingleton()->setSessionErrorIfAdminIsLoggedIn($e->getMessage());
            return;
        }
        catch(Exception $e)
        {
            // Any other Exception is understood to prevent product save
            throw $e;
        }

        try
        {
            $product = $observer->getProduct();
            // If we have reached this point, and the create/update performed above was successful, and the admin
            //      uploaded any new images, queue image syncs for each of the new images
            if ($listingWrapper->wasCallSuccessful())
            {
                Mage::helper('ReverbSync/sync_image')->queueImageSyncForProductGalleryImages($product, true);
            }
        }
        catch(Exception $e)
        {
            // Exceptions during image sync should NOT prevent product save
        }
    }

    // function to get the product quantity placed through order
    public function orderSave($observer)
    {
        try
        {
            $productSyncHelper = Mage::helper('ReverbSync/sync_product');
            $order = $observer -> getEvent() -> getOrder();

            foreach ($order->getAllItems() as $item)
            {
                try
                {
                    $product_id = $item->getProductId();
                    $productSyncHelper->executeIndividualProductDataSync($product_id, true);
                }
                catch(Reverb_ReverbSync_Model_Exception_Product_Excluded $e)
                {
                    // If the product has been listed as being excluded from the sync, don't log an exception
                    $this->_getLogSingleton()->setSessionErrorIfAdminIsLoggedIn($e->getMessage());
                }
                catch(Reverb_ReverbSync_Model_Exception_Deactivated $e)
                {
                    // If the module is deactivated, don't log an exception
                    $this->_getLogSingleton()->setSessionErrorIfAdminIsLoggedIn($e->getMessage());
                }
                catch(Exception $e)
                {
                    Mage::logException($e);
                }
            }
        }
        catch(Exception $e)
        {
            Mage::logException($e);
        }
    }

    protected function _getLogSingleton()
    {
        if (is_null($this->_logSingleton))
        {
            $this->_logSingleton = Mage::getSingleton('reverbSync/log');
        }

        return $this->_logSingleton;
    }
}
