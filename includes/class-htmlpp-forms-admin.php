<?php
/**
 * "HTML Pages → Detect Forms" screen.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists pages that contain HTML forms and converts them on request.
 */
class HTMLPP_Forms_Admin {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'htmlpp-detect-forms';

	/**
	 * admin-post.php action.
	 */
	const ACTION = 'htmlpp_forms_convert';

	/**
	 * Field kinds as shown in the preview.
	 *
	 * @var array<string, string>
	 */
	private static $kind_labels = array();

	/**
	 * Integration instance.
	 *
	 * @var HTMLPP_Forms
	 */
	private $forms;

	/**
	 * Register hooks.
	 *
	 * @param HTMLPP_Forms $forms Integration instance.
	 */
	public function __construct( HTMLPP_Forms $forms ) {
		$this->forms = $forms;
		add_filter( 'htmlpp_page_meta_defaults', array( $this, 'meta_defaults' ) );
		add_action( 'htmlpp_page_created', array( $this, 'flag_page' ), 10, 2 );
		add_action( 'htmlpp_page_updated', array( $this, 'flag_page' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_convert' ) );
		add_action( 'admin_notices', array( $this, 'upload_notice' ) );
	}

	/**
	 * Persist the per-page form count through HTMLPP_Meta.
	 *
	 * @param array $defaults Page record defaults.
	 * @return array
	 */
	public function meta_defaults( $defaults ) {
		$defaults[ HTMLPP_Forms_Converter::META_KEY ] = 0;
		return $defaults;
	}

	/**
	 * Flag a page whenever its HTML is written (upload, replace, edit, restore).
	 *
	 * @param string $slug Page slug.
	 * @param string $html Page HTML.
	 */
	public function flag_page( $slug, $html ) {
		HTMLPP_Forms_Converter::flag( $slug, $html );
	}

	/**
	 * Form plugins the current user may convert to.
	 *
	 * @return HTMLPP_Forms_Adapter[]
	 */
	private function targets() {
		if ( ! HTMLPP_Plugin::can_write_markup() ) {
			return array();
		}
		return array_filter(
			$this->forms->available_adapters(),
			static function ( HTMLPP_Forms_Adapter $adapter ) {
				return $adapter->can_create();
			}
		);
	}

	/**
	 * Add the submenu under HTML Pages, with a count bubble.
	 */
	public function add_menu() {
		$count = HTMLPP_Forms_Converter::flagged_count();
		$label = __( 'Detect Forms', 'forms-for-html-page-publisher' );
		if ( $count ) {
			$label .= sprintf( ' <span class="awaiting-mod">%d</span>', $count );
		}

		add_submenu_page(
			HTMLPP_Admin::PAGE_SLUG,
			__( 'Detect Forms', 'forms-for-html-page-publisher' ),
			$label,
			HTMLPP_Plugin::capability(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * After an upload, point editors at pages that still contain HTML forms.
	 */
	public function upload_notice() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, HTMLPP_Admin::PAGE_SLUG ) || ! current_user_can( HTMLPP_Plugin::capability() ) ) {
			return;
		}
		$count = HTMLPP_Forms_Converter::flagged_count();
		if ( ! $count ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of pages. */
					_n( '%d page contains an HTML form that is not connected to a form plugin.', '%d pages contain HTML forms that are not connected to a form plugin.', $count, 'forms-for-html-page-publisher' ),
					$count
				)
			),
			esc_url( self::url() ),
			esc_html__( 'Review in Detect Forms', 'forms-for-html-page-publisher' )
		);
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	private static function url() {
		return add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Handle a "Convert" submission.
	 */
	public function handle_convert() {
		$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $slug );

		$target  = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		$targets = $this->targets();
		if ( ! isset( $targets[ $target ] ) ) {
			wp_die( esc_html__( 'You are not allowed to create forms in that form plugin.', 'forms-for-html-page-publisher' ), 403 );
		}
		$adapter = $targets[ $target ];

		$index  = isset( $_POST['form_index'] ) ? absint( $_POST['form_index'] ) : 0;
		$hash   = isset( $_POST['form_hash'] ) ? sanitize_key( wp_unslash( $_POST['form_hash'] ) ) : '';
		$result = HTMLPP_Forms_Converter::convert( $adapter, $slug, $index, $hash );

		$notice = is_wp_error( $result )
			? array( 'error' => $result->get_error_message() )
			: array(
				'adapter' => $adapter->id(),
				'form_id' => $result['form_id'],
				'title'   => $result['title'],
				'slug'    => $slug,
			);
		set_transient( 'htmlpp_forms_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		$pages   = HTMLPP_Plugin::get_instance()->pages;
		$results = array();
		foreach ( $pages->list_pages() as $page ) {
			$html = HTMLPP_Storage::read_page( $page['slug'] );
			if ( ! is_string( $html ) ) {
				continue;
			}
			HTMLPP_Forms_Converter::flag( $page['slug'], $html ); // Catches pages uploaded before activation.
			$forms = HTMLPP_Forms_Parser::find_forms( $html );
			if ( $forms ) {
				$results[] = array(
					'page'  => $page,
					'forms' => $forms,
				);
			}
		}
		$targets = $this->targets();
		?>
		<div class="wrap htmlpp-forms">
			<h1><?php esc_html_e( 'Detect Forms', 'forms-for-html-page-publisher' ); ?></h1>
			<p><?php esc_html_e( 'Pages below contain HTML forms that do not submit anywhere in WordPress. Converting creates a form with the detected fields in the form plugin you choose and replaces that form in the page with a placeholder. The previous version is kept in the page’s history.', 'forms-for-html-page-publisher' ); ?></p>
			<?php $this->render_notice(); ?>
			<?php if ( ! $this->forms->available_adapters() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Activate Gravity Forms, Contact Form 7 or WPForms to convert forms.', 'forms-for-html-page-publisher' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $results ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'No HTML forms found. All pages are clear.', 'forms-for-html-page-publisher' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $results as $result ) : ?>
				<?php $this->render_page( $result['page'], $result['forms'], $targets ); ?>
			<?php endforeach; ?>
		</div>
		<style>
			.htmlpp-forms .htmlpp-forms-page { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0 16px 16px; margin: 16px 0; max-width: 1100px; }
			.htmlpp-forms .htmlpp-forms-page h2 { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: baseline; }
			.htmlpp-forms .htmlpp-forms-page h2 small { font-weight: 400; font-size: 13px; }
			.htmlpp-forms .htmlpp-forms-form { border-top: 1px solid #f0f0f1; padding-top: 12px; margin-top: 12px; }
			.htmlpp-forms .htmlpp-forms-form h3 { margin: 0 0 6px; font-size: 14px; }
			.htmlpp-forms .htmlpp-forms-meta { color: #50575e; margin: 0 0 10px; }
			.htmlpp-forms table.widefat { margin-bottom: 12px; }
			.htmlpp-forms .htmlpp-forms-choices { color: #50575e; }
			.htmlpp-forms .htmlpp-forms-external { color: #996800; }
			.htmlpp-forms .htmlpp-forms-notes { margin: 0 0 12px; padding: 8px 12px; background: #f6f7f7; border-left: 4px solid #72aee6; }
			.htmlpp-forms .htmlpp-forms-notes[hidden] { display: none; }
			.htmlpp-forms .htmlpp-forms-notes p { margin: 4px 0; }
			.htmlpp-forms .htmlpp-forms-convert { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
			@media (max-width: 782px) { .htmlpp-forms table.widefat td:nth-child(4), .htmlpp-forms table.widefat th:nth-child(4) { display: none; } }
		</style>
		<script>
			document.addEventListener( 'change', function ( event ) {
				if ( ! event.target.matches( '.htmlpp-forms-target' ) ) {
					return;
				}
				var form = event.target.closest( '.htmlpp-forms-form' );
				form.querySelectorAll( '.htmlpp-forms-notes' ).forEach( function ( notes ) {
					notes.hidden = notes.dataset.target !== event.target.value;
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Result notice from the last conversion.
	 */
	private function render_notice() {
		$key    = 'htmlpp_forms_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		if ( isset( $notice['error'] ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice['error'] ) );
			return;
		}
		$adapter = $this->forms->adapter( $notice['adapter'] );
		if ( ! $adapter ) {
			return;
		}
		printf(
			'<div class="notice notice-success"><p>%s</p><p><a class="button" href="%s">%s</a> <a class="button" href="%s" target="_blank" rel="noopener">%s</a> <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: form plugin name, 2: form ID, 3: form title, 4: page slug. */
					__( 'Created %1$s form #%2$d “%3$s” and placed it in “%4$s”.', 'forms-for-html-page-publisher' ),
					$adapter->label(),
					$notice['form_id'],
					$notice['title'],
					$notice['slug']
				)
			),
			esc_url( $adapter->edit_url( $notice['form_id'] ) ),
			esc_html__( 'Edit form', 'forms-for-html-page-publisher' ),
			esc_url( HTMLPP_Storage::public_page_url( $notice['slug'] ) ),
			esc_html__( 'View page', 'forms-for-html-page-publisher' ),
			esc_url( HTMLPP_Admin::edit_url( $notice['slug'] ) ),
			esc_html__( 'Undo from page history', 'forms-for-html-page-publisher' )
		);
	}

	/**
	 * One page and its forms.
	 *
	 * @param array                  $page    Page description.
	 * @param array[]                $forms   Parsed forms.
	 * @param HTMLPP_Forms_Adapter[] $targets Plugins the user may convert to.
	 */
	private function render_page( array $page, array $forms, array $targets ) {
		?>
		<div class="htmlpp-forms-page">
			<h2>
				<?php echo esc_html( '' !== $page['title'] ? $page['title'] : $page['slug'] ); ?>
				<small>
					<code><?php echo esc_html( $page['slug'] ); ?></code>
					<?php if ( 'draft' === $page['status'] ) : ?>
						— <?php esc_html_e( 'Draft', 'forms-for-html-page-publisher' ); ?>
					<?php endif; ?>
					· <a href="<?php echo esc_url( $page['preview_url'] ? $page['preview_url'] : $page['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'forms-for-html-page-publisher' ); ?></a>
					· <a href="<?php echo esc_url( $page['edit_url'] ); ?>"><?php esc_html_e( 'Edit HTML', 'forms-for-html-page-publisher' ); ?></a>
				</small>
			</h2>
			<?php foreach ( $forms as $form ) : ?>
				<?php $this->render_form( $page, $form, count( $forms ), $targets ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One detected form: summary, field preview, convert controls.
	 *
	 * @param array                  $page    Page description.
	 * @param array                  $form    Parsed form.
	 * @param int                    $total   Forms on the page.
	 * @param HTMLPP_Forms_Adapter[] $targets Plugins the user may convert to.
	 */
	private function render_form( array $page, array $form, $total, array $targets ) {
		$confirm = $form['external']
			? sprintf(
				/* translators: %s: external host name. */
				__( 'This form currently sends data to %s. Converting replaces it, and that service will no longer receive submissions from this page. Continue?', 'forms-for-html-page-publisher' ),
				$form['external']
			)
			: '';
		$first   = $targets ? reset( $targets )->id() : '';
		?>
		<div class="htmlpp-forms-form">
			<h3>
				<?php
				/* translators: 1: form number, 2: total forms on the page. */
				echo esc_html( sprintf( __( 'Form %1$d of %2$d', 'forms-for-html-page-publisher' ), $form['index'] + 1, $total ) );
				?>
			</h3>
			<p class="htmlpp-forms-meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of fields, 2: submit button text. */
						_n( '%1$d field · button “%2$s”', '%1$d fields · button “%2$s”', count( $form['fields'] ), 'forms-for-html-page-publisher' ),
						count( $form['fields'] ),
						$form['button']
					)
				);
				?>
				·
				<?php if ( $form['external'] ) : ?>
					<span class="htmlpp-forms-external dashicons-before dashicons-warning">
						<?php
						/* translators: %s: external host name. */
						echo esc_html( sprintf( __( 'Posts to external service: %s', 'forms-for-html-page-publisher' ), $form['external'] ) );
						?>
					</span>
				<?php elseif ( '' !== $form['action'] && '#' !== $form['action'] ) : ?>
					<?php
					/* translators: %s: form action URL. */
					echo esc_html( sprintf( __( 'Action: %s', 'forms-for-html-page-publisher' ), $form['action'] ) );
					?>
				<?php else : ?>
					<?php esc_html_e( 'No working backend', 'forms-for-html-page-publisher' ); ?>
				<?php endif; ?>
			</p>

			<?php if ( $form['fields'] ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'forms-for-html-page-publisher' ); ?></th>
							<th><?php esc_html_e( 'Field type', 'forms-for-html-page-publisher' ); ?></th>
							<th><?php esc_html_e( 'Required', 'forms-for-html-page-publisher' ); ?></th>
							<th><?php esc_html_e( 'HTML name', 'forms-for-html-page-publisher' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $form['fields'] as $field ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $field['label'] ); ?>
									<?php if ( $field['choices'] ) : ?>
										<br><small class="htmlpp-forms-choices"><?php echo esc_html( implode( ', ', wp_list_pluck( $field['choices'], 'text' ) ) ); ?></small>
									<?php elseif ( 'consent' === $field['type'] ) : ?>
										<br><small class="htmlpp-forms-choices">☐ <?php echo esc_html( $field['checkbox_label'] ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::kind_label( $field['type'] ) ); ?> <small>(<?php echo esc_html( $field['html_type'] ); ?>)</small></td>
								<td><?php echo $field['required'] ? esc_html__( 'Yes', 'forms-for-html-page-publisher' ) : '—'; ?></td>
								<td><code><?php echo esc_html( $field['name'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php foreach ( $targets as $adapter ) : ?>
					<?php $notes = $adapter->conversion_notes( $form ); ?>
					<?php if ( $notes ) : ?>
						<div class="htmlpp-forms-notes" data-target="<?php echo esc_attr( $adapter->id() ); ?>"<?php echo $adapter->id() !== $first ? ' hidden' : ''; ?>>
							<?php foreach ( $notes as $note ) : ?>
								<p><?php echo esc_html( $note ); ?></p>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'No supported fields were found in this form.', 'forms-for-html-page-publisher' ); ?></p>
			<?php endif; ?>

			<?php if ( $targets && $form['fields'] ) : ?>
				<form class="htmlpp-forms-convert" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="slug" value="<?php echo esc_attr( $page['slug'] ); ?>">
					<input type="hidden" name="form_index" value="<?php echo esc_attr( $form['index'] ); ?>">
					<input type="hidden" name="form_hash" value="<?php echo esc_attr( $form['hash'] ); ?>">
					<?php wp_nonce_field( self::ACTION . '_' . $page['slug'] ); ?>
					<?php if ( count( $targets ) > 1 ) : ?>
						<?php $select_id = 'htmlpp-forms-target-' . $page['slug'] . '-' . $form['index']; ?>
						<label for="<?php echo esc_attr( $select_id ); ?>"><?php esc_html_e( 'Convert to', 'forms-for-html-page-publisher' ); ?></label>
						<select class="htmlpp-forms-target" id="<?php echo esc_attr( $select_id ); ?>" name="target">
							<?php foreach ( $targets as $adapter ) : ?>
								<option value="<?php echo esc_attr( $adapter->id() ); ?>"><?php echo esc_html( $adapter->label() ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Convert', 'forms-for-html-page-publisher' ), 'primary', 'submit', false ); ?>
					<?php else : ?>
						<input type="hidden" name="target" value="<?php echo esc_attr( $first ); ?>">
						<?php
						/* translators: %s: form plugin name. */
						submit_button( sprintf( __( 'Convert to %s', 'forms-for-html-page-publisher' ), reset( $targets )->label() ), 'primary', 'submit', false );
						?>
					<?php endif; ?>
				</form>
			<?php elseif ( ! $targets ) : ?>
				<p><em><?php esc_html_e( 'You need permission to edit page HTML and to create forms in an active form plugin to convert this form.', 'forms-for-html-page-publisher' ); ?></em></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Human label for a field kind.
	 *
	 * @param string $kind Field kind.
	 * @return string
	 */
	private static function kind_label( $kind ) {
		if ( ! self::$kind_labels ) {
			self::$kind_labels = array(
				'text'     => __( 'Text', 'forms-for-html-page-publisher' ),
				'email'    => __( 'Email', 'forms-for-html-page-publisher' ),
				'tel'      => __( 'Phone', 'forms-for-html-page-publisher' ),
				'url'      => __( 'Website', 'forms-for-html-page-publisher' ),
				'number'   => __( 'Number', 'forms-for-html-page-publisher' ),
				'date'     => __( 'Date', 'forms-for-html-page-publisher' ),
				'hidden'   => __( 'Hidden', 'forms-for-html-page-publisher' ),
				'textarea' => __( 'Paragraph text', 'forms-for-html-page-publisher' ),
				'select'   => __( 'Drop-down', 'forms-for-html-page-publisher' ),
				'radio'    => __( 'Radio buttons', 'forms-for-html-page-publisher' ),
				'checkbox' => __( 'Checkboxes', 'forms-for-html-page-publisher' ),
				'consent'  => __( 'Consent', 'forms-for-html-page-publisher' ),
			);
		}
		return isset( self::$kind_labels[ $kind ] ) ? self::$kind_labels[ $kind ] : $kind;
	}
}
