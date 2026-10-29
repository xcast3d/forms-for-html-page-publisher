<?php
/**
 * Converts a page's HTML form into a form plugin's form.
 *
 * @package FFHPP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the form and swaps the <form> for a placeholder.
 */
class FFHPP_Converter {

	/**
	 * Page meta key holding the number of unconverted <form> elements.
	 */
	const META_KEY = 'html_forms';

	/**
	 * Transient caching the number of flagged pages.
	 */
	const COUNT_CACHE = 'ffhpp_flagged_count';

	/**
	 * Convert one form on a page.
	 *
	 * The page is written through HTML Page Publisher's page service, which
	 * snapshots the previous version first, so a conversion can be rolled
	 * back from the page's version history.
	 *
	 * @param FFHPP_Adapter $adapter Target form plugin.
	 * @param string        $slug    Page slug.
	 * @param int           $index   Zero-based form index in the page.
	 * @param string        $hash    md5 of the form's raw HTML when it was reviewed.
	 * @return array|WP_Error { form_id, title } on success.
	 */
	public static function convert( FFHPP_Adapter $adapter, $slug, $index, $hash ) {
		$pages = htmlpp()->pages;
		$page  = $pages->get( $slug, true );
		if ( is_wp_error( $page ) ) {
			return $page;
		}
		$html = isset( $page['html'] ) ? (string) $page['html'] : '';

		$forms = FFHPP_Parser::find_forms( $html );
		if ( ! isset( $forms[ $index ] ) || ! hash_equals( $forms[ $index ]['hash'], (string) $hash ) ) {
			return new WP_Error( 'ffhpp_changed', __( 'The page changed since it was scanned. Review the form again before converting.', 'forms-converter-add-on-for-html-page-publisher' ) );
		}
		$form = $forms[ $index ];
		if ( ! $form['fields'] ) {
			return new WP_Error( 'ffhpp_empty', __( 'No supported fields were found in this form.', 'forms-converter-add-on-for-html-page-publisher' ) );
		}

		// Number forms by position in the page, counting forms already
		// converted to placeholders, so titles stay "form 1", "form 2"… however
		// many conversions it takes.
		preg_match_all( FFHPP_Plugin::PLACEHOLDER, $html, $converted, PREG_OFFSET_CAPTURE );
		$total  = count( $forms ) + count( $converted[0] );
		$number = $index + 1;
		foreach ( $converted[0] as $match ) {
			if ( $match[1] < $form['offset'] ) {
				++$number;
			}
		}

		$title = '' !== $page['title'] ? $page['title'] : $page['slug'];
		if ( $total > 1 ) {
			/* translators: 1: page title, 2: form number on the page. */
			$title = sprintf( __( '%1$s — form %2$d', 'forms-converter-add-on-for-html-page-publisher' ), $title, $number );
		}

		$form_id = $adapter->create_form( $form, $title );
		if ( is_wp_error( $form_id ) ) {
			return $form_id;
		}

		/**
		 * Filter how converted forms are styled: 'page' keeps the landing
		 * page's CSS in charge (the form plugin's theme is turned off and the
		 * original form/button classes are carried over); 'plugin' uses the
		 * form plugin's own theme.
		 *
		 * @param string        $styles  'page' or 'plugin'.
		 * @param FFHPP_Adapter $adapter Target form plugin.
		 * @param array         $form    Parsed form.
		 */
		$styles = apply_filters_deprecated( 'htmlpp_forms_default_styles', array( 'page', $adapter, $form ), '0.7.0', 'ffhpp_default_styles' );
		$styles = 'plugin' === apply_filters( 'ffhpp_default_styles', $styles, $adapter, $form ) ? 'plugin' : 'page';

		$placeholder = $adapter->placeholder(
			$form_id,
			array(
				'styles'       => $styles,
				'button-class' => $form['button_class'],
			)
		);
		$new_html    = substr_replace( $html, self::replacement( $form, $placeholder ), $form['offset'], strlen( $form['raw'] ) );

		$saved = $pages->update_html( $page['slug'], $new_html );
		if ( is_wp_error( $saved ) ) {
			$adapter->delete_form( $form_id ); // Don't leave an orphan form behind.
			return $saved;
		}

		/**
		 * Fires after a page's HTML form was converted.
		 *
		 * @param string $adapter Adapter ID (gravity, cf7, wpforms…).
		 * @param int    $form_id New form ID.
		 * @param string $slug    Page slug.
		 * @param array  $form    Parsed form (see FFHPP_Parser::find_forms()).
		 */
		do_action( 'ffhpp_form_converted', $adapter->id(), (int) $form_id, $page['slug'], $form );
		do_action_deprecated( 'htmlpp_forms_form_converted', array( $adapter->id(), (int) $form_id, $page['slug'], $form ), '0.7.0', 'ffhpp_form_converted' );

		return array(
			'form_id' => (int) $form_id,
			'title'   => $title,
		);
	}

	/**
	 * What replaces the <form>: a <div> with the form's class, id and other
	 * presentational attributes, keeping any content before the first field
	 * (a heading, intro text) and after the button (small print) around the
	 * placeholder. The page's CSS for `.class` / `#id` keeps applying to the
	 * panel, and descendant rules (`.class input`) reach the new form inside.
	 *
	 * @param array  $form        Parsed form.
	 * @param string $placeholder Placeholder comment.
	 * @return string
	 */
	private static function replacement( array $form, $placeholder ) {
		$attrs = '';
		foreach ( $form['attributes'] as $name => $value ) {
			$attrs .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$parts = array_filter( array( $form['intro'], $placeholder, $form['outro'] ), 'strlen' );
		return '<div' . $attrs . ">\n  " . implode( "\n  ", $parts ) . "\n</div>";
	}

	/**
	 * Record how many HTML forms a page still contains.
	 *
	 * @param string $slug Page slug.
	 * @param string $html Page HTML.
	 */
	public static function flag( $slug, $html ) {
		$pages = htmlpp()->pages;
		$page  = $pages->get( $slug );
		if ( is_wp_error( $page ) ) {
			return;
		}
		$count = FFHPP_Parser::count_forms( $html );
		if ( (int) ( isset( $page[ self::META_KEY ] ) ? $page[ self::META_KEY ] : 0 ) !== $count ) {
			$pages->set_meta( $slug, array( self::META_KEY => $count ) );
		}
	}

	/**
	 * Number of pages flagged as containing HTML forms.
	 *
	 * Cached, since listing pages reads every page from disk and the count is
	 * shown on every admin screen (menu badge). See forget_flagged_count().
	 *
	 * @return int
	 */
	public static function flagged_count() {
		$count = get_transient( self::COUNT_CACHE );
		if ( false !== $count ) {
			return (int) $count;
		}

		$count = 0;
		foreach ( htmlpp()->pages->list_pages() as $page ) {
			if ( ! empty( $page[ self::META_KEY ] ) ) {
				++$count;
			}
		}
		set_transient( self::COUNT_CACHE, $count, DAY_IN_SECONDS );
		return $count;
	}

	/**
	 * Drop the cached flagged-page count (page meta changed, or a page was
	 * deleted, renamed or copied).
	 */
	public static function forget_flagged_count() {
		delete_transient( self::COUNT_CACHE );
	}
}
