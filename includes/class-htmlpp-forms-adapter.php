<?php
/**
 * Base class for form plugin adapters.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * One form plugin (Gravity Forms, Contact Form 7, WPForms…).
 *
 * An adapter renders its forms into published pages, processes submissions
 * posted to a page, and creates forms from fields detected in raw HTML.
 * Register extra adapters with the `htmlpp_forms_adapters` filter.
 */
abstract class HTMLPP_Forms_Adapter {

	/**
	 * Placeholder key, e.g. "gravity" in <!-- form:gravity:12 -->.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human name of the form plugin.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the form plugin is active.
	 *
	 * @return bool
	 */
	abstract public function is_available();

	/**
	 * Whether a form exists and can be shown (published/active, not trashed).
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	abstract public function form_exists( $form_id );

	/**
	 * Render a form and enqueue the assets it needs.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $flags   Placeholder flags (title, description).
	 * @param bool  $preview Whether this is a draft preview.
	 * @return string|null Markup, or null when the form can't be shown.
	 */
	abstract public function render( $form_id, array $flags, $preview );

	/**
	 * Create a form from detected fields.
	 *
	 * @param array  $form  Parsed form (see HTMLPP_Forms_Parser::find_forms()).
	 * @param string $title Form title.
	 * @return int|WP_Error New form ID.
	 */
	abstract public function create_form( array $form, $title );

	/**
	 * Delete a form (used to roll back a failed conversion).
	 *
	 * @param int $form_id Form ID.
	 */
	abstract public function delete_form( $form_id );

	/**
	 * Admin URL for editing a form.
	 *
	 * @param int $form_id Form ID.
	 * @return string
	 */
	abstract public function edit_url( $form_id );

	/**
	 * Whether the current user may create forms in this plugin.
	 *
	 * @return bool
	 */
	abstract public function can_create();

	/**
	 * Whether the plugin's scripts are only registered on wp_enqueue_scripts,
	 * which never fires on published pages.
	 *
	 * @return bool
	 */
	public function needs_enqueue_pass() {
		return false;
	}

	/**
	 * Called once after this adapter's forms on a page have been rendered.
	 *
	 * @param int[] $form_ids Rendered form IDs.
	 */
	public function after_render( array $form_ids ) {}

	/**
	 * Markup printed after the page's scripts (inline settings and the like).
	 *
	 * @return string
	 */
	public function footer_html() {
		return '';
	}

	/**
	 * ID of the form this request posts to, if it is one of this plugin's.
	 *
	 * @return int 0 when the request is not a submission for this plugin.
	 */
	public function posted_form_id() {
		return 0;
	}

	/**
	 * Process a submission posted to the page URL (non-AJAX fallbacks).
	 *
	 * @param int $form_id Form ID from posted_form_id().
	 */
	public function process_submission( $form_id ) {}

	/**
	 * When the response to this request should be the form's markup alone
	 * (e.g. Gravity Forms' iframe AJAX postback).
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	public function is_postback( $form_id ) {
		return false;
	}

	/**
	 * Differences between the detected form and what this plugin will create
	 * (unsupported field types, substitutions), shown before converting.
	 *
	 * @param array $form Parsed form.
	 * @return string[]
	 */
	public function conversion_notes( array $form ) {
		return array();
	}

	/**
	 * Placeholder for a form of this plugin.
	 *
	 * @param int                   $form_id Form ID.
	 * @param array<string, string> $attrs   Attributes, e.g. styles, class, id, button-class.
	 * @return string
	 */
	public function placeholder( $form_id, array $attrs = array() ) {
		$out = '<!-- form:' . $this->id() . ':' . (int) $form_id;
		foreach ( $attrs as $key => $value ) {
			$value = str_replace( array( '"', '--' ), '', (string) $value );
			if ( '' !== $value ) {
				$out .= ' ' . $key . '=' . ( preg_match( '/^[\w-]+$/', $value ) ? $value : '"' . $value . '"' );
			}
		}
		return $out . ' -->';
	}

	/**
	 * Turn the plugin's visual styling off or back on for the next render.
	 *
	 * Called with true before rendering a form whose placeholder has
	 * styles=page, and with false afterwards. Functional CSS (hidden
	 * honeypots, screen-reader text) should stay.
	 *
	 * @param bool $off Whether the plugin's own theme should be off.
	 */
	public function page_styles( $off ) {}
}
