<?php
/**
 * Revision change summary WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Revision_Change_Summary;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Revision_Change_Summary\Revision_Change_Summary as Revision_Change_Summary_Experiment;

use function WordPress\AI\normalize_content;

/**
 * Revision change summary WordPress Ability.
 *
 * Summarizes, in plain language, what changed in the title, content and
 * excerpt between two revisions of the same post.
 *
 * @since x.x.x
 */
class Revision_Change_Summary extends Abstract_Ability {

	/**
	 * The default maximum length, in characters, of the diff sent to the model.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const MAX_DIFF_LENGTH_DEFAULT = 20000;

	/**
	 * The maximum number of change items returned.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const MAX_CHANGES = 10;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'          => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the post the revisions belong to.', 'ai' ),
				),
				'revision_id'      => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the newer revision to compare.', 'ai' ),
				),
				'from_revision_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the older revision to compare against. Defaults to the revision before the newer revision.', 'ai' ),
				),
			),
			'required'             => array( 'post_id', 'revision_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'summary'          => array(
					'type'        => 'string',
					'description' => esc_html__( 'Short plain-language summary of the changes.', 'ai' ),
				),
				'changes'          => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'List of individual changes.', 'ai' ),
				),
				'truncated'        => array(
					'type'        => 'boolean',
					'description' => esc_html__( 'Whether the changes were too long and only part of them was summarized.', 'ai' ),
				),
				'from_revision_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'ID of the older revision that was compared.', 'ai' ),
				),
				'revision_id'      => array(
					'type'        => 'integer',
					'description' => esc_html__( 'ID of the newer revision that was compared.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_id'          => 0,
				'revision_id'      => 0,
				'from_revision_id' => 0,
			)
		);

		$post_id = absint( $args['post_id'] );

		$to_revision = $this->get_revision_of_post( absint( $args['revision_id'] ), $post_id );
		if ( is_wp_error( $to_revision ) ) {
			return $to_revision;
		}

		if ( ! empty( $args['from_revision_id'] ) ) {
			$from_revision = $this->get_revision_of_post( absint( $args['from_revision_id'] ), $post_id );
			if ( is_wp_error( $from_revision ) ) {
				return $from_revision;
			}
		} else {
			$from_revision = $this->get_previous_revision( $to_revision );
			if ( ! $from_revision ) {
				return new WP_Error(
					'no_previous_revision',
					esc_html__( 'There is no earlier revision to compare with.', 'ai' )
				);
			}
		}

		if ( $from_revision->ID === $to_revision->ID ) {
			return new WP_Error(
				'same_revision',
				esc_html__( 'Choose two different revisions to compare.', 'ai' )
			);
		}

		// Always describe the changes from the older revision to the newer one.
		if ( $this->is_newer_revision( $from_revision, $to_revision ) ) {
			[ $from_revision, $to_revision ] = array( $to_revision, $from_revision );
		}

		$diff = $this->build_diff( $from_revision, $to_revision );

		$result = array(
			'summary'          => '',
			'changes'          => array(),
			'truncated'        => false,
			'from_revision_id' => $from_revision->ID,
			'revision_id'      => $to_revision->ID,
		);

		// No text changed (for example only meta, block settings or an image), so don't spend a model request on it.
		if ( '' === $diff ) {
			$result['summary'] = __( 'No text changes were found in the title, content, or excerpt between these revisions.', 'ai' );
			return $result;
		}

		$max_length = $this->get_max_diff_length();
		if ( mb_strlen( $diff ) > $max_length ) {
			$diff                = $this->truncate_diff( $diff, $max_length );
			$result['truncated'] = true;
		}

		$generated = $this->generate_summary( $diff, $result['truncated'], $to_revision );

		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		$summary = sanitize_textarea_field( trim( $generated['summary'] ) );
		$changes = array_values(
			array_filter(
				array_map(
					static function ( string $change ): string {
						return sanitize_text_field( $change );
					},
					$generated['changes']
				),
				static function ( string $change ): bool {
					return '' !== $change;
				}
			)
		);

		if ( '' === $summary && empty( $changes ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No summary of the changes was generated.', 'ai' )
			);
		}

		$result['summary'] = $summary;
		$result['changes'] = array_slice( $changes, 0, self::MAX_CHANGES );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		// Ensure the post exists.
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'post_not_found',
				/* translators: %d: Post ID. */
				sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
			);
		}

		// Revisions can only be compared by users who can edit the post, same as core.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to view the revisions of this post.', 'ai' )
			);
		}

		// Ensure the post type is allowed in REST endpoints and supports revisions.
		return self::post_type_is_supported( $post->post_type );
	}

	/**
	 * Checks whether revision changes of a post type can be summarized.
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type.
	 * @return bool True if the post type is shown in REST and supports revisions.
	 */
	public static function post_type_is_supported( string $post_type ): bool {
		$post_type_obj = get_post_type_object( $post_type );

		if ( ! $post_type_obj instanceof WP_Post_Type || empty( $post_type_obj->show_in_rest ) ) {
			return false;
		}

		return post_type_supports( $post_type, 'revisions' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Returns a revision, making sure it belongs to the given post.
	 *
	 * @since x.x.x
	 *
	 * @param int $revision_id The revision ID.
	 * @param int $post_id     The parent post ID.
	 * @return \WP_Post|\WP_Error The revision, or WP_Error if it is not a revision of the post.
	 */
	private function get_revision_of_post( int $revision_id, int $post_id ) {
		// `wp_get_post_revision()` takes its argument by reference, so pass a copy.
		$lookup_id = $revision_id;
		$revision  = $lookup_id ? wp_get_post_revision( $lookup_id ) : null;

		if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== $post_id ) {
			return new WP_Error(
				'invalid_revision',
				/* translators: %d: Revision ID. */
				sprintf( esc_html__( 'Revision with ID %d is not a revision of this post.', 'ai' ), $revision_id )
			);
		}

		return $revision;
	}

	/**
	 * Returns the revision saved just before the given revision.
	 *
	 * Uses the same order as the visual revisions screen (newest first, by
	 * date and then ID), so autosaves are included. Revisions are found even
	 * when revisions are now turned off for the post, as long as they exist.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $revision The newer revision.
	 * @return \WP_Post|null The previous revision, or null if there is none.
	 */
	private function get_previous_revision( WP_Post $revision ): ?WP_Post {
		$revision_ids = array_map(
			static function ( $item ): int {
				return $item instanceof WP_Post ? $item->ID : (int) $item;
			},
			array_values(
				wp_get_post_revisions(
					(int) $revision->post_parent,
					array(
						'orderby'       => 'date ID',
						'order'         => 'DESC',
						'fields'        => 'ids',
						'check_enabled' => false,
					)
				)
			)
		);

		$index = array_search( $revision->ID, $revision_ids, true );

		if ( false === $index || ! isset( $revision_ids[ $index + 1 ] ) ) {
			return null;
		}

		$previous = get_post( $revision_ids[ $index + 1 ] );

		return $previous instanceof WP_Post ? $previous : null;
	}

	/**
	 * Checks whether the first revision was saved after the second one.
	 *
	 * Uses the local post date, then the ID, like the revisions screens.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $first  The first revision.
	 * @param \WP_Post $second The second revision.
	 * @return bool True if the first revision is newer.
	 */
	private function is_newer_revision( WP_Post $first, WP_Post $second ): bool {
		if ( $first->post_date !== $second->post_date ) {
			return $first->post_date > $second->post_date;
		}

		return $first->ID > $second->ID;
	}

	/**
	 * Builds a compact, line-based diff of the title, content and excerpt.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $from The older revision.
	 * @param \WP_Post $to   The newer revision.
	 * @return string The diff, or an empty string if nothing changed.
	 */
	protected function build_diff( WP_Post $from, WP_Post $to ): string {
		$fields = array(
			'Title'   => array( $this->get_plain_lines( $from->post_title ), $this->get_plain_lines( $to->post_title ) ),
			'Excerpt' => array( $this->get_plain_lines( $from->post_excerpt ), $this->get_plain_lines( $to->post_excerpt ) ),
			'Content' => array( $this->get_content_lines( $from->post_content ), $this->get_content_lines( $to->post_content ) ),
		);

		$sections = array();

		foreach ( $fields as $label => $lines ) {
			$field_diff = $this->diff_lines( $lines[0], $lines[1] );

			if ( '' === $field_diff ) {
				continue;
			}

			$sections[] = $label . ":\n" . $field_diff;
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Splits plain text, like a title or excerpt, into normalized lines.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text.
	 * @return list<string> The normalized, non-empty lines.
	 */
	private function get_plain_lines( string $text ): array {
		$lines = preg_split( '/\n\s*\n/', $text );

		return $this->normalize_lines( false === $lines ? array( $text ) : $lines );
	}

	/**
	 * Splits post content into one normalized line per block.
	 *
	 * Blocks are read from their saved markup only. They are never rendered,
	 * so dynamic blocks and shortcodes don't run and don't show the current
	 * state of the site instead of the revision.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The post content.
	 * @return list<string> The normalized, non-empty lines.
	 */
	private function get_content_lines( string $content ): array {
		if ( ! has_blocks( $content ) ) {
			return $this->get_plain_lines( $content );
		}

		return $this->normalize_lines( $this->get_block_texts( parse_blocks( $content ) ) );
	}

	/**
	 * Collects the text of each block, including nested blocks, in order.
	 *
	 * @since x.x.x
	 *
	 * @param array<int|string, mixed> $blocks Parsed blocks.
	 * @return list<string> The raw text of each block.
	 */
	private function get_block_texts( array $blocks ): array {
		$texts = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$inner_html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			if ( empty( $block['blockName'] ) ) {
				// Freeform (classic) content between blocks can hold several paragraphs.
				$parts = preg_split( '/\n\s*\n/', $inner_html );
				$texts = array_merge( $texts, false === $parts ? array( $inner_html ) : $parts );
			} else {
				$texts[] = $inner_html;
			}

			if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				continue;
			}

			$texts = array_merge( $texts, $this->get_block_texts( $block['innerBlocks'] ) );
		}

		return $texts;
	}

	/**
	 * Normalizes lines of text and removes empty ones.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $lines The raw lines.
	 * @return list<string> The normalized, non-empty lines.
	 */
	private function normalize_lines( array $lines ): array {
		$normalized = array();

		foreach ( $lines as $line ) {
			$line = normalize_content( $line );

			if ( '' === $line ) {
				continue;
			}

			$normalized[] = $line;
		}

		return $normalized;
	}

	/**
	 * Diffs two lists of lines into a compact format the model can read.
	 *
	 * Removed lines start with "- ", added lines with "+ ", and unchanged
	 * lines next to a change are kept as context and start with two spaces.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $from_lines The older lines.
	 * @param list<string> $to_lines   The newer lines.
	 * @return string The diff, or an empty string if the lines are the same.
	 */
	private function diff_lines( array $from_lines, array $to_lines ): string {
		if ( $from_lines === $to_lines ) {
			return '';
		}

		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . 'wp-includes/wp-diff.php';
		}

		$diff  = new \Text_Diff( 'auto', array( $from_lines, $to_lines ) );
		$edits = $diff->getDiff();
		$last  = count( $edits ) - 1;
		$lines = array();

		foreach ( $edits as $index => $edit ) {
			if ( $edit instanceof \Text_Diff_Op_copy ) {
				$lines = array_merge( $lines, $this->get_context_lines( $this->get_op_lines( $edit->orig ), $index > 0, $index < $last ) );
				continue;
			}

			foreach ( $this->get_op_lines( $edit->orig ) as $line ) {
				$lines[] = '- ' . $line;
			}

			foreach ( $this->get_op_lines( $edit->final ) as $line ) {
				$lines[] = '+ ' . $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Returns the lines of one side of a diff operation.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $lines The lines, or false when that side is empty.
	 * @return list<string> The lines.
	 */
	private function get_op_lines( $lines ): array {
		return is_array( $lines ) ? array_values( array_filter( $lines, 'is_string' ) ) : array();
	}

	/**
	 * Returns the unchanged lines kept as context around a change.
	 *
	 * Keeps one line next to each change and replaces the rest with "...".
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $copied        The unchanged lines.
	 * @param bool         $change_before Whether a change comes before these lines.
	 * @param bool         $change_after  Whether a change comes after these lines.
	 * @return list<string> The context lines.
	 */
	private function get_context_lines( array $copied, bool $change_before, bool $change_after ): array {
		$count = count( $copied );

		if ( 0 === $count ) {
			return array();
		}

		$first = '  ' . $copied[0];
		$final = '  ' . $copied[ $count - 1 ];

		if ( $change_before && $change_after ) {
			if ( $count <= 2 ) {
				return array_map(
					static function ( string $line ): string {
						return '  ' . $line;
					},
					$copied
				);
			}

			return array( $first, '  ...', $final );
		}

		if ( $change_before ) {
			return array( $first );
		}

		if ( $change_after ) {
			return $count > 1 ? array( '  ...', $final ) : array( $final );
		}

		return array();
	}

	/**
	 * Returns the maximum length of the diff sent to the model.
	 *
	 * @since x.x.x
	 *
	 * @return int The maximum number of characters.
	 */
	protected function get_max_diff_length(): int {
		/**
		 * Filters the maximum length of the revision diff sent to the model.
		 *
		 * Longer diffs are cut and the summary only covers the first part of
		 * the changes.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_length The maximum number of characters. Default 20000.
		 */
		$max_length = (int) apply_filters( 'wpai_revision_change_summary_max_diff_length', self::MAX_DIFF_LENGTH_DEFAULT );

		return max( 1000, $max_length );
	}

	/**
	 * Cuts the diff to the maximum length on a line boundary.
	 *
	 * @since x.x.x
	 *
	 * @param string $diff       The diff.
	 * @param int    $max_length The maximum number of characters.
	 * @return string The shortened diff.
	 */
	private function truncate_diff( string $diff, int $max_length ): string {
		$diff     = mb_substr( $diff, 0, $max_length );
		$last_new = mb_strrpos( $diff, "\n" );

		if ( false !== $last_new && $last_new > 0 ) {
			$diff = mb_substr( $diff, 0, $last_new );
		}

		return $diff;
	}

	/**
	 * Generates the summary of the changes.
	 *
	 * @since x.x.x
	 *
	 * @param string   $diff      The diff of the two revisions.
	 * @param bool     $truncated Whether the diff was shortened.
	 * @param \WP_Post $revision  The newer revision.
	 * @return array{summary: string, changes: list<string>}|\WP_Error The summary and changes, or WP_Error on failure.
	 */
	protected function generate_summary( string $diff, bool $truncated, WP_Post $revision ) {
		$prompt = '<changes>' . $diff . '</changes>';

		if ( $truncated ) {
			$prompt .= "\n\n<note>The changes were too long and have been cut. Only the first part is shown.</note>";
		}

		$prompt         = $this->filter_prompt( $prompt, $diff, $truncated, $revision );
		$prompt_builder = $this->get_prompt_builder( $prompt, $truncated );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$response = $prompt_builder->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_summary( (string) $response );
	}

	/**
	 * Decodes the structured summary returned by the model.
	 *
	 * @since x.x.x
	 *
	 * @param string $response The raw JSON response.
	 * @return array{summary: string, changes: list<string>}|\WP_Error The summary and changes, or WP_Error if the response could not be parsed.
	 */
	private function parse_summary( string $response ) {
		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['summary'] ) || ! is_string( $decoded['summary'] ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse the AI response as a summary of changes.', 'ai' )
			);
		}

		$changes = isset( $decoded['changes'] ) && is_array( $decoded['changes'] ) ? $decoded['changes'] : array();

		return array(
			'summary' => $decoded['summary'],
			'changes' => array_values( array_filter( $changes, 'is_string' ) ),
		);
	}

	/**
	 * Returns the JSON schema the model must respond with.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The response schema.
	 */
	protected function summary_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'summary' => array(
					'type' => 'string',
				),
				'changes' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
			),
			'required'             => array( 'summary', 'changes' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Gets a prompt builder for summarizing the changes.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt    The prompt.
	 * @param bool   $truncated Whether the diff was shortened.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or WP_Error.
	 */
	private function get_prompt_builder( string $prompt, bool $truncated ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction( null, array( 'max_changes' => self::MAX_CHANGES ) ) )
			->as_json_response( $this->summary_schema() );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Revision_Change_Summary_Experiment::class, array(), $prompt, $truncated );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Summarizing the changes failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}
