<?php
/**
 * Versi Content Auditor.
 *
 * @package VersiContentTools
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Versi_Auditor
 */
class Versi_Auditor {

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
	 * Get a map of all attachment IDs currently used in posts (featured or embedded).
	 *
	 * @return array
	 */
	private function get_used_attachment_ids() {
		global $wpdb;
		$used_ids = array();

		// 1. Featured images.
		$featured = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id'" );
		foreach ( $featured as $id ) {
			$used_ids[ (int) $id ] = true;
		}

		// 2. Embedded images (wp-image-{id} class).
		// To avoid memory exhaustion on large sites, we scan content in chunks.
		$total_posts = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
		$chunk_size = 100;
		$offset = 0;

		while ( $offset < $total_posts ) {
			$posts = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
				$chunk_size,
				$offset
			) );

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				if ( preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$used_ids[ (int) $id ] = true;
					}
				}
			}
			$offset += $chunk_size;
		}
		return $used_ids;
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

		// Get all unlinked attachments.
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

		$used_ids        = $this->get_used_attachment_ids();
		$potential_links = array();

		foreach ( $unlinked_attachments as $attachment ) {
			// If ID is in our used map, it's not truly unlinked.
			if ( isset( $used_ids[ (int) $attachment->ID ] ) ) {
				continue;
			}

			// For truly unlinked, find potential parents (by filename match in content).
			$filename = basename( $attachment->guid );
			$found_in = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $filename ) . '%'
				)
			);

			// Fix: a simple regex that checks for the filename bounded by non-word characters.
			// We ensure the match is preceded by a slash or start of string to prevent substring matches in IDs.
			$pattern = '/(?<=\/|^)' . preg_quote( $filename, '/' ) . '(?![A-Za-z0-9_-])/i';

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
