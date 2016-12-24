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
class zipship extends base 
{
    var $code, $title, $description, $enabled, $num_zones;

// class constructor
    function __construct() 
    {
        global $db, $order;
        
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
            $enabled_for_zone = false;
            $check = $db->Execute (
                "SELECT zone_id 
                   FROM " . TABLE_ZONES_TO_GEO_ZONES . " 
                    AND zone_country_id = " . $order->delivery['country']['id'] . " 
               ORDER BY zone_id"
            );
            while (!$check->EOF) {
                if ($check->fields['zone_id'] < 1 || $check->fields['zone_id'] == $order->delivery['zone_id']) {
                    $enabled_for_zone = true;
                    break;
                }
                $check->MoveNext();
            }
            $this->enabled = $enabled_for_zone;
        }

        if ($this->enabled) {
            // -----
            // Gather the destination's zipcode, uppercasing and then truncating to the store's minimum zipcode length.
            //
            // NOTE:  I'm counting on the address-book generation logic for the store to have properly ensured that the
            // postcode entered is at least the minimum-length configured!
            //
            $this->dest_zipcode = substr (strtoupper ($order->delivery['postcode']), 0, ENTRY_POSTCODE_MIN_LENGTH);
            for ($i=1, $dest_zone = 0; $i<=$this->num_zones; $i++) {
                $zipcode_table = constant ('MODULE_SHIPPING_ZIPSHIP_CODES_' . $i);
                $zipcode_zones = explode (',', strtoupper ($zipcode_table));
                if (in_array ($this->dest_zipcode, $zipcode_zones)) {
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
    function quote ($method = '') 
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
                case 'Weight':
                    if (round ($shipping_weight, 9) <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];

                        switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
                            case (0):
                                $show_box_weight = '';
                                break;
                            case (1):
                                $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
                                break;
                            case (2):
                                $show_box_weight = ' (' . number_format ($shipping_weight * $shipping_num_boxes, 2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                break;
                            default:
                                $show_box_weight = ' (' . $shipping_num_boxes . ' x ' . number_format ($shipping_weight, 2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                break;
                        }
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_zipcode . $show_box_weight;
                        $shipping_cost = ($shipping * $shipping_num_boxes) + constant ('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
                        $done = true;
                    }
                    break;
                case 'Price':
// shipping adjustment
                    if ($_SESSION['cart']->show_total() - $_SESSION['cart']->free_shipping_prices () <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_zipcode;
                        $shipping_cost = $shipping + constant ('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
                        $done = true;
                    }
                    break;
                case 'Item':
// shipping adjustment
                    if ($total_count - $_SESSION['cart']->free_shipping_items () <= $zipcode_table[$i]) {
                        $shipping = $zipcode_table[$i+1];
                        $shipping_method = MODULE_SHIPPING_ZIPSHIP_TEXT_WAY . ' ' . $dest_zipcode;
                        $shipping_cost = $shipping + constant ('MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone);
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
            $this->quotes['tax'] = zen_get_tax_rate ($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']);
        }

        if (zen_not_null ($this->icon)) {
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
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Shipping Zone', 'MODULE_SHIPPING_ZIPSHIP_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', '6', '0', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");

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
        $keys = array('MODULE_SHIPPING_ZIPSHIP_STATUS', 'MODULE_SHIPPING_ZIPSHIP_METHOD', 'MODULE_SHIPPING_ZIPSHIP_TAX_CLASS', 'MODULE_SHIPPING_ZIPSHIP_TAX_BASIS', 'MODULE_SHIPPING_ZIPSHIP_ZONE', 'MODULE_SHIPPING_ZIPSHIP_SORT_ORDER');

        for ($i=1; $i<=$this->num_zones; $i++) {
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_CODES_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_COST_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $i;
        }
        return $keys;
    }
}
