<?php
/**
 * APIs to interact with global settings & styles.
 *
 * @package WordPress
 */

/**
 * Gets the settings resulting of merging core, theme, and user data.
 *
 * @since 5.9.0
 *
 * @param array $path    Path to the specific setting to retrieve. Optional.
 *                       If empty, will return all settings.
 * @param array $context {
 *     Metadata to know where to retrieve the $path from. Optional.
 *
 *     @type string $block_name Which block to retrieve the settings from.
 *                              If empty, it'll return the settings for the global context.
 *     @type string $origin     Which origin to take data from.
 *                              Valid values are 'all' (core, theme, and user) or 'base' (core and theme).
 *                              If empty or unknown, 'all' is used.
 * }
 * @return array The settings to retrieve.
 */
function wp_get_global_settings( $path = array(), $context = array() ) {
	if ( ! empty( $context['block_name'] ) ) {
		$path = array_merge( array( 'blocks', $context['block_name'] ), $path );
	}

	$origin = 'custom';
	if ( isset( $context['origin'] ) && 'base' === $context['origin'] ) {
		$origin = 'theme';
	}

	$settings = WP_Theme_JSON_Resolver::get_merged_data( $origin )->get_settings();

	return _wp_array_get( $settings, $path, $settings );
}

/**
 * Gets the styles resulting of merging core, theme, and user data.
 *
 * @since 5.9.0
 *
 * @param array $path    Path to the specific style to retrieve. Optional.
 *                       If empty, will return all styles.
 * @param array $context {
 *     Metadata to know where to retrieve the $path from. Optional.
 *
 *     @type string $block_name Which block to retrieve the styles from.
 *                              If empty, it'll return the styles for the global context.
 *     @type string $origin     Which origin to take data from.
 *                              Valid values are 'all' (core, theme, and user) or 'base' (core and theme).
 *                              If empty or unknown, 'all' is used.
 * }
 * @return array The styles to retrieve.
 */
function wp_get_global_styles( $path = array(), $context = array() ) {
	if ( ! empty( $context['block_name'] ) ) {
		$path = array_merge( array( 'blocks', $context['block_name'] ), $path );
	}

	$origin = 'custom';
	if ( isset( $context['origin'] ) && 'base' === $context['origin'] ) {
		$origin = 'theme';
	}

	$styles = WP_Theme_JSON_Resolver::get_merged_data( $origin )->get_raw_data()['styles'];

	return _wp_array_get( $styles, $path, $styles );
}

/**
 * Returns the stylesheet resulting of merging core, theme, and user data.
 *
 * @since 5.9.0
 *
 * @param array $types Types of styles to load. Optional.
 *                     It accepts 'variables', 'styles', 'presets' as values.
 *                     If empty, it'll load all for themes with theme.json support
 *                     and only [ 'variables', 'presets' ] for themes without theme.json support.
 * @return string Stylesheet.
 */
function wp_get_global_stylesheet( $types = array() ) {
	// Return cached value if it can be used and exists.
	// It's cached by theme to make sure that theme switching clears the cache.
	$can_use_cached = (
		( empty( $types ) ) &&
		( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) &&
		( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) &&
		( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) &&
		! is_admin()
	);
	$transient_name = 'global_styles_' . get_stylesheet();
	if ( $can_use_cached ) {
		$cached = get_transient( $transient_name );
		if ( $cached ) {
			return $cached;
		}
	}

	$tree = WP_Theme_JSON_Resolver::get_merged_data();

	$supports_theme_json = wp_theme_has_theme_json();
	if ( empty( $types ) && ! $supports_theme_json ) {
		$types = array( 'variables', 'presets', 'base-layout-styles' );
	} elseif ( empty( $types ) ) {
		$types = array( 'variables', 'styles', 'presets' );
	}

	/*
	 * If variables are part of the stylesheet, then add them.
	 * This is so themes without a theme.json still work as before 5.9:
	 * they can override the default presets.
	 * See https://core.trac.wordpress.org/ticket/54782
	 */
	$styles_variables = '';
	if ( in_array( 'variables', $types, true ) ) {
		/*
		 * Only use the default, theme, and custom origins. Why?
		 * Because styles for `blocks` origin are added at a later phase
		 * (i.e. in the render cycle). Here, only the ones in use are rendered.
		 * @see wp_add_global_styles_for_blocks
		 */
		$origins          = array( 'default', 'theme', 'custom' );
		$styles_variables = $tree->get_stylesheet( array( 'variables' ), $origins );
		$types            = array_diff( $types, array( 'variables' ) );
	}

	/*
	 * For the remaining types (presets, styles), we do consider origins:
	 *
	 * - themes without theme.json: only the classes for the presets defined by core
	 * - themes with theme.json: the presets and styles classes, both from core and the theme
	 */
	$styles_rest = '';
	if ( ! empty( $types ) ) {
		/*
		 * Only use the default, theme, and custom origins. Why?
		 * Because styles for `blocks` origin are added at a later phase
		 * (i.e. in the render cycle). Here, only the ones in use are rendered.
		 * @see wp_add_global_styles_for_blocks
		 */
		$origins = array( 'default', 'theme', 'custom' );
		if ( ! $supports_theme_json ) {
			$origins = array( 'default' );
		}
		$styles_rest = $tree->get_stylesheet( $types, $origins );
	}

	$stylesheet = $styles_variables . $styles_rest;

	if ( $can_use_cached ) {
		// Cache for a minute.
		// This cache doesn't need to be any longer, we only want to avoid spikes on high-traffic sites.
		set_transient( $transient_name, $stylesheet, MINUTE_IN_SECONDS );
	}

	return $stylesheet;
}

