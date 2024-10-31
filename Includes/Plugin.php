<?php

namespace PelemanProductUploader\Includes;

use PelemanProductUploader\Includes\PpuLoader;
use PelemanProductUploader\Includes\PpuI18n;
use PelemanProductUploader\Admin\PpuAdmin;
use PelemanProductUploader\PublicPage\PpuPublic;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 */
class Plugin
{

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct()
	{
		if (defined('PELEMAN_PRODUCT_UPLOADER_VERSION')) {
			$this->version = PELEMAN_PRODUCT_UPLOADER_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'peleman-product_uploader';

		//$this->load_dependencies();
		$this->loader = new PpuLoader();
		$this->set_locale();
		$this->define_admin_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies()
	{
		$this->loader = new PpuLoader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 */
	private function set_locale()
	{

		$plugin_i18n = new PpuI18n();

		$this->loader->add_action('plugins_loaded', $plugin_i18n, 'load_plugin_textdomain');
	}

	/**
	 * Register all of the hooks related to the admin area functionality of the plugin.
	 */
	private function define_admin_hooks()
	{
		$plugin_admin = new PpuAdmin($this->get_plugin_name(), $this->get_version());

		// Enqueue scripts and styles
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
		$this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts', 5);
		// Register admin menu, plugin settings
		$this->loader->add_action('admin_menu', $plugin_admin, 'ppu_add_admin_menu');
		$this->loader->add_action('admin_init', $plugin_admin, 'ppu_register_plugin_settings');
		// Register GET endpoints
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetAttributesEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetCategoriesEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetTagsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetProductsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetTermsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetVariationsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerGetImagesEndpoint');
		// Register POST endpoints
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostAttributesEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostCategoriesEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostTagsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostProductsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostTermsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostVariationsEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostImageEndpoint');
		$this->loader->add_action('rest_api_init', $plugin_admin, 'registerPostMenuEndpoint');
		// Various
		$this->loader->add_action('big_image_size_threshold', $plugin_admin, 'disableImageDownscaling'); // disable WP adding "-scaled" to images
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run()
	{
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name()
	{
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PPU_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader()
	{
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version()
	{
		return $this->version;
	}
}
