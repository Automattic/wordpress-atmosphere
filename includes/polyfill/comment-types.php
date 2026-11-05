<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName -- These are the core names, in one shareable file.
/**
 * Polyfill for the comment type API proposed for WordPress core.
 *
 * Mirrors two open core pull requests for Trac #35214:
 *
 * - WordPress/wordpress-develop#12311 (at 1473c5b): the registration API, `WP_Comment_Type`,
 *   `register_comment_type()` and friends, and the built-in comment types.
 * - WordPress/wordpress-develop#12310 (at 217a46c): `wp_get_default_excluded_comment_types()` and
 *   the `default_excluded_comment_types` filter, applied to comment queries, the stored comment
 *   count, the comment feeds and the comment page calculation.
 *
 * Every function and the class are guarded, so whatever core ships wins and the copy here is
 * skipped. The exclusion hooks only run while core does not apply the excluded set itself.
 *
 * The file is shared between the ATmosphere, ActivityPub and Webmention plugins, so it has no
 * namespace, no text domain and no plugin specific code. When several plugins ship a copy, the
 * copy with the highest version loads, on `plugins_loaded`. Bump the version on every change.
 * Include the file at plugin file scope, not from a hook, so every copy is registered before
 * the loader picks one. The API exists from `plugins_loaded` on, so register comment types on
 * `init`, as core asks anyway.
 *
 * Known differences to core:
 *
 * - `get_pending_comments_num()` and the `comment_type()` label fallback have no hook, so they
 *   keep their current behavior.
 * - Core changes the default `type` of `get_page_of_comment()` from 'all' to ''. The polyfill
 *   cannot tell the default from an explicit 'all', so it maps every 'all' to ''.
 * - The non-singular comment feeds only apply the excluded set when filters are not suppressed.
 * - WordPress 6.9 excludes 'note' from comment queries and feeds on its own. Removing 'note'
 *   from the excluded set brings it back into the stored comment count, but not into queries.
 *
 * Requires PHP 7.4 and WordPress 6.2, the lowest versions of the plugins sharing it.
 *
 * @see https://core.trac.wordpress.org/ticket/35214
 * @see https://github.com/WordPress/wordpress-develop/pull/12311
 * @see https://github.com/WordPress/wordpress-develop/pull/12310
 *
 * @package Atmosphere
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * First pass, when a plugin includes its copy: register the copy and its version, and load
 * the highest version on `plugins_loaded`. Only the second pass, the `require` from the
 * loader, defines the API.
 */
if ( ! defined( 'WP_COMMENT_TYPES_POLYFILL_FILE' ) || WP_COMMENT_TYPES_POLYFILL_FILE !== __FILE__ ) {
	$GLOBALS['wp_comment_types_polyfill_versions']['1.0.0'] = __FILE__;

	if ( ! function_exists( '_wp_comment_types_polyfill_load' ) ) {
		/**
		 * Loads the copy of the polyfill with the highest version.
		 *
		 * Keep this function stable: the copy that is included first defines it, which is
		 * not necessarily the copy that gets loaded.
		 *
		 * @access private
		 */
		function _wp_comment_types_polyfill_load() {
			if ( defined( 'WP_COMMENT_TYPES_POLYFILL_FILE' ) ) {
				return;
			}

			$versions = $GLOBALS['wp_comment_types_polyfill_versions'];
			uksort( $versions, 'version_compare' );

			define( 'WP_COMMENT_TYPES_POLYFILL_FILE', end( $versions ) );

			require WP_COMMENT_TYPES_POLYFILL_FILE;
		}

		add_action( 'plugins_loaded', '_wp_comment_types_polyfill_load', PHP_INT_MIN );
	}

	// A copy included after plugins_loaded has fired loads right away.
	if ( did_action( 'plugins_loaded' ) ) {
		_wp_comment_types_polyfill_load();
	}

	return;
}

/*
 * Checked before anything below is defined, so it reflects what core ships.
 */
$wp_comment_types_polyfill_core_registry  = function_exists( 'register_comment_type' );
$wp_comment_types_polyfill_core_exclusion = function_exists( 'wp_get_default_excluded_comment_types' );

