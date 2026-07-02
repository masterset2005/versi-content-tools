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
	 * Find images not linked to any post but referenced in post_content.
	 *
	 * @return array
	 */
	public function find_unlinked_images() {
		global $wpdb;

		// Get all image attachments with no parent.
		$unlinked_attachments = $wpdb->get_results(
			"SELECT ID, guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_parent = 0"
		);

		$potential_links = array();

		foreach ( $unlinked_attachments as $attachment ) {
			// Get filename from URL.
			$filename = basename( $attachment->guid );

			// Look for this filename in post_content.
			$found_in = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $filename ) . '%'
				)
			);

			foreach ( $found_in as $post ) {
				$potential_links[] = array(
					'attachment_id'   => $attachment->ID,
					'attachment_url'  => $attachment->guid,
					'post_id'         => $post->ID,
					'post_title'      => $post->post_title,
				);
			}
		}

		return $potential_links;
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
