<?php
/**
 * Plugin Name:          ChatPuff live chat
 * Plugin URI:           https://chatpuff.com
 * Description:          Live chat with your customers, answered from your WordPress admin, the ChatPuff dashboard or your phone.
 * Version:              0.4.0
 * Requires at least:    5.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               ChatPuff
 * Author URI:           https://chatpuff.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          chatpuff
 * Domain Path:          /languages
 * WC requires at least: 3.8
 * WC tested up to:      11.1
 *
 * @package ChatPuff
 */

/*
 * Copyright 2026 ChatPuff
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * General Public License for more details.
 */

defined( 'ABSPATH' ) || exit;

define( 'CHATPUFF_VERSION', '0.4.0' );
define( 'CHATPUFF_FILE', __FILE__ );

require_once __DIR__ . '/includes/load.php';

register_deactivation_hook( __FILE__, array( \ChatPuff\WooCommerce\Plugin::class, 'deactivate' ) );

\ChatPuff\WooCommerce\Plugin::boot();
