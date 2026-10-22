<?php
/**
 * Contact Form 7 adapter.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7: <!-- form:cf7:ID -->.
 *
 * CF7 submits through its REST API when JavaScript runs, so only the no-JS
 * fallback posts to the page URL.
 */
class HTMLPP_Forms_Adapter_CF7 extends HTMLPP_Forms_Adapter {

	/**
	 * Whether the current render uses styles=page.
	 *
	 * @var bool
	 */
	private $off = false;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'cf7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Contact Form 7';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( 'WPCF7_ContactForm' ) && function_exists( 'wpcf7_contact_form' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs_enqueue_pass() {
		return true; // CF7 registers its scripts on wp_enqueue_scripts.
	}

	/**
	 * {@inheritDoc}
	 *
	 * CF7's stylesheet is functional (hides the screen-reader response and
	 * spinner), so it stays; only auto-paragraphs (extra <p>/<br> around
	 * fields) are turned off.
	 */
	public function page_styles( $off ) {
		$this->off = (bool) $off;
		if ( $off ) {
			add_filter( 'wpcf7_autop_or_not', '__return_false', 99 );
		} else {
			remove_filter( 'wpcf7_autop_or_not', '__return_false', 99 );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function form_exists( $form_id ) {
		return 'wpcf7_contact_form' === get_post_type( $form_id ) && 'publish' === get_post_status( $form_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( $form_id, array $flags, $preview ) {
		if ( ! $this->form_exists( $form_id ) ) {
			return null;
		}
		$contact_form = wpcf7_contact_form( $form_id );
		if ( ! $contact_form ) {
			return null;
		}

		$this->enqueue();

		$markup = $contact_form->form_html(
			array(
				'html_title' => $flags['title'] ? $contact_form->title() : '',
			)
		);

		// CF7's submit is an <input>, so page rules written for the original
		// <button> lose to the page's own `input` rules. Use a <button> (CF7's
		// script works with either; it looks the element up by class).
		return $this->off ? $this->submit_as_button( $markup ) : $markup;
	}

	/**
	 * Replace CF7's <input type="submit"> with an equivalent <button>.
	 *
	 * @param string $markup Form markup.
	 * @return string
	 */
	private function submit_as_button( $markup ) {
		$result = preg_replace_callback(
			'/<input\b([^>]*\btype="submit"[^>]*)\/?>/i',
			static function ( $m ) {
				$attrs = $m[1];
				$label = preg_match( '/\svalue="([^"]*)"/i', $attrs, $v ) ? $v[1] : '';
				$attrs = preg_replace( '/\s(?:value|type)="[^"]*"/i', '', $attrs );
				return '<button type="submit"' . rtrim( $attrs, ' /' ) . '>' . $label . '</button>';
			},
			$markup
		);
		return null === $result ? $markup : $result;
	}

	/**
	 * Enqueue CF7's script (with its REST settings) and stylesheet.
	 */
	private function enqueue() {
		// The registration pass already added the inline `var wpcf7` settings
		// unless the site turned CF7's JS off with wpcf7_load_js.
		if ( wp_scripts()->get_data( 'contact-form-7', 'before' ) ) {
			wp_enqueue_script( 'contact-form-7' );
		} else {
			wpcf7_enqueue_scripts();
		}
		if ( function_exists( 'wpcf7_load_css' ) && wpcf7_load_css() ) {
			wpcf7_enqueue_styles();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function posted_form_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- CF7 validates its own submissions.
		return isset( $_POST['_wpcf7'] ) ? absint( wp_unslash( $_POST['_wpcf7'] ) ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function process_submission( $form_id ) {
		wpcf7_control_init(); // CF7's own non-AJAX handler (normally on parse_request).
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create() {
		return current_user_can( 'wpcf7_edit_contact_forms' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function conversion_notes( array $form ) {
		$notes = array();
		foreach ( $form['fields'] as $field ) {
			if ( 'hidden' === $field['type'] ) {
				/* translators: %s: field name. */
				$notes[] = sprintf( __( 'Hidden field “%s” is filled from the URL query string (default:get).', 'forms-for-html-page-publisher' ), $field['name'] );
			}
		}
		return $notes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_form( array $form, $title ) {
		$names = HTMLPP_Forms_Parser::field_names( $form );
		$lines = array();
		$body  = array();
		$email = '';

		foreach ( $form['fields'] as $i => $field ) {
			$name    = $names[ $i ];
			$lines[] = $this->form_tag_markup( $field, $name );
			if ( 'hidden' !== $field['type'] ) {
				$body[] = $field['label'] . ': [' . $name . ']';
			}
			if ( '' === $email && 'email' === $field['type'] ) {
				$email = $name;
			}
		}
		$lines[] = '[submit ' . self::quote( $form['button'] ) . ']';

		$contact_form = WPCF7_ContactForm::get_template( array( 'title' => $title ) );

		$mail                       = $contact_form->prop( 'mail' );
		$mail['subject']            = '[_site_title] ' . $title;
		$mail['body']               = implode( "\n", $body ) . "\n\n-- \n" . __( 'Sent from', 'forms-for-html-page-publisher' ) . ' [_url]';
		$mail['additional_headers'] = '' !== $email ? 'Reply-To: [' . $email . ']' : '';

		$contact_form->set_properties(
			array(
				'form' => implode( "\n\n", $lines ),
				'mail' => $mail,
			)
		);

		$form_id = (int) $contact_form->save();
		if ( ! $form_id ) {
			return new WP_Error( 'htmlpp_forms_cf7', __( 'Contact Form 7 could not save the form.', 'forms-for-html-page-publisher' ) );
		}
		return $form_id;
	}

	/**
	 * CF7 form-template markup for one field.
	 *
	 * @param array  $field Detected field.
	 * @param string $name  Unique field name.
	 * @return string
	 */
	private function form_tag_markup( array $field, $name ) {
		$label = esc_html( $field['label'] );
		$star  = $field['required'] ? '*' : '';

		switch ( $field['type'] ) {
			case 'hidden':
				return '[hidden ' . $name . ' default:get]';

			case 'consent':
				return '[acceptance ' . $name . ( $field['required'] ? '' : ' optional' ) . '] '
					. esc_html( $field['checkbox_label'] ) . ' [/acceptance]';

			case 'select':
				$tag = '[select' . $star . ' ' . $name;
				if ( '' !== $field['placeholder'] ) {
					$tag .= ' first_as_label ' . self::quote( $field['placeholder'] );
				}
				return "<label> {$label}\n    " . $tag . ' ' . self::choices( $field['choices'] ) . '] </label>';

			case 'radio':
			case 'checkbox':
				// Radio buttons always require a choice in CF7, so no "*".
				$tag = '[' . $field['type'] . ( 'checkbox' === $field['type'] ? $star : '' ) . ' ' . $name . ' use_label_element ' . self::choices( $field['choices'] ) . ']';
				return "<fieldset>\n    <legend>{$label}</legend>\n    {$tag}\n</fieldset>";

			default:
				$types = array(
					'text'     => 'text',
					'email'    => 'email',
					'tel'      => 'tel',
					'url'      => 'url',
					'number'   => 'number',
					'date'     => 'date',
					'textarea' => 'textarea',
				);
				$tag   = '[' . $types[ $field['type'] ] . $star . ' ' . $name;
				if ( $field['rows'] ) {
					$tag .= ' x' . $field['rows']; // Textarea rows.
				}
				if ( '' !== $field['placeholder'] ) {
					$tag .= ' placeholder ' . self::quote( $field['placeholder'] );
				}
				return "<label> {$label}\n    " . $tag . '] </label>';
		}
	}

	/**
	 * Quoted CF7 choice list; "Text|value" when they differ (CF7 pipes).
	 *
	 * @param array[] $choices Choices with text and value.
	 * @return string
	 */
	private static function choices( array $choices ) {
		$out = array();
		foreach ( $choices as $choice ) {
			$value = $choice['text'] === $choice['value'] ? $choice['text'] : $choice['text'] . '|' . $choice['value'];
			$out[] = self::quote( $value );
		}
		return implode( ' ', $out );
	}

	/**
	 * A CF7 quoted value (double quotes can't be escaped, so they're dropped).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		return '"' . str_replace( array( '"', ']' ), '', (string) $value ) . '"';
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_form( $form_id ) {
		wp_delete_post( $form_id, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function edit_url( $form_id ) {
		return admin_url( 'admin.php?page=wpcf7&action=edit&post=' . (int) $form_id );
	}
}
