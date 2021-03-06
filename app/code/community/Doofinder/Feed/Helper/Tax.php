<?php
/**
 * This file is part of Doofinder_Feed.
 */

/**
 * @category   Helpers
 * @package    Doofinder_Feed
 * @version    1.6.10
 */

/**
 * Tax helper for Doofinder Feed
 *
 * @version    1.6.10
 * @package    Doofinder_Feed
 */
class Doofinder_Feed_Helper_Tax extends Mage_Tax_Helper_Data
{
    public function needPriceConversion($store = null)
    {
        return true;
    }
}