/*
 * Registration API, WordPress/wordpress-develop#12311.
 */

if ( ! class_exists( 'WP_Comment_Type', false ) ) {
	/**
	 * Core class used for interacting with comment types.
	 *
	 * @see register_comment_type()
	 */
	#[AllowDynamicProperties]
	final class WP_Comment_Type {
		/**
		 * Comment type key.
		 *
		 * @var string
		 */
		public $name;

		/**
		 * Name of the comment type. Usually plural.
		 *
		 * @var string
		 */
		public $label;

		/**
		 * Labels object for this comment type.
		 *
		 * If not set, the default comment labels are used.
		 *
		 * @see get_comment_type_labels()
		 *
		 * @var stdClass
		 */
		public $labels;

		/**
		 * Default labels.
		 *
		 * @var (string|null)[][] $default_labels
		 */
		protected static $default_labels = array();

		/**
		 * A short descriptive summary of what the comment type is for.
		 *
		 * @var string
		 */
		public $description = '';

		/**
		 * Whether a comment type is intended for use publicly either via the admin interface or by front-end users.
		 *
		 * Core does not currently act on this property. Default true.
		 *
		 * @var bool
		 */
		public $public = true;

		/**
		 * Whether the comment type is for internal use only.
		 *
		 * Internal types are excluded from comment queries and counts by default,
		 * see wp_get_default_excluded_comment_types(). Default false.
		 *
		 * @var bool
		 */
		public $internal = false;

		/**
		 * Whether this comment type is a native or "built-in" comment type.
		 *
		 * Default false.
		 *
		 * @var bool
		 */
		public $_builtin = false; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- Core name.

		/**
		 * Whether the comment type is hierarchical.
		 *
		 * Comment types are never hierarchical. This property exists so the shared label
		 * helper {@see _get_custom_object_labels()} can resolve default labels.
		 *
		 * @var bool
		 */
		public $hierarchical = false;

		/**
		 * Constructor.
		 *
		 * @see register_comment_type()
		 *
		 * @param string       $comment_type Comment type key.
		 * @param array|string $args         Optional. Array or string of arguments for registering a comment type.
		 *                                   Default empty array.
		 */
		public function __construct( $comment_type, $args = array() ) {
			$this->name = $comment_type;

			$this->set_props( $args );
		}

		/**
		 * Sets comment type properties.
		 *
		 * @param array|string $args Array or string of arguments for registering a comment type.
		 */
		public function set_props( $args ) {
			$args = wp_parse_args( $args );

			/**
			 * Filters the arguments for registering a comment type.
			 *
			 * @param array  $args         Array of arguments for registering a comment type.
			 *                             See the register_comment_type() function for accepted arguments.
			 * @param string $comment_type Comment type key.
			 */
			$args = apply_filters( 'register_comment_type_args', $args, $this->name );

			$comment_type = $this->name;

			/**
			 * Filters the arguments for registering a specific comment type.
			 *
			 * The dynamic portion of the filter name, `$comment_type`, refers to the comment type key.
			 *
			 * @param array  $args         Array of arguments for registering a comment type.
			 *                             See the register_comment_type() function for accepted arguments.
			 * @param string $comment_type Comment type key.
			 */
			$args = apply_filters( "register_{$comment_type}_comment_type_args", $args, $this->name );

			/*
			 * 'label' is intentionally omitted from the defaults, so get_comment_type_labels()
			 * falls back to the default labels, like WP_Post_Type and WP_Taxonomy.
			 */
			$defaults = array(
				'labels'      => array(),
				'description' => '',
				'public'      => true,
				'internal'    => false,
				'_builtin'    => false,
			);

			$args = array_merge( $defaults, $args );

			$args['name'] = $this->name;

			// Comment types are never hierarchical, a provided value would resolve every default label to null.
			$args['hierarchical'] = false;

			foreach ( $args as $property_name => $property_value ) {
				$this->$property_name = $property_value;
			}

			$this->labels = get_comment_type_labels( $this );
			$this->label  = $this->labels->name;
		}

		/**
		 * Returns the default labels for comment types.
		 *
		 * @return (string|null)[][] The default labels for comment types.
		 */
		public static function get_default_labels() {
			if ( ! empty( self::$default_labels ) ) {
				return self::$default_labels;
			}

			self::$default_labels = array(
				'name'          => array( _x( 'Comments', 'comment type general name', 'default' ), null ),
				'singular_name' => array( _x( 'Comment', 'comment type singular name', 'default' ), null ),
			);

			return self::$default_labels;
		}

		/**
		 * Resets the cache for the default labels.
		 */
		public static function reset_default_labels() {
			self::$default_labels = array();
		}
	}
}

