<?php
/**
 * REST API: CoCart_REST_Products_V2_Controller class
 *
 * @author  Sébastien Dumont
 * @package CoCart\RESTAPI\Products\v2
 * @since   3.1.0 Introduced.
 * @version 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CoCart\Utilities\APIPermission;
use CoCart\Utilities\Fields;
use CoCart\Utilities\MonetaryFormatting;
use CoCart\ProductsAPI\Utilities\Helpers;

/**
 * Controller for returning products via the REST API (API v2).
 *
 * This REST API controller handles requests to return product details
 * via "cocart/v2/products" endpoint.
 *
 * @since 3.1.0 Introduced.
 */
class CoCart_REST_Products_V2_Controller extends CoCart_Products_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'cocart/v2';

	/**
	 * Register routes.
	 *
	 * @access public
	 */
	public function register_routes() {
		// Get Products - cocart/v2/products (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'args'                => $this->get_collection_params(),
					'permission_callback' => array( 'CoCart\Utilities\APIPermission', 'has_api_permission' ),
				),
				'allow_batch' => array( 'v1' => true ),
				'schema'      => array( $this, 'get_public_items_schema' ),
			)
		);

		// Get a single product by ID - cocart/v2/products/32 (GET).
		// Get a single product by SKU - cocart/v2/products/woo-vneck-tee (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\w-]+)',
			array(
				'args'        => array(
					'id' => array(
						'description' => __( 'Unique identifier for the product.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'args'                => array(
						'context' => $this->get_context_param(
							array(
								'default' => 'view',
							)
						),
					),
					'permission_callback' => array( 'CoCart\Utilities\APIPermission', 'has_api_permission' ),
				),
				'allow_batch' => array( 'v1' => true ),
				'schema'      => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get a collection of products.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 * @since 3.2.0 Moved products to it's own object and returned also pagination information.
	 * @since 4.0.0 Added product categories and tags.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$query_args    = $this->prepare_objects_query( $request );
		$query_results = $this->get_objects( $query_args );

		$objects = array();

		foreach ( $query_results['objects'] as $object ) {
			$data      = $this->prepare_object_for_response( $object, $request );
			$objects[] = $this->prepare_response_for_collection( $data );
		}

		$page      = (int) $query_args['paged'];
		$max_pages = $query_results['pages'];

		$results  = array(
			'products'       => $objects,
			'categories'     => $this->get_all_product_taxonomies( 'cat' ),
			'tags'           => $this->get_all_product_taxonomies( 'tag' ),
			'page'           => $page,
			'total_pages'    => (int) $max_pages,
			'total_products' => $query_results['total'],
		);
		$response = rest_ensure_response( $results );
		$response->header( 'X-WP-Total', $query_results['total'] );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		// Add timestamp of response.
		$response->header( 'CoCart-Timestamp', time() );

		// Add version of CoCart.
		$response->header( 'CoCart-Version', COCART_VERSION );

		$base          = $this->rest_base;
		$attrib_prefix = '(?P<';

		if ( strpos( $base, $attrib_prefix ) !== false ) {
			$attrib_names = array();

			preg_match( '/\(\?P<[^>]+>.*\)/', $base, $attrib_names, PREG_OFFSET_CAPTURE );

			foreach ( $attrib_names as $attrib_name_match ) {
				$beginning_offset = strlen( $attrib_prefix );
				$attrib_name_end  = strpos( $attrib_name_match[0], '>', $attrib_name_match[1] );
				$attrib_name      = substr( $attrib_name_match[0], $beginning_offset, $attrib_name_end - $beginning_offset );

				if ( isset( $request[ $attrib_name ] ) ) {
					$base = str_replace( "(?P<$attrib_name>[\d]+)", $request[ $attrib_name ], $base );
				}
			}
		}

		$base = add_query_arg( $request->get_query_params(), rest_url( sprintf( '/%s/%s', $this->namespace, $base ) ) );

		if ( $page > 1 ) {
			$prev_page = $page - 1;

			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}

			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->add_links( array(
				'prev' => array( 'href' => $prev_link ),
			) );
			$response->link_header( 'prev', $prev_link );
		}

		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );
			$response->add_links( array(
				'next' => array( 'href' => $next_link ),
			) );
			$response->link_header( 'next', $next_link );
		}

		return $response;
	} // END get_items()

	/**
	 * Prepare links for the request.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array Links for the given product.
	 */
	protected function prepare_links( $product, $request ) {
		$links = array(
			'self'       => array(
				'permalink' => cocart_get_permalink( get_permalink( $product->get_id() ) ),
				'href'      => rest_url( sprintf( '/%s/%s/%d', $this->namespace, $this->rest_base, $product->get_id() ) ),
			),
			'collection' => array(
				'permalink' => cocart_get_permalink( wc_get_page_permalink( 'shop' ) ),
				'href'      => rest_url( sprintf( '/%s/%s', $this->namespace, $this->rest_base ) ),
			),
		);

		if ( $product->get_parent_id() ) {
			$links['parent_product'] = array(
				'permalink' => cocart_get_permalink( get_permalink( $product->get_parent_id() ) ),
				'href'      => rest_url( sprintf( '/%s/products/%d', $this->namespace, $product->get_parent_id() ) ),
			);
		}

		// If product is a variable product, return links to all variations.
		if ( $product->is_type( 'variable' ) && $product->has_child() || $product->is_type( 'variable-subscription' ) && $product->has_child() ) {
			$variations = $product->get_children();

			foreach ( $variations as $variation_product ) {
				$links['variations'][ $variation_product ] = array(
					'permalink' => cocart_get_permalink( get_permalink( $variation_product ) ),
					'href'      => rest_url( sprintf( '/%s/products/%d/variations/%d', $this->namespace, $product->get_id(), $variation_product ) ),
				);
			}
		}

		return $links;
	} // END prepare_links()

	/**
	 * Prepare objects query.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array
	 */
	protected function prepare_objects_query( $request ) {
		$args = array(
			'offset'              => $request['offset'],
			'order'               => ! empty( $request['order'] ) ? strtoupper( $request['order'] ) : 'DESC',
			'orderby'             => ! empty( $request['orderby'] ) ? strtolower( $request['orderby'] ) : get_option( 'woocommerce_default_catalog_orderby' ),
			'paged'               => $request['page'],
			'post__in'            => $request['include'],
			'post__not_in'        => $request['exclude'],
			'posts_per_page'      => $request['per_page'],
			'post_parent__in'     => $request['parent'],
			'post_parent__not_in' => $request['parent_exclude'],
			's'                   => $request['search'],
			'name'                => $request['slug'],
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
			'post_status'         => 'publish',
			'date_query'          => array(),
			'post_type'           => 'product',
		);

		// If searching for a specific SKU or including variations, allow all product post types.
		if ( ! empty( $request['sku'] ) || ! empty( $request['include_variations'] ) ) {
			$args['post_type'] = $this->get_post_types();
		}

		// If order by is not set then use WooCommerce default catalog setting.
		if ( empty( $args['orderby'] ) ) {
			$args['orderby'] = get_option( 'woocommerce_default_catalog_orderby' );
		}

		switch ( $args['orderby'] ) {
			case 'id':
				$args['orderby'] = 'ID'; // ID must be capitalized.
				break;
			case 'menu_order':
				$args['orderby'] = 'menu_order title';
				break;
			case 'include':
				$args['orderby'] = 'post__in';
				break;
			case 'name':
			case 'slug':
				$args['orderby'] = 'name';
				break;
			case 'alphabetical':
				$args['orderby']  = 'title';
				$args['order']    = 'ASC';
				$args['meta_key'] = '';
				break;
			case 'reverse_alpha':
				$args['orderby']  = 'title';
				$args['order']    = 'DESC';
				$args['meta_key'] = '';
				break;
			case 'title':
				$args['orderby'] = 'title';
				$args['order']   = ( 'DESC' === $args['order'] ) ? 'DESC' : 'ASC';
				break;
			case 'relevance':
				$args['orderby'] = 'relevance';
				$args['order']   = 'DESC';
				break;
			case 'rand':
				$args['orderby'] = 'rand';
				break;
			case 'date':
				$args['orderby'] = 'date ID';
				$args['order']   = ( 'ASC' === $args['order'] ) ? 'ASC' : 'DESC';
				break;
			case 'by_stock':
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'title'          => 'ASC',
				);
				$args['meta_key'] = '_stock';
				break;
			case 'review_count':
				$args['orderby']  = array(
					'meta_value_num' => 'DESC',
					'title'          => 'ASC',
				);
				$args['meta_key'] = '_wc_review_count';
				break;
			case 'on_sale_first':
				$args['orderby']      = array(
					'meta_value_num' => 'DESC',
					'title'          => 'ASC',
				);
				$args['meta_key']     = '_sale_price';
				$args['meta_value']   = 0;
				$args['meta_compare'] = '>=';
				$args['meta_type']    = 'NUMERIC';
				break;
			case 'featured_first':
				$args['orderby']  = array(
					'meta_value' => 'DESC',
					'title'      => 'ASC',
				);
				$args['meta_key'] = '_featured';
				break;
			case 'price_asc':
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'ASC';
				$args['meta_key'] = '_price';
				break;
			case 'price_desc':
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				$args['meta_key'] = '_price';
				break;
			case 'sales':
				$args['orderby']  = 'meta_value_num';
				$args['meta_key'] = 'total_sales';
				break;
			case 'rating':
				$args['orderby']  = 'meta_value_num';
				$args['order']    = 'DESC';
				$args['meta_key'] = '_wc_average_rating';
				break;
		}

		// Taxonomy query to filter products by type, category, tag and attribute.
		$tax_query = array();

		// Filter product type by slug...
		if ( ! empty( $request['type'] ) ) {
			if ( 'variation' === $request['type'] ) {
				$args['post_type'] = 'product_variation';
			} else {
				$tax_query[] = array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $request['type'],
				);
			}
		} else {
			// ... otherwise, check if we are including variations.
			if ( ! empty( $request['include_variations'] ) ) {
				$tax_query[] = array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => 'variation',
					'operator' => 'NOT EXISTS',
				);
			}
		}

		// Set before into date query. Date query must be specified as an array of an array.
		if ( isset( $request['before'] ) ) {
			$args['date_query'][0]['before'] = $request['before'];
		}

		// Set after into date query. Date query must be specified as an array of an array.
		if ( isset( $request['after'] ) ) {
			$args['date_query'][0]['after'] = $request['after'];
		}

		$operator_mapping = array(
			'in'     => 'IN',
			'not_in' => 'NOT IN',
			'and'    => 'AND',
		);

		// Map between taxonomy name and arg key.
		$taxonomies = array(
			'product_cat' => 'category',
			'product_tag' => 'tag',
		);

		// Set tax_query for each passed arg.
		foreach ( $taxonomies as $taxonomy => $key ) {
			if ( ! empty( $request[ $key ] ) ) {
				$operator    = $request[ $key . '_operator' ] && isset( $operator_mapping[ $request[ $key . '_operator' ] ] ) ? $operator_mapping[ $request[ $key . '_operator' ] ] : 'IN';
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => is_numeric( $request[ $key ] ) ? 'term_id' : 'slug',
					'terms'    => $request[ $key ],
					'operator' => $operator,
				);
			}
		}

		// Filter by attributes.
		if ( ! empty( $request['attributes'] ) ) {
			$att_queries = array();

			foreach ( $request['attributes'] as $attribute ) {
				if ( empty( $attribute['term_id'] ) && empty( $attribute['slug'] ) ) {
					continue;
				}

				if ( in_array( $attribute['attribute'], wc_get_attribute_taxonomy_names(), true ) ) {
					$operator      = isset( $attribute['operator'], $operator_mapping[ $attribute['operator'] ] ) ? $operator_mapping[ $attribute['operator'] ] : 'IN';
					$att_queries[] = array(
						'taxonomy' => $attribute['attribute'],
						'field'    => ! empty( $attribute['term_id'] ) ? 'term_id' : 'slug',
						'terms'    => ! empty( $attribute['term_id'] ) ? $attribute['term_id'] : $attribute['slug'],
						'operator' => $operator,
					);
				}
			}

			if ( 1 < count( $att_queries ) ) {
				// Add relation arg when using multiple attributes.
				$relation    = $request['attribute_relation'] && isset( $operator_mapping[ $request['attribute_relation'] ] ) ? $operator_mapping[ $request['attribute_relation'] ] : 'IN';
				$tax_query[] = array(
					'relation' => $relation,
					$att_queries,
				);
			} else {
				$tax_query = array_merge( $tax_query, $att_queries );
			}
		}

		// Build tax_query if taxonomies are set.
		if ( ! empty( $tax_query ) ) {
			if ( ! empty( $args['tax_query'] ) ) {
				$args['tax_query'] = array_merge( $tax_query, $args['tax_query'] );
			} else {
				$args['tax_query'] = $tax_query;
			}
		}

		// Hide free products.
		if ( ! empty( $request['hide_free'] ) ) {
			$args['meta_query'] = $this->add_meta_query(
				$args,
				array(
					'key'     => '_price',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'DECIMAL',
				)
			);
		}

		// Filter featured.
		if ( is_bool( $request['featured'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => 'featured',
				'operator' => true === $request['featured'] ? 'IN' : 'NOT IN',
			);
		}

		// Filter by sku.
		if ( function_exists( 'wc_product_sku_enabled' ) && wc_product_sku_enabled() ) {
			if ( ! empty( $request['sku'] ) ) {
				$skus = explode( ',', $request['sku'] );

				// Include the current string as a SKU too.
				if ( 1 < count( $skus ) ) {
					$skus[] = $request['sku'];
				}

				$args['meta_query'] = $this->add_meta_query( // WPCS: slow query ok.
					$args,
					array(
						'key'     => '_sku',
						'value'   => $skus,
						'compare' => 'IN',
					)
				);
			}
		}

		// Price filter.
		if ( ! empty( $request['min_price'] ) || ! empty( $request['max_price'] ) ) {
			$args['meta_query'] = $this->add_meta_query( $args, cocart_get_min_max_price_meta_query( $request ) ); // WPCS: slow query ok.
		}

		// Filter product in stock or out of stock.
		if ( is_bool( $request['stock_status'] ) ) {
			$args['meta_query'] = $this->add_meta_query( // WPCS: slow query ok.
				$args,
				array(
					'key'   => '_stock_status',
					'value' => true === $request['stock_status'] ? 'instock' : 'outofstock',
				)
			);
		}

		// Filter by on sale products.
		if ( is_bool( $request['on_sale'] ) ) {
			$on_sale_key = $request['on_sale'] ? 'post__in' : 'post__not_in';
			$on_sale_ids = wc_get_product_ids_on_sale();

			// Use 0 when there's no on sale products to avoid return all products.
			$on_sale_ids = empty( $on_sale_ids ) ? array( 0 ) : $on_sale_ids;

			$args[ $on_sale_key ] = $on_sale_ids;
		}

		// Filter by Catalog Visibility
		$catalog_visibility = $request->get_param( 'catalog_visibility' );
		$visibility_options = wc_get_product_visibility_options();

		if ( in_array( $catalog_visibility, array_keys( $visibility_options ), true ) ) {
			$exclude_from_catalog = 'search' === $catalog_visibility ? '' : 'exclude-from-catalog';
			$exclude_from_search  = 'catalog' === $catalog_visibility ? '' : 'exclude-from-search';

			$args['tax_query'][] = array(
				'taxonomy'      => 'product_visibility',
				'field'         => 'name',
				'terms'         => array( $exclude_from_catalog, $exclude_from_search ),
				'operator'      => 'hidden' === $catalog_visibility ? 'AND' : 'NOT IN',
				'rating_filter' => true,
			);
		}

		// Filter by Product Rating
		$rating = $request->get_param( 'rating' );

		if ( ! empty( $rating ) ) {
			$rating_terms = array();

			foreach ( $rating as $value ) {
				$rating_terms[] = 'rated-' . $value;
			}

			$args['tax_query'][] = array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => $rating_terms,
			);
		}

		/**
		 * Filter allows you to prepare the objects query.
		 *
		 * @since 3.1.0 Introduced.
		 *
		 * @param array           $args    Arguments for the query.
		 * @param WP_REST_Request $request The request object.
		 */
		$args = apply_filters( 'cocart_prepare_objects_query', $args, $request );

		return $args;
	} // END prepare_objects_query()

	/**
	 * Prepare a single product output for response.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 * @since 4.0.0 Replaced function `get_product_data` with `get_requested_data`.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_object_for_response( $product, $request ) {
		// Check what product type before returning product data.
		if ( $product->get_type() !== 'variation' || ! empty( $request['include_variations'] ) ) {
			$data = $this->get_requested_data( $product, $request );
		} else {
			$data = $this->get_variation_product_data( $product, $request );
		}

		$data = $this->filter_response_by_context( $data, 'view' );

		$response = rest_ensure_response( $data );
		$response->add_links( $this->prepare_links( $product, $request ) );

		/**
		 * Filter the data for a response.
		 *
		 * @since 3.1.0 Introduced.
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WC_Product       $product  The product object.
		 * @param WP_REST_Request  $request  The request object.
		 */
		return apply_filters( "cocart_prepare_{$this->post_type}_object_v2", $response, $product, $request );
	} // END prepare_object_for_response()

	/**
	 * Return the basic of each variation to make it easier
	 * for developers with their UI/UX.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 * @since 4.0.0 Added the request object as parameter.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array $variations Returns the variations.
	 */
	public function get_variations( $product, $request ) {
		$variation_ids    = $product->get_children();
		$tax_display_mode = $this->get_tax_display_mode();
		$price_function   = $this->get_price_from_tax_display_mode( $tax_display_mode );
		$variations       = array();

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			// Hide out of stock variations if 'Hide out of stock items from the catalog' is checked.
			if ( ! $variation || ! $variation->exists() || ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $variation->is_in_stock() ) ) {
				continue;
			}

			// Filter 'woocommerce_hide_invisible_variations' to optionally hide invisible variations (disabled variations and variations with empty price).
			if ( apply_filters( 'woocommerce_hide_invisible_variations', true, $variation_id, $variation ) && ! $variation->variation_is_visible() ) {
				continue;
			}

			$expected_attributes = wc_get_product_variation_attributes( $variation_id );
			$featured_image_id   = $variation->get_image_id();
			$attachment_post     = get_post( $featured_image_id );
			$attachment_sizes    = Helpers::get_product_image_sizes();

			// Get each image size of the attachment.
			foreach ( $attachment_sizes as $size ) {
				$attachments[ $size ] = current( wp_get_attachment_image_src( $featured_image_id, $size ) );
			}

			$date_on_sale_from = $variation->get_date_on_sale_from( 'view' );
			$date_on_sale_to   = $variation->get_date_on_sale_to( 'view' );

			$variations[] = array(
				'id'             => $variation_id,
				'sku'            => $variation->get_sku( 'view' ),
				'description'    => $variation->get_description( 'view' ),
				'attributes'     => $expected_attributes,
				'featured_image' => $attachments,
				'prices'         => array(
					'price'         => MonetaryFormatting::format_money( $price_function( $variation ), $request ),
					'regular_price' => MonetaryFormatting::format_money( $price_function( $variation, array( 'price' => $variation->get_regular_price() ) ), $request ),
					'sale_price'    => $variation->get_sale_price( 'view' ) ? MonetaryFormatting::format_money( $price_function( $variation, array( 'price' => $variation->get_sale_price() ) ), $request ) : '',
					'on_sale'       => $variation->is_on_sale( 'view' ),
					'date_on_sale'  => array(
						'from'     => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ), false ) : null,
						'from_gmt' => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ) ) : null,
						'to'       => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ), false ) : null,
						'to_gmt'   => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ) ) : null,
					),
					'currency'      => cocart_get_store_currency(),
				),
				'add_to_cart'    => array(
					'is_purchasable'    => $variation->is_purchasable(),
					'purchase_quantity' => array(
						'min_purchase' => Helpers::get_quantity_minimum_requirement( $variation ),
						'max_purchase' => Helpers::get_quantity_maximum_allowed( $variation ),
					),
					'rest_url'          => $this->add_to_cart_rest_url( $variation, $variation->get_type() ),
				),
			);
		}

		return $variations;
	} // END get_variations()

	/**
	 * Get a single item.
	 *
	 * @throws CoCart_Data_Exception Exception if invalid data is detected.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		try {
			$product_id = ! isset( $request['id'] ) ? 0 : wc_clean( wp_unslash( $request['id'] ) );

			// If the product ID was used by a SKU ID, then look up the product ID and return it.
			if ( ! is_numeric( $product_id ) ) {
				$product_id_by_sku = wc_get_product_id_by_sku( $product_id );

				if ( ! empty( $product_id_by_sku ) && $product_id_by_sku > 0 ) {
					$product_id = $product_id_by_sku;
				} else {
					$message = __( 'Product does not exist! Check that you have submitted a product ID or SKU ID correctly for a product that exists.', 'cart-rest-api-for-woocommerce' );

					throw new CoCart_Data_Exception( 'cocart_unknown_product_id', $message, 404 );
				}
			}

			// Force product ID to be integer.
			$product_id = (int) $product_id;

			$_product = wc_get_product( $product_id );

			if ( ! $_product || 0 === $_product->get_id() ) {
				throw new CoCart_Data_Exception( 'cocart_' . $this->post_type . '_invalid_id', __( 'Invalid ID.', 'cart-rest-api-for-woocommerce' ), 404 );
			}

			$data     = $this->prepare_object_for_response( $_product, $request );
			$response = rest_ensure_response( $data );

			return $response;
		} catch ( CoCart_Data_Exception $e ) {
			return CoCart_Response::get_error_response( $e->getErrorCode(), $e->getMessage(), $e->getCode(), $e->getAdditionalData() );
		}
	} // END get_item()

	/**
	 * Get product data.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WC_Product $product The product object.
	 *
	 * @return array $data Product data.
	 */
	protected function get_product_data( $product ) {
		cocart_deprecated_function( 'CoCart_REST_Products_V2_Controller::get_product_data', '4.0', 'get_requested_data' );

		$type         = $product->get_type();
		$rating_count = $product->get_rating_count( 'view' );
		$average      = $product->get_average_rating( 'view' );

		$tax_display_mode = $this->get_tax_display_mode();
		$price_function   = $this->get_price_from_tax_display_mode( $tax_display_mode );

		// If we have a variable product, get the price from the variations (this will use the min value).
		if ( $product->is_type( 'variable' ) ) {
			$regular_price = $product->get_variation_regular_price();
			$sale_price    = $product->get_variation_sale_price();
		} else {
			$regular_price = $product->get_regular_price();
			$sale_price    = $product->get_sale_price();
		}

		// Provide purchase quantity if not a variable or external product type.
		$purchase_quantity = array();

		if ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'external' ) ) {
			$purchase_quantity = array(
				'min_purchase' => Helpers::get_quantity_minimum_requirement( $product ),
				'max_purchase' => Helpers::get_quantity_maximum_allowed( $product ),
			);
		}

		$date_created      = $product->get_date_created( 'view' );
		$date_modified     = $product->get_date_modified( 'view' );
		$date_on_sale_from = $product->get_date_on_sale_from( 'view' );
		$date_on_sale_to   = $product->get_date_on_sale_to( 'view' );

		$data = array(
			'id'                 => $product->get_id(),
			'parent_id'          => $product->get_parent_id( 'view' ),
			'name'               => $product->get_name( 'view' ),
			'type'               => $type,
			'slug'               => $product->get_slug( 'view' ),
			'permalink'          => cocart_get_permalink( $product->get_permalink() ),
			'sku'                => $product->get_sku( 'view' ),
			'description'        => $product->get_description( 'view' ),
			'short_description'  => $product->get_short_description( 'view' ),
			'dates'              => array(
				'created'      => cocart_prepare_date_response( $date_created->date( 'Y-m-d\TH:i:s' ), false ),
				'created_gmt'  => cocart_prepare_date_response( $date_created->date( 'Y-m-d\TH:i:s' ) ),
				'modified'     => cocart_prepare_date_response( $date_modified->date( 'Y-m-d\TH:i:s' ), false ),
				'modified_gmt' => cocart_prepare_date_response( $date_modified->date( 'Y-m-d\TH:i:s' ) ),
			),
			'featured'           => $product->is_featured(),
			'prices'             => array(
				'price'         => cocart_prepare_money_response( $price_function( $product ) ),
				'regular_price' => cocart_prepare_money_response( $price_function( $product, array( 'price' => $regular_price ) ) ),
				'sale_price'    => $product->get_sale_price( 'view' ) ? cocart_prepare_money_response( $price_function( $product, array( 'price' => $sale_price ) ) ) : '',
				'price_range'   => Helpers::get_price_range( $product, $tax_display_mode ),
				'on_sale'       => $product->is_on_sale( 'view' ),
				'date_on_sale'  => array(
					'from'     => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ), false ) : null,
					'from_gmt' => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ) ) : null,
					'to'       => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ), false ) : null,
					'to_gmt'   => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ) ) : null,
				),
				'currency'      => cocart_get_store_currency(),
			),
			'hidden_conditions'  => array(
				'virtual'           => $product->is_virtual(),
				'downloadable'      => $product->is_downloadable(),
				'manage_stock'      => $product->managing_stock(),
				'sold_individually' => $product->is_sold_individually(),
				'reviews_allowed'   => $product->get_reviews_allowed( 'view' ),
				'shipping_required' => $product->needs_shipping(),
			),
			'average_rating'     => $average,
			'review_count'       => $product->get_review_count( 'view' ),
			'rating_count'       => $rating_count,
			'rated_out_of'       => html_entity_decode( wp_strip_all_tags( wc_get_rating_html( $average, $rating_count ) ) ),
			'images'             => Helpers::get_images( $product ),
			'categories'         => $this->get_taxonomy_terms( $product ),
			'tags'               => $this->get_taxonomy_terms( $product, 'tag' ),
			'attributes'         => $this->get_attributes( $product ),
			'default_attributes' => $this->get_default_attributes( $product ),
			'variations'         => array(),
			'grouped_products'   => array(),
			'stock'              => array(
				'is_in_stock'        => $product->is_in_stock(),
				'stock_quantity'     => $product->get_stock_quantity( 'view' ),
				'stock_status'       => $product->get_stock_status( 'view' ),
				'backorders'         => $product->get_backorders( 'view' ),
				'backorders_allowed' => $product->backorders_allowed(),
				'backordered'        => $product->is_on_backorder(),
				'low_stock_amount'   => $product->get_low_stock_amount( 'view' ),
			),
			'weight'             => array(
				'value' => $product->get_weight( 'view' ),
				'unit'  => get_option( 'woocommerce_weight_unit' ),
			),
			'dimensions'         => array(
				'length' => $product->get_length( 'view' ),
				'width'  => $product->get_width( 'view' ),
				'height' => $product->get_height( 'view' ),
				'unit'   => get_option( 'woocommerce_dimension_unit' ),
			),
			'reviews'            => array(),
			'related'            => $this->get_connected_products( $product, 'related' ),
			'upsells'            => $this->get_connected_products( $product, 'upsells' ),
			'cross_sells'        => $this->get_connected_products( $product, 'cross_sells' ),
			'total_sales'        => $product->get_total_sales( 'view' ),
			'external_url'       => $product->is_type( 'external' ) ? $product->get_product_url( 'view' ) : '',
			'button_text'        => $product->is_type( 'external' ) ? $product->get_button_text( 'view' ) : '',
			'add_to_cart'        => array(
				'text'              => $product->add_to_cart_text(),
				'description'       => $product->add_to_cart_description(),
				'has_options'       => $product->has_options(),
				'is_purchasable'    => $product->is_purchasable(),
				'purchase_quantity' => $purchase_quantity,
				'rest_url'          => $this->add_to_cart_rest_url( $product, $type ),
			),
			'meta_data'          => $product->get_meta_data(),
		);

		return $data;
	} // END get_product_data()

	/**
	 * Get requested product data.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param WC_Product      $product The product object.
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array $data Product data.
	 */
	protected function get_requested_data( $product, $request ) {
		$type         = $product->get_type();
		$rating_count = $product->get_rating_count( 'view' );
		$average      = $product->get_average_rating( 'view' );

		$tax_display_mode = $this->get_tax_display_mode();
		$price_function   = $this->get_price_from_tax_display_mode( $tax_display_mode );

		// If we have a variable product, get the price from the variations (this will use the min value).
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variable-subscription' ) ) {
			$regular_price = $product->get_variation_regular_price();
			$sale_price    = $product->get_variation_sale_price();
		} else {
			$regular_price = $product->get_regular_price();
			$sale_price    = $product->get_sale_price();
		}

		// Provide purchase quantity if not a variable or external product type.
		$purchase_quantity = array();

		if ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'variable-subscription' ) && ! $product->is_type( 'external' ) ) {
			$purchase_quantity = array(
				'min_purchase' => Helpers::get_quantity_minimum_requirement( $product ),
				'max_purchase' => Helpers::get_quantity_maximum_allowed( $product ),
			);
		}

		$schema            = $this->get_public_item_schema();
		$default_fields    = Fields::get_response_from_fields( $request );
		$additional_fields = $this->get_additional_fields();
		$fields            = Fields::get_fields_for_request( $request, $schema, $default_fields, $additional_fields );
		$exclude_fields    = Fields::get_excluded_fields_for_response( $request, $schema );

		// Product data response container.
		$product_data = array();

		if ( cocart_is_field_included( 'id', $fields, $exclude_fields ) ) {
			$product_data['id'] = $product->get_id();
		}

		if ( cocart_is_field_included( 'parent_id', $fields, $exclude_fields ) ) {
			$product_data['parent_id'] = $product->get_parent_id( 'view' );
		}

		if ( cocart_is_field_included( 'name', $fields, $exclude_fields ) ) {
			$product_data['name'] = $product->get_name( 'view' );
		}

		if ( cocart_is_field_included( 'type', $fields, $exclude_fields ) ) {
			$product_data['type'] = $type;
		}

		if ( cocart_is_field_included( 'slug', $fields, $exclude_fields ) ) {
			$product_data['slug'] = $product->get_slug( 'view' );
		}

		if ( cocart_is_field_included( 'permalink', $fields, $exclude_fields ) ) {
			$product_data['permalink'] = cocart_get_permalink( $product->get_permalink() );
		}

		if ( cocart_is_field_included( 'sku', $fields, $exclude_fields ) ) {
			$product_data['sku'] = $product->get_sku( 'view' );
		}

		if ( cocart_is_field_included( 'description', $fields, $exclude_fields ) ) {
			$product_data['description'] = $product->get_description( 'view' );
		}

		if ( cocart_is_field_included( 'short_description', $fields, $exclude_fields ) ) {
			$product_data['short_description'] = $product->get_short_description( 'view' );
		}

		if ( cocart_is_field_included( 'dates', $fields, $exclude_fields ) ) {
			$date_created  = $product->get_date_created( 'view' );
			$date_modified = $product->get_date_modified( 'view' );

			$product_data['dates'] = array(
				'created'      => cocart_prepare_date_response( $date_created->date( 'Y-m-d\TH:i:s' ), false ),
				'created_gmt'  => cocart_prepare_date_response( $date_created->date( 'Y-m-d\TH:i:s' ) ),
				'modified'     => cocart_prepare_date_response( $date_modified->date( 'Y-m-d\TH:i:s' ), false ),
				'modified_gmt' => cocart_prepare_date_response( $date_modified->date( 'Y-m-d\TH:i:s' ) ),
			);
		}

		if ( cocart_is_field_included( 'featured', $fields, $exclude_fields ) ) {
			$product_data['featured'] = $product->is_featured();
		}

		if ( cocart_is_field_included( 'prices', $fields, $exclude_fields ) ) {
			$date_on_sale_from = $product->get_date_on_sale_from( 'view' );
			$date_on_sale_to   = $product->get_date_on_sale_to( 'view' );

			$product_data['prices'] = array(
				'price'         => MonetaryFormatting::format_money( $price_function( $product ), $request ),
				'regular_price' => MonetaryFormatting::format_money( $price_function( $product, array( 'price' => $regular_price ) ), $request ),
				'sale_price'    => $product->get_sale_price( 'view' ) ? MonetaryFormatting::format_money( $price_function( $product, array( 'price' => $sale_price ) ), $request ) : '',
				'price_range'   => Helpers::get_price_range( $product, $tax_display_mode, $request ),
				'on_sale'       => $product->is_on_sale( 'view' ),
				'date_on_sale'  => array(
					'from'     => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ), false ) : null,
					'from_gmt' => ! is_null( $date_on_sale_from ) ? cocart_prepare_date_response( $date_on_sale_from->date( 'Y-m-d\TH:i:s' ) ) : null,
					'to'       => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ), false ) : null,
					'to_gmt'   => ! is_null( $date_on_sale_to ) ? cocart_prepare_date_response( $date_on_sale_to->date( 'Y-m-d\TH:i:s' ) ) : null,
				),
				'currency'      => cocart_get_store_currency(),
			);
		}

		if ( cocart_is_field_included( 'hidden_conditions', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions'] = array();
		}
		if ( cocart_is_field_included( 'hidden_conditions.virtual', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['virtual'] = $product->is_virtual();
		}
		if ( cocart_is_field_included( 'hidden_conditions.downloadable', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['downloadable'] = $product->is_downloadable();
		}
		if ( cocart_is_field_included( 'hidden_conditions.manage_stock', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['manage_stock'] = $product->managing_stock();
		}
		if ( cocart_is_field_included( 'hidden_conditions.sold_individually', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['sold_individually'] = $product->is_sold_individually();
		}
		if ( cocart_is_field_included( 'hidden_conditions.reviews_allowed', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['reviews_allowed'] = $product->get_reviews_allowed( 'view' );
		}
		if ( cocart_is_field_included( 'hidden_conditions.shipping_required', $fields, $exclude_fields ) ) {
			$product_data['hidden_conditions']['shipping_required'] = $product->needs_shipping();
		}

		if ( cocart_is_field_included( 'average_rating', $fields, $exclude_fields ) ) {
			$product_data['average_rating'] = $average;
		}

		if ( cocart_is_field_included( 'review_count', $fields, $exclude_fields ) ) {
			$product_data['review_count'] = $product->get_review_count( 'view' );
		}

		if ( cocart_is_field_included( 'rating_count', $fields, $exclude_fields ) ) {
			$product_data['rating_count'] = $rating_count;
		}

		if ( cocart_is_field_included( 'rated_out_of', $fields, $exclude_fields ) ) {
			$product_data['rated_out_of'] = html_entity_decode( wp_strip_all_tags( wc_get_rating_html( $average, $rating_count ) ) );
		}

		if ( cocart_is_field_included( 'images', $fields, $exclude_fields ) ) {
			$product_data['images'] = Helpers::get_images( $product );
		}

		if ( cocart_is_field_included( 'categories', $fields, $exclude_fields ) ) {
			$product_data['categories'] = $this->get_taxonomy_terms( $product );
		}

		if ( cocart_is_field_included( 'tags', $fields, $exclude_fields ) ) {
			$product_data['tags'] = $this->get_taxonomy_terms( $product, 'tag' );
		}

		if ( cocart_is_field_included( 'attributes', $fields, $exclude_fields ) ) {
			$product_data['attributes'] = $this->get_attributes( $product );
		}

		if ( cocart_is_field_included( 'default_attributes', $fields, $exclude_fields ) ) {
			$product_data['default_attributes'] = $this->get_default_attributes( $product );
		}

		if ( cocart_is_field_included( 'variations', $fields, $exclude_fields ) ) {
			$product_data['variations'] = ( $product->is_type( 'variable' ) && $product->has_child() ) || ( $product->is_type( 'variable-subscription' ) && $product->has_child() ) ? $this->get_variations( $product, $request ) : array();
		}

		if ( cocart_is_field_included( 'grouped_products', $fields, $exclude_fields ) ) {
			$product_data['grouped_products'] = ( $product->is_type( 'grouped' ) && $product->has_child() ) ? $product->get_children() : array();
		}

		if ( cocart_is_field_included( 'stock', $fields, $exclude_fields ) ) {
			$product_data['stock'] = array();
		}
		if ( cocart_is_field_included( 'stock.is_in_stock', $fields, $exclude_fields ) ) {
			$product_data['stock']['is_in_stock'] = $product->is_in_stock();
		}
		if ( cocart_is_field_included( 'stock.stock_quantity', $fields, $exclude_fields ) ) {
			$product_data['stock']['stock_quantity'] = $product->get_stock_quantity( 'view' );
		}
		if ( cocart_is_field_included( 'stock.status', $fields, $exclude_fields ) ) {
			$product_data['stock']['stock_status'] = $product->get_stock_status( 'view' );
		}
		if ( cocart_is_field_included( 'stock.backorders', $fields, $exclude_fields ) ) {
			$product_data['stock']['backorders'] = $product->get_backorders( 'view' );
		}
		if ( cocart_is_field_included( 'stock.backorders_allowed', $fields, $exclude_fields ) ) {
			$product_data['stock']['backorders_allowed'] = $product->backorders_allowed();
		}
		if ( cocart_is_field_included( 'stock.backordered', $fields, $exclude_fields ) ) {
			$product_data['stock']['backordered'] = $product->is_on_backorder();
		}
		if ( cocart_is_field_included( 'stock.low_stock_amount', $fields, $exclude_fields ) ) {
			$product_data['stock']['low_stock_amount'] = $product->get_low_stock_amount( 'view' );
		}

		if ( cocart_is_field_included( 'weight', $fields, $exclude_fields ) ) {
			$product_data['weight'] = array(
				'value' => $product->get_weight( 'view' ),
				'unit'  => get_option( 'woocommerce_weight_unit' ),
			);
		}

		if ( cocart_is_field_included( 'dimensions', $fields, $exclude_fields ) ) {
			$product_data['dimensions'] = array(
				'length' => $product->get_length( 'view' ),
				'width'  => $product->get_width( 'view' ),
				'height' => $product->get_height( 'view' ),
				'unit'   => get_option( 'woocommerce_dimension_unit' ),
			);
		}

		if ( cocart_is_field_included( 'reviews', $fields, $exclude_fields ) ) {
			// Add review data to products if requested.
			$product_data['reviews'] = isset( $request['show_reviews'] ) ? $this->get_reviews( $product ) : array();
		}

		if ( cocart_is_field_included( 'related', $fields, $exclude_fields ) ) {
			$product_data['related'] = $this->get_connected_products( $product, 'related', $request );
		}

		if ( cocart_is_field_included( 'upsells', $fields, $exclude_fields ) ) {
			$product_data['upsells'] = $this->get_connected_products( $product, 'upsells', $request );
		}

		if ( cocart_is_field_included( 'cross_sells', $fields, $exclude_fields ) ) {
			$product_data['cross_sells'] = $this->get_connected_products( $product, 'cross_sells', $request );
		}

		if ( cocart_is_field_included( 'total_sales', $fields, $exclude_fields ) ) {
			$product_data['total_sales'] = $product->get_total_sales( 'view' );
		}

		if ( cocart_is_field_included( 'external_url', $fields, $exclude_fields ) ) {
			$product_data['external_url'] = $product->is_type( 'external' ) ? $product->get_product_url( 'view' ) : '';
		}

		if ( cocart_is_field_included( 'button_text', $fields, $exclude_fields ) ) {
			$product_data['button_text'] = $product->is_type( 'external' ) ? $product->get_button_text( 'view' ) : '';
		}

		if ( cocart_is_field_included( 'add_to_cart', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart'] = array();
		}
		if ( cocart_is_field_included( 'add_to_cart.text', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['text'] = $product->add_to_cart_text();
		}
		if ( cocart_is_field_included( 'add_to_cart.description', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['description'] = $product->add_to_cart_description();
		}
		if ( cocart_is_field_included( 'add_to_cart.has_options', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['has_options'] = $product->has_options();
		}
		if ( cocart_is_field_included( 'add_to_cart.is_purchasable', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['is_purchasable'] = $product->is_purchasable();
		}
		if ( cocart_is_field_included( 'add_to_cart.purchase_quantity', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['purchase_quantity'] = $purchase_quantity;
		}
		if ( cocart_is_field_included( 'add_to_cart.rest_url', $fields, $exclude_fields ) ) {
			$product_data['add_to_cart']['rest_url'] = $this->add_to_cart_rest_url( $product, $type );
		}

		if ( cocart_is_field_included( 'meta_data', $fields, $exclude_fields ) ) {
			$product_data['meta_data'] = $this->get_meta_data( $product );
		}

		return $product_data;
	} // END get_requested_data()

	/**
	 * Get variation product data.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WC_Variation_Product $product The product object.
	 * @param WP_REST_Request      $request The request object.
	 *
	 * @return array $data Product data.
	 */
	protected function get_variation_product_data( $product, $request = array() ) {
		$data = self::get_requested_data( $product, $request );

		$fields_not_required = array(
			'type',
			'short_description',
			'hidden_conditions' => array( 'reviews_allowed' ),
			'average_rating',
			'review_count',
			'rating_count',
			'rated_out_of',
			'reviews',
			'default_attributes',
			'variations',
			'grouped_products',
			'related',
			'upsells',
			'cross_sells',
			'external_url',
			'button_text',
			'add_to_cart'       => array( 'has_options' ),
		);

		// Remove fields not required for a variation.
		foreach ( $fields_not_required as $key => $field ) {
			if ( is_array( $field ) && ! empty( $field ) ) {
				foreach ( $field as $child_field ) {
					unset( $data[ $key ][ $child_field ] );
				}
			} else {
				unset( $data[ $field ] );
			}
		}

		return $data;
	} // END get_variation_product_data()

	/**
	 * Get taxonomy terms.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WC_Product $product  The product object.
	 * @param string     $taxonomy Taxonomy slug.
	 *
	 * @return array $terms Taxonomy terms.
	 */
	protected function get_taxonomy_terms( $product, $taxonomy = 'cat' ) {
		$terms = array();

		foreach ( wc_get_object_terms( $product->get_id(), 'product_' . $taxonomy ) as $term ) {
			$terms[] = array(
				'id'       => $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'rest_url' => $this->product_rest_url( $term->term_id, $taxonomy ),
			);
		}

		return $terms;
	} // END get_taxonomy_terms()

	/**
	 * Get attribute options.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $attribute  Attribute data.
	 *
	 * @return array $attributes Attribute options.
	 */
	protected function get_attribute_options( $product_id, $attribute ) {
		$attributes = array();

		if ( isset( $attribute['is_taxonomy'] ) && $attribute['is_taxonomy'] ) {
			$terms = wc_get_product_terms(
				$product_id,
				$attribute['name'],
				array(
					'fields' => 'all',
				)
			);

			foreach ( $terms as $term ) {
				$attributes[ $term->slug ] = $term->name;
			}
		} elseif ( isset( $attribute['value'] ) ) {
			$options = explode( '|', $attribute['value'] );

			foreach ( $options as $attribute ) {
				$slug                = trim( $attribute );
				$attributes[ $slug ] = trim( $attribute );
			}
		}

		return $attributes;
	} // END get_attribute_options()

	/**
	 * Get the attributes for a product or product variation.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WC_Product|WC_Product_Variation $product The product object.
	 *
	 * @return array $attributes Attributes data.
	 */
	protected function get_attributes( $product ) {
		$attributes = array();

		if ( $product->is_type( 'variation' ) || $product->is_type( 'subscription_variation' ) ) {
			$_product = wc_get_product( $product->get_parent_id() );

			foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {
				$name = str_replace( 'attribute_', '', $attribute_name );

				if ( ! $attribute ) {
					continue;
				}

				// Taxonomy-based attributes are prefixed with `pa_`, otherwise simply `attribute_`.
				if ( 0 === strpos( $attribute_name, 'attribute_pa_' ) ) {
					$option_term = get_term_by( 'slug', $attribute, $name );

					$attributes[ 'attribute_' . $name ] = array(
						'id'     => wc_attribute_taxonomy_id_by_name( $name ),
						'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
						'option' => $option_term && ! is_wp_error( $option_term ) ? array( $option_term->slug => $option_term->name ) : array( $attribute => $attribute ),
					);
				} else {
					$attributes[ 'attribute_' . $name ] = array(
						'id'     => 0,
						'name'   => $this->get_attribute_taxonomy_name( $name, $_product ),
						'option' => array( $attribute => $attribute ),
					);
				}
			}
		} else {
			foreach ( $product->get_attributes() as $attribute ) {
				$attribute_id = 'attribute_' . str_replace( ' ', '-', strtolower( $attribute['name'] ) );

				$attributes[ $attribute_id ] = array(
					'id'                   => $attribute['is_taxonomy'] ? wc_attribute_taxonomy_id_by_name( $attribute['name'] ) : 0,
					'name'                 => $this->get_attribute_taxonomy_name( $attribute['name'], $product ),
					'position'             => (int) $attribute['position'],
					'is_attribute_visible' => (bool) $attribute['is_visible'],
					'used_for_variation'   => (bool) $attribute['is_variation'],
					'options'              => $this->get_attribute_options( $product->get_id(), $attribute ),
				);
			}
		}

		return $attributes;
	} // END get_attributes()

	/**
	 * Get minimum details on connected products.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 * @since 4.0.0 Added the request object as parameter.
	 *
	 * @param WC_Product      $product The product object.
	 * @param string          $type    Type of products to return.
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return array $connected_products Product data.
	 */
	public function get_connected_products( $product, $type, $request ) {
		switch ( $type ) {
			case 'upsells':
				$ids = array_map( 'absint', $product->get_upsell_ids( 'view' ) );
				break;
			case 'cross_sells':
				$ids = array_map( 'absint', $product->get_cross_sell_ids( 'view' ) );
				break;
			case 'related':
			default:
				$ids = array_map( 'absint', array_values( wc_get_related_products( $product->get_id(), apply_filters( 'cocart_products_get_related_products_limit', 5 ) ) ) );
				break;
		}

		$connected_products = array();

		// Proceed if we have product ID's.
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$_product = wc_get_product( $id );

				// If product exists, fetch product data.
				if ( $_product ) {
					$type = $_product->get_type();

					$connected_products[] = array(
						'id'          => $id,
						'name'        => $_product->get_name( 'view' ),
						'permalink'   => cocart_get_permalink( $_product->get_permalink() ),
						'price'       => MonetaryFormatting::format_money( $_product->get_price( 'view' ), $request ),
						'add_to_cart' => array(
							'text'        => $_product->add_to_cart_text(),
							'description' => $_product->add_to_cart_description(),
							'rest_url'    => $this->add_to_cart_rest_url( $_product, $type ),
						),
						'rest_url'    => $this->product_rest_url( $id ),
					);
				}
			}
		}

		return $connected_products;
	} // END get_connected_products()

	/**
	 * Returns the REST URL for a specific product or taxonomy.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param int    $id       Product ID or Taxonomy ID.
	 * @param string $taxonomy Taxonomy type.
	 *
	 * @return string
	 */
	public function product_rest_url( $id, $taxonomy = '' ) {
		if ( ! empty( $taxonomy ) ) {
			switch ( $taxonomy ) {
				case 'cat':
					$route = '/%s/products/categories/%s';
					break;
				case 'tag':
					$route = '/%s/products/tags/%s';
					break;
			}
		} else {
			$route = '/%s/products/%s';
		}

		return rest_url( sprintf( $route, $this->namespace, $id ) );
	} // END product_rest_url()

	/**
	 * Returns an Array of REST URLs for each ID.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param array $ids Product ID's.
	 *
	 * @return array $urls Array of REST URLs.
	 */
	public function product_rest_urls( $ids = array() ) {
		$rest_urls = array();

		foreach ( $ids as $id ) {
			$rest_urls[] = $this->product_rest_url( $id );
		}

		return $rest_urls;
	} // END product_rest_urls()

	/**
	 * Returns the REST URL for adding product to the cart.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param WC_Product $product The product object.
	 * @param string     $type    Product type.
	 *
	 * @return string $rest_url REST URL for adding product to the cart.
	 */
	public function add_to_cart_rest_url( WC_Product $product, $type ) {
		$id = $product->get_id();

		$rest_url = rest_url( sprintf( '/%s/cart/add-item?id=%d', $this->namespace, $id ) );
		$rest_url = add_query_arg( 'quantity', 1, $rest_url ); // Default Quantity = 1.

		switch ( $type ) {
			case 'variation':
			case 'subscription_variation':
				$_product = wc_get_product( $product->get_parent_id() );

				foreach ( $product->get_variation_attributes() as $attribute_name => $attribute ) {
					$name = str_replace( 'attribute_', '', $attribute_name );

					if ( ! $attribute ) {
						continue;
					}

					$rest_url = add_query_arg( array(
						"variation[attribute_$name]" => $attribute,
					), $rest_url );
				}

				$rest_url = urldecode( html_entity_decode( $rest_url ) );
				break;
			case 'variable':
			case 'variable-subscription':
			case 'external':
			case 'grouped':
				$rest_url = ''; // Return nothing for these product types.
				break;
			default:
				/**
				 * Filters the REST URL shortcut for adding the product to cart.
				 *
				 * @since 3.1.0 Introduced.
				 *
				 * @param string     $rest_url REST URL for adding product to the cart.
				 * @param WC_Product $product  The product object.
				 * @param string     $type     Product type
				 * @param int        $id       Product ID
				 */
				$rest_url = apply_filters( 'cocart_products_add_to_cart_rest_url', $rest_url, $product, $type, $id );
				break;
		}

		return $rest_url;
	} // END add_to_cart_rest_url()

	/**
	 * WooCommerce can return prices including or excluding tax.
	 * Choose the correct method based on tax display mode.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param string $tax_display_mode Provided tax display mode.
	 *
	 * @return string Valid tax display mode.
	 */
	protected function get_tax_display_mode( $tax_display_mode = '' ) {
		return in_array( $tax_display_mode, array( 'incl', 'excl' ), true ) ? $tax_display_mode : get_option( 'woocommerce_tax_display_shop' );
	} // END get_tax_display_mode()

	/**
	 * WooCommerce can return prices including or excluding tax.
	 * Choose the correct method based on tax display mode.
	 *
	 * @access protected
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @param string $tax_display_mode If returned prices are incl or excl of tax.
	 *
	 * @return string Function name.
	 */
	protected function get_price_from_tax_display_mode( $tax_display_mode ) {
		return 'incl' === $tax_display_mode ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
	} // END get_price_from_tax_display_mode()

	/**
	 * Get all products taxonomy terms.
	 *
	 * Stores taxonomies as a transient for a whole day to cache for performance.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $page_num Page number.
	 * @param int    $offset   Offset.
	 *
	 * @return array Array of taxonomy terms.
	 */
	public function get_all_product_taxonomies( $taxonomy = 'cat', $page_num = '', $offset = '' ) {
		$terms = get_transient( 'cocart_products_taxonomies_' . $taxonomy );

		if ( empty( $terms ) ) {
			$terms = array();

			$all_terms = get_terms( array(
				'taxonomy'   => 'product_' . $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => $page_num,
				'offset'     => $offset,
			) );

			foreach ( $all_terms as $term ) {
				$terms[] = array(
					'id'       => $term->term_id,
					'name'     => $term->name,
					'slug'     => $term->slug,
					'rest_url' => $this->product_rest_url( $term->term_id, $taxonomy ),
				);
			}

			set_transient( 'cocart_products_taxonomies_' . $taxonomy, $terms, DAY_IN_SECONDS );
		}

		return $terms;
	} // END get_all_product_taxonomies()

	/**
	 * Gets the product meta data.
	 *
	 * @access public
	 *
	 * @since 3.11.0 Introduced.
	 *
	 * @param WC_Product $product The product object.
	 *
	 * @return array
	 */
	public function get_meta_data( $product, $request ) {
		$meta_data = $product->get_meta_data();

		/**
		 * Filter the meta data based on certain request parameters.
		 *
		 * @since 4.0.0 Introduced.
		 */
		$meta_data = $this->get_meta_data_for_response( $request, $meta_data );

		$safe_meta = array();

		/**
		 * Filter allows you to ignore private meta data for the product to return.
		 *
		 * When filtering, only list the meta key!
		 *
		 * @since 3.11.0 Introduced.
		 *
		 * @param WC_Product $product The product object.
		 */
		$ignore_private_meta_keys = apply_filters( 'cocart_products_ignore_private_meta_keys', array(), $product );

		foreach ( $meta_data as $meta ) {
			$ignore_meta = false;

			foreach ( $ignore_private_meta_keys as $ignore ) {
				if ( str_starts_with( $meta->key, $ignore ) ) {
					$ignore_meta = true;
					break; // Exit the inner loop once a match is found.
				}
			}

			/**
			 * Filter by default will skip any meta data that contains an email address as a value.
			 *
			 * @since 4.0.0 Introduced.
			 *
			 * @param object $meta Meta data
			 */
			if ( apply_filters( 'cocart_products_meta_skip_email_values', true, $meta ) && is_email( trim( $meta->value ) ) ) {
				$ignore_meta = true;
			}

			// Add meta data only if it's not ignored.
			if ( ! $ignore_meta ) {
				$safe_meta[ $meta->key ] = $meta;
			}
		}

		/**
		 * Filter allows you to control what remaining product meta data is safe to return.
		 *
		 * @since 3.11.0 Introduced.
		 *
		 * @param WC_Product $product The product object.
		 */
		return array_values( apply_filters( 'cocart_products_get_safe_meta_data', $safe_meta, $product ) );
	} // END get_meta_data()

	/**
	 * Limit the contents of the meta_data property based on certain request parameters.
	 *
	 * Note that if both `include_meta` and `exclude_meta` are present in the request,
	 * `include_meta` will take precedence.
	 *
	 * @access protected
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @param WP_REST_Request $request   The request object.
	 * @param array           $meta_data All of the meta data for an object.
	 *
	 * @return array
	 */
	protected function get_meta_data_for_response( $request, $meta_data ) {
		if ( ! in_array( 'meta_data', $this->fields, true ) ) {
			return array();
		}

		$include = (array) $request['include_meta'];
		$exclude = (array) $request['exclude_meta'];

		if ( ! empty( $include ) ) {
			$meta_data = array_filter(
				$meta_data,
				function( WC_Meta_Data $item ) use ( $include ) {
					$data = $item->get_data();
					return in_array( $data['key'], $include, true );
				}
			);
		} elseif ( ! empty( $exclude ) ) {
			$meta_data = array_filter(
				$meta_data,
				function( WC_Meta_Data $item ) use ( $exclude ) {
					$data = $item->get_data();
					return ! in_array( $data['key'], $exclude, true );
				}
			);
		}

		// Ensure the array indexes are reset so it doesn't get converted to an object in JSON.
		return array_values( $meta_data );
	} // END get_meta_data_for_response()

	/**
	 * Get the query params for collections of products.
	 *
	 * @access public
	 *
	 * @since 4.0.0 Introduced.
	 *
	 * @return array $params
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$defaults = cocart_get_settings( 'products' );

		$params['fields'] = array(
			'description'       => __( 'Specify each parent field you want to request separated by (,) in the response before the data is fetched.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['exclude_fields'] = array(
			'description'       => __( 'Specify each parent field you want to exclude separated by (,) in the response before the data is fetched.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['response'] = array(
			'description'       => __( 'Alternative to setting individual fields, set the default response.', 'cart-rest-api-for-woocommerce' ),
			'default'           => ! empty( $defaults['products_response'] ) ? $defaults['products_response'] : 'default',
			'type'              => 'string',
			'required'          => false,
			'enum'              => array(
				'default',
				'quick_browse',
				'quick_view'
			),
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['prices'] = array(
			'description'       => __( 'Return the price values in the format you prefer.', 'cart-rest-api-for-woocommerce' ),
			'default'           => ! empty( $defaults['products_prices'] ) ? $defaults['products_prices'] : 'raw',
			'type'              => 'string',
			'required'          => false,
			'enum'              => array(
				'raw',
				'formatted'
			),
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['order'] = array(
			'description'       => __( 'Sort collection by order.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'string',
			'enum'              => array(
				'DESC',
				'ASC',
			),
			'default'           => ! empty( $defaults['order'] ) ? $defaults['order'] : 'DESC',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['include_variations'] = array(
			'description'       => __( 'Return product variations without the parent product.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'boolean',
			'default'           => ! empty( $defaults['include_variations'] ) && $defaults['include_variations'] === 'yes' ? true : false,
			'sanitize_callback' => 'wc_string_to_bool',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['include_meta'] = array(
			'default'           => array(),
			'description'       => __( 'Limit meta_data to specific keys.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'string',
			),
			'sanitize_callback' => 'wp_parse_list',
		);

		$params['exclude_meta'] = array(
			'default'           => array(),
			'description'       => __( 'Ensure meta_data excludes specific keys.', 'cart-rest-api-for-woocommerce' ),
			'type'              => 'array',
			'items'             => array(
				'type' => 'string',
			),
			'sanitize_callback' => 'wp_parse_list',
		);

		return $params;
	} // END get_collection_params()

	/**
	 * Retrieves the item’s schema, conforming to JSON Schema.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @return array Product schema data.
	 */
	public function get_item_schema() {
		$weight_unit    = get_option( 'woocommerce_weight_unit' );
		$dimension_unit = get_option( 'woocommerce_dimension_unit' );

		$schema = array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => $this->post_type,
			'type'    => 'object',
		);

		$schema['properties'] = array(
			'id'                 => array(
				'description' => __( 'Unique identifier for the product.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'parent_id'          => array(
				'description' => __( 'ID of the parent product, if applicable.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'name'               => array(
				'description' => __( 'Product name.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'type'               => array(
				'description' => __( 'Product type.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'default'     => 'simple',
				'enum'        => array_keys( wc_get_product_types() ),
				'readonly'    => true,
			),
			'slug'               => array(
				'description' => __( 'Product slug.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'permalink'          => array(
				'description' => __( 'Product permalink.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'sku'                => array(
				'description' => __( 'Unique identifier for the product.', 'cart-rest-api-for-woocommerce' ) . ' (SKU)',
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'description'        => array(
				'description' => __( 'Product description.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'short_description'  => array(
				'description' => __( 'Product short description.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'dates'              => array(
				'description' => __( 'Product dates.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'created'      => array(
						'description' => __( "The date the product was created, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'created_gmt'  => array(
						'description' => __( 'The date the product was created, as GMT.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'modified'     => array(
						'description' => __( "The date the product was last modified, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'modified_gmt' => array(
						'description' => __( 'The date the product was last modified, as GMT.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'date-time',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
				),
				'readonly'    => true,
			),
			'featured'           => array(
				'description' => __( 'Featured product.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'boolean',
				'context'     => array( 'view' ),
				'default'     => false,
				'readonly'    => true,
			),
			'prices'             => array(
				'description' => __( 'Product prices.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'price'         => array(
						'description' => __( 'Product price (currently).', 'cart-rest-api-for-woocommerce' ),
						'type'        => array(
							"integer",
							"string",
						),
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'regular_price' => array(
						'description' => __( 'Product regular price.', 'cart-rest-api-for-woocommerce' ),
						'type'        => array(
							"integer",
							"string",
						),
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'sale_price'    => array(
						'description' => __( 'Product sale price.', 'cart-rest-api-for-woocommerce' ),
						'type'        => array(
							"integer",
							"string",
						),
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'price_range'   => array(
						'description' => __( 'Product price range.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'properties'  => array(
							'from' => array(
								'description' => __( 'Minimum product price range.', 'cart-rest-api-for-woocommerce' ),
								'type'        => array(
									"integer",
									"string",
								),
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'to'   => array(
								'description' => __( 'Maximum product price range.', 'cart-rest-api-for-woocommerce' ),
								'type'        => array(
									"integer",
									"string",
								),
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
						),
						'readonly'    => true,
					),
					'on_sale'       => array(
						'description' => __( 'Shows if the product is on sale.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'date_on_sale'  => array(
						'description' => __( 'Product dates for on sale.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'properties'  => array(
							'from'     => array(
								'description' => __( "Start date of sale price, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
								'type'        => 'date-time',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'from_gmt' => array(
								'description' => __( 'Start date of sale price, as GMT.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'date-time',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'to'       => array(
								'description' => __( "End date of sale price, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
								'type'        => 'date-time',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'to_gmt'   => array(
								'description' => __( 'End date of sale price, as GMT.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'date-time',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
						),
						'readonly'    => true,
					),
					'currency'      => array(
						'description' => __( 'Product currency.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'properties'  => array(
							'currency_code'               => array(
								'description' => __( 'Currency code.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_symbol'             => array(
								'description' => __( 'Currency symbol.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_minor_unit'         => array(
								'description' => __( 'Currency minor unit.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'integer',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_decimal_separator'  => array(
								'description' => __( 'Currency decimal separator.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_thousand_separator' => array(
								'description' => __( 'Currency thousand separator.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_prefix'             => array(
								'description' => __( 'Currency prefix.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
							'currency_suffix'             => array(
								'description' => __( 'Currency suffix.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'string',
								'context'     => array( 'view' ),
								'readonly'    => true,
							),
						),
						'readonly'    => true,
					),
				),
			),
			'hidden_conditions'  => array(
				'description' => __( 'Various hidden conditions.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'virtual'           => array(
						'description' => __( 'Is the product virtual?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'downloadable'      => array(
						'description' => __( 'Is the product downloadable?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'manage_stock'      => array(
						'description' => __( 'Is stock management at product level?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'sold_individually' => array(
						'description' => __( 'Are we limiting to just one of item to be bought in a single order?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'reviews_allowed'   => array(
						'description' => __( 'Are reviews allowed for this product?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => true,
						'readonly'    => true,
					),
					'shipping_required' => array(
						'description' => __( 'Does this product require shipping?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
				),
				'readonly'    => true,
			),
			'average_rating'     => array(
				'description' => __( 'Reviews average rating.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'review_count'       => array(
				'description' => __( 'Amount of reviews that the product has.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'rating_count'       => array(
				'description' => __( 'Rating count for the reviews in total.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'rated_out_of'       => array(
				'description' => __( 'Reviews rated out of 5 on average.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'images'             => array(
				'description' => __( 'List of product images.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'description' => __( 'Image ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'src'      => array(
							'description' => __( 'Image URL source for each attachment size registered.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'properties'  => array(),
							'readonly'    => true,
						),
						'name'     => array(
							'description' => __( 'Image name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'alt'      => array(
							'description' => __( 'Image alternative text.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'position' => array(
							'description' => __( 'Image position.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'featured' => array(
							'description' => __( 'Image set featured?', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'boolean',
							'context'     => array( 'view' ),
							'default'     => false,
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
			'categories'         => array(
				'description' => __( 'List of product categories.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'description' => __( 'Category ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'     => array(
							'description' => __( 'Category name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'slug'     => array(
							'description' => __( 'Category slug.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'rest_url' => array(
							'description' => __( 'The REST URL for viewing this product category.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'format'      => 'uri',
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
			'tags'               => array(
				'description' => __( 'List of product tags.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array(
							'description' => __( 'Tag ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'     => array(
							'description' => __( 'Tag name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'slug'     => array(
							'description' => __( 'Tag slug.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'rest_url' => array(
							'description' => __( 'The REST URL for viewing this product tag.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'format'      => 'uri',
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
			'attributes'         => array(
				'description' => __( 'List of attributes.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                   => array(
							'description' => __( 'Attribute ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'                 => array(
							'description' => __( 'Attribute name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'position'             => array(
							'description' => __( 'Attribute position.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'is_attribute_visible' => array(
							'description' => __( "Is the attribute visible on the \"Additional information\" tab in the product's page.", 'cart-rest-api-for-woocommerce' ),
							'type'        => 'boolean',
							'context'     => array( 'view' ),
							'default'     => false,
							'readonly'    => true,
						),
						'used_for_variation'   => array(
							'description' => __( 'Can the attribute be used as a variation?', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'boolean',
							'context'     => array( 'view' ),
							'default'     => false,
							'readonly'    => true,
						),
						'options'              => array(
							'description' => __( 'List of available term names of the attribute.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
			'default_attributes' => array(
				'description' => __( 'Defaults variation attributes.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(
							'description' => __( 'Attribute ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'   => array(
							'description' => __( 'Attribute name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'option' => array(
							'description' => __( 'Selected attribute term name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
			'variations'         => array(
				'description' => __( 'List of all variations and data.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array(
							'description' => __( 'Unique identifier for the variation product.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'sku'            => array(
							'description' => __( 'Unique identifier for the variation product.', 'cart-rest-api-for-woocommerce' ) . ' (SKU)',
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'description'    => array(
							'description' => __( 'Product description.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'attributes'     => array(
							'description' => __( 'Product attributes.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'featured_image' => array(
							'description' => __( 'Variation product featured image.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'properties'  => array(),
							'readonly'    => true,
						),
						'prices'         => array(
							'description' => __( 'Product prices.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'properties'  => array(
								'price'         => array(
									'description' => __( 'Product price (currently).', 'cart-rest-api-for-woocommerce' ),
									'type'        => array(
										"integer",
										"string",
									),
									'context'     => array( 'view' ),
									'readonly'    => true,
								),
								'regular_price' => array(
									'description' => __( 'Product regular price.', 'cart-rest-api-for-woocommerce' ),
									'type'        => array(
										"integer",
										"string",
									),
									'context'     => array( 'view' ),
									'readonly'    => true,
								),
								'sale_price'    => array(
									'description' => __( 'Product sale price.', 'cart-rest-api-for-woocommerce' ),
									'type'        => array(
										"integer",
										"string",
									),
									'context'     => array( 'view' ),
									'readonly'    => true,
								),
								'on_sale'       => array(
									'description' => __( 'Shows if the product is on sale.', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'boolean',
									'context'     => array( 'view' ),
									'readonly'    => true,
								),
								'date_on_sale'  => array(
									'description' => __( 'Product dates for on sale.', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'object',
									'context'     => array( 'view' ),
									'properties'  => array(
										'from'     => array(
											'description' => __( "Start date of sale price, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
											'type'        => 'date-time',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'from_gmt' => array(
											'description' => __( 'Start date of sale price, as GMT.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'date-time',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'to'       => array(
											'description' => __( "End date of sale price, in the site's timezone.", 'cart-rest-api-for-woocommerce' ),
											'type'        => 'date-time',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'to_gmt'   => array(
											'description' => __( 'End date of sale price, as GMT.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'date-time',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
									),
									'readonly'    => true,
								),
								'currency'      => array(
									'description' => __( 'Product currency.', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'object',
									'context'     => array( 'view' ),
									'properties'  => array(
										'currency_code'   => array(
											'description' => __( 'Currency code.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_symbol' => array(
											'description' => __( 'Currency symbol.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_minor_unit' => array(
											'description' => __( 'Currency minor unit.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'integer',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_decimal_separator' => array(
											'description' => __( 'Currency decimal separator.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_thousand_separator' => array(
											'description' => __( 'Currency thousand separator.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_prefix' => array(
											'description' => __( 'Currency prefix.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
										'currency_suffix' => array(
											'description' => __( 'Currency suffix.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'string',
											'context'     => array( 'view' ),
											'readonly'    => true,
										),
									),
									'readonly'    => true,
								),
							),
							'readonly'    => true,
						),
						'add_to_cart'    => array(
							'description' => __( 'Add to Cart button.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'object',
							'context'     => array( 'view' ),
							'properties'  => array(
								'is_purchasable'    => array(
									'description' => __( 'Is product purchasable?', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'boolean',
									'context'     => array( 'view' ),
									'default'     => true,
									'readonly'    => true,
								),
								'purchase_quantity' => array(
									'description' => __( 'Purchase limits depending on stock.', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'object',
									'context'     => array( 'view' ),
									'properties'  => array(
										'min_purchase' => array(
											'description' => __( 'Minimum purchase quantity allowed for product.', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'integer',
											'context'     => array( 'view' ),
											'default'     => 1,
											'readonly'    => true,
										),
										'max_purchase' => array(
											'description' => __( 'Maximum purchase quantity allowed based on stock (if managed).', 'cart-rest-api-for-woocommerce' ),
											'type'        => 'integer',
											'context'     => array( 'view' ),
											'default'     => -1,
											'readonly'    => true,
										),
									),
									'readonly'    => true,
								),
								'rest_url'          => array(
									'description' => __( 'The REST URL for adding the product to cart.', 'cart-rest-api-for-woocommerce' ),
									'type'        => 'string',
									'context'     => array( 'view' ),
									'format'      => 'uri',
									'readonly'    => true,
								),
							),
							'readonly'    => true,
						),
					),
					'readonly'   => true,
				),
			),
			'grouped_products'   => array(
				'description' => __( 'List of grouped products ID.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type' => 'integer',
				),
				'readonly'    => true,
			),
			'stock'              => array(
				'description' => __( 'Product stock details.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'is_in_stock'        => array(
						'description' => __( 'Determines if product is listed as "in stock" or "out of stock".', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => true,
						'readonly'    => true,
					),
					'stock_quantity'     => array(
						'description' => __( 'Stock quantity.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'integer',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'stock_status'       => array(
						'description' => __( 'Stock status.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'default'     => 'instock',
						'enum'        => wc_get_product_stock_status_options(),
						'readonly'    => true,
					),
					'backorders'         => array(
						'description' => __( 'If managing stock, this tells us if backorders are allowed.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'default'     => 'no',
						'enum'        => wc_get_product_backorder_options(),
						'readonly'    => true,
					),
					'backorders_allowed' => array(
						'description' => __( 'Are backorders allowed?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'backordered'        => array(
						'description' => __( 'Do we show if the product is on backorder?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
				),
			),
			'weight'             => array(
				/* translators: %s: weight unit */
				'description' => sprintf( __( 'Product weight (%s).', 'cart-rest-api-for-woocommerce' ), $weight_unit ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'value'  => array(
						'description' => __( 'Product weight value.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'weight' => array(
						'description' => __( 'Product weight unit.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'default'     => $weight_unit,
						'readonly'    => true,
					),
				),
				'readonly'    => true,
			),
			'dimensions'         => array(
				'description' => __( 'Product dimensions.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'length' => array(
						/* translators: %s: dimension unit */
						'description' => sprintf( __( 'Product length (%s).', 'cart-rest-api-for-woocommerce' ), $dimension_unit ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'width'  => array(
						/* translators: %s: dimension unit */
						'description' => sprintf( __( 'Product width (%s).', 'cart-rest-api-for-woocommerce' ), $dimension_unit ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'height' => array(
						/* translators: %s: dimension unit */
						'description' => sprintf( __( 'Product height (%s).', 'cart-rest-api-for-woocommerce' ), $dimension_unit ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'unit'   => array(
						/* translators: %s: dimension unit */
						'description' => __( 'Product dimension unit.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'default'     => $dimension_unit,
						'readonly'    => true,
					),
				),
				'readonly'    => true,
			),
			'reviews'            => array(
				'description' => __( 'Returns a list of product review IDs', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type' => 'integer',
				),
				'readonly'    => true,
			),
			'rating_html'        => array(
				'description' => __( 'Returns the rating of the product in html.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'related'            => array(
				'description' => __( 'List of related products IDs.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type' => 'integer',
				),
				'readonly'    => true,
			),
			'upsells'            => array(
				'description' => __( 'List of up-sell products IDs.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type' => 'integer',
				),
				'readonly'    => true,
			),
			'cross_sells'        => array(
				'description' => __( 'List of cross-sell products IDs.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type' => 'integer',
				),
				'readonly'    => true,
			),
			'total_sales'        => array(
				'description' => __( 'Amount of product sales.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'integer',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'external_url'       => array(
				'description' => __( 'Product external URL. Only for external products.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'format'      => 'uri',
				'readonly'    => true,
			),
			'button_text'        => array(
				'description' => __( 'Product external button text. Only for external products.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'readonly'    => true,
			),
			'add_to_cart'        => array(
				'description' => __( 'Add to Cart button.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'object',
				'context'     => array( 'view' ),
				'properties'  => array(
					'text'              => array(
						'description' => __( 'Add to Cart Text', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'default'     => __( 'Add to Cart', 'cart-rest-api-for-woocommerce' ),
						'readonly'    => true,
					),
					'description'       => array(
						'description' => __( 'Description', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'readonly'    => true,
					),
					'has_options'       => array(
						'description' => __( 'Determines whether or not the product has additional options that need selecting before adding to cart.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => false,
						'readonly'    => true,
					),
					'is_purchasable'    => array(
						'description' => __( 'Is product purchasable?', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'boolean',
						'context'     => array( 'view' ),
						'default'     => true,
						'readonly'    => true,
					),
					'purchase_quantity' => array(
						'description' => __( 'Purchase limits depending on stock.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'object',
						'context'     => array( 'view' ),
						'properties'  => array(
							'min_purchase' => array(
								'description' => __( 'Minimum purchase quantity allowed for product.', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'integer',
								'context'     => array( 'view' ),
								'default'     => 1,
								'readonly'    => true,
							),
							'max_purchase' => array(
								'description' => __( 'Maximum purchase quantity allowed based on stock (if managed).', 'cart-rest-api-for-woocommerce' ),
								'type'        => 'integer',
								'context'     => array( 'view' ),
								'default'     => -1,
								'readonly'    => true,
							),
						),
						'readonly'    => true,
					),
					'rest_url'          => array(
						'description' => __( 'The REST URL for adding the product to cart.', 'cart-rest-api-for-woocommerce' ),
						'type'        => 'string',
						'context'     => array( 'view' ),
						'format'      => 'uri',
						'readonly'    => true,
					),
				),
				'readonly'    => true,
			),
			'meta_data'          => array(
				'description' => __( 'Product meta data.', 'cart-rest-api-for-woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'description' => __( 'Meta ID.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'key'   => array(
							'description' => __( 'Meta key.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'value' => array(
							'description' => __( 'Meta value.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'mixed',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
					),
				),
				'readonly'    => true,
			),
		);

		// Fetch each image size.
		$attachment_sizes = Helpers::get_product_image_sizes();

		foreach ( $attachment_sizes as $size ) {
			// Generate the product featured image URL properties for each attachment size.
			$schema['properties']['images']['items']['properties']['src']['properties'][ $size ] = array(
				'description' => sprintf(
					/* translators: %s: Product image URL */
					__( 'The product image URL for "%s".', 'cart-rest-api-for-woocommerce' ),
					$size
				),
				'type'        => 'string',
				'context'     => array( 'view' ),
				'format'      => 'uri',
				'readonly'    => true,
			);

			// Generate the variation product featured image URL properties for each attachment size.
			if ( isset( $schema['properties']['variations']['items']['properties']['featured_image']['properties'] ) ) {
				$schema['properties']['variations']['items']['properties']['featured_image']['properties'][ $size ] = array(
					'description' => sprintf(
						/* translators: %s: Product image URL */
						__( 'The product image URL for "%s".', 'cart-rest-api-for-woocommerce' ),
						$size
					),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'format'      => 'uri',
					'readonly'    => true,
				);
			}
		}

		return $this->add_additional_fields_schema( $schema );
	} // END get_item_schema()

	/**
	 * Retrieves the item's schema for display / public consumption purposes
	 * for the product archive.
	 *
	 * @access public
	 *
	 * @since 3.1.0 Introduced.
	 *
	 * @return array Products archive schema data.
	 */
	public function get_public_items_schema() {
		$product_schema = $this->get_item_schema();

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'cocart_products_archive',
			'type'       => 'object',
			'properties' => array(
				'products'       => array(
					'description' => __( 'Returned products based on result criteria.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'properties'  => $product_schema['properties'],
				),
				'categories'     => array(
					'description' => __( 'Returns all product categories.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'properties'  => array(
						'id'       => array(
							'description' => __( 'Unique identifier for the product category.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'     => array(
							'description' => __( 'Product category name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'slug'     => array(
							'description' => __( 'Product category slug.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'rest_url' => array(
							'description' => __( 'The REST URL for viewing this product category.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
					),
				),
				'tags'           => array(
					'description' => __( 'Returns all product tags.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view' ),
					'properties'  => array(
						'id'       => array(
							'description' => __( 'Unique identifier for the product tag.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'integer',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'name'     => array(
							'description' => __( 'Product tag name.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'slug'     => array(
							'description' => __( 'Product tag slug.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
						'rest_url' => array(
							'description' => __( 'The REST URL for viewing this product tag.', 'cart-rest-api-for-woocommerce' ),
							'type'        => 'string',
							'context'     => array( 'view' ),
							'readonly'    => true,
						),
					),
				),
				'page'           => array(
					'description' => __( 'Current page of pagination.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_pages'    => array(
					'description' => __( 'Total number of pages based on result criteria.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'total_products' => array(
					'description' => __( 'Total of available products in store.', 'cart-rest-api-for-woocommerce' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $schema;
	} // END get_public_items_schema()

} // END class
