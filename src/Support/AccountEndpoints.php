<?php
/**
 * The My Account screens: which exist, what they are called, where they live.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Support;

use Galaxie\Woo\Core\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One list of screens, read by the admin tab, the menu widget, the content
 * widget and WooCommerce's own account menu.
 *
 * A screen is one of three kinds:
 *
 * - `core`: WooCommerce's own (Orders, Addresses...). Its URL slug is stored in
 *   WooCommerce's own option, the one under Settings → Advanced, so the two
 *   screens can never disagree and every link WooCommerce prints (emails, the
 *   "View" button on an order) follows a rename.
 * - `external`: added to the account menu by another plugin. Its URL and content
 *   stay that plugin's business; here it can be hidden, renamed or given a
 *   template.
 * - `galaxie`: screens this plugin brings (Interests, Communication, Wishlist),
 *   each drawn by its widget, offered only when what it needs is switched on.
 * - `custom`: created here. It becomes a real WooCommerce endpoint, so
 *   `/my-account/{slug}/` resolves, `is_wc_endpoint_url()` knows it and the page
 *   title follows its label. Its content is the Elementor template picked for it.
 *
 * Nothing here decides how a screen looks. That is the template's job, and
 * without one WooCommerce's own screen is shown.
 */
final class AccountEndpoints {

	public const MODULE = 'my-account';

	/** Set when a slug changes; the rewrite rules are rebuilt on the next request. */
	public const FLUSH_OPTION = 'galaxie_woo_account_flush';

	/** WooCommerce's screens, in its own order: key => slug option. */
	private const CORE = array(
		'dashboard'       => '',
		'orders'          => 'woocommerce_myaccount_orders_endpoint',
		'view-order'      => 'woocommerce_myaccount_view_order_endpoint',
		'downloads'       => 'woocommerce_myaccount_downloads_endpoint',
		'edit-address'    => 'woocommerce_myaccount_edit_address_endpoint',
		'payment-methods' => 'woocommerce_myaccount_payment_methods_endpoint',
		'edit-account'    => 'woocommerce_myaccount_edit_account_endpoint',
		'customer-logout' => 'woocommerce_logout_endpoint',
	);

	/** Our own screens' default addresses: key => slug. A saved slug overrides it. */
	private const GALAXIE_SLUGS = array(
		'galaxie-interests'     => 'interesses',
		'galaxie-communication' => 'comunicacao',
		'galaxie-wishlist'      => 'lista-de-desejos',
	);

	/** Suggested icons for the menu widget's starting list. */
	public const ICONS = array(
		'dashboard'       => 'Line/pixfort-icon-dashboard-1',
		'orders'          => 'Line/pixfort-icon-bag-1',
		'downloads'       => 'Line/pixfort-icon-download-1',
		'edit-address'    => 'Line/pixfort-icon-map-pin-1',
		'payment-methods' => 'Line/pixfort-icon-credit-card-1',
		'edit-account'    => 'Line/pixfort-icon-user-circle-1',
		'customer-logout' => 'Line/pixfort-icon-logout-1',
		'galaxie-interests'     => 'Line/pixfort-icon-check-badge-1',
		'galaxie-communication' => 'Line/pixfort-icon-mail-1',
		'galaxie-wishlist'      => 'Line/pixfort-icon-heart-1',
	);

	/**
	 * What a screen shows when no template was picked: Galaxie's widgets, drawn
	 * with their own defaults. Pick "WooCommerce's own screen" to go back to
	 * WooCommerce's markup.
	 */
	private const DEFAULT_WIDGETS = array(
		'dashboard'             => array(
			array( 'galaxie-account-user', array() ),
			// Gift Wrap's card: a kit left half-built is the one thing on this
			// screen the customer can lose. With the module off the widget is
			// not registered and render_widget() draws nothing.
			array( 'galaxie-account-kit', array() ),
			array( 'galaxie-account-orders', array( 'orders_source' => 'recent', 'orders_per_page' => 3, 'orders_heading' => 'Pedidos recentes' ) ),
		),
		'orders'                => array( array( 'galaxie-account-orders', array() ) ),
		'view-order'            => array( array( 'galaxie-account-order', array() ) ),
		'edit-address'          => array( array( 'galaxie-account-addresses', array() ) ),
		'payment-methods'       => array( array( 'galaxie-account-payment-methods', array() ) ),
		'edit-account'          => array( array( 'galaxie-account-details', array() ), array( 'galaxie-account-delete', array() ) ),
		'galaxie-interests'     => array( array( 'galaxie-account-interests', array() ) ),
		'galaxie-communication' => array( array( 'galaxie-account-communication', array() ) ),
		'galaxie-wishlist'      => array( array( 'galaxie-account-wishlist', array() ) ),
	);

