<?php
/**
 * Tests for Hypercart_Query_Guard::classify_sql() and ::is_admin_ajax_request().
 *
 * These methods are pure (no WordPress runtime needed beyond the stubs in
 * bootstrap.php), so each test is straightforward input → expected-output.
 */
class SqlClassifierTest extends \PHPUnit\Framework\TestCase {

	// -----------------------------------------------------------------------
	// classify_sql — guard rails
	// -----------------------------------------------------------------------

	public function test_empty_string_returns_all_defaults() {
		$r = Hypercart_Query_Guard::classify_sql( '' );
		$this->assertSame( '', $r['table_hint'] );
		$this->assertFalse( $r['is_comment_query'] );
		$this->assertFalse( $r['has_large_in_list'] );
		$this->assertSame( 0, $r['estimated_in_list_size'] );
		$this->assertFalse( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['is_probable_order_note_query'] );
	}

	public function test_non_string_returns_all_defaults() {
		// @phpstan-ignore-next-line  intentional wrong type for guard test
		$r = Hypercart_Query_Guard::classify_sql( null );
		$this->assertSame( '', $r['table_hint'] );
		$this->assertFalse( $r['has_large_in_list'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — write queries are not classified
	// -----------------------------------------------------------------------

	public function test_update_query_not_classified() {
		$ids = implode( ', ', range( 1, 300 ) );
		$sql = "UPDATE wp_comments SET comment_content = 'x' WHERE comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( '', $r['table_hint'] );
		$this->assertFalse( $r['is_comment_query'] );
		$this->assertFalse( $r['has_large_in_list'] );
		$this->assertSame( 0, $r['estimated_in_list_size'] );
	}

	public function test_delete_query_not_classified() {
		$sql = 'DELETE FROM wp_comments WHERE comment_ID IN (1, 2, 3)';
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( '', $r['table_hint'] );
		$this->assertFalse( $r['has_large_in_list'] );
	}

	public function test_insert_query_not_classified() {
		$sql = "INSERT INTO wp_comments (comment_content) SELECT comment_content FROM wp_comments WHERE comment_ID IN (1,2,3)";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( '', $r['table_hint'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — normal SELECT with no notable characteristics
	// -----------------------------------------------------------------------

	public function test_normal_select_returns_clean_defaults() {
		$sql = 'SELECT ID, post_title FROM wp_posts WHERE post_status = \'publish\'';
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( '', $r['table_hint'] );
		$this->assertFalse( $r['is_comment_query'] );
		$this->assertFalse( $r['has_large_in_list'] );
		$this->assertSame( 0, $r['estimated_in_list_size'] );
		$this->assertFalse( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['is_probable_order_note_query'] );
	}

	public function test_small_in_list_is_not_flagged_as_large() {
		// 10 IDs — well below the 200-item threshold.
		$ids = implode( ', ', range( 1, 10 ) );
		$sql = "SELECT comment_ID FROM wp_comments WHERE comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertFalse( $r['has_large_in_list'] );
		$this->assertSame( 10, $r['estimated_in_list_size'] );
		// Table hint should still fire.
		$this->assertSame( 'wp_comments', $r['table_hint'] );
		$this->assertTrue( $r['is_comment_query'] );
	}

	public function test_in_list_at_boundary_not_large() {
		// 199 IDs — one below threshold, must not be flagged.
		$ids = implode( ', ', range( 1, 199 ) );
		$sql = "SELECT * FROM wp_posts WHERE ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertFalse( $r['has_large_in_list'] );
		$this->assertSame( 199, $r['estimated_in_list_size'] );
	}

	public function test_in_list_at_threshold_is_large() {
		// Exactly 200 IDs — at threshold, must be flagged.
		$ids = implode( ', ', range( 1, 200 ) );
		$sql = "SELECT * FROM wp_posts WHERE ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['has_large_in_list'] );
		$this->assertSame( 200, $r['estimated_in_list_size'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — wp_comments large IN list (the incident pattern)
	// -----------------------------------------------------------------------

	public function test_large_wp_comments_in_list_classified_correctly() {
		// Mirrors the production-incident query shape: ~10 k IDs.
		$ids = implode( ', ', range( 1, 500 ) );
		$sql = "SELECT comment_ID, comment_author, comment_content FROM wp_comments WHERE comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( 'wp_comments', $r['table_hint'] );
		$this->assertTrue( $r['is_comment_query'] );
		$this->assertTrue( $r['has_large_in_list'] );
		$this->assertSame( 500, $r['estimated_in_list_size'] );
	}

	public function test_large_wp_comments_in_list_without_space_before_paren() {
		// Some ORMs emit IN( without a space.
		$ids = implode( ',', range( 1, 250 ) );
		$sql = "SELECT comment_ID FROM wp_comments WHERE comment_ID IN({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['has_large_in_list'] );
		$this->assertSame( 250, $r['estimated_in_list_size'] );
	}

	public function test_wp_comments_table_alias_still_detected() {
		// Table present in FROM clause even with alias.
		$ids = implode( ', ', range( 1, 300 ) );
		$sql = "SELECT c.comment_ID FROM wp_comments AS c WHERE c.comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertSame( 'wp_comments', $r['table_hint'] );
		$this->assertTrue( $r['is_comment_query'] );
		$this->assertTrue( $r['has_large_in_list'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — WooCommerce / order-note heuristics
	// -----------------------------------------------------------------------

	public function test_order_note_query_flagged_as_woocommerce_and_order_note() {
		$ids = implode( ', ', range( 1, 300 ) );
		$sql = "SELECT * FROM wp_comments WHERE comment_type = 'order_note' AND comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_comment_query'] );
		$this->assertTrue( $r['is_probable_order_note_query'] );
		$this->assertTrue( $r['is_probable_woocommerce'] );
		$this->assertTrue( $r['has_large_in_list'] );
	}

	public function test_order_note_keyword_without_large_in_still_flagged_as_wc() {
		// Even a small order-note query should surface the WC heuristic.
		$sql = "SELECT * FROM wp_comments WHERE comment_type = 'order_note' AND comment_post_ID = 42";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_comment_query'] );
		$this->assertTrue( $r['is_probable_order_note_query'] );
		$this->assertTrue( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['has_large_in_list'] );
	}

	public function test_woocommerce_order_table_detected_without_comments() {
		$sql = "SELECT * FROM wp_wc_orders WHERE status = 'wc-processing'";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['is_comment_query'] );
		$this->assertFalse( $r['is_probable_order_note_query'] );
	}

	public function test_shop_order_post_type_detected() {
		$sql = "SELECT ID FROM wp_posts WHERE post_type = 'shop_order' AND post_status = 'wc-processing'";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['is_comment_query'] );
	}

	public function test_non_wc_comment_query_not_flagged_as_woocommerce() {
		// A standard WordPress comment query with no WC signals.
		$ids = implode( ', ', range( 1, 300 ) );
		$sql = "SELECT * FROM wp_comments WHERE comment_post_ID IN ({$ids}) AND comment_approved = '1'";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_comment_query'] );
		$this->assertFalse( $r['is_probable_woocommerce'] );
		$this->assertFalse( $r['is_probable_order_note_query'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — leading whitespace tolerance
	// -----------------------------------------------------------------------

	public function test_select_with_leading_whitespace_is_classified() {
		$ids = implode( ', ', range( 1, 250 ) );
		$sql = "  \n  SELECT comment_ID FROM wp_comments WHERE comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['is_comment_query'] );
		$this->assertTrue( $r['has_large_in_list'] );
	}

	// -----------------------------------------------------------------------
	// classify_sql — subquery masking regression (preg_match_all fix)
	// A query whose first IN ( is a small subquery must still surface the
	// large literal list that follows it.
	// -----------------------------------------------------------------------

	public function test_large_in_list_not_masked_by_earlier_subquery_in() {
		// First IN ( is a subquery with no commas; second IN ( is the 10k ID list.
		// The old preg_match would stop at the subquery and return size=1.
		$ids = implode( ', ', range( 1, 500 ) );
		$sql = "SELECT * FROM wp_comments
				 WHERE comment_type IN (SELECT slug FROM wp_term_taxonomy WHERE taxonomy = 'comment_type')
				 AND comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['has_large_in_list'], 'large literal list masked by earlier subquery IN' );
		$this->assertSame( 500, $r['estimated_in_list_size'] );
		$this->assertTrue( $r['is_comment_query'] );
	}

	public function test_small_subquery_in_before_large_list_reports_max_size() {
		// Subquery IN with a small comma list (3 items), then a big literal list.
		// estimated_in_list_size must reflect the larger one.
		$ids = implode( ', ', range( 1, 300 ) );
		$sql = "SELECT * FROM wp_comments
				 WHERE comment_type IN ('order_note', 'note', 'status')
				 AND comment_ID IN ({$ids})";
		$r   = Hypercart_Query_Guard::classify_sql( $sql );

		$this->assertTrue( $r['has_large_in_list'] );
		$this->assertSame( 300, $r['estimated_in_list_size'] );
	}

	// -----------------------------------------------------------------------
	// is_admin_ajax_request
	// -----------------------------------------------------------------------

	public function test_admin_ajax_php_uri_is_detected() {
		$this->assertTrue( Hypercart_Query_Guard::is_admin_ajax_request( '/wp-admin/admin-ajax.php' ) );
	}

	public function test_admin_ajax_php_uri_with_query_string_is_detected() {
		$this->assertTrue(
			Hypercart_Query_Guard::is_admin_ajax_request( '/wp-admin/admin-ajax.php?action=woocommerce_load_order_notes' )
		);
	}

	public function test_non_ajax_admin_uri_is_not_detected() {
		$this->assertFalse( Hypercart_Query_Guard::is_admin_ajax_request( '/wp-admin/post.php' ) );
	}

	public function test_frontend_uri_is_not_detected() {
		$this->assertFalse( Hypercart_Query_Guard::is_admin_ajax_request( '/shop/my-product/' ) );
	}

	public function test_rest_api_uri_is_not_detected() {
		$this->assertFalse( Hypercart_Query_Guard::is_admin_ajax_request( '/wp-json/wc/v3/orders' ) );
	}

	public function test_empty_uri_is_not_detected() {
		$this->assertFalse( Hypercart_Query_Guard::is_admin_ajax_request( '' ) );
	}
}