if ( ! function_exists( 'create_initial_comment_types' ) ) {
	/**
	 * Creates the initial comment types when 'init' action is fired.
	 *
	 * See register_comment_type() for accepted arguments.
	 */
	function create_initial_comment_types() {
		WP_Comment_Type::reset_default_labels();

		/*
		 * The 'comment', 'pingback', and 'trackback' labels deliberately reuse existing
		 * core translation strings, while 'note' introduces new strings with explicit
		 * contexts.
		 */
		register_comment_type(
			'comment',
			array(
				'label'    => __( 'Comments', 'default' ),
				'labels'   => array(
					'singular_name' => _x( 'Comment', 'noun', 'default' ),
				),
				'public'   => true,
				'_builtin' => true,
			)
		);

		register_comment_type(
			'pingback',
			array(
				'label'    => __( 'Pingbacks', 'default' ),
				'labels'   => array(
					'singular_name' => __( 'Pingback', 'default' ),
				),
				'public'   => true,
				'_builtin' => true,
			)
		);

		register_comment_type(
			'trackback',
			array(
				'label'    => __( 'Trackbacks', 'default' ),
				'labels'   => array(
					'singular_name' => __( 'Trackback', 'default' ),
				),
				'public'   => true,
				'_builtin' => true,
			)
		);

		register_comment_type(
			'note',
			array(
				'label'    => _x( 'Notes', 'comment type general name', 'default' ),
				'labels'   => array(
					'singular_name' => _x( 'Note', 'comment type singular name', 'default' ),
				),
				'public'   => false,
				'internal' => true,
				'_builtin' => true,
			)
		);
	}
}

