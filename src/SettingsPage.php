<?php
/**
 * SPDX-FileCopyrightText: 2026 The Gatepost authors
 * SPDX-License-Identifier: Apache-2.0
 *
 * @package Gatepost\WooCommerce
 */

namespace Gatepost\WooCommerce;

use WC_Admin_Settings;

/**
 * The section WooCommerce > Settings > Advanced > Nigerian postcodes. WooCommerce's settings API
 * renders it, and checks the nonce and the manage_woocommerce capability when a person saves it.
 */
final class SettingsPage {

	const SECTION = 'gatepost_postcode';
	const REMOVE  = 'gatepost_wc_remove_key';

	/**
	 * True after the person ticks the box that removes the saved key.
	 *
	 * @var bool
	 */
	private static bool $remove_requested = false;

	/**
	 * Adds the section and its fields to WooCommerce's settings.
	 */
	public static function register(): void {
		$class = self::class;
		add_filter( 'woocommerce_get_sections_advanced', array( $class, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_advanced', array( $class, 'add_fields' ), 10, 2 );
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . Settings::SECRET_KEY,
			array( $class, 'sanitize_secret_key' )
		);
		add_filter(
			'woocommerce_admin_settings_sanitize_option_' . self::REMOVE,
			array( $class, 'note_removal' )
		);
		add_action(
			'woocommerce_admin_field_gatepost_secret_key',
			array( $class, 'render_secret_key' )
		);
		add_action(
			'woocommerce_update_options_advanced_' . self::SECTION,
			array( $class, 'remove_key' )
		);
	}

	/**
	 * Adds the section to the Advanced tab.
	 *
	 * @param array<string, string> $sections The sections of the tab, by id.
	 * @return array<string, string>
	 */
	public static function add_section( array $sections ): array {
		$sections[ self::SECTION ] = self::title();
		return $sections;
	}

	/**
	 * Gives the fields of the section.
	 *
	 * @param array<int, array<string, mixed>> $settings The fields of the current section.
	 * @param string                           $section  The id of the current section.
	 * @return array<int, array<string, mixed>>
	 */
	public static function add_fields( array $settings, string $section ): array {
		if ( self::SECTION !== $section ) {
			return $settings;
		}
		return array(
			array(
				'id'    => 'gatepost_wc_options',
				'type'  => 'title',
				'title' => self::title(),
				'desc'  => self::description(),
			),
			array(
				'id'       => Settings::SECRET_KEY,
				'type'     => 'gatepost_secret_key',
				'title'    => __( 'Live secret key', 'gatepost-postcode-for-woocommerce' ),
				'desc'     => __(
					'Starts with nipost_live_. Leave it empty to check the format only.',
					'gatepost-postcode-for-woocommerce'
				),
				'default'  => '',
				'autoload' => false,
			),
			array(
				'id'    => self::REMOVE,
				'type'  => 'checkbox',
				'title' => __( 'Remove the key', 'gatepost-postcode-for-woocommerce' ),
				'desc'  => __(
					'Remove the saved key when you save these settings.',
					'gatepost-postcode-for-woocommerce'
				),
			),
			array(
				'id'      => Settings::REQUIRED,
				'type'    => 'checkbox',
				'title'   => __( 'Required', 'gatepost-postcode-for-woocommerce' ),
				'desc'    => __(
					'A customer in Nigeria must enter a postcode.',
					'gatepost-postcode-for-woocommerce'
				),
				'default' => 'no',
			),
			array(
				'id'      => Settings::LEGACY,
				'type'    => 'select',
				'title'   => __( 'Old 6-digit postcodes', 'gatepost-postcode-for-woocommerce' ),
				'default' => 'accept',
				'options' => array(
					'accept' => __( 'Accept them', 'gatepost-postcode-for-woocommerce' ),
					'reject' => __(
						'Ask for the new postcode',
						'gatepost-postcode-for-woocommerce'
					),
				),
			),
			array(
				'id'      => Settings::CONFIRM,
				'type'    => 'select',
				'title'   => __( 'Lookup', 'gatepost-postcode-for-woocommerce' ),
				'default' => 'level1',
				'options' => array(
					'level1' => __(
						'Ask NIPOST whether the postcode exists',
						'gatepost-postcode-for-woocommerce'
					),
					'none'   => __( 'Check the format only', 'gatepost-postcode-for-woocommerce' ),
				),
			),
			array(
				'id'   => 'gatepost_wc_options',
				'type' => 'sectionend',
			),
		);
	}

	/**
	 * Gives the name of the section.
	 */
	private static function title(): string {
		return __( 'Nigerian postcodes', 'gatepost-postcode-for-woocommerce' );
	}

	/**
	 * Gives the text at the top of the section. It names the lookup, because the lookup sends
	 * each postcode to NIPOST's gateway.
	 */
	private static function description(): string {
		$sentences = array(
			__(
				'Unofficial. Not made or endorsed by NIPOST.',
				'gatepost-postcode-for-woocommerce'
			),
			__(
				'With a live secret key, the store looks up each postcode after the order.',
				'gatepost-postcode-for-woocommerce'
			),
			__( 'A failed lookup never stops an order.', 'gatepost-postcode-for-woocommerce' ),
		);
		return implode( ' ', $sentences );
	}

	/**
	 * Keeps a key only when it is a live secret key. A test key works only on NIPOST's staging
	 * gateway, and a publishable key must never reach a server setting (SEC-1).
	 *
	 * @param mixed $value The key that the person typed.
	 * @return string The key to save.
	 */
	public static function sanitize_secret_key( $value ): string {
		$key = trim( is_string( $value ) ? $value : '' );
		if ( '' === $key ) {
			// The field never shows the saved key, so an empty field means "keep it".
			return (string) get_option( Settings::SECRET_KEY, '' );
		}
		if ( 1 === preg_match( '/\Anipost_live_[A-Za-z0-9_]+\z/', $key ) ) {
			return $key;
		}
		WC_Admin_Settings::add_error(
			__(
				'The key was not saved. Enter a live secret key, which starts with nipost_live_.',
				'gatepost-postcode-for-woocommerce'
			)
		);
		return (string) get_option( Settings::SECRET_KEY, '' );
	}

	/**
	 * Notes that the person ticked the box that removes the key. The box itself is not saved.
	 *
	 * @param mixed $value The value of the checkbox, "yes" or "no".
	 * @return null
	 */
	public static function note_removal( $value ) {
		self::$remove_requested = 'yes' === $value;
		return null;
	}

	/**
	 * Removes the saved key when the person asked for it. It runs after WooCommerce saves the
	 * fields, because WooCommerce saves every field in one step at the end.
	 */
	public static function remove_key(): void {
		if ( self::$remove_requested ) {
			delete_option( Settings::SECRET_KEY );
		}
		self::$remove_requested = false;
	}

	/**
	 * Prints the key field. It never prints the saved key: the field stays empty, and a line
	 * says whether a key is saved.
	 *
	 * @param array<string, mixed> $field The field, as WooCommerce gives it.
	 */
	public static function render_secret_key( array $field ): void {
		$id    = (string) ( $field['id'] ?? '' );
		$title = (string) ( $field['title'] ?? '' );
		$desc  = (string) ( $field['desc'] ?? '' );
		$saved = '' !== (string) get_option( Settings::SECRET_KEY, '' );
		$note  = $saved ? implode(
			' ',
			array(
				__( 'A key is saved.', 'gatepost-postcode-for-woocommerce' ),
				__( 'Type a new key to replace it.', 'gatepost-postcode-for-woocommerce' ),
			)
		) : '';
		?>
		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $title ); ?>
				</label>
			</th>
			<td class="forminp forminp-password">
				<input
					name="<?php echo esc_attr( $id ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					type="password"
					value=""
					autocomplete="new-password"
					class="regular-text"
				/>
				<p class="description">
					<?php echo esc_html( $desc ); ?>
					<?php echo esc_html( $note ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
