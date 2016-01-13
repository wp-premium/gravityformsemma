<?php

GFForms::include_feed_addon_framework();

class GFEmma extends GFFeedAddOn {

	protected $_version = GF_EMMA_VERSION;
	protected $_min_gravityforms_version = '1.9.10.19';
	protected $_slug = 'gravityformsemma';
	protected $_path = 'gravityformsemma/emma.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms Emma Add-On';
	protected $_short_title = 'Emma';
	protected $api = null;
	protected $_new_custom_fields = array();

	/**
	 * Members plugin integration
	 */
	protected $_capabilities = array( 'gravityforms_emma', 'gravityforms_emma_uninstall' );

	/**
	 * Permissions
	 */
	protected $_capabilities_settings_page = 'gravityforms_emma';
	protected $_capabilities_form_settings = 'gravityforms_emma';
	protected $_capabilities_uninstall = 'gravityforms_emma_uninstall';
	protected $_enable_rg_autoupgrade = true;

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFEmma
	 */
	public static function get_instance() {

		if ( self::$_instance == null ) {
			self::$_instance = new GFEmma();
		}

		return self::$_instance;

	}

	// # FEED PROCESSING -----------------------------------------------------------------------------------------------

	/**
	 * Process the feed, subscribe the user to the list.
	 *
	 * @param array $feed The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form The form object currently being processed.
	 *
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {

		$this->log_debug( __METHOD__ . '(): Processing feed.' );

		/* If API instance is not initialized, exit. */
		if ( ! $this->initialize_api() ) {
			$this->log_error( __METHOD__ . '(): Failed to set up the API.' );

			return;
		}

		/* Setup member array. */
		$member = array(
			'email'     => $this->get_field_value( $form, $entry, $feed['meta']['email_address'] ),
			'fields'    => array(),
			'group_ids' => array()
		);

		/* If email address is empty, exit. */
		if ( GFCommon::is_invalid_or_empty_email( $member['email'] ) ) {
			$this->log_error( __METHOD__ . '(): Aborting. Email address invalid.' );

			return;
		}

		/* If a group is set, add it to the member array. */
		if ( $feed['meta']['group'] !== 'none' ) {
			$member['group_ids'][] = $feed['meta']['group'];
		}

		/* Add custom fields (if exist) to the member array. */
		if ( ! empty( $feed['meta']['custom_fields'] ) ) {

			foreach ( $feed['meta']['custom_fields'] as $custom_field ) {
				$member['fields'][ $custom_field['key'] ] = $this->get_field_value( $form, $entry, $custom_field['value'] );
			}

		}

		/* If no custom fields were added to the member array, remove it. */
		if ( empty( $member['fields'] ) ) {
			unset( $member['fields'] );
		}

