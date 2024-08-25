<?php
// -----
// Part of the "ZipShip" shipping method by lat9 (https://vinosdefrutastropicales.com)
// Copyright (c) 2016-2024 Vinos de Frutas Tropicales
//
// +----------------------------------------------------------------------+
// |zen-cart Open Source E-commerce                                       |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 The zen-cart developers                           |
// |                                                                      |
// | http://www.zen-cart.com/index.php                                    |
// |                                                                      |
/*
//  $Id: zipship.php,v 1.1 2007 - 1-25 RW -
//  osCommerce, Open Source E-Commerce Solutions
//  http://www.oscommerce.com
//  modified for Zen-Cart v 1.3.7 - by Ruth Woody - http://www.randbhosting.net 
//
//  Copyright (c) 2002 osCommerce
//
//  Released under the GNU General Public License
*/
$define = [
    'MODULE_SHIPPING_ZIPSHIP_TEXT_DESCRIPTION' => 'Zipcode-Based Rate',
    'MODULE_SHIPPING_ZIPSHIP_TEXT_UNITS' => 'lb(s)',

    // -----
    // This constant is used in the admin's Modules->Shipping to identify the ZipShip
    // shipping method.
    //
    'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE' => 'Zipcode Rate',

    // -----
    // Starting with ZipShip v3.0.0, you can define different 'title/way' combinations
    // for different ZipShip zones.
    //
    // Note: If you've increased the number of ZipShip 'zones', you can append that added
    // zone-number, e.g. replacing '_1' with '_4', when creating any additional 'overrides'.
    //
    'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE_1' => 'Zipcode Rate',
    'MODULE_SHIPPING_ZIPSHIP_TEXT_WAY_1' => 'Deliver To Zipcode: ',

    'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE_2' => 'Zipcode Rate',
    'MODULE_SHIPPING_ZIPSHIP_TEXT_WAY_2' => 'Deliver To Zipcode: ',

    'MODULE_SHIPPING_ZIPSHIP_TEXT_TITLE_3' => 'Zipcode Rate',
    'MODULE_SHIPPING_ZIPSHIP_TEXT_WAY_3' => 'Deliver To Zipcode: ',
];
return $define;
