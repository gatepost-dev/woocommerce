<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce\Tests;

use Gatepost\WooCommerce\Plugin;
use Gatepost\WooCommerce\Settings;
use Gatepost\WooCommerce\SettingsPage;
use WC_Admin_Settings;
use WP_UnitTestCase;
use WPDieException;

/**
 * The section WooCommerce > Settings > Advanced > Nigerian postcodes.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function test_adds_the_section_only_to_its_own_page_of_the_advanced_tab(): void {
		$sections = SettingsPage::add_section( array( 'keys' => 'REST API' ) );
		$this->assertSame(
			array(
				'keys'              => 'REST API',
				'gatepost_postcode' => 'Nigerian postcodes',
			),
			$sections
		);
		$other = array( array( 'id' => 'woocommerce_api_enabled' ) );
		$this->assertSame( $other, SettingsPage::add_fields( $other, 'keys' ) );
		$ids = array_column( SettingsPage::add_fields( array(), 'gatepost_postcode' ), 'id' );
		$this->assertContains( Settings::SECRET_KEY, $ids );
		$this->assertContains( Settings::REQUIRED, $ids );
		$this->assertContains( Settings::LEGACY, $ids );
		$this->assertContains( Settings::CONFIRM, $ids );
	}

	public function test_keeps_a_live_secret_key_or_an_empty_one(): void {
		$saved = SettingsPage::sanitize_secret_key( ' nipost_live_example ' );
		$this->assertSame( 'nipost_live_example', $saved );
		$this->assertSame( '', SettingsPage::sanitize_secret_key( '' ) );
	}

	public function test_an_empty_field_keeps_the_saved_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		$this->assertSame( 'nipost_live_saved', SettingsPage::sanitize_secret_key( '' ) );
	}

	public function test_boot_registers_the_section(): void {
		Plugin::boot();
		$class = SettingsPage::class;
		$this->assertNotFalse(
			has_filter( 'woocommerce_get_sections_advanced', array( $class, 'add_section' ) )
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_get_settings_advanced', array( $class, 'add_fields' ) )
		);
	}

	public function test_the_key_field_never_prints_the_saved_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_hidden_value' );
		$field = array(
			'id'    => Settings::SECRET_KEY,
			'title' => 'Live <b>secret</b> key',
			'desc'  => 'Starts with <i>nipost_live_</i>.',
			'value' => 'nipost_live_hidden_value',
		);
		ob_start();
		SettingsPage::render_secret_key( $field );
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'hidden_value', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'autocomplete="new-password"', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
	}

	public function test_the_key_field_says_when_a_key_is_saved(): void {
		$field = array(
			'id'    => Settings::SECRET_KEY,
			'title' => 'Key',
			'desc'  => 'Help.',
		);
		ob_start();
		SettingsPage::render_secret_key( $field );
		$this->assertStringNotContainsString( 'A key is saved.', (string) ob_get_clean() );
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		ob_start();
		SettingsPage::render_secret_key( $field );
		$this->assertStringContainsString( 'A key is saved.', (string) ob_get_clean() );
	}

	public function test_a_ticked_box_removes_the_saved_key_after_the_save(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		Plugin::boot();
		$fields = SettingsPage::add_fields( array(), SettingsPage::SECTION );
		WC_Admin_Settings::save_fields(
			$fields,
			array(
				Settings::SECRET_KEY => '',
				SettingsPage::REMOVE => 'yes',
			)
		);
		do_action( 'woocommerce_update_options_advanced_' . SettingsPage::SECTION );
		$this->assertSame( '', get_option( Settings::SECRET_KEY, '' ) );
		$this->assertSame( 'unset', get_option( SettingsPage::REMOVE, 'unset' ) );
	}

	public function tear_down(): void {
		// Clears the flags, so a failed test cannot leak them into the next one.
		SettingsPage::remove_key();
		parent::tear_down();
	}

	public function test_a_new_valid_key_replaces_the_saved_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_old' );
		Plugin::boot();
		$fields = SettingsPage::add_fields( array(), SettingsPage::SECTION );
		WC_Admin_Settings::save_fields(
			$fields,
			array( Settings::SECRET_KEY => 'nipost_live_new' )
		);
		do_action( 'woocommerce_update_options_advanced_' . SettingsPage::SECTION );
		$this->assertSame( 'nipost_live_new', get_option( Settings::SECRET_KEY ) );
	}

	public function test_a_new_key_wins_over_a_ticked_box_in_the_same_save(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_old' );
		Plugin::boot();
		$fields = SettingsPage::add_fields( array(), SettingsPage::SECTION );
		WC_Admin_Settings::save_fields(
			$fields,
			array(
				Settings::SECRET_KEY => 'nipost_live_new',
				SettingsPage::REMOVE => 'yes',
			)
		);
		do_action( 'woocommerce_update_options_advanced_' . SettingsPage::SECTION );
		$this->assertSame( 'nipost_live_new', get_option( Settings::SECRET_KEY ) );
	}

	public function test_the_remove_box_shows_only_when_a_key_is_saved(): void {
		$ids = array_column( SettingsPage::add_fields( array(), SettingsPage::SECTION ), 'id' );
		$this->assertNotContains( SettingsPage::REMOVE, $ids );
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		$ids = array_column( SettingsPage::add_fields( array(), SettingsPage::SECTION ), 'id' );
		$this->assertContains( SettingsPage::REMOVE, $ids );
	}

	public function test_the_page_output_has_the_key_field_and_never_the_saved_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_hidden_value' );
		Plugin::boot();
		ob_start();
		WC_Admin_Settings::output_fields(
			SettingsPage::add_fields( array(), SettingsPage::SECTION )
		);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'hidden_value', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'name="' . Settings::SECRET_KEY . '"', $html );
		$this->assertStringContainsString(
			'aria-describedby="' . Settings::SECRET_KEY . '-description"',
			$html
		);
		$this->assertStringContainsString( 'id="' . Settings::SECRET_KEY . '-description"', $html );
	}

	public function test_a_person_without_the_capability_cannot_save(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST[ Settings::SECRET_KEY ] = 'nipost_live_attack';
		try {
			WC_Admin_Settings::save();
			$this->fail( 'The save should stop.' );
		} catch ( WPDieException $stop ) {
			$this->assertSame( 'nipost_live_saved', get_option( Settings::SECRET_KEY ) );
		} finally {
			unset( $_POST[ Settings::SECRET_KEY ] );
		}
	}

	public function test_an_unticked_box_keeps_the_saved_key(): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		Plugin::boot();
		$fields = SettingsPage::add_fields( array(), SettingsPage::SECTION );
		WC_Admin_Settings::save_fields( $fields, array( Settings::SECRET_KEY => '' ) );
		do_action( 'woocommerce_update_options_advanced_' . SettingsPage::SECTION );
		$this->assertSame( 'nipost_live_saved', get_option( Settings::SECRET_KEY ) );
	}

	/**
	 * A test key, a publishable key or other text keeps the saved key, with an error.
	 *
	 * @dataProvider refused_keys
	 *
	 * @param string $typed The text that the person typed.
	 */
	public function test_refuses_any_key_that_is_not_a_live_secret_key( string $typed ): void {
		update_option( Settings::SECRET_KEY, 'nipost_live_saved' );
		$this->assertSame( 'nipost_live_saved', SettingsPage::sanitize_secret_key( $typed ) );
		ob_start();
		WC_Admin_Settings::show_messages();
		$this->assertStringContainsString( 'The key was not saved.', (string) ob_get_clean() );
	}

	/**
	 * Keys that the setting refuses.
	 *
	 * @return array<string, array{string}>
	 */
	public static function refused_keys(): array {
		return array(
			'test key'        => array( 'nipost_test_example' ),
			'publishable key' => array( 'nipost_pk_live_example' ),
			'other text'      => array( 'my key' ),
		);
	}
}
