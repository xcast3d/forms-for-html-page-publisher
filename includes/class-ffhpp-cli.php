<?php
/**
 * WP-CLI: wp htmlpp forms <command>
 *
 * @package FFHPP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect, convert and audit forms in HTML Page Publisher pages.
 */
class FFHPP_CLI extends WP_CLI_Command {

	/**
	 * Integration instance.
	 *
	 * @return FFHPP_Plugin
	 */
	private function forms() {
		return FFHPP_Plugin::instance();
	}

	/**
	 * Exit with a WP_Error's message.
	 *
	 * @param mixed $result Result to check.
	 * @return mixed The result when it is not an error.
	 */
	private function check( $result ) {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Pages to scan: one slug, or all pages.
	 *
	 * @param array $args Positional args.
	 * @return array[] Page descriptions with 'html'.
	 */
	private function pages( $args ) {
		$service = htmlpp()->pages;
		if ( ! empty( $args[0] ) ) {
			return array( $this->check( $service->get( $args[0], true ) ) );
		}
		$pages = array();
		foreach ( $service->list_pages() as $page ) {
			$page = $service->get( $page['slug'], true );
			if ( ! is_wp_error( $page ) ) {
				$pages[] = $page;
			}
		}
		return $pages;
	}

	/**
	 * Output rows with --format/--fields, like `wp htmlpp list`.
	 *
	 * @param array    $assoc_args Flags.
	 * @param array[]  $rows       Rows.
	 * @param string[] $fields     Default fields.
	 */
	private function output( $assoc_args, array $rows, array $fields ) {
		if ( isset( $assoc_args['fields'] ) ) {
			$fields = explode( ',', $assoc_args['fields'] );
		}
		WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, $fields );
	}

