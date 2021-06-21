<?php

namespace WP_CLI\I18n;

use Gettext\Extractors\Extractor;
use Gettext\Extractors\ExtractorInterface;
use Gettext\Translations;
use WP_CLI;

final class ThemeJsonExtractor extends Extractor implements ExtractorInterface {
	use IterableCodeExtractor;

	/**
	 * @inheritdoc
	 */
	public static function fromString( $string, Translations $translations, array $options = [] ) {
		$file = $options['file'];
		WP_CLI::debug( "Parsing file {$file}", 'make-pot' );

		$theme_json = json_decode( $string, true );

		if ( null === $theme_json ) {
			WP_CLI::debug(
				sprintf(
					'Could not parse file %1$s: error code %2$s',
					$file,
					json_last_error()
				),
				'make-pot'
			);

			return;
		}

		$fields = self::get_fields_to_translate();
		foreach ( $fields as $field ) {
			$path    = $field['path'];
			$key     = $field['key'];
			$context = $field['context'];

			/*
			 * We need to process the paths that include '*' separately.
			 * One example of such a path would be:
			 * [ 'settings', 'blocks', '*', 'color', 'palette' ]
			 */
			$nodes_to_iterate = array_keys( $path, '*', true );
			if ( ! empty( $nodes_to_iterate ) ) {
				/*
				 * At the moment, we only need to support one '*' in the path, so take it directly.
				 * - base will be [ 'settings', 'blocks' ]
				 * - data will be [ 'color', 'palette' ]
				 */
				$base_path = array_slice( $path, 0, $nodes_to_iterate[0] );
				$data_path = array_slice( $path, $nodes_to_iterate[0] + 1 );
				$base_tree = self::_wp_array_get( $theme_json, $base_path, array() );
				foreach ( $base_tree as $node_name => $node_data ) {
					$array_to_translate = self::_wp_array_get( $node_data, $data_path, null );
					if ( is_null( $array_to_translate ) ) {
						continue;
					}

					// Whole path will be [ 'settings', 'blocks', 'core/paragraph', 'color', 'palette' ].
					$whole_path  = array_merge( $base_path, array( $node_name ), $data_path );
					$translation = $translations->insert( $context, $original );
					$translation->addReference( $file );
				}
			} else {
				$array_to_translate = self::_wp_array_get( $theme_json, $path, null );
				if ( is_null( $array_to_translate ) ) {
					continue;
				}

				$translation = $translations->insert( $context, $original );
				$translation->addReference( $file );
			}
		}
	}

	private static function read_json_file( $file_path ) {
		$config = array();
		if ( $file_path ) {
			$decoded_file = json_decode(
				file_get_contents( $file_path ),
				true
			);

			$json_decoding_error = json_last_error();
			if ( JSON_ERROR_NONE !== $json_decoding_error ) {
				WP_CLI::debug( "Error when decoding {$file_path}", 'make-pot' );

				return $config;
			}

			if ( is_array( $decoded_file ) ) {
				$config = $decoded_file;
			}
		}
		return $config;
	}

	private static function get_fields_to_translate() {
		if ( null === self::$theme_json_i18n ) {
			$file_structure        = self::read_json_file( __DIR__ . '/theme-i18n.json' );
			self::$theme_json_i18n = self::extract_paths_to_translate( $file_structure );
		}
		return self::$theme_json_i18n;
	}

	private static function extract_paths_to_translate( $i18n_partial, $current_path = array() ) {
		$result = array();
		foreach ( $i18n_partial as $property => $partial_child ) {
			if ( is_numeric( $property ) ) {
				foreach ( $partial_child as $key => $context ) {
					return array(
						array(
							'path'    => $current_path,
							'key'     => $key,
							'context' => $context,
						),
					);
				}
			}
			$result = array_merge(
				$result,
				self::extract_paths_to_translate( $partial_child, array_merge( $current_path, array( $property ) ) )
			);
		}
		return $result;
	}

	/**
	 * Accesses an array in depth based on a path of keys.
	 *
	 * It is the PHP equivalent of JavaScript's `lodash.get()` and mirroring it may help other components
	 * retain some symmetry between client and server implementations.
	 *
	 * Example usage:
	 *
	 *     $array = array(
	 *         'a' => array(
	 *             'b' => array(
	 *                 'c' => 1,
	 *             ),
	 *         ),
	 *     );
	 *     _wp_array_get( $array, array( 'a', 'b', 'c' ) );
	 *
	 * @param array $array   An array from which we want to retrieve some information.
	 * @param array $path    An array of keys describing the path with which to retrieve information.
	 * @param mixed $default The return value if the path does not exist within the array,
	 *                       or if `$array` or `$path` are not arrays.
	 *
	 * @return mixed The value from the path specified.
	 */
	private function _wp_array_get( $array, $path, $default = null ) {
		// Confirm $path is valid.
		if ( ! is_array( $path ) || 0 === count( $path ) ) {
			return $default;
		}

		foreach ( $path as $path_element ) {
			if (
				! is_array( $array ) ||
				( ! is_string( $path_element ) && ! is_integer( $path_element ) && ! is_null( $path_element ) ) ||
				! array_key_exists( $path_element, $array )
			) {
				return $default;
			}
			$array = $array[ $path_element ];
		}

		return $array;
	}

}
