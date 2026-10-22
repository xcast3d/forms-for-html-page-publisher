<?php
/**
 * Detects HTML <form> elements in a page and describes their fields.
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses raw page HTML for forms.
 *
 * Forms are located in the raw source with offsets so a conversion can
 * replace exactly one <form>…</form> and leave every other byte of the
 * document untouched. Each form's fragment is then parsed with DOMDocument
 * to read its controls.
 *
 * Fields are described with plugin-neutral kinds; adapters map them:
 * text, email, tel, url, number, date, hidden, textarea, select, radio,
 * checkbox, consent.
 */
class HTMLPP_Forms_Parser {

	/**
	 * A complete <form>…</form>. HTML forms cannot nest, so lazy matching
	 * to the first closing tag is correct.
	 */
	const FORM = '/<form\b[^>]*>.*?<\/form\s*>/is';

	/**
	 * HTML control types mapped to field kinds.
	 *
	 * @var array<string, string>
	 */
	private static $kinds = array(
		'text'     => 'text',
		'search'   => 'text',
		'password' => 'text',
		'email'    => 'email',
		'tel'      => 'tel',
		'url'      => 'url',
		'number'   => 'number',
		'range'    => 'number',
		'date'     => 'date',
		'hidden'   => 'hidden',
		'textarea' => 'textarea',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
	);

	/**
	 * Number of <form> elements in a document.
	 *
	 * @param string $html Page HTML.
	 * @return int
	 */
	public static function count_forms( $html ) {
		return (int) preg_match_all( self::FORM, (string) $html );
	}

