<?php
/**
 * Deletes the plugin's options when a person deletes the plugin. The order meta stays, because it
 * belongs to the store's order records (WP-9).
 *
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'gatepost_wc_secret_key' );
delete_option( 'gatepost_wc_required' );
delete_option( 'gatepost_wc_legacy' );
delete_option( 'gatepost_wc_confirm' );