/**
 * Returns a string containing the SVGs to be referenced as filters (duotone).
 *
 * @since 5.9.1
 *
 * @return string
 */
function wp_get_global_styles_svg_filters() {
	// Return cached value if it can be used and exists.
	// It's cached by theme to make sure that theme switching clears the cache.
	$can_use_cached = (
		( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) &&
		( ! defined( 'SCRIPT_DEBUG' ) || ! SCRIPT_DEBUG ) &&
		( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) &&
		! is_admin()
	);
	$transient_name = 'global_styles_svg_filters_' . get_stylesheet();
	if ( $can_use_cached ) {
		$cached = get_transient( $transient_name );
		if ( $cached ) {
			return $cached;
		}
	}

	$supports_theme_json = wp_theme_has_theme_json();

	$origins = array( 'default', 'theme', 'custom' );
	if ( ! $supports_theme_json ) {
		$origins = array( 'default' );
	}

	$tree = WP_Theme_JSON_Resolver::get_merged_data();
	$svgs = $tree->get_svg_filters( $origins );

	if ( $can_use_cached ) {
		// Cache for a minute, same as wp_get_global_stylesheet.
		set_transient( $transient_name, $svgs, MINUTE_IN_SECONDS );
	}

	return $svgs;
}

/**
 * Adds global style rules to the inline style for each block.
 *
 * @since 6.1.0
 */
function wp_add_global_styles_for_blocks() {
	$tree        = WP_Theme_JSON_Resolver::get_merged_data();
	$block_nodes = $tree->get_styles_block_nodes();
	foreach ( $block_nodes as $metadata ) {
		$block_css = $tree->get_styles_for_block( $metadata );

		if ( ! wp_should_load_separate_core_block_assets() ) {
			wp_add_inline_style( 'global-styles', $block_css );
			continue;
		}

		$stylesheet_handle = 'global-styles';
		if ( isset( $metadata['name'] ) ) {
			/*
			 * These block styles are added on block_render.
			 * This hooks inline CSS to them so that they are loaded conditionally
			 * based on whether or not the block is used on the page.
			 */
			if ( str_starts_with( $metadata['name'], 'core/' ) ) {
				$block_name        = str_replace( 'core/', '', $metadata['name'] );
				$stylesheet_handle = 'wp-block-' . $block_name;
			}
			wp_add_inline_style( $stylesheet_handle, $block_css );
		}

		// The likes of block element styles from theme.json do not have  $metadata['name'] set.
		if ( ! isset( $metadata['name'] ) && ! empty( $metadata['path'] ) ) {
			$result = array_values(
				array_filter(
					$metadata['path'],
					function ( $item ) {
						if ( strpos( $item, 'core/' ) !== false ) {
							return true;
						}
						return false;
					}
				)
			);
			if ( isset( $result[0] ) ) {
				if ( str_starts_with( $result[0], 'core/' ) ) {
					$block_name        = str_replace( 'core/', '', $result[0] );
					$stylesheet_handle = 'wp-block-' . $block_name;
				}
				wp_add_inline_style( $stylesheet_handle, $block_css );
			}
		}
	}
}

/**
 * Checks whether a theme or its parent has a theme.json file.
 *
 * @since 6.2.0
 *
 * @return bool Returns true if theme or its parent has a theme.json file, false otherwise.
 */
function wp_theme_has_theme_json() {
	static $theme_has_support = null;

	if (
		null !== $theme_has_support &&
		/*
		 * Ignore static cache when `WP_DEBUG` is enabled. Why? To avoid interfering with
		 * the theme developer's workflow.
		 *
		 * @todo Replace `WP_DEBUG` once an "in development mode" check is available in Core.
		 */
		! WP_DEBUG &&
		/*
		 * Ignore cache when automated test suites are running. Why? To ensure
		 * the static cache is reset between each test.
		 */
		! ( defined( 'WP_RUN_CORE_TESTS' ) && WP_RUN_CORE_TESTS )
	) {
		return $theme_has_support;
	}

	// Does the theme have its own theme.json?
	$theme_has_support = is_readable( get_stylesheet_directory() . '/theme.json' );

	// Look up the parent if the child does not have a theme.json.
	if ( ! $theme_has_support ) {
		$theme_has_support = is_readable( get_template_directory() . '/theme.json' );
	}

	return $theme_has_support;
}

/**
 * Cleans the caches under the theme_json group.
 *
 * @since 6.2.0
 */
function wp_clean_theme_json_cache() {
	WP_Theme_JSON_Resolver::clean_cached_data();
}
