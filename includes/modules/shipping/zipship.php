<?php
// -----
// Part of the "ZipShip" shipping method by lat9 (https://vinosdefrutastropicales.com)
// Copyright (c) 2016-2019 Vinos de Frutas Tropicales
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
    public $code, $title, $description, $enabled, $num_zones;
    public $moduleVersion = '3.0.0';

    public function __construct() 
    {
        global $db, $order;
        
        // CUSTOMIZE THIS SETTING FOR THE NUMBER OF ZONES NEEDED
        $this->num_zones = 3;
        
        $this->code = 'zipship';
        $this->title = MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE;
        if (IS_ADMIN_FLAG === true) {
            $this->title .= ' v' . $this->moduleVersion;
        }
        $this->description = MODULE_SHIPPING_ZIPSHIP_TEXT_DESCRIPTION;
        
        $this->enabled = (defined('MODULE_SHIPPING_ZIPSHIP_STATUS') && MODULE_SHIPPING_ZIPSHIP_STATUS == 'True');
        $this->sort_order = (defined('MODULE_SHIPPING_ZIPSHIP_SORT_ORDER')) ? (int)MODULE_SHIPPING_ZIPSHIP_SORT_ORDER : false;
        $this->icon = '';
        
        if ($this->enabled && isset($order)) {
            $this->tax_class = (int)MODULE_SHIPPING_ZIPSHIP_TAX_CLASS;

            // disable only when entire cart is free shipping
            if (!zen_get_shipping_enabled($this->code)) {
                $this->enabled = false;
            } elseif (((int)MODULE_SHIPPING_ZIPSHIP_ZONE) > 0) {
                $enabled_for_zone = false;
                $check = $db->Execute (
                    "SELECT zone_id 
                       FROM " . TABLE_ZONES_TO_GEO_ZONES . " 
                      WHERE geo_zone_id = " . (int)MODULE_SHIPPING_ZIPSHIP_ZONE . " 
                        AND zone_country_id = " . (int)$order->delivery['country']['id'] . " 
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

            if ($this->enabled && !empty($order->delivery['postcode'])) {
                // -----
                // Gather the destination's zipcode, uppercasing and then truncating to the store's minimum zipcode length.
                //
                // NOTE:  I'm counting on the address-book generation logic for the store to have properly ensured that the
                // postcode entered is at least the minimum-length configured!
                //
                $this->dest_zone = false;
                $this->default_zone = false;
                $this->dest_zipcode = substr(strtoupper($order->delivery['postcode']), 0, (int)ENTRY_POSTCODE_MIN_LENGTH);
                for ($i = 1; $i <= $this->num_zones; $i++) {
                    $current_table = "MODULE_SHIPPING_ZIPSHIP_CODES_$i";
                    if (defined($current_table)) {
                        $zipcode_table = constant($current_table);
                        if ($zipcode_table === '00000') {
                            $this->default_zone = $i;
                        } else {
                            $zipcode_zones = explode(',', strtoupper($zipcode_table));
                            if (in_array($this->dest_zipcode, $zipcode_zones)) {
                                $this->dest_zone = $i;
                            }
                        }
                    }
                }
                if ($this->dest_zone === false && $this->default_zone === false) {
                    $this->enabled = false;
                }
            }
        }
    }

    public function quote($method = '') 
    {
        global $order, $shipping_weight, $shipping_num_boxes, $total_count;

        // -----
        // Indicate, initially, that no matching zipship quote was found for the order.
        //
        $this->shipping_method = false;
        $this->shipping_cost = false;
        
        // -----
        // If the order's ship-to zipcode matched one of the definitions, get a
        // quote for that zipcode 'zone'.
        //
        if ($this->dest_zone !== false) {
            $this->getQuote($this->dest_zone);
        // -----
        // Otherwise, if a default zipcode 'zone' was defined, get a quote for that
        // zipcode 'zone'.
        //
        } elseif ($this->default_zone !== false) {
            $this->getQuote($this->default_zone);
        }

        // -----
        // If no matching condition for the order's zip-code, indicate that this shipping
        // method is not available.
        //
        if ($this->shipping_method === false) {
            $this->quotes = array(
                'id' => $this->code,
                'module' => '',
                'methods' => '',
            );
        // -----
        // Otherwise, send back the zipcode-based quote.
        //
        } else {
            $this->quotes = array(
                'id' => $this->code,
                'module' => $this->title,
                'methods' => array(
                    array(
                        'id' => $this->code,
                        'title' => $this->shipping_method,
                        'cost' => $this->shipping_cost
                    )
                )
            );

            if ($this->shipping_cost != 0 && $this->tax_class > 0) {
                $this->quotes['tax'] = zen_get_tax_rate($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']);
            }

            if (zen_not_null ($this->icon)) {
                $this->quotes['icon'] = zen_image($this->icon, $this->title);
            }
        }
        return $this->quotes;
    }
    
    // -----
    // This method determines any zipship shipping cost for an order, based on the zipcode 'zone'
    // supplied.
    //
    // Side-effect: If a matching quote is found, sets the class variables 'shipping_method' and 'shipping_cost'.
    //
    protected function getQuote($dest_zone)
    {
        $quote_found = false;
        
        // -----
        // Determine the constant names for the cost-breakdown and handling-fee settings for the
        // ZipShip zone that matched the order's current shipping location.
        //
        $zipship_cost = 'MODULE_SHIPPING_ZIPSHIP_COST_' . $dest_zone;
        $zipship_handling = 'MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $dest_zone;
        
        // -----
        // Starting with v3.0.0, the shipping method uses different title/way text based
        // on the ZipShip 'zone' definitions, with a fall-back to the previous one-value-per
        // language constant.
        //
        $zipship_title = 'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE_' . $dest_zone;
        $zipship_way ='MODULE_SHIPPING_ZIPSHIP_TEXT_WAY_' . $dest_zone;
        
        // -----
        // Both the cost-breakdown and shipping-fee constants must be defined (i.e. present in the configuration)
        // to continue.  Otherwise, more recent versions of PHP will generate either a notice or a warning.
        //
        if (!(defined($zipship_cost) && defined($zipship_handling))) {
            trigger_error("Missing cost ($zipship_cost) or handling ($zipship_handling) settings; zipship is disabled.", E_USER_WARNING);
        // -----
        // The language constante, present in /includes/languages/english/modules/shipping[/YOUR_TEMPLATE]/zipship.php, that
        // inform the customer about this shipping method must also be present for the zipship shipping method to continue.
        //
        // Either the zone-suffixed constant or the contstant used by prior versions for *each* the title/way must be present
        // to continue.
        //
        } elseif (!(defined($zipship_title) || defined('MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE')) || !(defined($zipship_way) || defined('MODULE_SHIPPING_ZIPSHIP_TEXT_WAY'))) {
            trigger_error("Missing title ($zipship_title) or way ($zipship_way) for the '{$_SESSION['language']}' language; zipship is disabled.", E_USER_WARNING);
        // -----
        // Otherwise, we've got all the information needed to see if this order qualifies for ZipShip shipping ...
        //
        } else {
            $zipship_handling = (float)constant($zipship_handling);
            
            $zipship_title = (defined($zipship_title)) ? $zipship_title : 'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE';
            $this->title = constant($zipship_title);
            
            $zipship_way = (defined($zipship_way)) ? $zipship_way : 'MODULE_SHIPPING_ZIPSHIP_TEXT_WAY';
            $zipship_way = constant($zipship_way);
            
            $zipcode_cost = constant($zipship_cost);
            $zipcode_table = preg_split('/[:,]/' , $zipcode_cost);
            for ($i = 0, $n = count($zipcode_table); $i < $n && !$quote_found; $i += 2) {
                $zipcode_key = (float)$zipcode_table[$i];
                $zipcode_value = (float)$zipcode_table[$i+1];
                switch (MODULE_SHIPPING_ZIPSHIP_METHOD) {
                    case 'Weight':
                        if (round($shipping_weight, 9) <= $zipcode_key) {
                            switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
                                case (0):
                                    $show_box_weight = '';
                                    break;
                                case (1):
                                    $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
                                    break;
                                case (2):
                                    $show_box_weight = ' (' . number_format($shipping_weight * $shipping_num_boxes, 2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                    break;
                                default:
                                    $show_box_weight = ' (' . $shipping_num_boxes . ' x ' . number_format($shipping_weight, 2) . MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS . ')';
                                    break;
                            }
                            $this->shipping_method = $zipship_way . ' ' . $this->dest_zipcode . $show_box_weight;
                            $this->shipping_cost = ($zipcode_value * $shipping_num_boxes) + $zipship_handling;
                            $quote_found = true;
                        }
                        break;
                    case 'Price':
                    $this->cart_total = $_SESSION['cart']->show_total();
                    $this->free_cart = $_SESSION['cart']->free_shipping_prices();
                        if ($_SESSION['cart']->show_total() - $_SESSION['cart']->free_shipping_prices() <= $zipcode_key) {
                            $this->shipping_method = $zipship_way . ' ' . $this->dest_zipcode;
                            $this->shipping_cost = $zipcode_value + $zipship_handling;
                            $quote_found = true;
                        }
                        break;
                    case 'Item':
                        if ($total_count - $_SESSION['cart']->free_shipping_items() <= $zipcode_key) {
                            $this->shipping_method = $zipship_way . ' ' . $this->dest_zipcode;
                            $this->shipping_cost = $zipcode_value + $zipship_handling;
                            $quote_found = true;
                        }
                        break;
                    default:
                        break;
                }
            }
        }
        return $quote_found;
    }

    public function check() 
    {
        global $db;
        if (!isset($this->_check)) {
            $check_query = $db->Execute("SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_SHIPPING_ZIPSHIP_STATUS' LIMIT 1");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    function install() 
    {
        global $db;
        $db->Execute(
            "INSERT INTO " . TABLE_CONFIGURATION . " 
                (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) 
             VALUES 
                ('Enable Zip Code Method', 'MODULE_SHIPPING_ZIPSHIP_STATUS', 'True', 'Do you want to offer zip code base shipping?', 6, 0, NULL, 'zen_cfg_select_option(array(\'True\', \'False\'), ', now()),
                
                ('Calculation Method', 'MODULE_SHIPPING_ZIPSHIP_METHOD', 'Weight', 'Calculate cost based on Weight, Price or Item?', 6, 0, NULL, 'zen_cfg_select_option(array(\'Weight\', \'Price\', \'Item\'), ', now()),
                
                ('Tax Class', 'MODULE_SHIPPING_ZIPSHIP_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', 6, 0, 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now()),
                
                ('Shipping Zone', 'MODULE_SHIPPING_ZIPSHIP_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', 6, 6, 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now()),
                
                ('Sort Order', 'MODULE_SHIPPING_ZIPSHIP_SORT_ORDER', '0', 'Sort order of display.', 6, 0, NULL, NULL, now())"
        );

        for ($i = 1; $i <= $this->num_zones; $i++) {
            $default_zipcodes = '';
            if ($i == 1) {
                $default_zipcodes = '33801,33803,33809';
            }
            $db->Execute(
                "INSERT INTO " . TABLE_CONFIGURATION . " 
                    (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) 
                 VALUES 
                    ('Zone " . $i . " Zip Codes', 'MODULE_SHIPPING_ZIPSHIP_CODES_" . $i . "', '" . $default_zipcodes . "', 'Comma separated list of five character zip codes that are part of Zone " . $i . ".<br />Set as 00000 to indicate all five character zip codes that are not specifically defined.', 6, 0, 'zen_cfg_textarea(', now()),
                    
                    ('Zone " . $i . " Shipping Table', 'MODULE_SHIPPING_ZIPSHIP_COST_" . $i . "', '50:5.50,51:0', 'Shipping rates to Zone " . $i . " destinations based on a group of maximum order weights/prices. Example: 3:8.50,7:10.50,... Weight/Price less than or equal to 3 would cost 8.50 for Zone " . $i . " destinations.', 6, 0, 'zen_cfg_textarea(', now()),
                    
                    ('Zone " . $i . " Handling Fee', 'MODULE_SHIPPING_ZIPSHIP_HANDLING_" . $i . "', '0', 'Handling Fee for this shipping zone', 6, 0, NULL, now())"
            );
        }
    }

    function remove() 
    {
        global $db;
        $db->Execute("DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE_SHIPPING_ZIPSHIP_%'");
    }

    function keys() 
    {
        $keys = array(
            'MODULE_SHIPPING_ZIPSHIP_STATUS', 
            'MODULE_SHIPPING_ZIPSHIP_METHOD', 
            'MODULE_SHIPPING_ZIPSHIP_TAX_CLASS',
            'MODULE_SHIPPING_ZIPSHIP_ZONE', 
            'MODULE_SHIPPING_ZIPSHIP_SORT_ORDER'
        );

        for ($i = 1; $i <= $this->num_zones; $i++) {
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_CODES_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_COST_' . $i;
            $keys[] = 'MODULE_SHIPPING_ZIPSHIP_HANDLING_' . $i;
        }
        return $keys;
    }
}