if ( ! function_exists( 'register_comment_type' ) ) {
	/**
	 * Registers a comment type.
	 *
	 * Note: Comment type registrations should not be hooked before the {@see 'init'} action.
	 *
	 * Registration provides labels and metadata for a type; it does not constrain which values
	 * may be stored in the `comment_type` column. Cannot be used to re-register built-in comment
	 * types. The names WP_Comment_Query reads as query tokens ('all', 'comments', 'pings')
	 * cannot be registered either.
	 *
	 * @global WP_Comment_Type[] $wp_comment_types List of comment types.
	 *
	 * @param string       $comment_type Comment type key. Must not exceed 20 characters and may only
	 *                                   contain lowercase alphanumeric characters, dashes, and underscores.
	 * @param array|string $args {
	 *     Optional. Array or string of arguments for registering a comment type. Default empty array.
	 *
	 *     @type string   $label       Name of the comment type. Usually plural.
	 *     @type string[] $labels      An array of labels for this comment type.
	 *     @type string   $description A short descriptive summary of what the comment type is.
	 *     @type bool     $public      Whether the comment type is intended for use publicly. Default true.
	 *     @type bool     $internal    Whether the comment type is for internal use only. Internal types
	 *                                 are excluded from comment queries and counts by default. Default false.
	 *     @type bool     $_builtin    For internal core use only. Default false.
	 * }
	 * @return WP_Comment_Type|WP_Error The registered comment type object on success,
	 *                                  WP_Error object on failure.
	 */
	function register_comment_type( $comment_type, $args = array() ) {
		global $wp_comment_types;

		if ( ! is_array( $wp_comment_types ) ) {
			$wp_comment_types = array();
		}

		$args = wp_parse_args( $args );

		// Sanitize comment type name.
		$comment_type = sanitize_key( $comment_type );

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- Passed on unescaped like core.
		if ( empty( $comment_type ) || strlen( $comment_type ) > 20 ) {
			_doing_it_wrong( __FUNCTION__, __( 'Comment type names must be between 1 and 20 characters in length.', 'default' ), '7.2.0' );
			return new WP_Error( 'comment_type_length_invalid', __( 'Comment type names must be between 1 and 20 characters in length.', 'default' ) );
		}

		/*
		 * Re-registering a built-in comment type could strip flags that core relies on.
		 * Core's own repeated registrations (on 'init' and 'change_locale') pass '_builtin'.
		 */
		if ( isset( $wp_comment_types[ $comment_type ] )
			&& $wp_comment_types[ $comment_type ]->_builtin
			&& empty( $args['_builtin'] )
		) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					/* translators: %s: Comment type key. */
					__( 'The "%s" comment type is a built-in type and cannot be re-registered.', 'default' ),
					$comment_type
				),
				'7.2.0'
			);
			return new WP_Error( 'comment_type_builtin', __( 'Built-in comment types cannot be re-registered.', 'default' ) );
		}

		// WP_Comment_Query reads these names as query tokens, not as comment_type values.
		if ( in_array( $comment_type, array( 'all', 'comments', 'pings' ), true ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				sprintf(
					/* translators: %s: Comment type key. */
					__( 'The "%s" comment type name is reserved for use by WP_Comment_Query.', 'default' ),
					$comment_type
				),
				'7.2.0'
			);
			return new WP_Error( 'comment_type_reserved', __( 'This comment type name is reserved.', 'default' ) );
		}
		// phpcs:enable

		$comment_type_object = new WP_Comment_Type( $comment_type, $args );

		$wp_comment_types[ $comment_type ] = $comment_type_object;

		/**
		 * Fires after a comment type is registered.
		 *
		 * @param string          $comment_type        Comment type key.
		 * @param WP_Comment_Type $comment_type_object Comment type object.
		 */
		do_action( 'registered_comment_type', $comment_type, $comment_type_object );

		/**
		 * Fires after a specific comment type is registered.
		 *
		 * The dynamic portion of the filter name, `$comment_type`, refers to the comment type key.
		 *
		 * @param string          $comment_type        Comment type key.
		 * @param WP_Comment_Type $comment_type_object Comment type object.
		 */
		do_action( "registered_comment_type_{$comment_type}", $comment_type, $comment_type_object );

		return $comment_type_object;
	}
}

if ( ! function_exists( 'unregister_comment_type' ) ) {
	/**
	 * Unregisters a comment type.
	 *
	 * Cannot be used to unregister built-in comment types.
	 *
	 * @global WP_Comment_Type[] $wp_comment_types List of comment types.
	 *
	 * @param string $comment_type Comment type key.
	 * @return true|WP_Error True on success, WP_Error on failure or if the comment type doesn't exist.
	 */
	function unregister_comment_type( $comment_type ) {
		global $wp_comment_types;

		if ( ! comment_type_exists( $comment_type ) ) {
			return new WP_Error( 'invalid_comment_type', __( 'Invalid comment type.', 'default' ) );
		}

		$comment_type_object = get_comment_type_object( $comment_type );

		// Do not allow unregistering built-in comment types.
		if ( $comment_type_object->_builtin ) {
			return new WP_Error( 'invalid_comment_type', __( 'Unregistering a built-in comment type is not allowed.', 'default' ) );
		}

		unset( $wp_comment_types[ $comment_type ] );

		/**
		 * Fires after a comment type is unregistered.
		 *
		 * @param string $comment_type Comment type key.
		 */
		do_action( 'unregistered_comment_type', $comment_type );

		return true;
	}
}

if ( ! function_exists( 'get_comment_type_object' ) ) {
	/**
	 * Retrieves a comment type object by name.
	 *
	 * @global WP_Comment_Type[] $wp_comment_types List of comment types.
	 *
	 * @param string $comment_type The name of a registered comment type.
	 * @return WP_Comment_Type|null WP_Comment_Type object if it exists, null otherwise.
	 */
	function get_comment_type_object( $comment_type ) {
		global $wp_comment_types;

		if ( ! is_scalar( $comment_type ) || empty( $wp_comment_types[ $comment_type ] ) ) {
			return null;
		}

		return $wp_comment_types[ $comment_type ];
	}
}

