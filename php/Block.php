<?php
/**
 * Block class.
 *
 * @package SiteCounts
 */

namespace XWP\SiteCounts;

use WP_Block;
use WP_Query;

/**
 * The Site Counts dynamic block.
 *
 * Registers and renders the dynamic block.
 */
class Block {

	/**
	 * The Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Instantiates the class.
	 *
	 * @param Plugin $plugin The plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds the action to register the block.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Registers the block.
	 */
	public function register_block() {
		register_block_type_from_metadata(
			$this->plugin->dir(),
			[
				'render_callback' => [ $this, 'render_callback' ],
			]
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array    $attributes The attributes for the block.
	 * @param string   $content    The block content, if any.
	 * @param WP_Block $block      The instance of this block.
	 * @return string The markup of the block.
	 */
	public function render_callback( $attributes, $content, $block ) {

		// Store this in a local var for caching since we'll reference it a few
		// times in this method.
		$current_post_id = get_the_ID();

		/**
		 * Fetch all public post types as objects, and construct a new array that
		 * maps the post type's label name (not slug), and the number of
		 * published posts within that post type so we can easily display it
		 * below.
		 */

		// Array to store our new values in.
		$public_post_type_counts = [];

		// Get all public post type objects.
		$post_type_objects = get_post_types(  [ 'public' => true ], 'objects' );

		// Validate the values.
		if (
			! empty( $post_type_objects )
			&& is_array( $post_type_objects )
		) {

			// Loop through each result and store the label and count mapped to
			// the slug.
			foreach ( $post_type_objects as $post_type_object ) {
				/**
				 * @var array $public_post_type_counts = [
				 *     'posts' => [
				 *         'label' => 'Posts',
				 *         'count' => 5,
				 *      ],
				 *      'pages' => [
				 *        'label' => 'Pages',
				 *        'count' => 3,
				 *      ],
				 * ]
				 */
				$public_post_type_counts[ $post_type_object->name ] = [
					'label' => $post_type_object->labels->name ?? '',
					'count' => wp_count_posts( $post_type_object->name )->publish ?? 0,
				];
			}
		}

		/**
		 * Prep data to display for up to 5 posts & pages published between
		 * 9:00 and 17:00, with the tag (slug) `foo`, and category (name)
		 * `baz`.
		 */

		// Cache the post IDs since those are the key values we're looking for.
		$cache_key                 = 'xwp\site_counts_block\posts_list_query_post_ids';
		$posts_list_query_post_ids = get_transient( $cache_key );

		// No cache found, query for the data and cache it.
		if ( false === $posts_list_query_post_ids ) {
			$posts_list_query = new WP_Query(
				[
					'post_type'              => [ 'post', 'page' ],
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'update_post_meta_cache' => false, // We're not displaying any post meta.
					'update_post_term_cache' => false, // Or terms.
					'posts_per_page'         => 6, // Actual number of posts we want (5), plus one for our current post.
					'date_query'             => [
						[
							'hour'   => 9,
							'compare'=> '>=',
						],
						[
							'hour'   => 17,
							'compare'=> '<=',
						],
					],
					'tax_query' => [
						[
							'taxonomy' => 'post_tag',
							'field'    => 'slug',
							'terms'    => 'foo',
						],
						[
							'taxonomy'=> 'category',
							'field'   => 'name',
							'terms'   => 'baz',
						],
					],
				]
			);

			/**
			 * If our current post (`get_the_ID()`) is returned in the query results,
			 * remove it. This mimics the functionality of using the `post__not_in`
			 * argument on the query.
			 *
			 * @see https://docs.wpvip.com/technical-references/code-quality-and-best-practices/using-post__not_in/
			 */
			if ( in_array( $current_post_id, $posts_list_query->posts, true ) ) {

				// Remove the current post id by using `array_diff`, then reset the
				// keys using `array_values` to ensure no gaps.
				$posts_list_query->posts = array_values( array_diff( $posts_list_query->posts, [ $current_post_id ] ) );

				// Update the post count and found post values now that the current
				// post ID has been removed.
				$posts_list_query->post_count    = $posts_list_query->post_count - 1;
				$posts_list_query->found_posts   = $posts_list_query->found_posts - 1;
			}

			// Cache for 5 minutes.
			set_transient( $cache_key, $posts_list_query->posts, MINUTE_IN_SECONDS * 5 );

		} else {
			/**
			 * Use the cached post ids to construct a real WP_Query. This is a
			 * bit more work, but for cache storage reasons, we don't want to
			 * store the entire WP_Query object, just the relevant data.
			 */
			$posts_list_query = new WP_Query(
				[
					'post__in'       => $posts_list_query_post_ids,
					'post_type'      => [ 'post', 'page' ], // Post types need to match the original query.
					'fields'         => 'ids',
				    'orderby'        => 'post__in', // Ensure proper order is maintained.
				    'posts_per_page' => count( $posts_list_query_post_ids ), // Ensure all ids are used.
				]
			);
		}

		// Block render callbacks expect a string, so begin capturing any direct
		// output.
		ob_start();
		?>
		<div class="<?php echo esc_attr( $attributes['className'] ?? '' ); ?>">
			<h2><?php esc_html_e( 'Post Counts', 'site-counts' ); ?></h2>

			<?php
			/**
			 * Display a list of public post types and how many posts each has.
			 */
			if ( ! empty( $public_post_type_counts ) ) :
				?>
					<ul>
						<?php foreach ( $public_post_type_counts as $values ) : ?>
							<li>
								<?php
								echo esc_html(
									sprintf(
										__( 'There are %1$d %2$s.', 'site-counts' ),
										$values['count'] ?? 0,
										$values['label'] ?? ''
									)
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php
			endif;

			/**
			 * Display the current post ID.
			 */
			?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						__( 'The current post ID is %1$d.', 'site-counts' ),
						absint( $current_post_id )
					)
				);
				?>
			</p>
			<?php

			/**
			 * Display post titles for up to 5 posts & pages published between 9:00 and 17:00,
			 * with the tag (slug) `foo`, and category (name) `baz`.
			 */
			if ( $posts_list_query->have_posts() ) :
				?>
				<h2><?php
					echo esc_html(
						sprintf(
							__( '%1$d posts with the tag of foo and the category of baz', 'site-counts' ),
							absint( $posts_list_query->found_posts )
						)
					);
				?></h2>
				<ul>
					<?php while( $posts_list_query->have_posts() ): $posts_list_query->the_post(); ?>
						<li><?php the_title() ?></li>
					<?php endwhile; ?>
				</ul>
			<?php endif; ?>
		</div>

		<?php
		// Return the captured output.
		return ob_get_clean();
	}
}
