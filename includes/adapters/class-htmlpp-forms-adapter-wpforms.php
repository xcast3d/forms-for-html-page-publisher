<?php
/**
 * WPForms adapter.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * WPForms (Lite or Pro): <!-- form:wpforms:ID -->.
 *
 * WPForms submits through admin-ajax.php when AJAX is on (the default for
 * new forms), so only the no-JS fallback posts to the page URL. Its assets
 * and settings are normally printed on wp_footer, which never fires on
 * published pages; the adapter calls the same public methods directly.
 */
class HTMLPP_Forms_Adapter_WPForms extends HTMLPP_Forms_Adapter {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'wpforms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'WPForms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return function_exists( 'wpforms' ) && wpforms()->obj( 'frontend' ) && wpforms()->obj( 'form' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs_enqueue_pass() {
		return true; // WPForms registers some scripts and styles on wp_enqueue_scripts.
	}

	/**
	 * Whether every WPForms form rendered so far uses styles=page.
	 *
	 * @var bool|null Null until the first render.
	 */
	private $all_page_styles = null;

	/**
	 * Whether the current render uses styles=page.
	 *
	 * @var bool
	 */
	private $off = false;

	/**
	 * {@inheritDoc}
	 *
	 * Uses WPForms' "No styles" level of its disable-css setting, which still
	 * loads a small functional stylesheet.
	 */
	public function page_styles( $off ) {
		$this->off = (bool) $off;
		if ( $off ) {
			add_filter( 'wpforms_setting', array( $this, 'no_styles' ), 99, 2 );
		} else {
			remove_filter( 'wpforms_setting', array( $this, 'no_styles' ), 99 );
		}
	}

	/**
	 * Force WPForms' "No styles" CSS level.
	 *
	 * @param mixed  $value Setting value.
	 * @param string $key   Setting key.
	 * @return mixed
	 */
	public function no_styles( $value, $key ) {
		return 'disable-css' === $key ? '3' : $value;
	}

	/**
	 * {@inheritDoc}
	 */
	public function form_exists( $form_id ) {
		return 'wpforms' === get_post_type( $form_id ) && 'publish' === get_post_status( $form_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( $form_id, array $flags, $preview ) {
		$this->all_page_styles = false !== $this->all_page_styles && $this->off;
		if ( ! $this->form_exists( $form_id ) ) {
			return null;
		}

		if ( ! has_filter( 'wpforms_field_properties_textarea', array( $this, 'textarea_rows' ) ) ) {
			add_filter( 'wpforms_field_properties_textarea', array( $this, 'textarea_rows' ), 10, 2 );
		}

		ob_start();
		wpforms()->obj( 'frontend' )->output( $form_id, $flags['title'], $flags['description'] );
		$markup = ob_get_clean();

		return '' !== trim( $markup ) ? $markup : null;
	}

	/**
	 * Use the original textarea's rows (WPForms prints none).
	 *
	 * @param array $properties Field properties.
	 * @param array $field      Field data.
	 * @return array
	 */
	public function textarea_rows( $properties, $field ) {
		if ( ! empty( $field['htmlpp_rows'] ) ) {
			$properties['inputs']['primary']['attr']['rows'] = absint( $field['htmlpp_rows'] );
		}
		return $properties;
	}

	/**
	 * {@inheritDoc}
	 */
	public function after_render( array $form_ids ) {
		// Enqueues the CSS/JS for the forms output() recorded (normally wp_footer, 15).
		// WPForms loads one stylesheet for all its forms on a page, so its theme
		// stays on if any of them uses styles=plugin.
		$this->page_styles( (bool) $this->all_page_styles );
		wpforms()->obj( 'frontend' )->assets_footer();
		$this->page_styles( false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function footer_html() {
		// Prints `var wpforms_settings` (normally wp_footer, 99).
		ob_start();
		wpforms()->obj( 'frontend' )->footer_end();
		return (string) ob_get_clean();
	}

	/**
	 * {@inheritDoc}
	 */
	public function posted_form_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WPForms validates its own submissions.
		return isset( $_POST['wpforms']['id'] ) ? absint( wp_unslash( $_POST['wpforms']['id'] ) ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function process_submission( $form_id ) {
		wpforms()->obj( 'process' )->listen(); // WPForms' own non-AJAX handler (normally on wp).
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create() {
		return function_exists( 'wpforms_current_user_can' ) && wpforms_current_user_can( 'create_forms' );
	}

	/**
	 * Field kinds WPForms renders in this edition.
	 *
	 * @return array<string, string|null> Kind => WPForms type (null = skipped).
	 */
	private function types() {
		$pro = wpforms()->is_pro();
		return array(
			'text'     => 'text',
			'email'    => 'email',
			'tel'      => $pro ? 'phone' : 'text',
			'url'      => $pro ? 'url' : 'text',
			'number'   => 'number',
			'date'     => $pro ? 'date-time' : 'text',
			'hidden'   => $pro ? 'hidden' : null,
			'textarea' => 'textarea',
			'select'   => 'select',
			'radio'    => 'radio',
			'checkbox' => 'checkbox',
			'consent'  => 'checkbox',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function conversion_notes( array $form ) {
		if ( wpforms()->is_pro() ) {
			return array();
		}
		$as_text = array();
		$skipped = array();
		foreach ( $form['fields'] as $field ) {
			if ( in_array( $field['type'], array( 'tel', 'url', 'date' ), true ) ) {
				$as_text[] = $field['label'];
			} elseif ( 'hidden' === $field['type'] ) {
				$skipped[] = $field['name'];
			}
		}
		$notes = array();
		if ( $as_text ) {
			/* translators: %s: comma-separated field labels. */
			$notes[] = sprintf( __( 'WPForms Lite has no Phone, Website or Date fields; these become text fields: %s.', 'htmlpp-forms' ), implode( ', ', $as_text ) );
		}
		if ( $skipped ) {
			/* translators: %s: comma-separated field names. */
			$notes[] = sprintf( __( 'WPForms Lite has no Hidden field; these are skipped: %s.', 'htmlpp-forms' ), implode( ', ', $skipped ) );
		}
		return $notes;
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_form( array $form, $title ) {
		$forms   = wpforms()->obj( 'form' );
		$form_id = $forms->add( $title, array(), array( 'builder' => false ) ); // Adds default notification + confirmation.
		if ( ! $form_id ) {
			return new WP_Error( 'htmlpp_forms_wpforms', __( 'WPForms could not create the form.', 'htmlpp-forms' ) );
		}

		$data   = $forms->get( $form_id, array( 'content_only' => true ) );
		$types  = $this->types();
		$fields = array();
		$id     = 1;
		foreach ( $form['fields'] as $field ) {
			$type = $types[ $field['type'] ];
			if ( null === $type ) {
				continue;
			}
			$fields[ (string) $id ] = $this->field( $field, $type, (string) $id );
			++$id;
		}

		// Same defaults as WPForms' blank template (AJAX submit, anti-spam).
		$defaults = class_exists( 'WPForms_Template_Blank' )
			? WPForms_Template_Blank::get_data()['settings']
			: array(
				'ajax_submit' => '1',
				'antispam_v3' => '1',
			);

		$data['fields']                  = $fields;
		$data['field_id']                = (string) $id;
		$data['settings']                = array_merge( $defaults, (array) $data['settings'] );
		$data['settings']['submit_text'] = $form['button'];

		$forms->update( $form_id, $data, array( 'cap' => 'create_forms' ) );

		return (int) $form_id;
	}

	/**
	 * WPForms field data for one detected field.
	 *
	 * @param array  $field Detected field.
	 * @param string $type  WPForms field type.
	 * @param string $id    Field ID.
	 * @return array
	 */
	private function field( array $field, $type, $id ) {
		$out = array(
			'id'          => $id,
			'type'        => $type,
			'label'       => $field['label'],
			'required'    => $field['required'] ? '1' : '',
			'size'        => 'medium',
			'placeholder' => $field['placeholder'],
		);

		if ( 'consent' === $field['type'] ) {
			// One checkbox; hide the field label so the text shows once.
			$out['label_hide'] = '1';
			$out['choices']    = array(
				1 => array(
					'label' => $field['checkbox_label'],
					'value' => '',
					'image' => '',
				),
			);
		} elseif ( $field['choices'] ) {
			$out['choices'] = array();
			foreach ( array_values( $field['choices'] ) as $n => $choice ) {
				$out['choices'][ $n + 1 ] = array(
					'label' => $choice['text'],
					'value' => $choice['value'],
					'image' => '',
				);
				if ( $choice['value'] !== $choice['text'] ) {
					$out['show_values'] = '1';
				}
			}
		}

		if ( $field['rows'] ) {
			$out['htmlpp_rows'] = $field['rows']; // Applied in textarea_rows().
		}

		if ( 'phone' === $type ) {
			$out['format'] = 'smart';
		} elseif ( 'date-time' === $type ) {
			$out['format'] = 'date';
		} elseif ( 'hidden' === $type ) {
			$out['default_value'] = '{query_var key="' . sanitize_key( $field['name'] ) . '"}';
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_form( $form_id ) {
		wpforms()->obj( 'form' )->delete( array( $form_id ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function edit_url( $form_id ) {
		return admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id=' . (int) $form_id );
	}
}