	/** A template value meaning "WooCommerce's own screen, not Galaxie's". */
	public const NATIVE = -1;

	/** True while WooCommerce's menu is being read raw, past our own filter. */
	private static bool $capturing = false;

	/** @var array<string,string>|null Other plugins' menu items, read once per request. */
	private static ?array $external = null;

	/** Guards a template that contains the content widget from rendering itself forever. */
	private static int $depth = 0;

	/** @return array<string,string> */
	private static function default_labels(): array {
		return array(
			'dashboard'       => __( 'Painel', 'galaxie-woo' ),
			'orders'          => __( 'Pedidos', 'galaxie-woo' ),
			'view-order'      => __( 'Pedido', 'galaxie-woo' ),
			'downloads'       => __( 'Downloads', 'galaxie-woo' ),
			'edit-address'    => __( 'Endereços', 'galaxie-woo' ),
			'payment-methods' => __( 'Formas de pagamento', 'galaxie-woo' ),
			'edit-account'    => __( 'Dados da conta', 'galaxie-woo' ),
			'customer-logout' => __( 'Sair', 'galaxie-woo' ),
		);
	}

	/**
	 * Our own screens, those whose requirements are met: key => [label, slug].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function galaxie_screens(): array {
		$modules = Plugin::instance()->modules();
		$screens = array();

		$fluent = Plugin::instance()->settings()->module_settings( 'fluentcrm' );

		if ( $modules->is_enabled_by_id( 'fluentcrm' ) && \Galaxie\Woo\Integrations\FluentCRM::is_active() && ! empty( $fluent['interests_enabled'] ) && ! empty( $fluent['interest_options'] ) ) {
			$screens['galaxie-interests'] = array( __( 'Interesses', 'galaxie-woo' ), self::GALAXIE_SLUGS['galaxie-interests'] );
		}

		$screens['galaxie-communication'] = array( __( 'Comunicação', 'galaxie-woo' ), self::GALAXIE_SLUGS['galaxie-communication'] );

		if ( $modules->is_enabled_by_id( 'wishlist' ) ) {
			$screens['galaxie-wishlist'] = array( __( 'Lista de desejos', 'galaxie-woo' ), self::GALAXIE_SLUGS['galaxie-wishlist'] );
		}

		return $screens;
	}

	/** Whether a screen has Galaxie widgets to show without a template. */
	public static function has_galaxie_default( string $key ): bool {
		return isset( self::DEFAULT_WIDGETS[ $key ] );
	}

	/** @return array<string,mixed> */
	private static function settings(): array {
		return Plugin::instance()->settings()->module_settings( self::MODULE );
	}

	/**
	 * Every screen, in menu order: WooCommerce's, then other plugins', then ours,
	 * with Log out last.
	 *
	 * @return array<string,array{key:string,type:string,label:string,default_label:string,slug:string,enabled:bool,template:int}>
	 */
	public static function all( bool $with_external = true ): array {
		$saved    = (array) ( self::settings()['endpoints'] ?? array() );
		$defaults = self::default_labels();
		$out      = array();

		foreach ( self::CORE as $key => $option ) {
			if ( 'customer-logout' === $key ) {
				continue;
			}

			$out[ $key ] = self::entry( $key, 'core', $defaults[ $key ], self::core_slug( $key ), (array) ( $saved[ $key ] ?? array() ) );
		}

		foreach ( self::galaxie_screens() as $key => $screen ) {
			$row  = (array) ( $saved[ $key ] ?? array() );
			$slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );

			$out[ $key ] = self::entry( $key, 'galaxie', $screen[0], '' !== $slug ? $slug : $screen[1], $row );
		}

