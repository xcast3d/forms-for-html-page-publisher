<?php
/**
 * "HTML Pages → Detect Forms" screen.
 *
 * @package FFHPP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists pages that contain HTML forms and converts them on request.
 */
class FFHPP_Admin {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'ffhpp-detect-forms';

	/**
	 * admin-post.php action.
	 */
	const ACTION = 'ffhpp_convert';

	/**
	 * Field kinds as shown in the preview.
	 *
	 * @var array<string, string>
	 */
	private static $kind_labels = array();

	/**
	 * Integration instance.
	 *
	 * @var FFHPP_Plugin
	 */
	private $forms;

	/**
	 * Register hooks.
	 *
	 * @param FFHPP_Plugin $forms Integration instance.
	 */
	public function __construct( FFHPP_Plugin $forms ) {
		$this->forms = $forms;
		add_filter( 'htmlpp_page_meta_defaults', array( $this, 'meta_defaults' ) );
		add_action( 'htmlpp_page_created', array( $this, 'flag_page' ), 10, 2 );
		add_action( 'htmlpp_page_updated', array( $this, 'flag_page' ), 10, 2 );
		foreach ( array( 'htmlpp_page_meta_updated', 'htmlpp_page_deleted', 'htmlpp_page_renamed', 'htmlpp_page_copied', 'htmlpp_page_duplicated' ) as $hook ) {
			add_action( $hook, array( 'FFHPP_Converter', 'forget_flagged_count' ) );
		}
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_convert' ) );
		add_action( 'admin_notices', array( $this, 'upload_notice' ) );
	}

	/**
	 * Register the per-page form count as an HTML Page Publisher page field.
	 *
	 * @param array $defaults Page record defaults.
	 * @return array
	 */
	public function meta_defaults( $defaults ) {
		$defaults[ FFHPP_Converter::META_KEY ] = 0;
		return $defaults;
	}

	/**
	 * Flag a page whenever its HTML is written (upload, replace, edit, restore).
	 *
	 * @param string $slug Page slug.
	 * @param string $html Page HTML.
	 */
	public function flag_page( $slug, $html ) {
		FFHPP_Converter::flag( $slug, $html );
	}

	/**
	 * Form plugins the current user may convert to.
	 *
	 * @return FFHPP_Adapter[]
	 */
	private function targets() {
		if ( ! HTMLPP_Plugin::can_write_markup() ) {
			return array();
		}
		return array_filter(
			$this->forms->available_adapters(),
			static function ( FFHPP_Adapter $adapter ) {
				return $adapter->can_create();
			}
		);
	}

