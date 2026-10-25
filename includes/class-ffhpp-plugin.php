<?php
/**
 * Replaces form placeholders in published pages.
 *
 * @package FFHPP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Integration between HTML Page Publisher and form plugins.
 *
 * Placeholder syntax, anywhere in a page's index.html:
 *
 *     <!-- form:gravity:12 -->
 *     <!-- form:cf7:34 -->
 *     <!-- form:wpforms:56 title=true description=true -->
 *     <!-- form:cf7:34 styles=page class="signup-form" id="signup" button-class="btn btn-primary" -->
 *     <!-- gravity-form:12 -->   (legacy, same as form:gravity:12)
 *
 * styles=page turns the form plugin's visual theme off, puts class/id on the
 * rendered form and button-class on its submit button, so the landing page's
 * own CSS styles the form. Without it the form plugin's theme is used.
 *
 * Only these explicit placeholders are processed; shortcodes elsewhere in the
 * uploaded document are left untouched.
 */
class FFHPP_Plugin {

	/**
	 * Matches a placeholder: adapter ID (or legacy "gravity-form"), form ID,
	 * optional key=value or key="value" attributes.
	 */
	const PLACEHOLDER = '/<!--\s*(?:form:([a-z0-9_-]+):|(gravity)-form:)(\d+)((?:\s+[a-z_-]+=(?:"[^"]*"|[^\s"]+))*)\s*-->/i';

	/**
	 * Singleton instance.
	 *
	 * @var FFHPP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Adapters keyed by ID, including unavailable ones.
	 *
	 * @var FFHPP_Adapter[]
	 */
	private $adapters = array();

	/**
	 * Whether the wp_enqueue_scripts registration pass has run.
	 *
	 * @var bool
	 */
	private $enqueue_pass_done = false;

	/**
	 * Bootstrap: check dependencies, then register hooks.
	 */
	public static function boot() {
		if ( null !== self::$instance ) {
			return;
		}
		if ( ! function_exists( 'htmlpp' ) || ! defined( 'HTMLPP_VERSION' ) ) {
			self::dependency_notice( __( 'Forms for HTML Page Publisher needs HTML Page Publisher to be active.', 'forms-for-html-page-publisher' ) );
			return;
		}
		if ( version_compare( HTMLPP_VERSION, FFHPP_MIN_HTMLPP, '<' ) ) {
			self::dependency_notice(
				sprintf(
					/* translators: 1: required version, 2: installed version. */
					__( 'Forms for HTML Page Publisher needs HTML Page Publisher %1$s or later (installed: %2$s). Update HTML Page Publisher to use it.', 'forms-for-html-page-publisher' ),
					FFHPP_MIN_HTMLPP,
					HTMLPP_VERSION
				)
			);
			return;
		}

		self::$instance = new self();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-cli.php';
			WP_CLI::add_command( 'htmlpp forms', 'FFHPP_CLI' );
		}

