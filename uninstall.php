<?php
/**
 * Deletes the plugin's options on every site of the network, when a person deletes the plugin.
 * The secret key is one of them, so no site keeps a live key for a plugin that is gone.
 *
 * The order meta stays, because it belongs to the store's order records (WP-9). The customer's
 * saved postcodes stay for the same reason: they are the store's customer records, and the
 * export and erase tools of WordPress and WooCommerce cover both.
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$gatepost_options = array(
	'gatepost_wc_secret_key',
	'gatepost_wc_required',
	'gatepost_wc_legacy',
	'gatepost_wc_confirm',
);

// WordPress runs this file once, for the site that a person deletes the plugin from.
$gatepost_sites = is_multisite()
	? get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	)
	: array( get_current_blog_id() );

foreach ( $gatepost_sites as $gatepost_site ) {
	if ( is_multisite() ) {
		switch_to_blog( (int) $gatepost_site );
	}
	foreach ( $gatepost_options as $gatepost_option ) {
		delete_option( $gatepost_option );
	}
	if ( is_multisite() ) {
		restore_current_blog();
	}
}