	/**
	 * Add the submenu under HTML Pages, with a count bubble.
	 */
	public function add_menu() {
		$count = FFHPP_Converter::flagged_count();
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
		$count = FFHPP_Converter::flagged_count();
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
		$result = FFHPP_Converter::convert( $adapter, $slug, $index, $hash );

		$notice = is_wp_error( $result )
			? array( 'error' => $result->get_error_message() )
			: array(
				'adapter' => $adapter->id(),
				'form_id' => $result['form_id'],
				'title'   => $result['title'],
				'slug'    => $slug,
			);
		set_transient( 'ffhpp_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		$pages   = htmlpp()->pages;
		$results = array();
		foreach ( $pages->list_pages() as $page ) {
			$full = $pages->get( $page['slug'], true );
			if ( is_wp_error( $full ) ) {
				continue;
			}
			$html = $full['html'];
			FFHPP_Converter::flag( $page['slug'], $html ); // Catches pages uploaded before activation.
			$forms    = FFHPP_Parser::find_forms( $html );
			$problems = array();
			foreach ( FFHPP_Plugin::placeholders( $html ) as $placeholder ) {
				$status = $this->forms->placeholder_status( $placeholder );
				if ( 'ok' !== $status ) {
					$problems[] = array(
						'placeholder' => $placeholder,
						'status'      => $status,
					);
				}
			}
			if ( $forms || $problems ) {
				$results[] = array(
					'page'     => $page,
					'forms'    => $forms,
					'problems' => $problems,
				);
			}
		}
		$targets = $this->targets();
		?>
		<div class="wrap ffhpp-wrap">
			<h1><?php esc_html_e( 'Detect Forms', 'forms-for-html-page-publisher' ); ?></h1>
			<p><?php esc_html_e( 'Pages below contain HTML forms that do not submit anywhere in WordPress. Converting creates a form with the detected fields in the form plugin you choose and replaces that form in the page with a placeholder. The previous version is kept in the page’s history.', 'forms-for-html-page-publisher' ); ?></p>
			<?php $this->render_notice(); ?>
			<?php if ( ! $this->forms->available_adapters() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Activate Gravity Forms, Contact Form 7 or WPForms to convert forms.', 'forms-for-html-page-publisher' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $results ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'No HTML forms or broken form placeholders found. All pages are clear.', 'forms-for-html-page-publisher' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $results as $result ) : ?>
				<?php $this->render_page( $result['page'], $result['forms'], $result['problems'], $targets ); ?>
			<?php endforeach; ?>
		</div>
		<style>
			.ffhpp-wrap .ffhpp-page { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 0 16px 16px; margin: 16px 0; max-width: 1100px; }
			.ffhpp-wrap .ffhpp-page h2 { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: baseline; }
			.ffhpp-wrap .ffhpp-page h2 small { font-weight: 400; font-size: 13px; }
			.ffhpp-wrap .ffhpp-form { border-top: 1px solid #f0f0f1; padding-top: 12px; margin-top: 12px; }
			.ffhpp-wrap .ffhpp-form h3 { margin: 0 0 6px; font-size: 14px; }
			.ffhpp-wrap .ffhpp-meta { color: #50575e; margin: 0 0 10px; }
			.ffhpp-wrap table.widefat { margin-bottom: 12px; }
			.ffhpp-wrap .ffhpp-choices { color: #50575e; }
			.ffhpp-wrap .ffhpp-external { color: #996800; }
			.ffhpp-wrap .ffhpp-notes { margin: 0 0 12px; padding: 8px 12px; background: #f6f7f7; border-left: 4px solid #72aee6; }
			.ffhpp-wrap .ffhpp-notes[hidden] { display: none; }
			.ffhpp-wrap .ffhpp-notes p { margin: 4px 0; }
			.ffhpp-wrap .ffhpp-convert { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
			@media (max-width: 782px) { .ffhpp-wrap table.widefat td:nth-child(4), .ffhpp-wrap table.widefat th:nth-child(4) { display: none; } }
		</style>
		<script>
			document.addEventListener( 'change', function ( event ) {
				if ( ! event.target.matches( '.ffhpp-target' ) ) {
					return;
				}
				var form = event.target.closest( '.ffhpp-form' );
				form.querySelectorAll( '.ffhpp-notes' ).forEach( function ( notes ) {
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
		$key    = 'ffhpp_notice_' . get_current_user_id();
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
		$page    = htmlpp()->pages->get( $notice['slug'] );
		if ( ! $adapter || is_wp_error( $page ) ) {
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
			esc_url( $page['url'] ),
			esc_html__( 'View page', 'forms-for-html-page-publisher' ),
			esc_url( $page['edit_url'] ),
			esc_html__( 'Undo from page history', 'forms-for-html-page-publisher' )
		);
	}

	/**
	 * One page and its forms.
	 *
	 * @param array           $page     Page description.
	 * @param array[]         $forms    Parsed forms.
	 * @param array[]         $problems Placeholders whose form can't be shown: placeholder, status.
	 * @param FFHPP_Adapter[] $targets  Plugins the user may convert to.
	 */
	private function render_page( array $page, array $forms, array $problems, array $targets ) {
		?>
		<div class="ffhpp-page">
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
			<?php if ( $problems ) : ?>
				<div class="notice notice-error inline ffhpp-problems">
					<?php foreach ( $problems as $problem ) : ?>
						<p>
							<?php echo esc_html( $this->problem_message( $problem['placeholder'], $problem['status'] ) ); ?>
							<br><code><?php echo esc_html( $problem['placeholder']['raw'] ); ?></code>
						</p>
					<?php endforeach; ?>
					<p><em><?php esc_html_e( 'Visitors see nothing where these placeholders are; draft previews show a warning. Create or restore the form, or edit the page HTML to use an existing form ID.', 'forms-for-html-page-publisher' ); ?></em></p>
				</div>
			<?php endif; ?>
			<?php foreach ( $forms as $form ) : ?>
				<?php $this->render_form( $page, $form, count( $forms ), $targets ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * One detected form: summary, field preview, convert controls.
	 *
	 * @param array           $page    Page description.
	 * @param array           $form    Parsed form.
	 * @param int             $total   Forms on the page.
	 * @param FFHPP_Adapter[] $targets Plugins the user may convert to.
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
		<div class="ffhpp-form">
			<h3>
				<?php
				/* translators: 1: form number, 2: total forms on the page. */
				echo esc_html( sprintf( __( 'Form %1$d of %2$d', 'forms-for-html-page-publisher' ), $form['index'] + 1, $total ) );
				?>
			</h3>
			<p class="ffhpp-meta">
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
					<span class="ffhpp-external dashicons-before dashicons-warning">
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
										<br><small class="ffhpp-choices"><?php echo esc_html( implode( ', ', wp_list_pluck( $field['choices'], 'text' ) ) ); ?></small>
									<?php elseif ( 'consent' === $field['type'] ) : ?>
										<br><small class="ffhpp-choices">☐ <?php echo esc_html( $field['checkbox_label'] ); ?></small>
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
						<div class="ffhpp-notes" data-target="<?php echo esc_attr( $adapter->id() ); ?>"<?php echo $adapter->id() !== $first ? ' hidden' : ''; ?>>
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
				<form class="ffhpp-convert" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''; ?>>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="slug" value="<?php echo esc_attr( $page['slug'] ); ?>">
					<input type="hidden" name="form_index" value="<?php echo esc_attr( $form['index'] ); ?>">
					<input type="hidden" name="form_hash" value="<?php echo esc_attr( $form['hash'] ); ?>">
					<?php wp_nonce_field( self::ACTION . '_' . $page['slug'] ); ?>
					<?php if ( count( $targets ) > 1 ) : ?>
						<?php $select_id = 'ffhpp-target-' . $page['slug'] . '-' . $form['index']; ?>
						<label for="<?php echo esc_attr( $select_id ); ?>"><?php esc_html_e( 'Convert to', 'forms-for-html-page-publisher' ); ?></label>
						<select class="ffhpp-target" id="<?php echo esc_attr( $select_id ); ?>" name="target">
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
	 * Why a placeholder's form can't be shown.
	 *
	 * @param array  $placeholder Placeholder from FFHPP_Plugin::placeholders().
	 * @param string $status      Status from FFHPP_Plugin::placeholder_status().
	 * @return string
	 */
	private function problem_message( array $placeholder, $status ) {
		$adapter = $this->forms->adapter( $placeholder['adapter'] );
		if ( 'unknown plugin' === $status || ! $adapter ) {
			/* translators: 1: plugin key from the placeholder, 2: form ID. */
			return sprintf( __( 'Form placeholder found for an unknown form plugin “%1$s” (form ID %2$d).', 'forms-for-html-page-publisher' ), $placeholder['adapter'], $placeholder['form_id'] );
		}
		if ( 'plugin inactive' === $status ) {
			/* translators: 1: form plugin name, 2: form ID. */
			return sprintf( __( '%1$s placeholder found for form ID %2$d, but %1$s is not active.', 'forms-for-html-page-publisher' ), $adapter->label(), $placeholder['form_id'] );
		}
		/* translators: 1: form plugin name, 2: form ID. */
		return sprintf( __( '%1$s placeholder found, but no active %1$s form has ID %2$d (it may not exist, be inactive or be in the trash).', 'forms-for-html-page-publisher' ), $adapter->label(), $placeholder['form_id'] );
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
