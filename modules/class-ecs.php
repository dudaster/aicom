<?php
/**
 * ECS — Ele Custom Skin (free + pro) module.
 *
 * Reads and writes ECS option data directly via wp_options / post meta.
 * No ECS classes are required at call time — all data is plain arrays.
 *
 * ECS Pro wp_options:
 *   ecs_pro_color_schemes  — [{ id, name, colors[] }]
 *   ecs_pro_font_schemes   — [{ id, name, fonts[] }]
 *   ecs_pro_custom_looks   — [{ id, name, enabled, conditions[], time_interval{}, styles{} }]
 *   ecs_pro_custom_css     — [{ id, label, css }]
 *   ecs_pro_alt_logos      — [{ id, label, attachment_id, url, alt, width, height }]
 *
 * ECS Free wp_options (Dynamic Repeater Builder):
 *   ecs_drb_presets        — { preset_id: { id, name, config{}, created } }
 *   ecs_drb_binding_index  — { "element_id:control_key": post_id }
 *
 * ECS Free post meta (Dynamic Repeater Builder):
 *   _ecs_drb_bindings      — { "element_id:control_key": { element_id, control_key, config{}, updated } }
 */
class AICOM_Module_ECS extends AICOM_Module_Base {

	// ECS Pro options
	const OPT_COLOR_SCHEMES = 'ecs_pro_color_schemes';
	const OPT_FONT_SCHEMES  = 'ecs_pro_font_schemes';
	const OPT_CUSTOM_LOOKS  = 'ecs_pro_custom_looks';
	const OPT_CUSTOM_CSS    = 'ecs_pro_custom_css';
	const OPT_ALT_LOGOS     = 'ecs_pro_alt_logos';

	// Dynamic Repeater Builder (free)
	const OPT_DRB_PRESETS       = 'ecs_drb_presets';
	const OPT_DRB_BINDING_INDEX = 'ecs_drb_binding_index';
	const META_DRB_BINDINGS     = '_ecs_drb_bindings';

	// AICOM-managed global look ID (our singleton for activate_global)
	const GLOBAL_LOOK_ID = 'aicom-global-scheme-override';

	public function get_module_name(): string {
		return 'ecs';
	}