		foreach ( $with_external ? self::external_items() : array() as $key => $label ) {
			$out[ $key ] = self::entry( $key, 'external', $label, '', (array) ( $saved[ $key ] ?? array() ) );
		}

		foreach ( self::custom_rows() as $row ) {
			$out[ $row['key'] ] = self::entry( $row['key'], 'custom', $row['label'], $row['slug'], $row );
		}

		$out['customer-logout'] = self::entry( 'customer-logout', 'core', $defaults['customer-logout'], self::core_slug( 'customer-logout' ), (array) ( $saved['customer-logout'] ?? array() ) );

		return $out;
	}

	/**
	 * @param array<string,mixed> $saved
	 * @return array{key:string,type:string,label:string,default_label:string,slug:string,enabled:bool,template:int}
	 */
	private static function entry( string $key, string $type, string $default_label, string $slug, array $saved ): array {
		$label = trim( (string) ( $saved['label'] ?? '' ) );

		return array(
			'key'           => $key,
			'type'          => $type,
			'label'         => '' !== $label ? $label : $default_label,
			'default_label' => $default_label,
			'slug'          => $slug,
			// The dashboard is where the account opens, and an order's own page
			// belongs to the orders list; neither is switched off on its own.
			'enabled'       => in_array( $key, array( 'dashboard', 'view-order' ), true ) || ! array_key_exists( 'enabled', $saved ) || ! empty( $saved['enabled'] ),
			'template'      => max( self::NATIVE, (int) ( $saved['template'] ?? 0 ) ),
		);
	}

	/** @return array{key:string,type:string,label:string,default_label:string,slug:string,enabled:bool,template:int}|null */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	public static function label( string $key ): string {
		return (string) ( self::get( $key )['label'] ?? $key );
	}

	public static function is_enabled( string $key ): bool {
		$entry = self::get( $key );

		if ( ! $entry ) {
			return false;
		}

		return 'view-order' === $key ? self::is_enabled( 'orders' ) : $entry['enabled'];
	}

	public static function core_slug( string $key ): string {
		$option = self::CORE[ $key ] ?? '';

		return '' === $option ? '' : (string) get_option( $option, $key );
	}

	/** @return array<int,array{key:string,label:string,slug:string,template:int,enabled:bool}> */
	public static function custom_rows(): array {
		$rows = array();

		foreach ( (array) ( self::settings()['custom'] ?? array() ) as $row ) {
			$row = (array) $row;

			if ( empty( $row['key'] ) || empty( $row['slug'] ) ) {
				continue;
			}

			$rows[] = array(
				'key'      => (string) $row['key'],
				'label'    => (string) ( $row['label'] ?? '' ),
				'slug'     => (string) $row['slug'],
				'template' => absint( $row['template'] ?? 0 ),
				'enabled'  => ! empty( $row['enabled'] ),
			);
		}

		return $rows;
	}

	/**
	 * Menu items other plugins add, read from WooCommerce's list before our own
	 * filter rewrites it.
	 *
	 * @return array<string,string>
	 */
	public static function external_items(): array {
		if ( null !== self::$external ) {
			return self::$external;
		}

		if ( ! function_exists( 'wc_get_account_menu_items' ) || ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
			return array();
		}

		$custom = wp_list_pluck( self::custom_rows(), 'key' );

		self::$capturing = true;
		$items           = (array) wc_get_account_menu_items();
		self::$capturing = false;

		self::$external = array();

		foreach ( $items as $key => $label ) {
			if ( ! array_key_exists( (string) $key, self::CORE ) && ! in_array( (string) $key, $custom, true ) && 0 !== strpos( (string) $key, 'galaxie-' ) ) {
				self::$external[ (string) $key ] = wp_strip_all_tags( (string) $label );
			}
		}

		return self::$external;
	}

	public static function url( string $key, string $value = '' ): string {
		if ( 'dashboard' === $key ) {
			return (string) wc_get_page_permalink( 'myaccount' );
		}

		if ( 'customer-logout' === $key ) {
			return (string) wc_logout_url();
		}

		return '' !== $value
			? (string) wc_get_endpoint_url( $key, $value, wc_get_page_permalink( 'myaccount' ) )
			: (string) wc_get_account_endpoint_url( $key );
	}

	/**
	 * The screen this request is on, and its value: the order number on an
	 * order's page, `billing` on the billing address form.
	 *
	 * @return array{key:string,value:string}
	 */
	public static function current(): array {
		global $wp;

		$key = function_exists( 'WC' ) && WC()->query ? (string) WC()->query->get_current_endpoint() : '';

		// A screen that is not in the list still counts: adding a payment method,
		// or another plugin's page that never made it into the menu. Its content
		// comes from its own hook, and treating it as the dashboard would show
		// the customer the wrong page.
		if ( '' === $key ) {
			return array( 'key' => 'dashboard', 'value' => '' );
		}

		return array( 'key' => $key, 'value' => (string) ( $wp->query_vars[ $key ] ?? '' ) );
	}

	/**
	 * One screen's content: its template when it has one, WooCommerce's own
	 * screen when it does not.
	 */
	public static function render( string $key, string $value = '', int $override = 0 ): string {
		if ( self::$depth > 0 ) {
			return '';
		}

		++self::$depth;

		try {
			$entry    = self::get( $key );
			$template = $entry ? $entry['template'] : 0;

			// A template picked beside the menu item wins, for the page that menu
			// is on. Everywhere else — WooCommerce's own account page, another
			// layout, a screen reached with no menu drawn — the admin still rules.
			if ( $override > 0 ) {
				$template = $override;
			}

			if ( $template > 0 && class_exists( '\Elementor\Plugin' ) && 'publish' === get_post_status( $template ) ) {
				return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $template, true );
			}

			if ( self::NATIVE !== $template && isset( self::DEFAULT_WIDGETS[ $key ] ) && class_exists( '\Elementor\Plugin' ) ) {
				$html = '';

				foreach ( self::DEFAULT_WIDGETS[ $key ] as $widget ) {
					$html .= AccountParts::render_widget( $widget[0], $widget[1] );
				}

				if ( '' !== trim( $html ) ) {
					return '<div class="galaxie-account-screen">' . $html . '</div>';
				}
			}

			ob_start();

			if ( 'dashboard' === $key ) {
				wc_get_template( 'myaccount/dashboard.php', array( 'current_user' => wp_get_current_user() ) );
			} elseif ( $entry && in_array( $entry['type'], array( 'custom', 'galaxie' ), true ) ) {
				if ( current_user_can( 'edit_posts' ) ) {
					printf(
						'<p class="galaxie-account-empty">%s</p>',
						esc_html__( 'This screen has no template yet. Pick one under Galaxie → My Account.', 'galaxie-woo' )
					);
				}
			} elseif ( has_action( 'woocommerce_account_' . $key . '_endpoint' ) ) {
				do_action( 'woocommerce_account_' . $key . '_endpoint', $value );
			}

			return (string) ob_get_clean();
		} finally {
			--self::$depth;
		}
	}

	/**
	 * Elementor templates a screen can use.
	 *
	 * @return array<int,string>
	 */
	public static function templates(): array {
		$out = array();

		$elementor = get_posts(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				// The site kit is stored as a template too, and is not one.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_elementor_template_type',
						'value'   => 'kit',
						'compare' => '!=',
					),
				),
			)
		);

		foreach ( $elementor as $post ) {
			$out[ (int) $post->ID ] = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );
		}

		// pixfort keeps its own templates in a post type of its own, and a theme
		// built on pixfort is where a merchant makes them. Queried separately on
		// purpose: the kit exclusion above is a `!=` meta clause, which WordPress
		// resolves with an INNER JOIN, so a post type that never carries that meta
		// would vanish from a combined query without saying so.
		if ( ! post_type_exists( 'pixfort_template' ) ) {
			return $out;
		}

		$args = array(
			'post_type'      => 'pixfort_template',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Their builder files headers, footers and popups under the same post type,
		// separated by this taxonomy. Only the plain templates belong here.
		if ( taxonomy_exists( 'pixfort_template_type' ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'pixfort_template_type',
					'field'    => 'slug',
					'terms'    => 'template',
				),
			);
		}

		foreach ( get_posts( $args ) as $post ) {
			$title = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );

			/* translators: %s: template name. */
			$out[ (int) $post->ID ] = sprintf( __( '%s (pixfort)', 'galaxie-woo' ), $title );
		}

		return $out;
	}

	// ------------------------------------------------------------------ hooks

	public static function hooks(): void {
		add_filter( 'woocommerce_get_query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu_items' ), 999 );
		add_action( 'init', array( self::class, 'register_screens' ), 20 );
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
		add_action( 'template_redirect', array( self::class, 'redirect_hidden' ) );
	}

	/**
	 * Our screens as WooCommerce endpoints, which is what makes their URLs real.
	 *
	 * @param array<string,string> $vars
	 * @return array<string,string>
	 */
	public static function query_vars( array $vars ): array {
		$saved = (array) ( self::settings()['endpoints'] ?? array() );

		foreach ( self::galaxie_screens() as $key => $screen ) {
			$slug         = sanitize_title( (string) ( $saved[ $key ]['slug'] ?? '' ) );
			$vars[ $key ] = '' !== $slug ? $slug : $screen[1];
		}

		foreach ( self::custom_rows() as $row ) {
			$vars[ $row['key'] ] = $row['slug'];
		}

		return $vars;
	}

	/**
	 * WooCommerce's account menu, as configured here. The theme's header
	 * dropdown and any other place that reads the menu then shows the same
	 * screens, under the same names, as the Galaxie menu widget.
	 *
	 * @param array<string,string> $items
	 * @return array<string,string>
	 */
	public static function menu_items( array $items ): array {
		if ( self::$capturing ) {
			return $items;
		}

		$out = array();

		foreach ( self::all() as $key => $entry ) {
			if ( 'view-order' === $key || ! $entry['enabled'] ) {
				continue;
			}

			// WooCommerce leaves some out on its own (no payment method to
			// manage, a blank slug). Those stay out.
			if ( ! in_array( $entry['type'], array( 'custom', 'galaxie' ), true ) && ! isset( $items[ $key ] ) ) {
				continue;
			}

			$out[ $key ] = $entry['label'];
		}

		return $out;
	}

	/** Page titles and content for every screen, now that the list is readable. */
	public static function register_screens(): void {
		// Without other plugins' items: reading those means building the whole
		// account menu, payment gateways included, on every request of the site.
		foreach ( self::all( false ) as $key => $entry ) {
			if ( in_array( $entry['type'], array( 'custom', 'galaxie' ), true ) ) {
				add_action(
					'woocommerce_account_' . $key . '_endpoint',
					static function ( $value ) use ( $key ): void {
						echo self::render( $key, (string) $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own output.
					}
				);
			}

			if ( in_array( $entry['type'], array( 'custom', 'galaxie' ), true ) || $entry['label'] !== $entry['default_label'] ) {
				add_filter(
					'woocommerce_endpoint_' . $key . '_title',
					static fn( $title ) => 'view-order' === $key ? $title : $entry['label']
				);
			}
		}
	}

	/**
	 * Rebuild the rewrite rules when our screens' addresses changed.
	 *
	 * Saving the settings flags it, and so does any other change to the set:
	 * turning the Wishlist module on adds a screen without anyone saving this
	 * tab, and its address would stay a 404 until someone thought to.
	 */
	public static function maybe_flush(): void {
		$signature = md5( (string) wp_json_encode( self::query_vars( array() ) ) );

		if ( get_option( self::FLUSH_OPTION ) || get_option( self::FLUSH_OPTION . '_signature' ) !== $signature ) {
			delete_option( self::FLUSH_OPTION );
			update_option( self::FLUSH_OPTION . '_signature', $signature, true );
			flush_rewrite_rules( false );
		}
	}

	/** A hidden screen is not reachable by typing its address either. */
	public static function redirect_hidden(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! is_user_logged_in() ) {
			return;
		}

		$current = self::current();

		// Only screens on the list can be hidden; the rest belong to whoever made them.
		if ( in_array( $current['key'], array( 'dashboard', 'customer-logout' ), true ) || ! self::get( $current['key'] ) || self::is_enabled( $current['key'] ) ) {
			return;
		}

		wp_safe_redirect( self::url( 'dashboard' ) );
		exit;
	}

	// --------------------------------------------------------------- settings

	/**
	 * @param array<string,mixed> $submitted
	 * @return array{endpoints:array<string,array<string,mixed>>,custom:array<int,array<string,mixed>>}
	 */
	public static function sanitize( array $submitted ): array {
		$flush     = false;
		$endpoints = array();
		$raw       = (array) ( $submitted['endpoints'] ?? array() );
		$templates = self::templates();

		foreach ( $raw as $key => $row ) {
			$key = sanitize_key( (string) $key );
			$row = (array) $row;

			$template = (int) ( $row['template'] ?? 0 );

			$endpoints[ $key ] = array(
				'enabled'  => ! empty( $row['enabled'] ),
				'label'    => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'template' => self::NATIVE === $template || isset( $templates[ $template ] ) ? $template : 0,
			);

			// Our own screens keep their address with their other settings.
			if ( 0 === strpos( $key, 'galaxie-' ) && isset( $row['slug'] ) ) {
				$slug    = sanitize_title( (string) $row['slug'] );
				$current = sanitize_title( (string) ( self::settings()['endpoints'][ $key ]['slug'] ?? '' ) );

				$endpoints[ $key ]['slug'] = $slug;

				if ( $slug !== $current ) {
					$flush = true;
				}
			}

			// WooCommerce's slugs live in WooCommerce's options.
			$option = self::CORE[ $key ] ?? '';

			if ( '' !== $option && isset( $row['slug'] ) ) {
				$slug = sanitize_title( (string) $row['slug'] );

				if ( '' !== $slug && get_option( $option, $key ) !== $slug ) {
					update_option( $option, $slug );
					$flush = true;
				}
			}
		}

		$taken = array_filter( array_map( array( self::class, 'core_slug' ), array_keys( self::CORE ) ) );

		// Our own screens' addresses are taken too: the slug this save keeps for
		// each, else the default. A screen whose module is off is not on the
		// form, and this save drops its old slug, so what it will use when
		// switched back on is the default, and that is what gets reserved.
		foreach ( self::GALAXIE_SLUGS as $galaxie_key => $default_slug ) {
			$galaxie_slug = (string) ( $endpoints[ $galaxie_key ]['slug'] ?? '' );
			$taken[]      = '' !== $galaxie_slug ? $galaxie_slug : $default_slug;
		}

		$before = wp_list_pluck( self::custom_rows(), 'slug', 'key' );
		$custom = array();

		foreach ( (array) ( $submitted['custom'] ?? array() ) as $row ) {
			$row   = (array) $row;
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$slug  = sanitize_title( '' !== trim( (string) ( $row['slug'] ?? '' ) ) ? (string) $row['slug'] : $label );

			if ( '' === $slug ) {
				continue;
			}

			// Two screens on one address would leave only one reachable.
			$base = $slug;
			$n    = 2;

			while ( in_array( $slug, $taken, true ) ) {
				$slug = $base . '-' . $n++;
			}

			$taken[] = $slug;

			// The key is what settings and widgets remember, so it is fixed at
			// creation and a later rename of the address does not orphan them.
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );

			if ( '' === $key ) {
				$key = 'gx-' . substr( $slug, 0, 40 );
			}

			while ( isset( $custom[ $key ] ) || isset( self::CORE[ $key ] ) ) {
				$key .= '-' . wp_rand( 10, 99 );
			}

			if ( ( $before[ $key ] ?? null ) !== $slug ) {
				$flush = true;
			}

			$custom[ $key ] = array(
				'key'      => $key,
				'label'    => '' !== $label ? $label : $slug,
				'slug'     => $slug,
				'template' => isset( $templates[ absint( $row['template'] ?? 0 ) ] ) ? absint( $row['template'] ) : 0,
				'enabled'  => ! empty( $row['enabled'] ),
			);
		}

		if ( array_diff( array_keys( $before ), array_keys( $custom ) ) ) {
			$flush = true;
		}

		if ( $flush ) {
			update_option( self::FLUSH_OPTION, 1 );
		}

		self::$external = null;

		return array(
			'endpoints' => $endpoints,
			'custom'    => array_values( $custom ),
		);
	}
}