		if ( ! self::$instance->available_adapters() ) {
			self::dependency_notice( __( 'Forms for HTML Page Publisher needs Gravity Forms, Contact Form 7 or WPForms to be active.', 'forms-for-html-page-publisher' ) );
		}
	}

	/**
	 * The running instance.
	 *
	 * @return FFHPP_Plugin|null
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Show an admin notice to users who manage plugins.
	 *
	 * @param string $message Message.
	 */
	private static function dependency_notice( $message ) {
		add_action(
			'admin_notices',
			static function () use ( $message ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $message ) );
				}
			}
		);
	}

	/**
	 * Register adapters and hooks.
	 */
	private function __construct() {
		/**
		 * Filter the form plugin adapters.
		 *
		 * @param FFHPP_Adapter[] $adapters Adapters, in order of preference.
		 */
		$adapters = array(
			new FFHPP_Adapter_Gravity(),
			new FFHPP_Adapter_WPForms(),
			new FFHPP_Adapter_CF7(),
		);
		$adapters = apply_filters_deprecated( 'htmlpp_forms_adapters', array( $adapters ), '0.7.0', 'ffhpp_adapters' );
		$adapters = apply_filters( 'ffhpp_adapters', $adapters );
		foreach ( $adapters as $adapter ) {
			if ( $adapter instanceof FFHPP_Adapter ) {
				$this->adapters[ $adapter->id() ] = $adapter;
			}
		}

		add_action( 'htmlpp_before_serve', array( $this, 'maybe_process_submission' ), 10, 3 );
		add_filter( 'htmlpp_page_html', array( $this, 'filter_page_html' ), 10, 4 );
		new FFHPP_Admin( $this );
	}

	/**
	 * Adapter by ID.
	 *
	 * @param string $id Adapter ID.
	 * @return FFHPP_Adapter|null
	 */
	public function adapter( $id ) {
		$id = strtolower( (string) $id );
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}

	/**
	 * Adapters whose form plugin is active.
	 *
	 * @return FFHPP_Adapter[]
	 */
	public function available_adapters() {
		return array_filter(
			$this->adapters,
			static function ( FFHPP_Adapter $adapter ) {
				return $adapter->is_available();
			}
		);
	}

	/**
	 * Serve pages after form plugins have initialised.
	 *
	 * HTML Page Publisher serves at init priority 0 by default, before form
	 * plugins register their scripts and services (init, 10). A later init
	 * priority keeps serving ahead of WordPress's main query while letting
	 * forms render normally. Hooked on `htmlpp_serve_priority`.
	 *
	 * @param int $priority Priority requested so far.
	 * @return int
	 */
	public static function serve_priority( $priority ) {
		/**
		 * Filter the init priority pages are served at when this add-on is active.
		 *
		 * Must be later than the form plugins' own init callbacks (10).
		 *
		 * @param int $priority Init priority. Default 20.
		 */
		$ours = apply_filters_deprecated( 'htmlpp_forms_serve_priority', array( 20 ), '0.7.0', 'ffhpp_serve_priority' );
		$ours = (int) apply_filters( 'ffhpp_serve_priority', $ours );
		return max( (int) $priority, $ours );
	}

	/**
	 * Placeholders in a document.
	 *
	 * @param string $html Page HTML.
	 * @return array[] Each: adapter, form_id, flags (see parse_attributes()).
	 */
	public static function placeholders( $html ) {
		if ( ! preg_match_all( self::PLACEHOLDER, (string) $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		return array_map( array( __CLASS__, 'parse_match' ), $matches );
	}

	/**
	 * Normalise one placeholder match.
	 *
	 * @param array $m preg match.
	 * @return array adapter, form_id, flags.
	 */
	private static function parse_match( array $m ) {
		return array(
			'adapter' => strtolower( '' !== $m[1] ? $m[1] : $m[2] ),
			'form_id' => (int) $m[3],
			'flags'   => self::parse_attributes( $m[4] ),
		);
	}

	/**
	 * Process a form posted to a published page.
	 *
	 * Non-AJAX submissions (and Gravity Forms' iframe AJAX method) POST to the
	 * page URL and are normally processed on `parse_request` or `wp`, which
	 * never run because pages are served during init. Only forms whose
	 * placeholder is in this page are processed; the result is then rendered
	 * by filter_page_html() as usual.
	 *
	 * @param string $slug Page slug.
	 * @param string $file Absolute path of the file about to be served.
	 * @param string $rel  Path relative to the page directory ('' for index.html).
	 */
	public function maybe_process_submission( $slug, $file, $rel ) {
		if ( '' !== $rel || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$page = htmlpp()->pages->get( $slug, true );
		if ( is_wp_error( $page ) || '' === $page['html'] ) {
			return;
		}
		$html = $page['html'];

		foreach ( self::placeholders( $html ) as $placeholder ) {
			$adapter = $this->adapter( $placeholder['adapter'] );
			if ( $adapter && $adapter->is_available() && $adapter->posted_form_id() === $placeholder['form_id'] ) {
				$adapter->process_submission( $placeholder['form_id'] );
				return;
			}
		}
	}

	/**
	 * Replace placeholders with rendered forms and add their assets.
	 *
	 * @param string $html    Page HTML.
	 * @param string $slug    Page slug.
	 * @param array  $meta    Page metadata.
	 * @param bool   $preview Whether this is a draft preview.
	 * @return string
	 */
	public function filter_page_html( $html, $slug, $meta, $preview ) {
		if ( false === stripos( $html, 'form:' ) ) {
			return $html;
		}

		$rendered    = array(); // Adapter ID => form IDs.
		$postback    = null;
		$page_styles = false;
		$html        = preg_replace_callback(
			self::PLACEHOLDER,
			function ( $m ) use ( $preview, &$rendered, &$postback, &$page_styles ) {
				$placeholder = self::parse_match( $m );
				$flags       = $placeholder['flags'];
				$adapter     = $this->adapter( $placeholder['adapter'] );
				$markup      = null;
				if ( $adapter && $adapter->is_available() ) {
					if ( $adapter->needs_enqueue_pass() ) {
						$this->enqueue_pass();
					}
					$off = 'page' === $flags['styles'];
					$adapter->page_styles( $off );
					$markup = $adapter->render( $placeholder['form_id'], $flags, $preview );
					if ( $off ) {
						$adapter->page_styles( false );
					}
				}
				if ( null === $markup ) {
					return self::unavailable( $placeholder, $adapter, $preview );
				}

				/**
				 * Filter a rendered form before it replaces its placeholder.
				 *
				 * @param string $markup  Form HTML.
				 * @param string $adapter Adapter ID.
				 * @param int    $form_id Form ID.
				 * @param bool   $preview Whether this is a draft preview.
				 */
				$markup = apply_filters_deprecated( 'htmlpp_forms_form_markup', array( $markup, $adapter->id(), $placeholder['form_id'], $preview ), '0.7.0', 'ffhpp_form_markup' );
				$markup = (string) apply_filters( 'ffhpp_form_markup', $markup, $adapter->id(), $placeholder['form_id'], $preview );

				$is_postback = null === $postback && $adapter->is_postback( $placeholder['form_id'] );
				$markup      = self::decorate( $markup, $adapter, $flags, ! $is_postback );
				if ( $is_postback ) {
					$postback = $markup;
				}
				$page_styles                  = $page_styles || 'page' === $flags['styles'];
				$rendered[ $adapter->id() ][] = $placeholder['form_id'];
				return $markup;
			},
			$html
		);

		// e.g. a Gravity Forms iframe AJAX submission: its script reads the
		// result from a standalone document, so send that instead of the page.
		if ( null !== $postback ) {
			return $postback;
		}

		if ( ! $rendered ) {
			return $html;
		}

		$footer = '';
		foreach ( $rendered as $id => $form_ids ) {
			$adapter = $this->adapter( $id );
			$adapter->after_render( array_unique( $form_ids ) );
		}

		$html = self::inject_assets( $html );
		if ( $page_styles ) {
			$html = self::insert_base_styles( $html );
		}

		foreach ( array_keys( $rendered ) as $id ) {
			$footer .= $this->adapter( $id )->footer_html();
		}
		return self::insert_before( $html, '</body>', $footer, true );
	}

	/**
	 * Run wp_enqueue_scripts once so form plugins register their assets,
	 * without keeping anything the theme or other plugins enqueued.
	 *
	 * Some form plugins (Contact Form 7, WPForms) only register scripts on
	 * that action, which never fires on published pages. Adapters enqueue
	 * their own handles afterwards.
	 */
	private function enqueue_pass() {
		if ( $this->enqueue_pass_done ) {
			return;
		}
		$this->enqueue_pass_done = true;

		$scripts = wp_scripts()->queue;
		$styles  = wp_styles()->queue;

		do_action( 'wp_enqueue_scripts' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		wp_scripts()->queue = $scripts;
		wp_styles()->queue  = $styles;
	}

	/**
	 * Parse placeholder attributes.
	 *
	 * @param string $raw Attribute string from the placeholder.
	 * @return array{title: bool, description: bool, styles: string, class: string, id: string, button_class: string}
	 */
	private static function parse_attributes( $raw ) {
		$attrs = array(
			'title'        => false,
			'description'  => false,
			'styles'       => 'plugin',
			'class'        => '',
			'id'           => '',
			'button_class' => '',
		);
		if ( ! preg_match_all( '/([a-z_-]+)=(?:"([^"]*)"|([^\s"]+))/i', (string) $raw, $pairs, PREG_SET_ORDER ) ) {
			return $attrs;
		}
		foreach ( $pairs as $pair ) {
			$key   = str_replace( '-', '_', strtolower( $pair[1] ) );
			$value = isset( $pair[3] ) && '' !== $pair[3] ? $pair[3] : $pair[2];
			switch ( $key ) {
				case 'title':
				case 'description':
					$attrs[ $key ] = 'true' === strtolower( $value );
					break;
				case 'styles':
					$attrs['styles'] = 'page' === strtolower( $value ) ? 'page' : 'plugin';
					break;
				case 'class':
				case 'button_class':
					$attrs[ $key ] = FFHPP_Parser::classes( $value );
					break;
				case 'id':
					$attrs['id'] = sanitize_html_class( $value );
					break;
			}
		}
		return $attrs;
	}

	/**
	 * Carry the landing page's class/id/button-class over to a rendered form.
	 *
	 * The markup is wrapped in a div (holding the id, since form plugins own
	 * their <form> ids) unless it is a standalone postback document.
	 *
	 * @param string        $markup  Rendered form.
	 * @param FFHPP_Adapter $adapter Adapter.
	 * @param array         $flags   Placeholder attributes.
	 * @param bool          $wrap    Whether to wrap the markup.
	 * @return string
	 */
	private static function decorate( $markup, FFHPP_Adapter $adapter, array $flags, $wrap ) {
		if ( '' !== $flags['class'] ) {
			$markup = self::add_class( $markup, '/<form\b[^>]*>/i', $flags['class'], 1 );
		}
		if ( '' !== $flags['button_class'] ) {
			$markup = self::add_class( $markup, '/<(?:button|input)\b[^>]*\btype=(["\'])submit\1[^>]*>/i', $flags['button_class'], -1 );
		}
		if ( ! $wrap ) {
			return $markup;
		}

		$classes = 'ffhpp-embed ffhpp-embed--' . $adapter->id() . ( 'page' === $flags['styles'] ? ' ffhpp-page-styles' : '' );
		$id      = '' !== $flags['id'] ? ' id="' . esc_attr( $flags['id'] ) . '"' : '';
		return '<div class="' . esc_attr( $classes ) . '"' . $id . '>' . $markup . '</div>';
	}

	/**
	 * Add classes to tags matching a pattern.
	 *
	 * @param string $markup  Markup.
	 * @param string $pattern Regex matching whole opening tags.
	 * @param string $classes Space-separated classes (already sanitized).
	 * @param int    $limit   Max tags to change (-1 for all).
	 * @return string
	 */
	private static function add_class( $markup, $pattern, $classes, $limit ) {
		$result = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $classes ) {
				$tag = $m[0];
				if ( preg_match( '/\sclass=(["\'])(.*?)\1/i', $tag ) ) {
					return preg_replace( '/\sclass=(["\'])(.*?)\1/i', ' class=$1$2 ' . $classes . '$1', $tag, 1 );
				}
				return preg_replace( '/^<([a-z]+)/i', '<$1 class="' . $classes . '"', $tag, 1 );
			},
			$markup,
			$limit
		);
		return null === $result ? $markup : $result;
	}

	/**
	 * Add the base stylesheet for styles=page forms at the top of <head>, so
	 * the landing page's own CSS (later in the document) wins any tie. Its
	 * selectors use :where() for zero specificity.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private static function insert_base_styles( $html ) {
		$css = file_get_contents( FFHPP_PLUGIN_DIR . 'assets/page-styles.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		if ( ! $css ) {
			return $html;
		}
		$style = "\n<style id=\"ffhpp-page-styles\">\n" . trim( $css ) . "\n</style>\n";
		if ( preg_match( '/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$at = $m[0][1] + strlen( $m[0][0] );
			return substr( $html, 0, $at ) . $style . substr( $html, $at );
		}
		return $style . $html;
	}

	/**
	 * Output for a placeholder whose form can't be shown.
	 *
	 * Draft previews (seen only by editors, and never cached) get a visible
	 * notice; published pages keep an HTML comment so visitors see nothing.
	 *
	 * @param array              $placeholder Parsed placeholder.
	 * @param FFHPP_Adapter|null $adapter     Adapter, if known.
	 * @param bool               $preview     Whether this is a draft preview.
	 * @return string
	 */
	private static function unavailable( array $placeholder, $adapter, $preview ) {
		if ( ! $adapter ) {
			/* translators: 1: placeholder plugin key, 2: form ID. */
			$message = sprintf( __( 'Unknown form plugin “%1$s” for form %2$d.', 'forms-for-html-page-publisher' ), $placeholder['adapter'], $placeholder['form_id'] );
		} elseif ( ! $adapter->is_available() ) {
			/* translators: 1: form plugin name, 2: form ID. */
			$message = sprintf( __( '%1$s is not active, so form %2$d could not be displayed.', 'forms-for-html-page-publisher' ), $adapter->label(), $placeholder['form_id'] );
		} else {
			/* translators: 1: form plugin name, 2: form ID. */
			$message = sprintf( __( '%1$s form %2$d could not be displayed. Check that it exists and is active.', 'forms-for-html-page-publisher' ), $adapter->label(), $placeholder['form_id'] );
		}

		if ( $preview ) {
			return '<div role="alert" style="padding:12px 16px;border:2px dashed #d63638;background:#fcf0f1;color:#8a2424;font:14px/1.4 system-ui,sans-serif">'
				. esc_html( $message ) . '</div>';
		}
		return '<!-- ffhpp: ' . esc_html( $message ) . ' -->';
	}

	/**
	 * Print the styles and scripts form plugins enqueued into the page.
	 *
	 * Pages bypass the theme, so wp_head()/wp_footer() never run; print only
	 * what is queued instead of the whole theme head. The queues are printed
	 * directly rather than via wp_print_styles()/wp_print_scripts(), whose
	 * actions would also pull in unrelated output (emoji styles, etc.).
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	private static function inject_assets( $html ) {
		ob_start();
		wp_styles()->do_items();
		$styles = ob_get_clean();

		ob_start();
		wp_scripts()->do_items(); // No group: prints footer scripts too.
		$scripts = ob_get_clean();

		$html = self::insert_before( $html, '</head>', $styles, false );
		return self::insert_before( $html, '</body>', $scripts, true );
	}

	/**
	 * Insert a fragment before the last occurrence of a closing tag.
	 *
	 * @param string $html     Document.
	 * @param string $tag      Closing tag, e.g. "</head>".
	 * @param string $fragment Markup to insert.
	 * @param bool   $append   Append to the end when the tag is missing (else prepend).
	 * @return string
	 */
	private static function insert_before( $html, $tag, $fragment, $append ) {
		if ( '' === trim( $fragment ) ) {
			return $html;
		}
		$pos = strripos( $html, $tag );
		if ( false === $pos ) {
			return $append ? $html . $fragment : $fragment . $html;
		}
		return substr( $html, 0, $pos ) . $fragment . "\n" . substr( $html, $pos );
	}
}