	/**
	 * Describe every form in a document.
	 *
	 * @param string $html Page HTML.
	 * @return array[] Each: index, offset, raw, hash, action, method, class, id, attributes,
	 *                 intro, outro, external, button, button_class, fields.
	 */
	public static function find_forms( $html ) {
		if ( ! preg_match_all( self::FORM, (string) $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$forms = array();
		foreach ( $matches[0] as $i => $match ) {
			$form             = self::parse_form( $match[0] );
			$form['index']    = $i;
			$form['offset']   = $match[1];
			$form['raw']      = $match[0];
			$form['hash']     = md5( $match[0] );
			$form['external'] = self::external_host( $form['action'] );
			$forms[]          = $form;
		}
		return $forms;
	}

	/**
	 * Unique, form-plugin-safe field names (letters, digits, - and _),
	 * one per field, in field order.
	 *
	 * @param array $form Parsed form.
	 * @return string[]
	 */
	public static function field_names( array $form ) {
		$names = array();
		foreach ( $form['fields'] as $i => $field ) {
			$name = strtolower( preg_replace( '/[^A-Za-z0-9_-]+/', '-', $field['name'] ) );
			$name = trim( $name, '-_' );
			if ( '' === $name || ! preg_match( '/^[a-z]/', $name ) ) {
				$name = 'field-' . ( $i + 1 ) . ( '' !== $name ? '-' . $name : '' );
			}
			$base = $name;
			for ( $n = 2; in_array( $name, $names, true ); $n++ ) {
				$name = $base . '-' . $n;
			}
			$names[] = $name;
		}
		return $names;
	}

	/**
	 * Parse one <form> fragment.
	 *
	 * @param string $fragment Raw <form>…</form> HTML.
	 * @return array action, method, button, fields.
	 */
	private static function parse_form( $fragment ) {
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><!DOCTYPE html><html><body>' . $fragment . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $doc );
		$el    = $xpath->query( '//form' )->item( 0 );

		$result = array(
			'action'       => $el ? trim( $el->getAttribute( 'action' ) ) : '',
			'method'       => $el ? strtolower( $el->getAttribute( 'method' ) ? $el->getAttribute( 'method' ) : 'get' ) : 'get',
			'class'        => $el ? self::classes( $el->getAttribute( 'class' ) ) : '',
			'id'           => $el ? sanitize_html_class( $el->getAttribute( 'id' ) ) : '',
			'button'       => __( 'Submit', 'forms-for-html-page-publisher' ),
			'button_class' => '',
			'fields'       => array(),
		);
		if ( ! $el ) {
			return $result;
		}

		$button = $xpath->query( './/button[@type="submit" or not(@type)] | .//input[@type="submit"]', $el )->item( 0 );
		if ( $button ) {
			$text = 'input' === $button->nodeName ? $button->getAttribute( 'value' ) : self::text( $button->textContent );
			if ( '' !== $text ) {
				$result['button'] = $text;
			}
			$result['button_class'] = self::classes( $button->getAttribute( 'class' ) );
		}

		$groups = array(); // Radio/checkbox groups: "type:name" => index in fields.
		foreach ( $xpath->query( './/input | .//select | .//textarea', $el ) as $control ) {
			$tag  = $control->nodeName;
			$type = 'input' === $tag ? strtolower( $control->getAttribute( 'type' ) ? $control->getAttribute( 'type' ) : 'text' ) : $tag;
			if ( ! isset( self::$kinds[ $type ] ) ) {
				continue; // submit, button, reset, image, file, color…
			}

			$name     = preg_replace( '/\[\]$/', '', $control->getAttribute( 'name' ) );
			$required = $control->hasAttribute( 'required' ) || 'true' === $control->getAttribute( 'aria-required' );

			if ( 'radio' === $type || 'checkbox' === $type ) {
				$key = $type . ':' . ( '' !== $name ? $name : spl_object_hash( $control ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$legend             = $xpath->query( 'ancestor::fieldset[1]/legend', $control )->item( 0 );
					$groups[ $key ]     = count( $result['fields'] );
					$result['fields'][] = self::field( $type, $legend ? self::text( $legend->textContent ) : self::label( $control, $xpath ), $name, false );
				}
				$i     = $groups[ $key ];
				$text  = self::label( $control, $xpath );
				$value = $control->getAttribute( 'value' );
				$result['fields'][ $i ]['required']  = $result['fields'][ $i ]['required'] || $required;
				$result['fields'][ $i ]['choices'][] = array(
					'text'  => $text,
					'value' => '' !== $value && 'on' !== $value ? $value : $text,
				);
				continue;
			}

			$label = 'hidden' === $type ? $name : self::label( $control, $xpath );
			$field = self::field( $type, $label, $name, $required );

			$field['placeholder'] = $control->getAttribute( 'placeholder' );
			if ( 'textarea' === $type && $control->getAttribute( 'rows' ) ) {
				$field['rows'] = absint( $control->getAttribute( 'rows' ) );
			}
			if ( 'select' === $type ) {
				foreach ( $control->getElementsByTagName( 'option' ) as $option ) {
					$text = self::text( $option->textContent );
					if ( $option->hasAttribute( 'value' ) && '' === $option->getAttribute( 'value' ) ) {
						$field['placeholder'] = $text; // "Choose…" option.
						continue;
					}
					$field['choices'][] = array(
						'text'  => $text,
						'value' => $option->hasAttribute( 'value' ) ? $option->getAttribute( 'value' ) : $text,
					);
				}
			}
			$result['fields'][] = $field;
		}

		foreach ( $result['fields'] as &$field ) {
			self::maybe_consent( $field );
		}
		unset( $field );

		$result['attributes'] = self::kept_attributes( $el );
		list( $result['intro'], $result['outro'] ) = self::surrounding_content( $el, $doc );

		return $result;
	}

	/**
	 * The <form>'s attributes worth keeping on the element that replaces it
	 * (class, id, style, data-*, aria-*…), minus submission-specific ones.
	 *
	 * @param DOMElement $form Form element.
	 * @return array<string, string>
	 */
	private static function kept_attributes( DOMElement $form ) {
		$drop  = array( 'action', 'method', 'enctype', 'target', 'novalidate', 'autocomplete', 'accept-charset', 'name', 'onsubmit', 'onreset' );
		$attrs = array();
		foreach ( $form->attributes as $attr ) {
			$name = strtolower( $attr->nodeName );
			if ( ! in_array( $name, $drop, true ) && preg_match( '/^[a-z][a-z0-9_:.-]*$/', $name ) ) {
				$attrs[ $name ] = $attr->nodeValue;
			}
		}
		return $attrs;
	}

	/**
	 * Content inside the <form> before its first control and after its last
	 * (headings, intro text, small print), so a conversion can keep it.
	 *
	 * @param DOMElement  $form Form element.
	 * @param DOMDocument $doc  Owner document.
	 * @return string[] Intro HTML, outro HTML.
	 */
	private static function surrounding_content( DOMElement $form, DOMDocument $doc ) {
		$nodes = array();
		$first = null;
		$last  = null;
		// Labels and fieldsets belong to the fields, even as siblings of their controls.
		$field_tags = array( 'input', 'select', 'textarea', 'button', 'label', 'fieldset', 'legend' );
		foreach ( $form->childNodes as $i => $node ) {
			$nodes[ $i ] = $node;
			$is_field    = false;
			if ( $node instanceof DOMElement ) {
				foreach ( $field_tags as $tag ) {
					if ( $node->nodeName === $tag || $node->getElementsByTagName( $tag )->length ) {
						$is_field = true;
						break;
					}
				}
			}
			if ( $is_field ) {
				$first = null === $first ? $i : $first;
				$last  = $i;
			}
		}
		if ( null === $first ) {
			return array( '', '' );
		}

		$intro = '';
		$outro = '';
		foreach ( $nodes as $i => $node ) {
			if ( $i < $first ) {
				$intro .= $doc->saveHTML( $node );
			} elseif ( $i > $last ) {
				$outro .= $doc->saveHTML( $node );
			}
		}
		return array( trim( $intro ), trim( $outro ) );
	}

	/**
	 * Mark a lone "I agree…" checkbox as a consent field.
	 *
	 * @param array $field Detected field, modified in place.
	 */
	private static function maybe_consent( array &$field ) {
		if ( 'checkbox' !== $field['type'] || 1 !== count( $field['choices'] ) ) {
			return;
		}
		$text = $field['choices'][0]['text'];

		/**
		 * Filter the pattern that identifies a consent checkbox by its text.
		 *
		 * @param string $pattern Regular expression.
		 */
		$pattern = (string) apply_filters( 'htmlpp_forms_consent_pattern', '/\b(agree|consent|accept|privacy|terms|policy|opt[\s-]?in)\b/i' );
		if ( ! preg_match( $pattern, $text ) ) {
			return;
		}

		$field['type']           = 'consent';
		$field['checkbox_label'] = $text;
		$field['choices']        = array();
		if ( $field['label'] === $text ) {
			$field['label'] = __( 'Consent', 'forms-for-html-page-publisher' );
		}
	}

	/**
	 * A detected field.
	 *
	 * @param string $html_type HTML control type.
	 * @param string $label     Label.
	 * @param string $name      Control name.
	 * @param bool   $required  Required flag.
	 * @return array
	 */
	private static function field( $html_type, $label, $name, $required ) {
		return array(
			'html_type'      => $html_type,
			'type'           => self::$kinds[ $html_type ],
			'label'          => '' !== $label ? $label : __( 'Untitled', 'forms-for-html-page-publisher' ),
			'name'           => (string) $name,
			'required'       => (bool) $required,
			'placeholder'    => '',
			'choices'        => array(),
			'checkbox_label' => '',
			'rows'           => 0,
		);
	}

	/**
	 * Visible label for a control: <label for>, wrapping <label>, aria-label,
	 * placeholder, then name.
	 *
	 * @param DOMElement $control Control.
	 * @param DOMXPath   $xpath   XPath for the fragment.
	 * @return string
	 */
	private static function label( DOMElement $control, DOMXPath $xpath ) {
		$id = $control->getAttribute( 'id' );
		if ( '' !== $id && false === strpos( $id, '"' ) ) {
			$label = $xpath->query( '//label[@for="' . $id . '"]' )->item( 0 );
			if ( $label ) {
				return self::label_text( $label );
			}
		}
		for ( $node = $control->parentNode; $node instanceof DOMElement; $node = $node->parentNode ) {
			if ( 'label' === $node->nodeName ) {
				return self::label_text( $node );
			}
			if ( 'form' === $node->nodeName ) {
				break;
			}
		}
		foreach ( array( 'aria-label', 'placeholder', 'title', 'name' ) as $attr ) {
			if ( '' !== $control->getAttribute( $attr ) ) {
				return self::text( $control->getAttribute( $attr ) );
			}
		}
		return '';
	}

	/**
	 * Text of a <label>, ignoring nested controls (e.g. a select's options).
	 *
	 * @param DOMElement $label Label element.
	 * @return string
	 */
	private static function label_text( DOMElement $label ) {
		$clone = $label->cloneNode( true );
		foreach ( array( 'select', 'textarea', 'input' ) as $tag ) {
			$nested = $clone->getElementsByTagName( $tag );
			for ( $i = $nested->length - 1; $i >= 0; $i-- ) {
				$nested->item( $i )->parentNode->removeChild( $nested->item( $i ) );
			}
		}
		return rtrim( self::text( $clone->textContent ), " *:\u{00a0}" );
	}

	/**
	 * A sanitized, space-separated class list.
	 *
	 * @param string $raw class attribute.
	 * @return string
	 */
	public static function classes( $raw ) {
		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $raw ) ) );
		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * Collapse whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function text( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Host of a form action that posts off-site, or '' for same-site/relative.
	 *
	 * @param string $action Form action attribute.
	 * @return string
	 */
	private static function external_host( $action ) {
		$host = wp_parse_url( $action, PHP_URL_HOST );
		if ( ! $host ) {
			return '';
		}
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		return strtolower( $host ) === strtolower( (string) $site ) ? '' : strtolower( $host );
	}
}