		/* Add member to group. */
		$this->log_debug( __METHOD__ . '(): Member to be added => ' . print_r( $member, true ) );
		try {

			/* If double optin, use membersSignup function. Otherwise, use addSingle function. */
			if ( $feed['meta']['double_optin'] == true ) {
				$add_member = json_decode( $this->api->membersSignup( $member ) );
			} else {
				$add_member = json_decode( $this->api->membersAddSingle( $member ) );
			}

			if ( $add_member->status == true ) {
				$this->log_debug( __METHOD__ . "(): Member {$member['email']} added." );
			} else {
				$this->log_debug( __METHOD__ . "(): Member {$member['email']} already existed and has been updated." );
			}

		} catch ( Exception $e ) {

			$this->log_error( __METHOD__ . "(): Unable to add member {$member['email']}; {$e->getMessage()}" );

		}

	}


	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------

	/**
	 * Plugin starting point. Handles hooks, loading of language files and PayPal delayed payment support.
	 */
	public function init() {

		parent::init();

		$this->add_delayed_payment_support(
			array(
				'option_label' => esc_html__( 'Subscribe member to Emma only when payment is received.', 'gravityformsemma' )
			)
		);

	}

	// ------- Plugin settings -------

	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {

		return array(
			array(
				'title'       => esc_html__( 'Emma Account Information', 'gravityformsemma' ),
				'description' => '<p>' . sprintf(
						esc_html__( 'Emma makes it easy to send email newsletters to your customers, manage your subscriber lists, and track campaign performance. Use Gravity Forms to collect customer information and automatically add them to your Emma member groups. If you don\'t have a Emma account, you can %1$s sign up for one here.%2$s', 'gravityformsemma' ),
						'<a href="http://myemma.com/partners/get-started?utm_source=GravityForms&utm_medium=integrationpartner&utm_campaign=GravityForms-integrationpartner-partner-trial" target="_blank">', '</a>'
					) . '</p>',
				'fields'      => array(
					array(
						'name'              => 'account_id',
						'label'             => esc_html__( 'Account ID', 'gravityformsemma' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'has_valid_account_id' )
					),
					array(
						'name'              => 'public_api_key',
						'label'             => esc_html__( 'Public API Key', 'gravityformsemma' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'initialize_api' )
					),
					array(
						'name'              => 'private_api_key',
						'label'             => esc_html__( 'Private API Key', 'gravityformsemma' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'initialize_api' )
					),
					array(
						'type'     => 'save',
						'messages' => array(
							'success' => esc_html__( 'Emma settings have been updated.', 'gravityformsemma' )
						),
					),
				),
			),
		);

	}

	// ------- Feed page -------

	/**
	 * Prevent feeds being listed or created if the api key isn't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {

		return $this->has_valid_account_id();

	}

	/**
	 * If the api keys are invalid or empty return the appropriate message.
	 *
	 * @return string
	 */
	public function configure_addon_message() {

		$settings_label = sprintf( esc_html__( '%s Settings', 'gravityforms' ), $this->get_short_title() );
		$settings_link  = sprintf( '<a href="%s">%s</a>', esc_url( $this->get_plugin_settings_url() ), $settings_label );

		if ( is_null( $this->has_valid_account_id() ) ) {

			return sprintf( esc_html__( 'To get started, please configure your %s.', 'gravityforms' ), $settings_link );
		}

		return sprintf( esc_html__( 'Please make sure you have entered valid API credentials on the %s page.', 'gravityformsemma' ), $settings_link );

	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {

		return array(
			'feed_name' => esc_html__( 'Feed Name', 'gravityformsemma' ),
			'group'     => esc_html__( 'Emma Group', 'gravityformsemma' ),
		);

	}

	/**
	 * Returns the value to be displayed in the Emma Group column.
	 *
	 * @param array $feed The feed being included in the feed list.
	 *
	 * @return string
	 */
	public function get_column_value_group( $feed ) {

		/* If API credentials are invalid, return group ID. */
		if ( ! $this->initialize_api() ) {
			return $feed['meta']['group'];
		}

		/* Get group and return name */
		$group = json_decode( $this->api->groupsGetById( $feed['meta']['group'] ) );

		return ( ! empty( $group ) ) ? $group->group_name : $feed['meta']['group'];

	}

	/**
	 * Configures the settings which should be rendered on the feed edit page.
	 *
	 * @return array The feed settings.
	 */
	public function feed_settings_fields() {

		return array(
			array(
				'title'  => '',
				'fields' => array(
					array(
						'name'          => 'feed_name',
						'label'         => esc_html__( 'Feed Name', 'gravityformsemma' ),
						'type'          => 'text',
						'required'      => true,
						'default_value' => $this->get_default_feed_name(),
						'tooltip'       => '<h6>' . esc_html__( 'Name', 'gravityformsemma' ) . '</h6>' . esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gravityformsemma' )
					),
					array(
						'name'     => 'group',
						'label'    => esc_html__( 'Emma Group', 'gravityformsemma' ),
						'type'     => 'select',
						'required' => true,
						'choices'  => $this->groups_for_feed_setting(),
						'tooltip'  => '<h6>' . esc_html__( 'Emma Group', 'gravityformsemma' ) . '</h6>' . esc_html__( 'Select which Emma group this feed will add members to.', 'gravityformsemma' )
					),
					array(
						'name'     => 'email_address',
						'label'    => esc_html__( 'Email Address', 'gravityformsemma' ),
						'type'     => 'field_select',
						'required' => true,
						'args'     => array( 'input_types' => array( 'email' ) ),
						'tooltip'  => '<h6>' . esc_html__( 'Email Address', 'gravityformsemma' ) . '</h6>' . esc_html__( 'Select which field will be used for the member\'s email address.', 'gravityformsemma' )
					),
					array(
						'name'      => 'custom_fields',
						'label'     => esc_html__( 'Custom Fields', 'gravityformsemma' ),
						'type'      => 'dynamic_field_map',
						'field_map' => $this->custom_fields_for_feed_setting(),
						'tooltip'   => '<h6>' . esc_html__( 'Custom Fields', 'gravityformsemma' ) . '</h6>' . esc_html__( 'Select or create a new Emma custom field to pair with Gravity Forms fields.', 'gravityformsemma' )
					),
					array(
						'name'    => 'feed_condition',
						'label'   => esc_html__( 'Conditional Logic', 'gravityformsemma' ),
						'type'    => 'feed_condition',
						'tooltip' => '<h6>' . esc_html__( 'Conditional Logic', 'gravityformsemma' ) . '</h6>' . esc_html__( 'When conditional logic is enabled, form submissions will only be exported to Emma when the condition is met. When disabled, all form submissions will be exported.', 'gravityformsemma' )
					),
					array(
						'name'    => 'options',
						'label'   => esc_html__( 'Options', 'gravityformsemma' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'          => 'double_optin',
								'label'         => esc_html__( 'Double Opt-In', 'gravityformsemma' ),
								'default_value' => 0,
								'tooltip'       => '<h6>' . esc_html__( 'Double Opt-In', 'gravityformsemma' ) . '</h6>' . esc_html__( 'When the double opt-in option is enabled, Emma will send a confirmation email to the user. This is an optional feature by Emma. If you choose to enable it, you can use Emma\'s segmentation feature to send emails only to users who have double opted-in.', 'gravityformsemma' ),
							),
						)
					),
				)
			)
		);

	}

	/**
	 * Fork of maybe_save_feed_settings to create new Emma custom fields.
	 *
	 * @param int $feed_id The current Feed ID.
	 * @param int $form_id The current Form ID.
	 *
	 * @return int
	 */
	public function maybe_save_feed_settings( $feed_id, $form_id ) {

		if ( ! rgpost( 'gform-settings-save' ) ) {
			return $feed_id;
		}

		// store a copy of the previous settings for cases where action would only happen if value has changed
		$feed = $this->get_feed( $feed_id );
		$this->set_previous_settings( $feed['meta'] );

		$settings = $this->get_posted_settings();
		$settings = $this->create_new_custom_fields( $settings );
		$sections = $this->get_feed_settings_fields();
		$settings = $this->trim_conditional_logic_vales( $settings, $form_id );

		$is_valid = $this->validate_settings( $sections, $settings );
		$result   = false;

		if ( $is_valid ) {
			$feed_id = $this->save_feed_settings( $feed_id, $form_id, $settings );
			if ( $feed_id ) {
				GFCommon::add_message( $this->get_save_success_message( $sections ) );
			} else {
				GFCommon::add_error_message( $this->get_save_error_message( $sections ) );
			}
		} else {
			GFCommon::add_error_message( $this->get_save_error_message( $sections ) );
		}

		return $feed_id;
	}

	/**
	 * Prepare Emma groups for feed field.
	 *
	 * @return array
	 */
	public function groups_for_feed_setting() {

		$groups = array();

		/* If Emma API credentials are invalid, return the groups array. */
		if ( ! $this->initialize_api() ) {
			return $groups;
		}

		/* Get available Emma groups. */
		$emma_groups = json_decode( $this->api->myGroups( array( 'group_types' => 'g,t' ) ) );

		/* If no Emma groups exist, return the groups array. */
		if ( empty( $emma_groups ) ) {
			return $groups;
		}

		/* Add Emma groups to array and return it. */
		foreach ( $emma_groups as $group ) {

			$groups[] = array(
				'label' => $group->group_name,
				'value' => $group->member_group_id
			);

		}

		return $groups;

	}

	/**
	 * Prepare Emma custom fields for feed field.
	 *
	 * @return array
	 */
	public function custom_fields_for_feed_setting() {

		$fields = array(
			array(
				'label' => esc_html__( 'Select Emma Field', 'gravityformsemma' ),
				'value' => ''
			)
		);

		/* If Emma API credentials are invalid, return the fields array. */
		if ( ! $this->initialize_api() ) {
			return $fields;
		}

		/* Get available Emma fields. */
		$emma_fields = json_decode( $this->api->myFields() );

		/* If no Emma fields exist, return the fields array. */
		if ( empty( $emma_fields ) ) {
			return $fields;
		}

		/* Add Emma fields to array. */
		foreach ( $emma_fields as $field ) {

			$fields[] = array(
				'label' => $field->display_name,
				'value' => $field->shortcut_name
			);

		}

		/* Check if any new custom fields were added in this request and add them to the UI. */
		if ( ! empty( $this->_new_custom_fields ) ) {

			foreach ( $this->_new_custom_fields as $new_field ) {

				$found_custom_field = false;
				foreach ( $fields as $field ) {

					if ( $field['value'] == $new_field['value'] ) {
						$found_custom_field = true;
					}

				}

				if ( ! $found_custom_field ) {
					$fields[] = array(
						'label' => $new_field['label'],
						'value' => $new_field['value']
					);
				}

			}

		}

		/* Add "Add Custom Field" to array. */
		$fields[] = array(
			'label' => esc_html__( 'Add Custom Field', 'gravityformsemma' ),
			'value' => 'gf_custom'
		);

		return $fields;

	}


	// # HELPERS -------------------------------------------------------------------------------------------------------

	/**
	 * Checks validity of Emma API credentials and initializes API if valid.
	 *
	 * @return bool|null
	 */
	public function initialize_api() {

		if ( ! is_null( $this->api ) ) {
			return true;
		}

		/* Load the Emma API library. */
		require_once 'includes/api/Emma.php';

		/* Get the plugin settings */
		$settings = $this->get_plugin_settings();

		/* If any of the account information fields are empty, return null. */
		if ( rgblank( $settings['account_id'] ) || rgblank( $settings['public_api_key'] ) || rgblank( $settings['private_api_key'] ) ) {
			return null;
		}

		$this->log_debug( __METHOD__ . "(): Validating API info for account {$settings['account_id']}." );

		$emma = new Emma( $settings['account_id'], $settings['public_api_key'], $settings['private_api_key'], false );

		try {

			/* Run test request. */
			$emma->myGroups();

			/* Log that test passed. */
			$this->log_debug( __METHOD__ . '(): API credentials are valid.' );

			/* Assign Emma object to the class. */
			$this->api = $emma;

			return true;

		} catch ( Exception $e ) {

			/* Log that test failed based on HTTP code. */
			if ( $e->getHttpCode() == 401 ) {

				$this->log_error( __METHOD__ . '(): API credentials are invalid; ' . $e->getMessage() );

				return false;

			} else if ( $e->getHttpCode() == 403 ) {

				$this->log_error( __METHOD__ . '(): API credentials are valid, Account ID is invalid; ' . $e->getMessage() );

				return true;

			}

		}

	}

	/**
	 * Check if the account ID is valid.
	 *
	 * @return bool|null
	 */
	public function has_valid_account_id() {

		/* Load the Emma API library. */
		require_once 'includes/api/Emma.php';

		/* Get the plugin settings */
		$settings = $this->get_plugin_settings();

		/* If any of the account information fields are empty, return null. */
		if ( rgblank( $settings['account_id'] ) || rgblank( $settings['public_api_key'] ) || rgblank( $settings['private_api_key'] ) ) {
			return null;
		}

		$emma = new Emma( $settings['account_id'], $settings['public_api_key'], $settings['private_api_key'], false );

		try {

			/* Run test request. */
			$emma->myGroups();

			return true;

		} catch ( Exception $e ) {

			/* Log that test failed based on HTTP code. */
			if ( $e->getHttpCode() == 403 ) {
				$this->log_error( __METHOD__ . '(): API credentials are valid, Account ID is invalid; ' . $e->getMessage() );
			}

			return false;

		}

	}

	/**
	 * Create new Emma custom fields.
	 *
	 * @param array $settings
	 *
	 * @return array
	 */
	public function create_new_custom_fields( $settings ) {

		global $_gaddon_posted_settings;

		/* If no custom fields are set or if the API credentials are invalid, return settings. */
		if ( empty( $settings['custom_fields'] ) || ! $this->initialize_api() ) {
			return $settings;
		}

		/* Loop through each custom field. */
		foreach ( $settings['custom_fields'] as $index => &$custom_field ) {

			/* If no custom key is set, move on. */
			if ( rgblank( $custom_field['custom_key'] ) ) {
				continue;
			}

			$shortcut_name = trim( $custom_field['custom_key'] ); // Set shortcut name to custom key
			$shortcut_name = str_replace( ' ', '_', $shortcut_name ); // Remove all spaces
			$shortcut_name = preg_replace( '([^\w\d])', '', $shortcut_name ); // Strip all custom characters
			$shortcut_name = strtolower( $shortcut_name ); // Set to lowercase
			$shortcut_name .= '_' . uniqid(); // Add a unique ID

			/* Prepare new field to add. */
			$field_to_add = array(
				'column_order'  => 0,
				'display_name'  => $custom_field['custom_key'],
				'field_type'    => 'text',
				'shortcut_name' => $shortcut_name,
				'widget_type'   => 'text',
			);

			/* Add new field. */
			$new_field = $this->add_emma_custom_field( $field_to_add );

			/* Replace key for field with new shortcut name and reset custom key. */
			$custom_field['key']        = $field_to_add['shortcut_name'];
			$custom_field['custom_key'] = '';

			/* Update POST field to ensure front-end display is up-to-date. */
			$_gaddon_posted_settings['custom_fields'][ $index ]['key']        = $field_to_add['shortcut_name'];
			$_gaddon_posted_settings['custom_fields'][ $index ]['custom_key'] = '';

			/* Push to new custom fields array to update the UI. */
			$this->_new_custom_fields[] = array(
				'label' => $field_to_add['display_name'],
				'value' => $field_to_add['shortcut_name'],
			);

		}

		return $settings;

	}

	/**
	 * Adds new Emma custom field
	 *
	 * @param array $field The field properties.
	 *
	 * @return string|bool
	 */
	public function add_emma_custom_field( $field ) {

		if ( ! $this->initialize_api() ) {
			return false;
		}

		try {

			$new_field = $this->api->fieldsAddSingle( $field );
			$this->log_debug( __METHOD__ . '(): Custom field ' . $field['shortcut_name'] . ' created' );

			return $new_field;

		} catch ( Exception $e ) {

			$this->log_error( __METHOD__ . '(): Custom field not created; ' . $e->getMessage() );

			return false;

		}

	}

}