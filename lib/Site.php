<?php
/**
 * Class to handle all of the functions for the site frontend
 *
 * @author Ollie Armstrong
 * @package CST
 * @copyright All rights reserved 2012
 * @license GNU GPLv2
 */
class Cst_Site {
	public function __construct() {
		self::_addHooks();
	}

	private function _addHooks() {
		add_action('wp_loaded', array($this, 'startOb'));
		add_action('wp_footer', array($this, 'stopOb'), 1000);
	}

	public function startOb() {
		ob_start(array($this, 'changeBuffer'));
	}

	public function stopOb() {
		ob_end_flush();
		return true;
	}

	public function changeBuffer($buffer) {
		if (get_option('cst-css-combine') == 'yes') {
			$buffer = $this->combineFiles($buffer, 'css');
		}
		if (get_option('cst-js-combine') == 'yes') {
			$buffer = $this->combineFiles($buffer, 'js');
		}
		return $buffer;
	}

	private function getFileContents($url, $filetype) {
		$file = file_get_contents( $url );

		if ( $filetype == 'css' ) {
			// Replace relative urls with absolute urls to cdn
			$relativeURL = dirname( parse_url( $url, PHP_URL_PATH ) );
			$base_URL    = get_option( 'ossdlcdn' ) == 1 ? get_option('ossdl_off_cdn_url') : site_url();
			$absoluteURL = $base_URL . $relativeURL;
			$file        = preg_replace( '~url\([\'" ]*(?!https?|data)([^\)\'\"]+)[\'" ]*\)~', 'url("' . trailingslashit($absoluteURL) . '$1")', $file);
		}

		return $file;
	}

	public function combineFiles($buffer, $filetype) {
		require_once CST_DIR.'lib/Cst.php';
		$core = new Cst;

		$stylesheetCombined = '';
		$stylesheets = array();
		$exclude = get_option('cst-'.$filetype.'-exclude');

		libxml_use_internal_errors( true ); // Hide HTML DOM errors
		$dom = new DomDocument( '1.0', 'utf-8' );

		// Todo allow XML to be processed
		$isHTML = strpos( $buffer, '<html');
		if ( !$isHTML ) return $buffer;

		if ( !empty( $buffer ) && $dom->loadHTML( mb_convert_encoding( (string) $buffer, 'HTML-ENTITIES', 'UTF-8' ) ) ) {
			$tag       = $filetype == 'css' ? 'link[@rel="stylesheet"]' : 'script[@src]';
			$attribute = $filetype == 'css' ? 'href' : 'src';
			$xp        = new DOMXPath( $dom );
			$nodes     = $xp->query( '//' . $tag );

			foreach ( $nodes as $key => $node ) {
				$stylesheet = $node->attributes->getNamedItem( $attribute )->nodeValue;

				if ( strpos( $exclude, str_replace( home_url() . '/', '', $stylesheet ) ) !== false ) {
					// File is excluded so skip
					continue;
				}

				// This test is a little flakey
				// TODO: Make this protocol agnostic
				$is_external = strpos( $stylesheet, site_url() ) === false;

				if ( $is_external && get_option( "cst-$filetype-exclude-external" ) == 'yes' )
					continue;

				// Remove from the DOM
				$parent = $node->parentNode->removeChild( $node );

				// Add to combined file
				$stylesheetCombined .= PHP_EOL . self::getFileContents( $stylesheet, $filetype );
			}
		}

		// Create unique filename based on the md5 of the content
		$hash = md5($stylesheetCombined);
		$combinedFilename = ABSPATH.get_option('cst-'.$filetype.'-savepath').'/'.$hash.'.'.$filetype;

		if (!is_readable($combinedFilename)) {
			if ($filetype == 'js' && get_option('cst-js-minify') == 'yes') {
				// Do minification
				switch (get_option('cst-js-optlevel')) {
					case 'simple':
						$complevel = 'SIMPLE_OPTIMIZATIONS';
					case 'advanced':
						$complevel = 'ADVANCED_OPTIMIZATIONS';
					default:
						$complevel = 'WHITESPACE_ONLY';
				}
				$ch = curl_init('http://closure-compiler.appspot.com/compile');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, 'output_info=compiled_code&output_format=text&compilation_level='.$complevel.'&js_code='.urlencode($stylesheetCombined));
				$output = curl_exec($ch);
				$stylesheetCombined = $output;
			}

			// Compress CSS
			if ( $filetype == 'css' ) {
				$patterns = array(
					'-\s{2,}-' => ' ', // strip double spaces
					'-[\n\r\t]-' => '', // strip newlines
					'-\s*(,|:|;|\{|\})\s*-' => '$1', //strip unnecessary spaces after ,;:{}
					'-\s*(?!<\")\/\*[^\*]+\*\/(?!\")\s*-' => '' // strip comments
				);

				$stylesheetCombined = preg_replace( array_keys( $patterns ), array_values( $patterns ), $stylesheetCombined );
			}

			// File needs saving and syncing
			file_put_contents($combinedFilename, $stylesheetCombined);
			$core->createConnection();
			$core->pushFile($combinedFilename, get_option('cst-'.$filetype.'-savepath').'/'.$hash.'.'.$filetype);
		}

		// File can be loaded
		$base_URL = get_option( 'ossdlcdn' ) == 1 ? get_option('ossdl_off_cdn_url') : site_url();
		$fileUrl  = sprintf( '%s/%s/%s.%s', $base_URL, get_option( "cst-$filetype-savepath" ), $hash, $filetype );

		// Build the new element for the combined file
		if ( $filetype == 'css' ) {
			$newNode         = $dom->createElement( 'link' );
			$linkRel         = $dom->createAttribute( 'rel' );
			$linkType        = $dom->createAttribute( 'type' );
			$linkHref        = $dom->createAttribute( 'href' );
			$linkRel->value  = 'stylesheet';
			$linkType->value = 'text/css';
			$linkHref->value = $fileUrl;
			$newNode->appendChild( $linkRel );
			$newNode->appendChild( $linkType );
			$newNode->appendChild( $linkHref );
		} else {
			$newNode           = $dom->createElement( 'script' );
			$scriptType        = $dom->createAttribute( 'type' );
			$scriptSrc         = $dom->createAttribute( 'src' );

			$scriptType->value = 'text/javascript';
			$scriptSrc->value  = $fileUrl;
			$newNode->appendChild( $scriptType );
			$newNode->appendChild( $scriptSrc );
		}

		if ( $filetype == 'css' ) {
			// Stylesheets are always appended at the end of the <head>
			$target = $dom->getElementsByTagName( 'head' )->item(0);
			$target->insertBefore( $newNode, $target->firstChild );
		} else {
			if ( get_option( 'cst-js-placement' ) == 'body' ) {
				$target = $dom->getElementsByTagName( 'body' )->item(0);
			} else {
				$target = $dom->getElementsByTagName( 'head' )->item(0);
			}

			$target->appendChild( $newNode );
		}

		// Return the modified DOM
		return $dom->saveHTML();
	}
}
