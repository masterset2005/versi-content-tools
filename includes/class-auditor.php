<?php
/**
 * Versi Content Auditor.
 *
 * @package VersiContentTools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Versi_Auditor
 */
class Versi_Auditor {
	use Versi_Singleton;

	/**
	 * Batch size for scanning.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Get total count of unlinked image attachments.
	 *
	 * @return int
	 */
	public function get_unlinked_count() {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_parent = 0",
				'image/%'
			)
		);
		return (int) $count;
	}

	/**
	 * Get a map of all image filenames currently used in published posts.
	 *
	 * @return array
	 */
	private function get_used_image_filenames() {
		global $wpdb;
		$posts          = $wpdb->get_results( "SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
		$used_filenames = array();
		foreach ( $posts as $post ) {
			if ( preg_match_all( '/\b([\w-]+\.(?:jpg|jpeg|png|gif|webp|svg))\b/i', $post->post_content, $matches ) ) {
				foreach ( $matches[1] as $filename ) {
					$used_filenames[ strtolower( $filename ) ] = true;
				}
			}
		}
		return $used_filenames;
	}

	/**
	 * Find unlinked images in a batch.
	 *
	 * @param int $offset Starting offset.
	 * @param int $limit  Batch size.
	 * @return array
	 */
	public function find_unlinked_batch( $offset = 0, $limit = 50 ) {
		global $wpdb;

		// Get a batch of unlinked image attachments.
		$unlinked_attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND post_parent = 0 ORDER BY ID ASC LIMIT %d OFFSET %d",
				'image/%',
				$limit,
				$offset
			)
		);

		if ( empty( $unlinked_attachments ) ) {
			return array();
		}

		$used_filenames  = $this->get_used_image_filenames();
		$potential_links = array();

		foreach ( $unlinked_attachments as $attachment ) {
			$filename = strtolower( basename( $attachment->guid ) );

			if ( isset( $used_filenames[ $filename ] ) ) {
				// We found the filename in our used-images index.
				// Now find which post(s) it is in, and verify the filename is an exact match.
				$found_in = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE %s",
						'%' . $wpdb->esc_like( basename( $attachment->guid ) ) . '%'
					)
				);

				// PHP regex for exact filename match (word-boundary aware).
				$pattern = '/(?<![\w-])' . preg_quote( basename( $attachment->guid ), '/' ) . '(?![A-Za-z0-9_-])(?=\.jpg|\.jpeg|\.png|\.gif|\.webp|\.svg)/i';

				foreach ( $found_in as $post ) {
					if ( preg_match( $pattern, $post->post_content ) ) {
						$potential_links[] = array(
							'attachment_id'  => $attachment->ID,
							'attachment_url' => $attachment->guid,
							'att_edit_link'  => get_edit_post_link( $attachment->ID ),
							'att_path'       => preg_replace( '/^.*\/wp-content\/uploads\//', '', $attachment->guid ),
							'post_id'        => $post->ID,
							'post_title'     => $post->post_title,
							'post_edit_link' => get_edit_post_link( $post->ID ),
						);
					}
				}
			}
		}

		return $potential_links;
	}

	/**
	 * Find images not linked to any post but referenced in post_content.
	 *
	 * @return array
	 */
	public function find_unlinked_images() {
		return $this->find_unlinked_batch( 0, PHP_INT_MAX );
	}

	/**
	 * Link an attachment to a post by updating post_parent.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function link_attachment( $attachment_id, $post_id ) {
		return wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_parent' => $post_id,
			)
		) > 0;
	}
}
