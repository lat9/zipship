<?php
/**
 * @package shippingMethod
 * @copyright Copyright 2003-2006 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: zones.php 4805 2006-10-22 02:32:33Z ajeh $
 * Modified for Zip Code usage by Ruth Woody - http://www.randbhosting.net
 * 1/25/2007 - for Zen-Cart 1.3.7
 */
/*

  USAGE
  By default, the module comes with support for 3 postcode zones.  This can be
  easily changed by editing the line below in the zones constructor
  that defines $this->num_zones.

  Next, you will want to activate the module by going to the Admin screen,
  clicking on Modules, then clicking on Shipping.  A list of all shipping
  modules should appear.  Click on the green dot next to the one labeled
  zones.php.  A list of settings will appear to the right.  Click on the
  Edit button.

  PLEASE NOTE THAT YOU WILL LOSE YOUR CURRENT SHIPPING RATES AND OTHER
  SETTINGS IF YOU TURN OFF THIS SHIPPING METHOD.  Make sure you keep a
  backup of your shipping settings somewhere at all times.

  If you want an additional handling charge applied to orders that use this
  method, set the Handling Fee field.

  Next, you will need to define which zip codes are in each zone.  Determining
  this might take some time and effort.  You should group a set of zip codes
  that has similar shipping charges for the same weight.

  When you enter these postcode lists, enter them into the Zone X Zipcodes
  fields, where "X" is the number of the zone.  They should be entered as
  five digit postcodes.  They should be
  separated by commas with no spaces or other punctuation. For example:
    1: 32903,32937
    2: 32901,32935
    3: 32940,32904
  Now you need to set up the shipping/deliver rate tables for each zone.  Again,
  some time and effort will go into setting the appropriate rates.  You
  will define a set of weight ranges and the shipping price for each
  range.  For instance, you might want an order than weighs more than 0
  and less than or equal to 3 to cost 5.50 to ship to a certain zone.
  This would be defined by this:  3:5.5

  You should combine a bunch of these rates together in a comma delimited
  list and enter them into the "Zone X Shipping Table" fields where "X"
  is the zone number.  For example, this might be used for Zone 1:
    1:3.5,2:3.95,3:5.2,4:6.45,5:7.7,6:10.4,7:11.85, 8:13.3,9:14.75,10:16.2,11:17.65,
    12:19.1,13:20.55,14:22,15:23.45

  The above example includes weights over 0 and up to 15.  Note that
  units are not specified in this explanation since they should be
  specific to your locale.

  CAVEATS
  At this time, it does not deal with weights, prices and or units that are
  above the highest amount    defined.  This will probably be the next area
  to be improved with the   module.  For now, you could have one last very
  high range with a very high shipping rate to include those amounts.
  instance:  999:1000
*/
// -----
// 20161027-lat9: Restructured to work with One-Page Checkout and PHP 7+, v2.0.0
//
// - Use PSR indentation
// - Add "extends base" to class definition
// - Rename constructor to __construct for PHP 7+ usage
// - Don't enable the shipping method unless the current ship-to address is configured for the method.
// - Change usage of the PHP "split" function for PHP 5.3+ usage (it's deprecated).
//
class zipship extends base 
{
    var $code, $title, $description, $enabled, $num_zones;

// class constructor
    function __construct() 
    {
        global $order;
        
        // CUSTOMIZE THIS SETTING FOR THE NUMBER OF ZONES NEEDED
        $this->num_zones = 3;
        
        $this->code = 'zipship';
        $this->title = MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE;
        $this->description = MODULE_SHIPPING_ZIPSHIP_TEXT_DESCRIPTION;
        $this->sort_order = MODULE_SHIPPING_ZIPSHIP_SORT_ORDER;
        $this->icon = '';
        $this->tax_class = MODULE_SHIPPING_ZIPSHIP_TAX_CLASS;
        $this->tax_basis = MODULE_SHIPPING_ZIPSHIP_TAX_BASIS;

        // disable only when entire cart is free shipping
        if (zen_get_shipping_enabled ($this->code)) {
            $this->enabled = (MODULE_SHIPPING_ZIPSHIP_STATUS == 'True');
        } else {
            $this->enabled = false;
        }

        if ($this->enabled) {
            $this->dest_zipcode = $order->delivery['postcode'];
            for ($i=1, $dest_zone = 0; $i<=$this->num_zones; $i++) {
                $zipcode_table = constant('MODULE_SHIPPING_ZIPSHIP_CODES_' . $i);
                $zipcode_zones = explode (',', $zipcode_table);
                if (in_array($this->dest_zipcode, $zipcode_zones)) {
                    $dest_zone = $i;
                    break;
                }
            }
            $this->dest_zone = $dest_zone;
            if ($dest_zone == 0) {
                $this->enabled = false;
            }
        }
    }

// class methods
    function quote($method = '') 
    {
        global $order, $shipping_weight, $shipping_num_boxes, $total_count;
        $dest_zipcode = $this->dest_zipcode;
        $dest_zone = $this->dest_zone;
        $error = false;
        $shipping = -1;
        
        $zipcode_cost = constant ('MODULE_SHIPPING_ZIPSHIP_COST_' . $dest_zone);
        $zipcode_table = preg_split ('/[:,]/' , $zipcode_cost);
        for ($i = 0, $n = count ($zipcode_table), $done = false; $i < $n && !$done; $i += 2) {
            switch (MODULE_SHIPPING_ZIPSHIP_METHOD) {
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Weight'):
                    if (round($shipping_weight,9) <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];

                        switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
                            case (0):
                                $show_box_weight = '';
                                break;
                            case (1):
                                $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
                                break;
                            case (2):
                                $show_box_weight = ' (' . number_format($shipping_weight * $shipping_num_boxes,2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                break;
                            default:
                                $show_box_weight = ' (' . $shipping_num_boxes . ' x ' . number_format($shipping_weight,2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                break;
                        }
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_country . $show_box_weight;
                        $done = true;
                    }
                    break;
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Price'):
// shipping adjustment
                    if (($_SESSION['cart']->show_total() - $_SESSION['cart']->free_shipping_prices()) <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_zipcode;
                        $done = true;
                    }
                    break;
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Item'):
// shipping adjustment
                    if (($total_count - $_SESSION['cart']->free_shipping_items()) <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_zipcode;
                        $done = true;
                    }
                    break;
                default:
                    break;
            }
        }

        if ($shipping == -1) {
            $shipping_cost = 0;
            $shipping_method = MODULE_SHIPPING_ZIPSHIP_UNDEFINED_RATE;
        } else {
            switch (MODULE_SHIPPING_ZIPSHIP_METHOD) {
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Weight'):
                    // charge per box when done by Price
                    $shipping_cost = ($shipping * $shipping_num_boxes) + constant('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
                    break;
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Price'):
                    // don't charge per box when done by Price
                    $shipping_cost = ($shipping) + constant('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
                    break;
                case (MODULE_SHIPPING_ZIPSHIP_METHOD == 'Item'):
                    // don't charge per box when done by Item
                    $shipping_cost = ($shipping) + constant('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
                    break;
            }
        }

        $this->quotes = array(
            'id' => $this->code,
            'module' => MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE,
            'methods' => array(
                array(
                    'id' => $this->code,
                    'title' => $shipping_method,
                    'cost' => $shipping_cost
                )
            )
        );

        if ($this->tax_class > 0) {
            $this->quotes['tax'] = zen_get_tax_rate($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']);
        }

        if (zen_not_null($this->icon)) {
            $this->quotes['icon'] = zen_image ($this->icon, $this->title);
        }

        return $this->quotes;
    }

    function check() 
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_SHIPPING_ZIPSHIP_STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function install() 
    {
        global $db;
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Enable Zip Code Method', 'MODULE_SHIPPING_ZIPSHIP_STATUS', 'True', 'Do you want to offer zip code base shipping?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) VALUES ('Calculation Method', 'MODULE_SHIPPING_ZIPSHIP_METHOD', 'Weight', 'Calculate cost based on Weight, Price or Item?', '6', '0', 'zen_cfg_select_option(array(\'Weight\', \'Price\', \'Item\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Tax Class', 'MODULE_SHIPPING_ZIPSHIP_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', '6', '0', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Tax Basis', 'MODULE_SHIPPING_ZIPSHIP_TAX_BASIS', 'Shipping', 'On what basis is Shipping Tax calculated. Options are<br />Shipping - Based on customers Shipping Address<br />Billing Based on customers Billing address<br />Store - Based on Store address if Billing/Shipping Zone equals Store zone', '6', '0', 'zen_cfg_select_option(array(\'Shipping\', \'Billing\', \'Store\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort Order', 'MODULE_SHIPPING_ZIPSHIP_SORT_ORDER', '0', 'Sort order of display.', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Skip Zipcodes, use a comma separated list of the five character Zip codes', 'MODULE_SHIPPING_ZIPSHIP_SKIPPED', '', 'Disable for the following Zip Codes:', '6', '0', 'zen_cfg_textarea(', now())");

        for ($i = 1; $i <= $this->num_zones; $i++) {
            $default_zipcodes = '';
            if ($i == 1) {
                $default_zipcodes = '33801,33803,33809';
            }
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Zone " . $i ." Zip Codes', 'MODULE_SHIPPING_ZIPSHIP_CODES_" . $i ."', '" . $default_zipcodes . "', 'Comma separated list of five character zip codes that are part of Zone " . $i . ".<br />Set as 00000 to indicate all five character zip codes that are not specifically defined.', '6', '0', 'zen_cfg_textarea(', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Zone " . $i ." Shipping Table', 'MODULE_SHIPPING_ZIPSHIP_COST_" . $i ."', '50:5.50,51:0', 'Shipping rates to Zone " . $i . " destinations based on a group of maximum order weights/prices. Example: 3:8.50,7:10.50,... Weight/Price less than or equal to 3 would cost 8.50 for Zone " . $i . " destinations.', '6', '0', 'zen_cfg_textarea(', now())");
            $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Zone " . $i ." Handling Fee', 'MODULE_SHIPPING_ZIPSHIP_HANDLING_" . $i."', '0', 'Handling Fee for this shipping zone', '6', '0', now())");
        }
    }

    function remove() 
    {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE_SHIPPING_ZIPSHIP_%'");
    }

    function keys() 
    {
        $keys = array('MODULE_SHIPPING_ZIPSHIP_STATUS', 'MODULE_SHIPPING_ZIPSHIP_METHOD', 'MODULE_SHIPPING_ZIPSHIP_TAX_CLASS', 'MODULE_SHIPPING_ZIPSHIP_TAX_BASIS', 'MODULE_SHIPPING_ZIPSHIP_SORT_ORDER', 'MODULE_SHIPPING_ZIPSHIP_SKIPPED');

        for ($i=1; $i<=$this->num_zones; $i++) {
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_CODES_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_COST_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $i;
        }
        return $keys;
    }
}