	/**
	 * List supported form plugins and whether each is active.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp forms plugins
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function plugins( $args, $assoc_args ) {
		$rows = array();
		foreach ( array( 'gravity', 'cf7', 'wpforms' ) as $id ) {
			$adapter = $this->forms()->adapter( $id );
			if ( $adapter ) {
				$rows[] = array(
					'plugin'      => $adapter->id(),
					'name'        => $adapter->label(),
					'active'      => $adapter->is_available() ? 'yes' : 'no',
					'placeholder' => '<!-- form:' . $adapter->id() . ':ID -->',
				);
			}
		}
		$this->output( $assoc_args, $rows, array( 'plugin', 'name', 'active', 'placeholder' ) );
	}

	/**
	 * List raw HTML forms in pages (forms not connected to a form plugin).
	 *
	 * ## OPTIONS
	 *
	 * [<slug>]
	 * : Only this page. Default: all pages.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count. Default: table.
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields. Default: slug,form,fields,button,posts_to.
	 * Also available: hash, class, id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp forms detect
	 *     wp htmlpp forms detect spring-promo --format=json
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function detect( $args, $assoc_args ) {
		$rows = array();
		foreach ( $this->pages( $args ) as $page ) {
			foreach ( FFHPP_Parser::find_forms( $page['html'] ) as $form ) {
				$rows[] = array(
					'slug'     => $page['slug'],
					'form'     => $form['index'] + 1,
					'fields'   => count( $form['fields'] ),
					'button'   => $form['button'],
					'posts_to' => self::posts_to( $form ),
					'hash'     => $form['hash'],
					'class'    => $form['class'],
					'id'       => $form['id'],
				);
			}
		}
		if ( ! $rows && ( ! isset( $assoc_args['format'] ) || 'table' === $assoc_args['format'] ) ) {
			WP_CLI::success( 'No HTML forms found.' );
			return;
		}
		$this->output( $assoc_args, $rows, array( 'slug', 'form', 'fields', 'button', 'posts_to' ) );
	}

	/**
	 * Convert a page's HTML form(s) into a form plugin's form.
	 *
	 * Creates the form, then replaces the <form> in the page with its
	 * placeholder. The previous version of the page is kept in its history
	 * (`wp htmlpp versions <slug>`).
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Page slug.
	 *
	 * [--to=<plugin>]
	 * : gravity, cf7 or wpforms. Required when more than one is active.
	 *
	 * [--form=<number>]
	 * : Which form on the page (1 = first). Required when the page has more than one, unless --all.
	 *
	 * [--all]
	 * : Convert every form on the page.
	 *
	 * [--styles=<styles>]
	 * : page (the landing page's CSS styles the form) or plugin (the form plugin's theme).
	 * ---
	 * default: page
	 * options:
	 *   - page
	 *   - plugin
	 * ---
	 *
	 * [--dry-run]
	 * : Show the fields that would be created, without changing anything.
	 *
	 * [--porcelain]
	 * : Print only the new form IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp forms convert spring-promo --to=gravity --dry-run
	 *     wp htmlpp forms convert spring-promo --to=cf7 --form=2 --user=admin
	 *     wp htmlpp forms convert spring-promo --to=wpforms --all --user=admin
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function convert( $args, $assoc_args ) {
		$slug    = $args[0];
		$adapter = $this->target( $assoc_args );
		$all     = ! empty( $assoc_args['all'] );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$styles  = isset( $assoc_args['styles'] ) && 'plugin' === $assoc_args['styles'] ? 'plugin' : 'page';

		$page  = $this->check( htmlpp()->pages->get( $slug, true ) );
		$forms = FFHPP_Parser::find_forms( $page['html'] );
		if ( ! $forms ) {
			WP_CLI::error( "No HTML forms found in {$slug}." );
		}

		if ( $all ) {
			$indexes = array_keys( $forms );
		} elseif ( isset( $assoc_args['form'] ) ) {
			$n = (int) $assoc_args['form'];
			if ( ! isset( $forms[ $n - 1 ] ) ) {
				WP_CLI::error( sprintf( '%s has %d form(s); --form=%s is out of range.', $slug, count( $forms ), $assoc_args['form'] ) );
			}
			$indexes = array( $n - 1 );
		} elseif ( 1 === count( $forms ) ) {
			$indexes = array( 0 );
		} else {
			WP_CLI::error( sprintf( '%s has %d forms. Pass --form=<number> or --all (see `wp htmlpp forms detect %s`).', $slug, count( $forms ), $slug ) );
		}

		if ( $dry_run ) {
			foreach ( $indexes as $i ) {
				$this->preview( $forms[ $i ], count( $forms ), $adapter, $styles );
			}
			return;
		}

		if ( ! HTMLPP_Plugin::can_write_markup() || ! $adapter->can_create() ) {
			WP_CLI::error( sprintf( 'The current user cannot edit page HTML and create %s forms. Run the command as an administrator, e.g. --user=<login>.', $adapter->label() ) );
		}

		$set_styles = static function () use ( $styles ) {
			return $styles;
		};
		add_filter( 'ffhpp_default_styles', $set_styles, 99 );

		// Each conversion shifts the remaining forms, so convert from the last
		// to the first and re-read the page every time.
		$created = array();
		foreach ( array_reverse( $indexes ) as $i ) {
			$fresh = FFHPP_Parser::find_forms( $this->check( htmlpp()->pages->get( $page['slug'], true ) )['html'] );
			if ( empty( $fresh[ $i ]['fields'] ) ) {
				WP_CLI::warning( sprintf( 'Form %d has no supported fields; skipped.', $i + 1 ) );
				continue;
			}
			$result    = $this->check( FFHPP_Converter::convert( $adapter, $page['slug'], $i, $fresh[ $i ]['hash'] ) );
			$created[] = $result['form_id'];
			if ( empty( $assoc_args['porcelain'] ) ) {
				WP_CLI::success( sprintf( 'Form %d → %s form #%d “%s” (%s)', $i + 1, $adapter->label(), $result['form_id'], $result['title'], $adapter->edit_url( $result['form_id'] ) ) );
			}
		}

		remove_filter( 'ffhpp_default_styles', $set_styles, 99 );

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( implode( ' ', array_reverse( $created ) ) );
		} elseif ( $created ) {
			WP_CLI::log( 'Previous version kept in the page history: wp htmlpp versions ' . $page['slug'] );
		}
	}

	/**
	 * List form placeholders in pages and check that their forms exist.
	 *
	 * ## OPTIONS
	 *
	 * [<slug>]
	 * : Only this page. Default: all pages.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count. Default: table.
	 *
	 * [--problems]
	 * : Only list placeholders whose form can't be shown.
	 *
	 * ## EXAMPLES
	 *
	 *     wp htmlpp forms placeholders
	 *     wp htmlpp forms placeholders --problems
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function placeholders( $args, $assoc_args ) {
		$rows     = array();
		$problems = 0;
		foreach ( $this->pages( $args ) as $page ) {
			foreach ( FFHPP_Plugin::placeholders( $page['html'] ) as $placeholder ) {
				$adapter = $this->forms()->adapter( $placeholder['adapter'] );
				if ( ! $adapter ) {
					$status = 'unknown plugin';
				} elseif ( ! $adapter->is_available() ) {
					$status = 'plugin inactive';
				} elseif ( ! $adapter->form_exists( $placeholder['form_id'] ) ) {
					$status = 'form missing';
				} else {
					$status = 'ok';
				}
				if ( 'ok' !== $status ) {
					++$problems;
				} elseif ( ! empty( $assoc_args['problems'] ) ) {
					continue;
				}
				$rows[] = array(
					'slug'    => $page['slug'],
					'plugin'  => $placeholder['adapter'],
					'form_id' => $placeholder['form_id'],
					'styles'  => $placeholder['flags']['styles'],
					'status'  => $status,
				);
			}
		}

		$this->output( $assoc_args, $rows, array( 'slug', 'plugin', 'form_id', 'styles', 'status' ) );
		if ( $problems && ( ! isset( $assoc_args['format'] ) || 'table' === $assoc_args['format'] ) ) {
			WP_CLI::warning( sprintf( '%d placeholder(s) cannot be shown. Published pages hide them; draft previews show a warning.', $problems ) );
		}
	}

	/**
	 * Target adapter from --to, or the only active one.
	 *
	 * @param array $assoc_args Flags.
	 * @return FFHPP_Adapter
	 */
	private function target( $assoc_args ) {
		$available = $this->forms()->available_adapters();
		if ( ! $available ) {
			WP_CLI::error( 'Activate Gravity Forms, Contact Form 7 or WPForms first.' );
		}
		if ( empty( $assoc_args['to'] ) ) {
			if ( 1 === count( $available ) ) {
				return reset( $available );
			}
			WP_CLI::error( 'Pass --to=<plugin>: ' . implode( ', ', array_keys( $available ) ) . '.' );
		}
		$id = strtolower( $assoc_args['to'] );
		if ( ! isset( $available[ $id ] ) ) {
			$adapter = $this->forms()->adapter( $id );
			WP_CLI::error( $adapter ? "{$adapter->label()} is not active." : "Unknown form plugin '{$id}'. Use one of: " . implode( ', ', array_keys( $available ) ) . '.' );
		}
		return $available[ $id ];
	}