if ( ! function_exists( 'get_comment_types' ) ) {
	/**
	 * Retrieves a list of registered comment type names or objects.
	 *
	 * @global WP_Comment_Type[] $wp_comment_types List of comment types.
	 *
	 * @param array  $args     Optional. An array of key => value arguments to match against
	 *                         the comment type objects. Default empty array.
	 * @param string $output   Optional. The type of output to return. Either comment type 'names'
	 *                         or 'objects'. Default 'names'.
	 * @param string $operator Optional. The logical operation to perform. 'or' means only one
	 *                         element from the array needs to match; 'and' means all elements
	 *                         must match; 'not' means no elements may match. Default 'and'.
	 * @return string[]|WP_Comment_Type[] An array of comment type names or objects.
	 */
	function get_comment_types( $args = array(), $output = 'names', $operator = 'and' ) {
		global $wp_comment_types;

		$field = ( 'names' === $output ) ? 'name' : false;

		return wp_filter_object_list( $wp_comment_types, $args, $operator, $field );
	}
}

if ( ! function_exists( 'comment_type_exists' ) ) {
	/**
	 * Determines whether a comment type is registered.
	 *
	 * @param string $comment_type Comment type name.
	 * @return bool Whether the comment type is registered.
	 */
	function comment_type_exists( $comment_type ) {
		return (bool) get_comment_type_object( $comment_type );
	}
}

if ( ! function_exists( 'get_comment_type_labels' ) ) {
	/**
	 * Builds an object with all comment type labels out of a comment type object.
	 *
	 * @param WP_Comment_Type $comment_type_object Comment type object.
	 * @return object {
	 *     Comment type labels object.
	 *
	 *     @type string $name          General name for the comment type, usually plural. Default 'Comments'.
	 *     @type string $singular_name Name for one object of this comment type. Default 'Comment'.
	 *     @type string $menu_name     Label for the menu name. Default is the same as `name`.
	 * }
	 */
	function get_comment_type_labels( $comment_type_object ) {
		$nohier_vs_hier_defaults = WP_Comment_Type::get_default_labels();

		$nohier_vs_hier_defaults['menu_name'] = $nohier_vs_hier_defaults['name'];

		$provided_labels = (array) $comment_type_object->labels;

		// _get_custom_object_labels() writes back onto the object it is given, so hand it a copy.
		$labels = _get_custom_object_labels( clone $comment_type_object, $nohier_vs_hier_defaults );

		// Remove the post type only labels the helper derives, unless they were provided.
		foreach ( array( 'name_admin_bar', 'all_items', 'archives' ) as $post_type_only_label ) {
			if ( ! array_key_exists( $post_type_only_label, $provided_labels ) ) {
				unset( $labels->$post_type_only_label );
			}
		}

		$comment_type = $comment_type_object->name;

		$default_labels = clone $labels;

		/**
		 * Filters the labels of a specific comment type.
		 *
		 * The dynamic portion of the hook name, `$comment_type`, refers to the comment type slug.
		 * Labels are stored unescaped; callers must escape them on output.
		 *
		 * @see get_comment_type_labels() for the full list of comment type labels.
		 *
		 * @param object $labels Object with labels for the comment type as member variables.
		 */
		$labels = apply_filters( "comment_type_labels_{$comment_type}", $labels );

		// Ensure that the filtered labels contain all required default values.
		return (object) array_merge( (array) $default_labels, (array) $labels );
	}
}

if ( ! $wp_comment_types_polyfill_core_registry ) {
	/*
	 * Core registers the built-in types in wp-settings.php, before plugins load, and again
	 * on 'init' and 'change_locale'. The polyfill loads on 'plugins_loaded', so it registers
	 * them right away instead.
	 */
	create_initial_comment_types();

	add_action( 'init', 'create_initial_comment_types', 0 );
	add_action( 'change_locale', 'create_initial_comment_types' );
}

