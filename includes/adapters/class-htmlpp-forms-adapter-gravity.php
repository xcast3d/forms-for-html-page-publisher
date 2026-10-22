<?php
/**
 * Gravity Forms adapter.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms: <!-- form:gravity:ID --> (or legacy <!-- gravity-form:ID -->).
 */
class HTMLPP_Forms_Adapter_Gravity extends HTMLPP_Forms_Adapter {

	/**
	 * Field kinds mapped to Gravity Forms field types.
	 *
	 * @var array<string, string>
	 */
	private static $types = array(
		'text'     => 'text',
		'email'    => 'email',
		'tel'      => 'phone',
		'url'      => 'website',
		'number'   => 'number',
		'date'     => 'date',
		'hidden'   => 'hidden',
		'textarea' => 'textarea',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
		'consent'  => 'consent',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'gravity';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Gravity Forms';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available() {
		return class_exists( 'GFForms' ) && class_exists( 'GFAPI' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function form_exists( $form_id ) {
		$form = GFAPI::get_form( $form_id );
		return $form && ! empty( $form['is_active'] ) && empty( $form['is_trash'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( $form_id, array $flags, $preview ) {
		if ( ! $this->form_exists( $form_id ) ) {
			return null;
		}
		if ( ! wp_script_is( 'gform_gravityforms', 'registered' ) ) {
			return null; // Gravity Forms has not initialised; see HTMLPP_Forms::defer_renderer().
		}

		// Init scripts default to wp_footer, which never fires on these pages.
		add_filter( 'gform_init_scripts_footer', '__return_false', 99 );
		if ( ! has_filter( 'gform_field_content', array( $this, 'textarea_rows' ) ) ) {
			add_filter( 'gform_field_content', array( $this, 'textarea_rows' ), 10, 2 );
		}

		gravity_form_enqueue_scripts( $form_id, true );
		$markup = gravity_form(
			$form_id,
			$flags['title'],
			$flags['description'],
			false, // Display inactive.
			null,  // Field values.
			true,  // AJAX.
			0,     // Tab index.
			false  // Echo.
		);

		remove_filter( 'gform_init_scripts_footer', '__return_false', 99 );

		return (string) $markup;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Turns off Gravity Forms' CSS and uses the plain "gravity-theme" markup
	 * (no Orbital CSS variables). The base stylesheet hides the honeypot and
	 * other fields Gravity Forms' CSS normally hides.
	 */
	public function page_styles( $off ) {
		// The '"*" indicates required fields' note isn't part of the page's
		// design; the asterisks on the labels remain.
		if ( $off ) {
			add_filter( 'gform_disable_css', '__return_true', 99 );
			add_filter( 'gform_form_theme_slug', array( $this, 'plain_theme' ), 99 );
			add_filter( 'gform_required_legend', '__return_empty_string', 99 );
		} else {
			remove_filter( 'gform_disable_css', '__return_true', 99 );
			remove_filter( 'gform_form_theme_slug', array( $this, 'plain_theme' ), 99 );
			remove_filter( 'gform_required_legend', '__return_empty_string', 99 );
		}
	}

	/**
	 * Use the original textarea's rows (Gravity Forms always prints 10).
	 *
	 * @param string   $content Field markup.
	 * @param GF_Field $field   Field.
	 * @return string
	 */
	public function textarea_rows( $content, $field ) {
		if ( 'textarea' !== $field->type || empty( $field->htmlppRows ) ) {
			return $content;
		}
		return preg_replace( '/\brows=([\'"])\d+\1/', 'rows=${1}' . absint( $field->htmlppRows ) . '${1}', $content );
	}

	/**
	 * Theme slug for page-styled forms.
	 *
	 * @return string
	 */
	public function plain_theme() {
		return 'gravity-theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function after_render( array $form_ids ) {
		// Localize gform_theme_config etc.; normally done on wp_enqueue_scripts.
		do_action( 'gform_output_config', array( 'form_ids' => $form_ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function posted_form_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms validates its own submissions (form state hash, honeypot).
		return isset( $_POST['gform_submit'] ) ? absint( wp_unslash( $_POST['gform_submit'] ) ) : 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function process_submission( $form_id ) {
		GFForms::maybe_process_form();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_postback( $form_id ) {
		return $this->posted_form_id() === (int) $form_id && GFFormDisplay::is_iframe_submission_method();
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_create() {
		return GFCommon::current_user_can_any( 'gravityforms_create_form' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_form( array $form, $title ) {
		$fields = array();
		$id     = 1;
		foreach ( $form['fields'] as $field ) {
			$gf = array(
				'type'        => self::$types[ $field['type'] ],
				'id'          => $id,
				'label'       => $field['label'],
				'isRequired'  => $field['required'],
				'inputName'   => $field['name'],
				'placeholder' => $field['placeholder'],
			);
			if ( $field['choices'] ) {
				$gf['choices'] = $field['choices'];
			}
			if ( 'checkbox' === $field['type'] ) {
				$gf['inputs'] = array();
				foreach ( $field['choices'] as $n => $choice ) {
					$gf['inputs'][] = array(
						'id'    => $id . '.' . ( $n + 1 ),
						'label' => $choice['text'],
					);
				}
			}
			if ( 'consent' === $field['type'] ) {
				// Mirrors what the form editor builds: the checkbox, plus hidden
				// inputs that store the agreed wording and description.
				$gf['checkboxLabel'] = $field['checkbox_label'];
				$gf['choices']       = array(
					array(
						'text'       => __( 'Checked', 'htmlpp-forms' ),
						'value'      => '1',
						'isSelected' => false,
						'price'      => '',
					),
				);
				$gf['inputs']        = array(
					array(
						'id'    => $id . '.1',
						'label' => __( 'Consent', 'htmlpp-forms' ),
						'name'  => '',
					),
					array(
						'id'       => $id . '.2',
						'label'    => __( 'Text', 'htmlpp-forms' ),
						'name'     => '',
						'isHidden' => true,
					),
					array(
						'id'       => $id . '.3',
						'label'    => __( 'Description', 'htmlpp-forms' ),
						'name'     => '',
						'isHidden' => true,
					),
				);
			}
			if ( 'tel' === $field['type'] ) {
				$gf['phoneFormat'] = 'standard';
			}
			if ( 'hidden' === $field['type'] ) {
				$gf['allowsPrepopulate'] = true; // e.g. utm_source from the query string.
			}
			if ( $field['rows'] ) {
				$gf['htmlppRows'] = $field['rows']; // Applied in textarea_rows().
			}
			$fields[] = $gf;
			++$id;
		}

		// GFAPI::add_form() adds a default confirmation but no notification;
		// mirror the "Admin Notification" the form editor creates.
		$notification_id = uniqid();

		return GFAPI::add_form(
			array(
				'title'             => $this->unique_title( $title ),
				'fields'            => $fields,
				'requiredIndicator' => 'asterisk',
				'button'            => array(
					'type' => 'text',
					'text' => $form['button'],
				),
				'notifications'     => array(
					$notification_id => array(
						'id'       => $notification_id,
						'isActive' => true,
						'name'     => __( 'Admin Notification', 'htmlpp-forms' ),
						'event'    => 'form_submission',
						'toType'   => 'email',
						'to'       => '{admin_email}',
						'subject'  => __( 'New submission from', 'htmlpp-forms' ) . ' {form_title}',
						'message'  => '{all_fields}',
					),
				),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_form( $form_id ) {
		GFAPI::delete_form( $form_id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function edit_url( $form_id ) {
		return admin_url( 'admin.php?page=gf_edit_forms&id=' . (int) $form_id );
	}

	/**
	 * Gravity Forms requires unique form titles.
	 *
	 * @param string $base Desired title.
	 * @return string
	 */
	private function unique_title( $base ) {
		$taken = array();
		foreach ( GFAPI::get_forms( null ) as $existing ) {
			$taken[ strtolower( $existing['title'] ) ] = true;
		}
		$title = $base;
		for ( $n = 2; isset( $taken[ strtolower( $title ) ] ); $n++ ) {
			$title = sprintf( '%s (%d)', $base, $n );
		}
		return $title;
	}
}