	/**
	 * Print what converting a form would create.
	 *
	 * @param array         $form    Parsed form.
	 * @param int           $total   Forms on the page.
	 * @param FFHPP_Adapter $adapter Target plugin.
	 * @param string        $styles  page or plugin.
	 */
	private function preview( array $form, $total, FFHPP_Adapter $adapter, $styles ) {
		WP_CLI::log( sprintf( 'Form %d of %d → %s · button “%s” · posts to: %s', $form['index'] + 1, $total, $adapter->label(), $form['button'], self::posts_to( $form ) ) );

		$rows = array();
		foreach ( $form['fields'] as $field ) {
			$rows[] = array(
				'label'    => $field['label'],
				'type'     => $field['type'],
				'required' => $field['required'] ? 'yes' : '',
				'name'     => $field['name'],
				'choices'  => 'consent' === $field['type'] ? $field['checkbox_label'] : implode( ', ', wp_list_pluck( $field['choices'], 'text' ) ),
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array( 'label', 'type', 'required', 'name', 'choices' ) );
		} else {
			WP_CLI::warning( 'No supported fields; this form would be skipped.' );
		}

		foreach ( $adapter->conversion_notes( $form ) as $note ) {
			WP_CLI::log( 'Note: ' . $note );
		}
		$placeholder = $adapter->placeholder(
			0,
			array(
				'styles'       => $styles,
				'button-class' => $form['button_class'],
			)
		);
		WP_CLI::log( 'Placeholder: ' . str_replace( ':' . $adapter->id() . ':0', ':' . $adapter->id() . ':ID', $placeholder ) );
		if ( $form['external'] ) {
			WP_CLI::warning( "This form posts to {$form['external']}; converting stops that service receiving submissions from this page." );
		}
		WP_CLI::log( '' );
	}

	/**
	 * Where a form currently sends its data.
	 *
	 * @param array $form Parsed form.
	 * @return string
	 */
	private static function posts_to( array $form ) {
		if ( $form['external'] ) {
			return $form['external'] . ' (external)';
		}
		return '' !== $form['action'] && '#' !== $form['action'] ? $form['action'] : 'nowhere';
	}
}