/*
 * Default excluded comment types, WordPress/wordpress-develop#12310.
 */

if ( ! function_exists( 'wp_get_default_excluded_comment_types' ) ) {
	/**
	 * Retrieves the comment types that are excluded from queries and counts by default.
	 *
	 * The default set is the 'note' type plus every comment type registered with
	 * `'internal' => true`. The {@see 'default_excluded_comment_types'} filter is then
	 * applied and the result normalized: non-scalar values are discarded, the rest are
	 * cast to strings, empties and duplicates are removed, and the special type tokens
	 * understood by WP_Comment_Query ('all', 'comment', 'comments', 'pings') are stripped.
	 *
	 * @param WP_Comment_Query|null $query Optional. The current query instance when called
	 *                                     from WP_Comment_Query, or null. Default null.
	 * @return string[] Comment types excluded by default.
	 */
	function wp_get_default_excluded_comment_types( $query = null ) {
		$default_excluded_types = array( 'note' );

		// The 'note' type is listed above as well, so the default holds before types are registered.
		if ( function_exists( 'get_comment_types' ) ) {
			$default_excluded_types = array_values(
				array_unique(
					array_merge(
						$default_excluded_types,
						get_comment_types( array( 'internal' => true ), 'names' )
					)
				)
			);
		}

		/**
		 * Filters the comment types that are excluded from query results by default.
		 *
		 * Comment types in this list are omitted from `WP_Comment_Query` results unless the
		 * query explicitly requests the 'all' type, or explicitly includes the specific type
		 * via the 'type' or 'type__in' query variables. The same set is applied to the stored
		 * comment count and the comment feeds.
		 *
		 * Values must be literal `comment_type` values as stored in the database. Register
		 * callbacks for this filter unconditionally rather than toggling them per call.
		 *
		 * A plugin that starts excluding a type which already has comments in the database
		 * should recount the affected posts on activation and deactivation, with
		 * wp_update_comment_count_now().
		 *
		 * This exclusion is a default-visibility convenience, not an access-control mechanism.
		 *
		 * @param string[]              $excluded_types Comment types excluded from query results by default.
		 * @param WP_Comment_Query|null $query          The WP_Comment_Query instance, or null when the set is
		 *                                              resolved outside of a comment query.
		 */
		$excluded_types = apply_filters( 'default_excluded_comment_types', $default_excluded_types, $query );

		// Drop values that cannot be cast to a string.
		$excluded_types = array_filter( (array) $excluded_types, 'is_scalar' );

		// Drop empty strings, but keep a type literally named '0'.
		$excluded_types = array_filter(
			array_map( 'strval', $excluded_types ),
			static function ( $excluded_type ) {
				return '' !== $excluded_type;
			}
		);

		$excluded_types = array_unique( $excluded_types );

		// Strip the special type tokens so an alias cannot poison explicit-type queries.
		return array_values( array_diff( $excluded_types, array( 'all', 'comment', 'comments', 'pings' ) ) );
	}
}

