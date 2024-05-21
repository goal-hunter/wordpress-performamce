<?php
/**
 * Add Picture Element support
 * Description: Use <picture> element when image has more than one mime type.
 * Experimental: No
 *
 * @package performance-lab
 * @since n.e.x.t
 */

/**
 * Potentially wrap content images in a picture element.
 *
 * @since n.e.x.t
 *
 * @param string $content The content to be filtered.
 */
function webp_uploads_wrap_images_in_picture_element( $content ): string {
	$pattern = '/(<img[^>]+>)/';
	$images  = preg_match_all( $pattern, $content, $matches );
	if ( $images ) {
		foreach ( $matches[0] as $image ) {
			// Wrap WordPress images where we can extract an attachment id.
			if ( preg_match( '/wp-image-([0-9]+)/i', $image, $class_id ) ) {
				$attachment_id = absint( $class_id[1] );
				$new_image     = webp_uploads_wrap_image_in_picture( $image, $attachment_id );
				if ( false !== $new_image ) {
					$content = str_replace( $image, $new_image, $content );
				}
			}
		}
	}

	return $content;
}
if ( webp_uploads_picture_element_enabled() ) {
	add_filter( 'the_content', 'webp_uploads_wrap_images_in_picture_element' );
}

/**
 * Wrap an image tag in a picture element.
 *
 * @since n.e.x.t
 *
 * @param string $image         The image tag.
 * @param int    $attachment_id The attachment id.
 *
 * @return string The new image tag.
 */
function webp_uploads_wrap_image_in_picture( $image, $attachment_id ) {
	$image_meta              = wp_get_attachment_metadata( $attachment_id );
	$original_file_mime_type = get_post_mime_type( $attachment_id );
	if ( false === $original_file_mime_type || ! isset( $image_meta['sizes'] ) ) {
		return $image;
	}

	// Collect all of the sub size image mime types.
	$sub_size_mime_types_heap = array();
	foreach ( $image_meta['sizes'] as $size ) {
		$sub_size_mime_types_heap[ $size['mime-type'] ] = true;
	}
	$sub_size_mime_types = array_keys( $sub_size_mime_types_heap );

	/**
	 * Filter the image mime types that can be used for the <picture> element.
	 *
	 * Default is: ['image/webp'] for testing. Returning an empty array will skip using the picture element.
	 *
	 * The mime types will output in the picture element in the order they are provided.
	 * The original image will be used as the fallback.
	 *
	 * @since n.e.x.t
	 * @param array<string> $mime_types    Mime types than can be used.
	 * @param int           $attachment_id The id of the image being evaluated.
	 */
	$enabled_mime_types = apply_filters(
		'wp_picture_element_mime_types',
		array(
			'image/webp',
		),
		$attachment_id
	);

	$mime_types = array_filter(
		$sub_size_mime_types,
		function( $mime_type ) use ( $enabled_mime_types ) {
			return in_array( $mime_type, $enabled_mime_types, true );
		}
	);

	// No eligible mime types.
	if ( ! $mime_types ) {
		return $image;
	}

	// If the original mime types is the only one available, no picture element is needed.
	if ( 1 === count( $mime_types ) && $mime_types[0] === $original_file_mime_type ) {
		return $image;
	}

	// Add each mime type to the picture's sources.
	$picture_sources = '';
	$image_src       = wp_get_attachment_image_src( $attachment_id, 'full', false );
	if ( ! $image_src ) {
		return $image;
	}
	list( $src, $width, $height ) = $image_src;
	$size_array                   = array( absint( $width ), absint( $height ) );

	foreach ( $mime_types as $image_mime_type ) {
		// @TODO limit by mime type when multiple mime types are supported.
		$sizes            = wp_calculate_image_sizes( $size_array, $src, $image_meta, $attachment_id );
		$image_srcset     = wp_get_attachment_image_srcset( $attachment_id, $size_array, $image_meta );
		$picture_sources .= sprintf(
			'<source type="%s" srcset="%s" sizes="%s">',
			$image_mime_type,
			$image_srcset,
			$sizes
		);
	}

	// Fall back to the original image without a srcset.
	$original_sizes = array( $image_src[1], $image_src[2] );
	$original_image = wp_get_original_image_url( $attachment_id );
	// Fail gracefully if the original image is not found.
	if ( ! $original_image ) {
		return $image;
	}
	add_filter( 'wp_calculate_image_srcset_meta', '__return_false' );
	$original_image_without_srcset = wp_get_attachment_image( $attachment_id, $original_sizes, false, array( 'src' => $original_image ) );
	remove_filter( 'wp_calculate_image_srcset_meta', '__return_false' );

	return sprintf(
		'<picture class=%s>%s %s</picture>',
		'wp-picture-' . $attachment_id,
		$picture_sources,
		$original_image_without_srcset
	);
}

/**
 * Helper function to check if the `webp_uploads_picture_element_enabled` option is enabled.
 *
 * @since n.e.x.t
 *
 * @return bool True if the option is enabled, false otherwise.
 */
function webp_uploads_picture_element_enabled(): bool {
	return get_option( 'webp_uploads_use_picture_element', false );
}