	public function register_tools(): void {
		$dep      = 'ecs';
		$dep_pro  = 'ecs_pro';
		$scope    = [ 'manage.elementor' ];

		// ── Color Schemes (Pro) ───────────────────────────────────────────
		$this->register( 'ecs.color_schemes.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'List all ECS color schemes (id, name, color count).',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_color_schemes_list' ],
		] );

		$this->register( 'ecs.color_schemes.get', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Get a single ECS color scheme by id or name, including all color definitions.',
			'input_schema'    => [
				'id'   => [ 'type' => 'string', 'description' => 'Scheme ID.' ],
				'name' => [ 'type' => 'string', 'description' => 'Scheme name (case-insensitive). Used if id not supplied.' ],
			],
			'handler'         => [ $this, 'handle_color_schemes_get' ],
		] );

		$this->register( 'ecs.color_schemes.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Create or update an ECS color scheme. Omit id to auto-generate (creates new).',
			'input_schema'     => [
				'id'     => [ 'type' => 'string', 'description' => 'Existing scheme ID to update. Omit to create new.' ],
				'name'   => [ 'type' => 'string', 'required' => true ],
				'colors' => [ 'type' => 'array',  'required' => true, 'description' => 'Array of { id, value, type:"global"|"custom", var? }.' ],
			],
			'handler'          => [ $this, 'handle_color_schemes_save' ],
		] );

		$this->register( 'ecs.color_schemes.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Delete an ECS color scheme by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_color_schemes_delete' ],
		] );

		$this->register( 'ecs.color_schemes.activate_global', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Apply an ECS color scheme site-wide by upserting an AICOM-managed Custom Look (general condition, always enabled). Identify scheme by id or name.',
			'input_schema'     => [
				'id'   => [ 'type' => 'string', 'description' => 'Scheme ID to activate.' ],
				'name' => [ 'type' => 'string', 'description' => 'Scheme name (case-insensitive). Used if id not supplied.' ],
			],
			'handler'          => [ $this, 'handle_color_schemes_activate_global' ],
		] );

		// ── Font Schemes (Pro) ────────────────────────────────────────────
		$this->register( 'ecs.font_schemes.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'List all ECS font schemes (id, name, font count).',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_font_schemes_list' ],
		] );

		$this->register( 'ecs.font_schemes.get', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Get a single ECS font scheme by id or name, including all font definitions.',
			'input_schema'    => [
				'id'   => [ 'type' => 'string' ],
				'name' => [ 'type' => 'string', 'description' => 'Case-insensitive. Used if id not supplied.' ],
			],
			'handler'         => [ $this, 'handle_font_schemes_get' ],
		] );

		$this->register( 'ecs.font_schemes.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Create or update an ECS font scheme. Omit id to auto-generate.',
			'input_schema'     => [
				'id'    => [ 'type' => 'string' ],
				'name'  => [ 'type' => 'string', 'required' => true ],
				'fonts' => [ 'type' => 'array',  'required' => true, 'description' => 'Array of { id, type:"global"|"custom", var?, family, weight, size, size_unit, line_height, lh_unit, letter_spacing, ls_unit, transform }.' ],
			],
			'handler'          => [ $this, 'handle_font_schemes_save' ],
		] );

		$this->register( 'ecs.font_schemes.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Delete an ECS font scheme by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_font_schemes_delete' ],
		] );

		// ── Custom Looks (Pro) ────────────────────────────────────────────
		$this->register( 'ecs.custom_looks.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'List all ECS Custom Looks with their enabled state and applied styles.',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_custom_looks_list' ],
		] );

		$this->register( 'ecs.custom_looks.get', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Get a single ECS Custom Look by id or name, including full conditions and styles.',
			'input_schema'    => [
				'id'   => [ 'type' => 'string' ],
				'name' => [ 'type' => 'string', 'description' => 'Case-insensitive. Used if id not supplied.' ],
			],
			'handler'         => [ $this, 'handle_custom_looks_get' ],
		] );

		$this->register( 'ecs.custom_looks.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Create or update an ECS Custom Look. conditions: [{type:"include"|"exclude", condition_type:"general"|"front_page"|"page"|"post"|"archive"|"search"|"404", sub_type:""|"id:123"|"type:post-type"}]. styles: {color_scheme, font_scheme, custom_css:[], logo}.',
			'input_schema'     => [
				'id'            => [ 'type' => 'string' ],
				'name'          => [ 'type' => 'string', 'required' => true ],
				'enabled'       => [ 'type' => 'boolean' ],
				'conditions'    => [ 'type' => 'array' ],
				'time_interval' => [ 'type' => 'object', 'description' => '{ enabled, start:"YYYY-MM-DD", end:"YYYY-MM-DD", repeat_annually }' ],
				'styles'        => [ 'type' => 'object', 'description' => '{ color_scheme, font_scheme, custom_css:[], logo }' ],
			],
			'handler'          => [ $this, 'handle_custom_looks_save' ],
		] );

		$this->register( 'ecs.custom_looks.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Delete an ECS Custom Look by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_custom_looks_delete' ],
		] );

		$this->register( 'ecs.custom_looks.toggle', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Enable or disable an ECS Custom Look by id or name.',
			'input_schema'    => [
				'id'      => [ 'type' => 'string' ],
				'name'    => [ 'type' => 'string', 'description' => 'Case-insensitive. Used if id not supplied.' ],
				'enabled' => [ 'type' => 'boolean', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_custom_looks_toggle' ],
		] );

		// ── Custom CSS (Pro) ──────────────────────────────────────────────
		$this->register( 'ecs.custom_css.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'List all ECS Custom CSS snippets (id, label, css byte length).',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_custom_css_list' ],
		] );

		$this->register( 'ecs.custom_css.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Create or update an ECS Custom CSS snippet. Omit id to auto-generate.',
			'input_schema'     => [
				'id'    => [ 'type' => 'string' ],
				'label' => [ 'type' => 'string', 'required' => true ],
				'css'   => [ 'type' => 'string', 'required' => true ],
			],
			'handler'          => [ $this, 'handle_custom_css_save' ],
		] );

		$this->register( 'ecs.custom_css.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Delete an ECS Custom CSS snippet by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_custom_css_delete' ],
		] );

		// ── Alt Logos (Pro) ───────────────────────────────────────────────
		$this->register( 'ecs.alt_logos.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'List all ECS alternative logos.',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_alt_logos_list' ],
		] );

		$this->register( 'ecs.alt_logos.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep_pro,
			'supports_dry_run' => true,
			'description'      => 'Create or update an ECS alternative logo. Provide attachment_id (dimensions/URL auto-resolved) or url as fallback.',
			'input_schema'     => [
				'id'            => [ 'type' => 'string' ],
				'label'         => [ 'type' => 'string',  'required' => true ],
				'attachment_id' => [ 'type' => 'integer', 'description' => 'Media library attachment ID. Preferred.' ],
				'url'           => [ 'type' => 'string',  'description' => 'Direct URL — used when no attachment_id.' ],
				'alt'           => [ 'type' => 'string' ],
			],
			'handler'          => [ $this, 'handle_alt_logos_save' ],
		] );

		$this->register( 'ecs.alt_logos.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep_pro,
			'description'     => 'Delete an ECS alternative logo by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_alt_logos_delete' ],
		] );

		// ── Dynamic Repeater Builder (Free) ───────────────────────────────
		$this->register( 'ecs.drb.presets.list', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep,
			'description'     => 'List all ECS Dynamic Repeater Builder presets (saved source+mapping configurations).',
			'input_schema'    => [],
			'handler'         => [ $this, 'handle_drb_presets_list' ],
		] );

		$this->register( 'ecs.drb.presets.save', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep,
			'supports_dry_run' => true,
			'description'      => 'Create or update a DRB preset. config.source must be one of: posts, terms, acf, json. For json source include config.source_config.json with a JSON array string.',
			'input_schema'     => [
				'id'     => [ 'type' => 'string', 'description' => 'Existing preset ID to update. Omit to create new.' ],
				'name'   => [ 'type' => 'string', 'required' => true ],
				'config' => [ 'type' => 'object', 'required' => true, 'description' => '{ source:"posts"|"terms"|"acf"|"json", source_config:{}, mapping:{} }' ],
			],
			'handler'          => [ $this, 'handle_drb_presets_save' ],
		] );

		$this->register( 'ecs.drb.presets.delete', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep,
			'description'     => 'Delete a DRB preset by id.',
			'input_schema'    => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_drb_presets_delete' ],
		] );

		$this->register( 'ecs.drb.bindings.get', [
			'class'           => 'read',
			'required_scopes' => $scope,
			'dependency'      => $dep,
			'description'     => 'Get all DRB data bindings on a page/post. Each binding connects an Elementor element+control to a data source.',
			'input_schema'    => [
				'post_id' => [ 'type' => 'integer', 'required' => true ],
			],
			'handler'         => [ $this, 'handle_drb_bindings_get' ],
		] );

		$this->register( 'ecs.drb.bindings.set', [
			'class'            => 'write',
			'required_scopes'  => $scope,
			'dependency'       => $dep,
			'supports_dry_run' => true,
			'description'      => 'Set a DRB data binding on an Elementor element. For JSON source, pass source_config.json as a JSON array string — e.g. \'[{"title":"Row 1"},{"title":"Row 2"}]\'. mapping maps source keys to repeater control field names.',
			'input_schema'     => [
				'post_id'       => [ 'type' => 'integer', 'required' => true ],
				'element_id'    => [ 'type' => 'string',  'required' => true, 'description' => 'Elementor widget/element ID from elementor.page.get_tree.' ],
				'control_key'   => [ 'type' => 'string',  'required' => true, 'description' => 'Repeater control name on the widget (e.g. "items", "slides").' ],
				'source'        => [ 'type' => 'string',  'required' => true, 'description' => '"posts"|"terms"|"acf"|"json"' ],
				'source_config' => [ 'type' => 'object',  'description' => 'Source-specific config. For json: { json: "[{...}]" }. For posts: { post_type, posts_per_page, ... }.' ],
				'mapping'       => [ 'type' => 'object',  'description' => 'Map source field keys → repeater sub-control names. e.g. { "title": "slide_title", "image_url": "slide_image" }.' ],
			],
			'handler'          => [ $this, 'handle_drb_bindings_set' ],
		] );

		$this->register( 'ecs.drb.bindings.clear', [
			'class'           => 'write',
			'required_scopes' => $scope,
			'dependency'      => $dep,
			'description'     => 'Remove a DRB binding from an element, reverting it to static data.',
			'input_schema'    => [
				'post_id'     => [ 'type' => 'integer', 'required' => true ],
				'element_id'  => [ 'type' => 'string',  'required' => true ],
				'control_key' => [ 'type' => 'string',  'required' => true ],
			],
			'handler'         => [ $this, 'handle_drb_bindings_clear' ],
		] );
	}

	// ── Color Scheme Handlers ──────────────────────────────────────────────

	public function handle_color_schemes_list( array $args, array $key_record, bool $dry_run ): array {
		$schemes = get_option( self::OPT_COLOR_SCHEMES, [] );
		$list    = array_map( function ( $s ) {
			return [ 'id' => $s['id'] ?? '', 'name' => $s['name'] ?? '', 'color_count' => count( $s['colors'] ?? [] ) ];
		}, $schemes );
		return $this->ok( [ 'count' => count( $schemes ), 'schemes' => $list ] );
	}

	public function handle_color_schemes_get( array $args, array $key_record, bool $dry_run ): array {
		$scheme = $this->find_in_option( self::OPT_COLOR_SCHEMES, $args );
		if ( ! $scheme ) {
			return $this->err( 'NOT_FOUND', 'Color scheme not found', 'error', 404 );
		}
		return $this->ok( [ 'scheme' => $scheme ] );
	}

	public function handle_color_schemes_save( array $args, array $key_record, bool $dry_run ): array {
		$name = $this->require_string( $args, 'name' );
		if ( ! $name ) {
			return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
		}
		if ( ! isset( $args['colors'] ) || ! is_array( $args['colors'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Parameter colors must be an array', 'validation_failed' );
		}

		$id    = $this->require_string( $args, 'id' ) ?: $this->generate_id();
		$entry = [ 'id' => $id, 'name' => $name, 'colors' => $args['colors'] ];

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => $entry ] );
		}

		$schemes = get_option( self::OPT_COLOR_SCHEMES, [] );
		$created = $this->upsert( $schemes, $id, $entry );
		update_option( self::OPT_COLOR_SCHEMES, $schemes );

		return $this->ok(
			[ 'id' => $id, 'name' => $name, 'created' => $created ],
			[ 'target_type' => 'ecs_color_scheme', 'target_id' => $id ]
		);
	}

	public function handle_color_schemes_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$schemes = get_option( self::OPT_COLOR_SCHEMES, [] );
		if ( ! $this->remove_by_id( $schemes, $id ) ) {
			return $this->err( 'NOT_FOUND', "Color scheme $id not found", 'error', 404 );
		}
		update_option( self::OPT_COLOR_SCHEMES, $schemes );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_color_scheme', 'target_id' => $id ] );
	}

	public function handle_color_schemes_activate_global( array $args, array $key_record, bool $dry_run ): array {
		$scheme = $this->find_in_option( self::OPT_COLOR_SCHEMES, $args );
		if ( ! $scheme ) {
			return $this->err( 'NOT_FOUND', 'Color scheme not found — provide id or name', 'error', 404 );
		}

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_activate' => $scheme['name'], 'scheme_id' => $scheme['id'] ] );
		}

		$looks = get_option( self::OPT_CUSTOM_LOOKS, [] );
		$look  = [
			'id'            => self::GLOBAL_LOOK_ID,
			'name'          => 'AICOM Global Scheme Override',
			'enabled'       => true,
			'conditions'    => [ [ 'type' => 'include', 'condition_type' => 'general', 'sub_type' => '' ] ],
			'time_interval' => [ 'enabled' => false, 'start' => '', 'end' => '', 'repeat_annually' => false ],
			'styles'        => [ 'color_scheme' => $scheme['id'], 'font_scheme' => '', 'custom_css' => [], 'logo' => '' ],
		];
		$this->upsert( $looks, self::GLOBAL_LOOK_ID, $look );
		update_option( self::OPT_CUSTOM_LOOKS, $looks );

		return $this->ok(
			[ 'activated' => $scheme['name'], 'scheme_id' => $scheme['id'], 'via_look' => self::GLOBAL_LOOK_ID ],
			[ 'target_type' => 'ecs_custom_look', 'target_id' => self::GLOBAL_LOOK_ID ]
		);
	}

	// ── Font Scheme Handlers ───────────────────────────────────────────────

	public function handle_font_schemes_list( array $args, array $key_record, bool $dry_run ): array {
		$schemes = get_option( self::OPT_FONT_SCHEMES, [] );
		$list    = array_map( function ( $s ) {
			return [ 'id' => $s['id'] ?? '', 'name' => $s['name'] ?? '', 'font_count' => count( $s['fonts'] ?? [] ) ];
		}, $schemes );
		return $this->ok( [ 'count' => count( $schemes ), 'schemes' => $list ] );
	}

	public function handle_font_schemes_get( array $args, array $key_record, bool $dry_run ): array {
		$scheme = $this->find_in_option( self::OPT_FONT_SCHEMES, $args );
		if ( ! $scheme ) {
			return $this->err( 'NOT_FOUND', 'Font scheme not found', 'error', 404 );
		}
		return $this->ok( [ 'scheme' => $scheme ] );
	}

	public function handle_font_schemes_save( array $args, array $key_record, bool $dry_run ): array {
		$name = $this->require_string( $args, 'name' );
		if ( ! $name ) {
			return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
		}
		if ( ! isset( $args['fonts'] ) || ! is_array( $args['fonts'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Parameter fonts must be an array', 'validation_failed' );
		}

		$id    = $this->require_string( $args, 'id' ) ?: $this->generate_id();
		$entry = [ 'id' => $id, 'name' => $name, 'fonts' => $args['fonts'] ];

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => $entry ] );
		}

		$schemes = get_option( self::OPT_FONT_SCHEMES, [] );
		$created = $this->upsert( $schemes, $id, $entry );
		update_option( self::OPT_FONT_SCHEMES, $schemes );

		return $this->ok(
			[ 'id' => $id, 'name' => $name, 'created' => $created ],
			[ 'target_type' => 'ecs_font_scheme', 'target_id' => $id ]
		);
	}

	public function handle_font_schemes_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$schemes = get_option( self::OPT_FONT_SCHEMES, [] );
		if ( ! $this->remove_by_id( $schemes, $id ) ) {
			return $this->err( 'NOT_FOUND', "Font scheme $id not found", 'error', 404 );
		}
		update_option( self::OPT_FONT_SCHEMES, $schemes );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_font_scheme', 'target_id' => $id ] );
	}

	// ── Custom Looks Handlers ──────────────────────────────────────────────

	public function handle_custom_looks_list( array $args, array $key_record, bool $dry_run ): array {
		$looks = get_option( self::OPT_CUSTOM_LOOKS, [] );
		return $this->ok( [ 'count' => count( $looks ), 'looks' => $looks ] );
	}

	public function handle_custom_looks_get( array $args, array $key_record, bool $dry_run ): array {
		$look = $this->find_by_id_or_name( get_option( self::OPT_CUSTOM_LOOKS, [] ), $args );
		if ( ! $look ) {
			return $this->err( 'NOT_FOUND', 'Custom look not found', 'error', 404 );
		}
		return $this->ok( [ 'look' => $look ] );
	}

	public function handle_custom_looks_save( array $args, array $key_record, bool $dry_run ): array {
		$name = $this->require_string( $args, 'name' );
		if ( ! $name ) {
			return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
		}

		$id    = $this->require_string( $args, 'id' ) ?: $this->generate_id();
		$looks = get_option( self::OPT_CUSTOM_LOOKS, [] );

		$existing = null;
		foreach ( $looks as $l ) {
			if ( ( $l['id'] ?? '' ) === $id ) { $existing = $l; break; }
		}

		$entry = $existing ?? [
			'id'            => $id,
			'enabled'       => true,
			'conditions'    => [],
			'time_interval' => [ 'enabled' => false, 'start' => '', 'end' => '', 'repeat_annually' => false ],
			'styles'        => [ 'color_scheme' => '', 'font_scheme' => '', 'custom_css' => [], 'logo' => '' ],
		];

		$entry['id']   = $id;
		$entry['name'] = $name;
		if ( isset( $args['enabled'] ) )       { $entry['enabled']       = (bool) $args['enabled']; }
		if ( isset( $args['conditions'] ) )     { $entry['conditions']    = (array) $args['conditions']; }
		if ( isset( $args['time_interval'] ) )  { $entry['time_interval'] = (array) $args['time_interval']; }
		if ( isset( $args['styles'] ) )         { $entry['styles']        = (array) $args['styles']; }

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => $entry ] );
		}

		$created = $this->upsert( $looks, $id, $entry );
		update_option( self::OPT_CUSTOM_LOOKS, $looks );

		return $this->ok(
			[ 'id' => $id, 'name' => $name, 'created' => $created ],
			[ 'target_type' => 'ecs_custom_look', 'target_id' => $id ]
		);
	}

	public function handle_custom_looks_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$looks = get_option( self::OPT_CUSTOM_LOOKS, [] );
		if ( ! $this->remove_by_id( $looks, $id ) ) {
			return $this->err( 'NOT_FOUND', "Custom look $id not found", 'error', 404 );
		}
		update_option( self::OPT_CUSTOM_LOOKS, $looks );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_custom_look', 'target_id' => $id ] );
	}

	public function handle_custom_looks_toggle( array $args, array $key_record, bool $dry_run ): array {
		if ( ! isset( $args['enabled'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Parameter enabled is required', 'validation_failed' );
		}
		$looks = get_option( self::OPT_CUSTOM_LOOKS, [] );
		$found = false;
		$id    = '';
		$name  = '';
		foreach ( $looks as &$look ) {
			if ( $this->matches_id_or_name( $look, $args ) ) {
				$look['enabled'] = (bool) $args['enabled'];
				$found           = true;
				$id              = $look['id']   ?? '';
				$name            = $look['name'] ?? '';
				break;
			}
		}
		unset( $look );

		if ( ! $found ) {
			return $this->err( 'NOT_FOUND', 'Custom look not found', 'error', 404 );
		}

		update_option( self::OPT_CUSTOM_LOOKS, $looks );
		return $this->ok(
			[ 'id' => $id, 'name' => $name, 'enabled' => (bool) $args['enabled'] ],
			[ 'target_type' => 'ecs_custom_look', 'target_id' => $id ]
		);
	}

	// ── Custom CSS Handlers ────────────────────────────────────────────────

	public function handle_custom_css_list( array $args, array $key_record, bool $dry_run ): array {
		$snippets = get_option( self::OPT_CUSTOM_CSS, [] );
		$list     = array_map( function ( $s ) {
			return [ 'id' => $s['id'] ?? '', 'label' => $s['label'] ?? '', 'css_bytes' => strlen( $s['css'] ?? '' ) ];
		}, $snippets );
		return $this->ok( [ 'count' => count( $snippets ), 'snippets' => $list ] );
	}

	public function handle_custom_css_save( array $args, array $key_record, bool $dry_run ): array {
		$label = $this->require_string( $args, 'label' );
		if ( ! $label ) {
			return $this->err( 'MISSING_PARAM', 'Parameter label is required', 'validation_failed' );
		}
		if ( ! isset( $args['css'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Parameter css is required', 'validation_failed' );
		}

		$id    = $this->require_string( $args, 'id' ) ?: $this->generate_id();
		$entry = [ 'id' => $id, 'label' => $label, 'css' => (string) $args['css'] ];

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => [ 'id' => $id, 'label' => $label ] ] );
		}

		$snippets = get_option( self::OPT_CUSTOM_CSS, [] );
		$created  = $this->upsert( $snippets, $id, $entry );
		update_option( self::OPT_CUSTOM_CSS, $snippets );

		return $this->ok(
			[ 'id' => $id, 'label' => $label, 'created' => $created ],
			[ 'target_type' => 'ecs_custom_css', 'target_id' => $id ]
		);
	}

	public function handle_custom_css_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$snippets = get_option( self::OPT_CUSTOM_CSS, [] );
		if ( ! $this->remove_by_id( $snippets, $id ) ) {
			return $this->err( 'NOT_FOUND', "CSS snippet $id not found", 'error', 404 );
		}
		update_option( self::OPT_CUSTOM_CSS, $snippets );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_custom_css', 'target_id' => $id ] );
	}

	// ── Alt Logos Handlers ─────────────────────────────────────────────────

	public function handle_alt_logos_list( array $args, array $key_record, bool $dry_run ): array {
		$logos = get_option( self::OPT_ALT_LOGOS, [] );
		return $this->ok( [ 'count' => count( $logos ), 'logos' => $logos ] );
	}

	public function handle_alt_logos_save( array $args, array $key_record, bool $dry_run ): array {
		$label = $this->require_string( $args, 'label' );
		if ( ! $label ) {
			return $this->err( 'MISSING_PARAM', 'Parameter label is required', 'validation_failed' );
		}
		if ( ! isset( $args['attachment_id'] ) && ! isset( $args['url'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Either attachment_id or url is required', 'validation_failed' );
		}

		$id            = $this->require_string( $args, 'id' ) ?: $this->generate_id();
		$attachment_id = isset( $args['attachment_id'] ) ? (int) $args['attachment_id'] : 0;
		$url           = $this->require_string( $args, 'url' ) ?? '';
		$alt           = $this->require_string( $args, 'alt' ) ?? '';
		$width         = 0;
		$height        = 0;

		if ( $attachment_id ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( $meta ) {
				$width  = (int) ( $meta['width']  ?? 0 );
				$height = (int) ( $meta['height'] ?? 0 );
			}
			if ( ! $url ) {
				$url = (string) wp_get_attachment_url( $attachment_id );
			}
			if ( ! $alt ) {
				$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			}
		}

		$entry = compact( 'id', 'label', 'attachment_id', 'url', 'alt', 'width', 'height' );

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => [ 'id' => $id, 'label' => $label ] ] );
		}

		$logos   = get_option( self::OPT_ALT_LOGOS, [] );
		$created = $this->upsert( $logos, $id, $entry );
		update_option( self::OPT_ALT_LOGOS, $logos );

		return $this->ok(
			[ 'id' => $id, 'label' => $label, 'created' => $created ],
			[ 'target_type' => 'ecs_alt_logo', 'target_id' => $id ]
		);
	}

	public function handle_alt_logos_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$logos = get_option( self::OPT_ALT_LOGOS, [] );
		if ( ! $this->remove_by_id( $logos, $id ) ) {
			return $this->err( 'NOT_FOUND', "Logo $id not found", 'error', 404 );
		}
		update_option( self::OPT_ALT_LOGOS, $logos );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_alt_logo', 'target_id' => $id ] );
	}

	// ── Dynamic Repeater Builder Handlers ─────────────────────────────────

	public function handle_drb_presets_list( array $args, array $key_record, bool $dry_run ): array {
		$presets = array_values( (array) get_option( self::OPT_DRB_PRESETS, [] ) );
		return $this->ok( [ 'count' => count( $presets ), 'presets' => $presets ] );
	}

	public function handle_drb_presets_save( array $args, array $key_record, bool $dry_run ): array {
		$name = $this->require_string( $args, 'name' );
		if ( ! $name ) {
			return $this->err( 'MISSING_PARAM', 'Parameter name is required', 'validation_failed' );
		}
		if ( ! isset( $args['config'] ) || ! is_array( $args['config'] ) ) {
			return $this->err( 'MISSING_PARAM', 'Parameter config must be an object', 'validation_failed' );
		}

		$presets   = (array) get_option( self::OPT_DRB_PRESETS, [] );
		$id        = $this->require_string( $args, 'id' ) ?: 'preset_' . bin2hex( random_bytes( 4 ) );
		$created   = ! isset( $presets[ $id ] );
		$entry     = [
			'id'      => $id,
			'name'    => $name,
			'config'  => $args['config'],
			'created' => $presets[ $id ]['created'] ?? time(),
		];

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_save' => [ 'id' => $id, 'name' => $name ] ] );
		}

		$presets[ $id ] = $entry;
		update_option( self::OPT_DRB_PRESETS, $presets );

		return $this->ok(
			[ 'id' => $id, 'name' => $name, 'created' => $created ],
			[ 'target_type' => 'ecs_drb_preset', 'target_id' => $id ]
		);
	}

	public function handle_drb_presets_delete( array $args, array $key_record, bool $dry_run ): array {
		$id = $this->require_string( $args, 'id' );
		if ( ! $id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter id is required', 'validation_failed' );
		}
		$presets = (array) get_option( self::OPT_DRB_PRESETS, [] );
		if ( ! isset( $presets[ $id ] ) ) {
			return $this->err( 'NOT_FOUND', "Preset $id not found", 'error', 404 );
		}
		unset( $presets[ $id ] );
		update_option( self::OPT_DRB_PRESETS, $presets );
		return $this->ok( [ 'id' => $id, 'deleted' => true ], [ 'target_type' => 'ecs_drb_preset', 'target_id' => $id ] );
	}

	public function handle_drb_bindings_get( array $args, array $key_record, bool $dry_run ): array {
		$post_id = $this->require_int( $args, 'post_id' );
		if ( ! $post_id ) {
			return $this->err( 'MISSING_PARAM', 'Parameter post_id is required', 'validation_failed' );
		}
		$raw      = get_post_meta( $post_id, self::META_DRB_BINDINGS, true );
		$bindings = is_array( $raw ) ? $raw : [];
		return $this->ok( [ 'post_id' => $post_id, 'count' => count( $bindings ), 'bindings' => array_values( $bindings ) ] );
	}

	public function handle_drb_bindings_set( array $args, array $key_record, bool $dry_run ): array {
		$post_id     = $this->require_int( $args, 'post_id' );
		$element_id  = $this->require_string( $args, 'element_id' );
		$control_key = $this->require_string( $args, 'control_key' );
		$source      = $this->require_string( $args, 'source' );

		if ( ! $post_id || ! $element_id || ! $control_key || ! $source ) {
			return $this->err( 'MISSING_PARAM', 'Parameters post_id, element_id, control_key, source are required', 'validation_failed' );
		}

		$valid_sources = [ 'posts', 'terms', 'acf', 'json' ];
		if ( ! in_array( $source, $valid_sources, true ) ) {
			return $this->err( 'INVALID_PARAM', 'source must be one of: ' . implode( ', ', $valid_sources ), 'validation_failed' );
		}

		$source_config = isset( $args['source_config'] ) ? (array) $args['source_config'] : [];
		$mapping       = isset( $args['mapping'] )       ? (array) $args['mapping']       : [];

		$binding_key = $element_id . ':' . $control_key;
		$binding     = [
			'element_id'    => $element_id,
			'control_key'   => $control_key,
			'config'        => array_merge( [ 'source' => $source, 'source_config' => $source_config, 'mapping' => $mapping ] ),
			'updated'       => time(),
		];

		if ( $dry_run ) {
			return $this->ok( [ 'dry_run' => true, 'would_bind' => $binding_key, 'source' => $source ] );
		}

		$raw                      = get_post_meta( $post_id, self::META_DRB_BINDINGS, true );
		$bindings                 = is_array( $raw ) ? $raw : [];
		$bindings[ $binding_key ] = $binding;
		update_post_meta( $post_id, self::META_DRB_BINDINGS, $bindings );

		$index                 = (array) get_option( self::OPT_DRB_BINDING_INDEX, [] );
		$index[ $binding_key ] = $post_id;
		update_option( self::OPT_DRB_BINDING_INDEX, $index, false );

		return $this->ok(
			[ 'post_id' => $post_id, 'binding_key' => $binding_key, 'source' => $source ],
			[ 'target_type' => 'ecs_drb_binding', 'target_id' => "$post_id:$binding_key" ]
		);
	}

	public function handle_drb_bindings_clear( array $args, array $key_record, bool $dry_run ): array {
		$post_id     = $this->require_int( $args, 'post_id' );
		$element_id  = $this->require_string( $args, 'element_id' );
		$control_key = $this->require_string( $args, 'control_key' );

		if ( ! $post_id || ! $element_id || ! $control_key ) {
			return $this->err( 'MISSING_PARAM', 'Parameters post_id, element_id, control_key are required', 'validation_failed' );
		}

		$binding_key = $element_id . ':' . $control_key;
		$raw         = get_post_meta( $post_id, self::META_DRB_BINDINGS, true );
		$bindings    = is_array( $raw ) ? $raw : [];

		if ( ! isset( $bindings[ $binding_key ] ) ) {
			return $this->err( 'NOT_FOUND', "Binding $binding_key not found on post $post_id", 'error', 404 );
		}

		unset( $bindings[ $binding_key ] );
		update_post_meta( $post_id, self::META_DRB_BINDINGS, $bindings );

		$index = (array) get_option( self::OPT_DRB_BINDING_INDEX, [] );
		unset( $index[ $binding_key ] );
		update_option( self::OPT_DRB_BINDING_INDEX, $index, false );

		return $this->ok(
			[ 'post_id' => $post_id, 'binding_key' => $binding_key, 'cleared' => true ],
			[ 'target_type' => 'ecs_drb_binding', 'target_id' => "$post_id:$binding_key" ]
		);
	}

	// ── Private Helpers ────────────────────────────────────────────────────

	private function find_in_option( string $option_key, array $args ): ?array {
		return $this->find_by_id_or_name( get_option( $option_key, [] ), $args );
	}

	private function find_by_id_or_name( array $list, array $args ): ?array {
		$id   = $this->require_string( $args, 'id' );
		$name = $this->require_string( $args, 'name' );
		foreach ( $list as $item ) {
			if ( $id && ( $item['id'] ?? '' ) === $id ) { return $item; }
			if ( $name && strtolower( $item['name'] ?? '' ) === strtolower( $name ) ) { return $item; }
		}
		return null;
	}

	private function matches_id_or_name( array $item, array $args ): bool {
		$id   = $this->require_string( $args, 'id' );
		$name = $this->require_string( $args, 'name' );
		if ( $id && ( $item['id'] ?? '' ) === $id ) { return true; }
		if ( $name && strtolower( $item['name'] ?? '' ) === strtolower( $name ) ) { return true; }
		return false;
	}

	/**
	 * Upsert an item into $list by id.
	 * Returns true if a new item was inserted, false if an existing one was replaced.
	 */
	private function upsert( array &$list, string $id, array $entry ): bool {
		foreach ( $list as $i => $item ) {
			if ( ( $item['id'] ?? '' ) === $id ) {
				$list[ $i ] = $entry;
				return false;
			}
		}
		$list[] = $entry;
		return true;
	}

	/**
	 * Remove an item by id from $list.
	 * Returns true if found and removed.
	 */
	private function remove_by_id( array &$list, string $id ): bool {
		foreach ( $list as $i => $item ) {
			if ( ( $item['id'] ?? '' ) === $id ) {
				array_splice( $list, $i, 1 );
				return true;
			}
		}
		return false;
	}

	private function generate_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}
}