if ( ! function_exists( '_wp_get_excluded_comment_types_clause' ) ) {
	/**
	 * Builds the SQL condition that excludes the default-excluded comment types.
	 *
	 * @access private
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $column Optional. The `comment_type` column to filter on. Must not contain
	 *                       user input. Default 'comment_type'.
	 * @return string Prepared ` AND <column> NOT IN (...)` condition, or an empty string when
	 *                no comment types are excluded.
	 */
	function _wp_get_excluded_comment_types_clause( $column = 'comment_type' ) {
		global $wpdb;

		$excluded_types = wp_get_default_excluded_comment_types();

		if ( ! $excluded_types ) {
			return '';
		}

		$placeholders = implode( ', ', array_fill( 0, count( $excluded_types ), '%s' ) );
		$clause       = sprintf( ' AND %s NOT IN ( %s )', $column, $placeholders );

		// The column name comes from the call sites; the type values are prepared here.
		return $wpdb->prepare( $clause, $excluded_types ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}

if ( ! $wp_comment_types_polyfill_core_exclusion ) {
	/**
	 * Excludes the default-excluded comment types from a comment query.
	 *
	 * Core does this in WP_Comment_Query::get_comment_ids(). Hooked last on
	 * 'pre_get_comments', so other callbacks see the query vars as the caller set them,
	 * and the added 'type__not_in' values become part of the query's cache key.
	 *
	 * @access private
	 *
	 * @param WP_Comment_Query $query The comment query.
	 */
	function _wp_comment_types_polyfill_exclude_from_query( $query ) {
		$raw_types = array_merge( (array) $query->query_vars['type'], (array) $query->query_vars['type__in'] );

		if ( in_array( 'all', $raw_types, true ) ) {
			return;
		}

		// Expand the type tokens to the comment_type values they represent.
		$requested_types = array();
		foreach ( $raw_types as $requested_type ) {
			switch ( $requested_type ) {
				case 'comment':
				case 'comments':
					$requested_types[] = '';
					$requested_types[] = 'comment';
					break;

				case 'pings':
					$requested_types[] = 'pingback';
					$requested_types[] = 'trackback';
					break;

				default:
					$requested_types[] = $requested_type;
					break;
			}
		}

		$type__not_in = (array) $query->query_vars['type__not_in'];

		foreach ( wp_get_default_excluded_comment_types( $query ) as $excluded_type ) {
			if ( ! in_array( $excluded_type, $requested_types, true ) && ! in_array( $excluded_type, $type__not_in, true ) ) {
				$type__not_in[] = $excluded_type;
			}
		}

		$query->query_vars['type__not_in'] = $type__not_in;
	}

	/**
	 * Recalculates a post's comment count without the default-excluded comment types.
	 *
	 * Core does this in wp_update_comment_count_now(), after the filter. Hooked last, so
	 * the query only runs when no other callback provided a count.
	 *
	 * @access private
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param int|null $new_count The new comment count, or null to calculate it.
	 * @param int      $old_count The old comment count.
	 * @param int      $post_id   Post ID.
	 * @return int|null The comment count.
	 */
	function _wp_comment_types_polyfill_update_comment_count( $new_count, $old_count, $post_id ) {
		global $wpdb;

		if ( null !== $new_count ) {
			return $new_count;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The clause is prepared.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved = '1'",
				$post_id
			) . _wp_get_excluded_comment_types_clause()
		);
	}

	/**
	 * Excludes the default-excluded comment types from the comment feeds.
	 *
	 * Core builds the condition into the WHERE clause before the filter runs, so the
	 * polyfill hooks in first.
	 *
	 * @access private
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $cwhere The WHERE clause of the comment feed query.
	 * @return string The WHERE clause.
	 */
	function _wp_comment_types_polyfill_comment_feed_where( $cwhere ) {
		global $wpdb;

		return $cwhere . _wp_get_excluded_comment_types_clause( "{$wpdb->comments}.comment_type" );
	}

	/**
	 * Counts only the comments the comment list renders when calculating a comment's page.
	 *
	 * Core changes the default 'type' of get_page_of_comment() from 'all' to ''. The
	 * default cannot be told apart from an explicit 'all' here, so every 'all' is mapped.
	 *
	 * @access private
	 *
	 * @param array $comment_args The comment query arguments.
	 * @return array The comment query arguments.
	 */
	function _wp_comment_types_polyfill_page_of_comment_args( $comment_args ) {
		if ( isset( $comment_args['type'] ) && 'all' === $comment_args['type'] ) {
			$comment_args['type'] = '';
		}

		return $comment_args;
	}

	add_action( 'pre_get_comments', '_wp_comment_types_polyfill_exclude_from_query', PHP_INT_MAX );
	add_filter( 'pre_wp_update_comment_count_now', '_wp_comment_types_polyfill_update_comment_count', PHP_INT_MAX, 3 );
	add_filter( 'comment_feed_where', '_wp_comment_types_polyfill_comment_feed_where', PHP_INT_MIN );
	add_filter( 'get_page_of_comment_query_args', '_wp_comment_types_polyfill_page_of_comment_args' );
}
