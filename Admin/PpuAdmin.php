<?php

namespace PelemanProductUploader\Admin;

use Automattic\WooCommerce\Client;

class PpuAdmin
{
	/**
	 * The ID of this plugin.
	 *
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Register the stylesheets for the admin area.
	 */
	public function enqueue_styles()
	{
		$randomVersionNumber = rand(1, 1000);
		wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/style.css', array(), $randomVersionNumber, 'all');
	}

	/**
	 * Register the JavaScript for the admin area.
	 */
	public function enqueue_scripts()
	{
		$randomVersionNumber = rand(1, 1000);
		wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/admin-ui.js', array('jquery'), $randomVersionNumber, true);
	}

	/**
	 * Register plugin menu item
	 */
	public function ppu_add_admin_menu()
	{
		add_menu_page('Peleman Product Uploader', 'Peleman Products Uploader', 'manage_options', 'ppu-menu.php', array($this, 'require_admin_page'),  'dashicons-welcome-write-blog');
	}

	/**
	 * Register plugin admin page
	 */
	public function require_admin_page()
	{
		require_once 'partials/ppu-menu.php';
	}


	/**
	 * Register plugin settings
	 */
	function ppu_register_plugin_settings()
	{
		register_setting('ppu_custom_settings', 'ppu-wc-key');
		register_setting('ppu_custom_settings', 'ppu-wc-secret');
		register_setting('ppu_custom_settings', 'peleman-authorization-key');
	}

	/**
	 * Instantiate an API client to handle internal API calls to the WooCommerce REST API.
	 * This client is used internally by the POST endpoints
	 * The default timeout is 15sec, which is too short.  Therefore it was increased to 300sec.
	 * It uses the WooCommerce key & secret that are created and saved in the plugins admin menu
	 */
	private function apiClient()
	{
		$siteUrl = get_site_url();
		return new Client(
			$siteUrl,
			get_option('ppu-wc-key'),
			get_option('ppu-wc-secret'),
			[
				'wp_api' => true,
				'version' => 'wc/v3',
				'timeout' => 300,
			]
		);
	}

	/**
	 * Performs simple authorization.
	 * Function compares the Peleman-Auth HTTP header to ppu-peleman-authorization-key
	 * In case of a mismatch, it returns a 401 and stops execution
	 *
	 * @param string $header
	 * @return void
	 */
	private function validateHeader($header)
	{
		$authKey = get_option('peleman-authorization-key');

		if ($header !== $authKey) {
			$statusCode = 403;
			$response['status'] = 'error';
			$response['message'] = 'You are not authorized to use this resource';

			wp_send_json($response, $statusCode);
			die();
		}
	}

	/**	
	 * Register get attributes endpoint
	 */
	public function registerGetAttributesEndpoint()
	{
		register_rest_route('ppu/v1', '/attributes', array(
			'methods' => 'GET',
			'callback' => array($this, 'getAttributes'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET attributes callback.
	 * It returns all attributes as a JSON
	 *
	 * @return string
	 */
	public function getAttributes()
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$api = $this->apiClient();
		$endpoint = 'products/attributes/';
		return $api->get($endpoint);
	}

	/**	
	 * Register get tags endpoint
	 * For pagination, this takes an optional page number
	 */
	public function registerGetTagsEndpoint()
	{
		register_rest_route('ppu/v1', '/tags(?:/(?P<page>\d+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getTags'),
			'args' => array('page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET tags callback.
	 * It returns all tags as a paginated JSON.
	 *
	 * @param array $request
	 * @return string
	 */
	public function getTags($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$page = $request['page'];
		if (!isset($page) || $page == '') $page = 1;
		$api = $this->apiClient();
		$endpoint = 'products/tags/';
		return $api->get($endpoint,	array(
			'page' => $page,
			'per_page' => 100
		));
	}

	/**
	 * Register get images endpoint
	 */
	public function registerGetImagesEndpoint()
	{
		register_rest_route('ppu/v1', '/image(?:/(?P<image_name>\S+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getImages'),
			'args' => array('page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET images callback.
	 * It returns all image infomration as a JSON.
	 *
	 * @param array $request
	 * @return string
	 */
	public function getImages($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		if (!empty($request['image_name'])) {
			$imageName = $request['image_name'];
			if (!$this->getImageIdByName($imageName)) {
				wp_send_json(array(
					'message' => 'No image found.',
					'name' => $imageName
				));
			} else {
				$imageId = $this->getImageIdByName($imageName);
				wp_send_json($this->getImageInformation($imageId));
			}
		}
		global $wpdb;
		$sql = "SELECT post_id FROM " . $wpdb->base_prefix . "postmeta WHERE meta_key = '_wp_attached_file'";
		$result = $wpdb->get_results($sql);
		$finalResult = array();
		foreach ($result as $image) {
			array_push($finalResult, $this->getImageInformation($image->post_id));
		}
		wp_send_json($finalResult);
	}


	/**
	 * It returns all image information as a JSON.
	 *
	 * @param string $imageId
	 * @return string
	 */
	private function getImageInformation($imageId)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$imageInformation = wp_get_attachment_metadata($imageId);

		return array(
			'id' => $imageId,
			'url' => wp_get_attachment_url($imageId),
			'size' => filesize(get_attached_file($imageId)),
			'dimensions' => !(empty($imageInformation['width'] && !empty($imageInformation['height']))) ? $imageInformation['width'] . 'x' . $imageInformation['height'] : 'no dimensions found'
		);
	}

	/**	
	 * Register get categories endpoint
	 * For pagination, this takes an optional page number
	 */
	public function registerGetCategoriesEndpoint()
	{
		register_rest_route('ppu/v1', '/categories(?:/(?P<page>\d+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getCategories'),
			'args' => array('page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET categories callback.
	 * It returns all categories as a paginated JSON.
	 *
	 * @param array $request
	 * @return string
	 */
	public function getCategories($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$page = $request['page'];
		if (!isset($page) || $page == '') $page = 1;
		$api = $this->apiClient();
		$endpoint = 'products/categories/';
		return $api->get($endpoint,	array(
			'page' => $page,
			'per_page' => 100
		));
	}

	/**	
	 * Register get products endpoint
	 * For pagination, this takes an optional page number
	 */
	public function registerGetProductsEndpoint()
	{
		register_rest_route('ppu/v1', '/products(?:/(?P<page>\d+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getProducts'),
			'args' => array('page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET products callback.
	 * It returns all products as a paginated JSON.
	 *
	 * @param string $request
	 * @return array
	 */
	public function getProducts($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$page = $request['page'];
		if (!isset($page) || $page == '') $page = 1;
		$api = $this->apiClient();
		$endpoint = 'products/';
		return $api->get($endpoint,	array(
			'page' => $page,
			'per_page' => 100
		));
	}

	/**	
	 * Register get terms endpoint
	 * For pagination, this takes an optional page number
	 */
	public function registerGetTermsEndpoint()
	{
		register_rest_route('ppu/v1', '/terms(?:/(?P<page>\d+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getTerms'),
			'args' => array('page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET terms callback.
	 * It returns all terms as a paginated JSON.
	 *
	 * @param array $request
	 * @return string
	 */
	public function getTerms($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$page = $request['page'];
		if (!isset($page) || $page == '') $page = 1;
		$api = $this->apiClient();
		$endpoint = 'products/attributes';
		$currentAttributes = $api->get(
			$endpoint,
			array(
				'page' => $page,
				'per_page' => 100
			)
		);
		$attributeIds = array_map(function ($e) {
			return $e->id;
		}, $currentAttributes);

		$termsArray = array();
		foreach ($attributeIds as $attributeId) {
			$endpoint = 'products/attributes/' . $attributeId . '/terms/';
			$result = $api->get($endpoint);
			array_push($termsArray, $result);
		}
		return $termsArray;
	}

	/**	
	 * Register get product variations endpoint
	 */
	public function registerGetVariationsEndpoint()
	{
		register_rest_route('ppu/v1', '/product/(?P<sku>\w+)/variations(?:/(?P<page>\d+))?', array(
			'methods' => 'GET',
			'callback' => array($this, 'getProductVariations'),
			'args' => array('sku', 'page'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * The GET variations callback.
	 * It returns all variations as a paginated JSON.
	 *
	 * @param array $request
	 * @return string
	 */
	public function getProductVariations($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$productId = wc_get_product_id_by_sku($request['sku']);
		$page = $request['page'];
		if (!isset($page) || $page == '') $page = 1;
		$api = $this->apiClient();

		$endpoint = 'products/' . $productId . '/variations/';
		return $api->get($endpoint,	array(
			'page' => $page,
			'per_page' => 100
		));
	}

	/**	
	 * Register post product variations endpoint
	 */
	public function registerPostAttributesEndpoint()
	{
		register_rest_route('ppu/v1', '/attributes', array(
			'methods' => 'POST',
			'callback' => array($this, 'postAttributes'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts attributes. It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postAttributes($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleAttributes($items);
	}

	/**	
	 * Register post tags endpoint
	 */
	public function registerPostTagsEndpoint()
	{
		register_rest_route('ppu/v1', '/tags', array(
			'methods' => 'POST',
			'callback' => array($this, 'postTags'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts tags. It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postTags($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleCategoriesAndTags($items, 'tag', 'tag');
	}

	/**	
	 * Register post categories endpoint
	 */
	public function registerPostCategoriesEndpoint()
	{
		register_rest_route('ppu/v1', '/categories', array(
			'methods' => 'POST',
			'callback' => array($this, 'postCategories'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts categories. It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postCategories($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleCategoriesAndTags($items, 'cat', 'category');
	}

	/**	
	 * Register post products endpoint
	 */
	public function registerPostProductsEndpoint()
	{
		register_rest_route('ppu/v1', '/products', array(
			'methods' => 'POST',
			'callback' => array($this, 'postProducts'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts products. It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postProducts($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleProducts($items);
	}

	/**	
	 * Register post variations endpoint
	 */
	public function registerPostVariationsEndpoint()
	{
		register_rest_route('ppu/v1', '/variations', array(
			'methods' => 'POST',
			'callback' => array($this, 'postVariations'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts variations. It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postVariations($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleProductVariations($items);
	}

	/**	
	 * Register post terms endpoint
	 */
	public function registerPostTermsEndpoint()
	{
		register_rest_route('ppu/v1', '/terms', array(
			'methods' => 'POST',
			'callback' => array($this, 'postTerms'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts terms.  It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postTerms($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$items = json_decode($request->get_body())->items;
		$this->handleAttributeTerms($items);
	}

	/**	
	 * Register post image endpoint
	 */
	public function registerPostImageEndpoint()
	{
		register_rest_route('ppu/v1', '/image', array(
			'methods' => 'POST',
			'callback' => array($this, 'postImage'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts an image using the POST data 
	 * TODO: check if this can be much simplified with media_sideload_image().
	 *
	 * @param object $request
	 * @return string
	 */
	public function postImage($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$data = json_decode($request->get_body());
		$finalResponse = array();
		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');

		foreach ($data->images as $image) {
			$filename = $image->name;
			$imageName = $image->title;
			$altText = $image->alt;
			$contentText = $image->content;
			$excerptText = $image->description;
			$base64ImageString = $image->base64image;
			$img = str_replace('data:image/jpeg;base64,', '', $base64ImageString);
			$img = str_replace(' ', '+', $img);
			$decoded = base64_decode($img);
			$file_type = 'image/jpeg';
			$upload_dir  = wp_upload_dir();
			$upload_path = str_replace('/', DIRECTORY_SEPARATOR, $upload_dir['path']) . DIRECTORY_SEPARATOR;

			file_put_contents($upload_path . $filename, $decoded);

			$attachment = array(
				'post_mime_type' => $file_type,
				'post_title'     => preg_replace('/\.[^.]+$/', '', basename($imageName)),
				'post_content'   => $contentText,
				'post_excerpt'   => $excerptText,
				'post_status'    => 'inherit',
				'guid'           => $upload_dir['url'] . '/' . basename($filename)
			);

			$imageExists = $this->getImageIdByName($filename);
			if ($imageExists) {
				$response['message'] = "Updated existing image";
				$attachment['ID'] = $imageExists;
			} else {
				$response['message'] = "Created new image";
			}

			try {
				$attachment_id = wp_insert_attachment($attachment, $upload_dir['path'] . '/' . $filename, 0, true);
				$attach_data = wp_generate_attachment_metadata($attachment_id, $upload_path . $filename);
				!empty($altText) ? update_post_meta($attachment_id, '_wp_attachment_image_alt', $altText) : '';

				if (empty($attach_data)) {
					$response['status'] = 'error';
					$response['message'] = 'Image upload failed';
					throw new \Exception('attach_data returned an empty array.');
				}
				wp_update_attachment_metadata($attachment_id, $attach_data);
				$response['status'] = 'success';
				$response['id'] = $attachment_id;
				$response['image_path'] = get_post_meta($attachment_id, '_wp_attached_file', true);
			} catch (\Throwable $th) {
				$response['status'] = 'error';
				$response['exception'] = $th->getMessage();
				$response['message'] = $th->getMessage();
			}

			if ($response['status'] !== 'error') {
				array_push($finalResponse, array(
					'status' => $response['status'],
					'id' => $response['id'],
					'image_name' => $image->name,
					'image' => $response['image_path'],
					'message' => $response['message'],
				));
			} else {
				array_push($finalResponse, array(
					'status' => 'error',
					'image_name' => $image->name,
					'message' => $response['message'],
				));
			}
			$response = array();
		}
		$statusCode = !in_array('error', array_column($finalResponse, 'status')) ? 200 : 207;

		wp_send_json($finalResponse, $statusCode);
		return;
	}


	/**	
	 * Register post menu endpoint
	 */
	public function registerPostMenuEndpoint()
	{
		register_rest_route('ppu/v1', '/menu', array(
			'methods' => 'POST',
			'callback' => array($this, 'postMenu'),
			'permission_callback' => '__return_true'
		));
	}

	/**
	 * Posts a menu.  It passes the request items to the handler.
	 * This function was made to be called by a HTML form.
	 *
	 * @param object $request
	 * @return void
	 */
	public function postMenu($request)
	{
		$this->validateHeader($_SERVER['HTTP_PELEMAN_AUTH']);

		$data = json_decode($request->get_body());
		$this->handleMenuUpload($data->menu);
	}

	/**
	 * Handles a product upload using the WC REST API
	 *
	 * @param array $dataArray
	 * @return string
	 */
	private function handleProducts($dataArray)
	{
		$endpoint = 'products/';
		$currentAttributes = $this->getFormattedArrayOfExistingItems('products/attributes/', 'attributes');
		$finalResponse = array();
		$response = array();

		foreach ($dataArray as $item) {
			$item->reviews_allowed = 0; // set reviews to false
			$isParentProduct = empty($item->lang); // parent or translation?
			$productId = wc_get_product_id_by_sku($item->sku);
			$isNewProduct = ($productId === 0 || $productId === null);
			$childProductId = null;
			// save the sku for the response 
			$response_sku = $item->sku;

			if (!$isParentProduct) {
				if ($productId === null || $productId === 0) {
					$response['status'] = 'error';
					$response['message'] = "Parent product not found (you are trying to upload a translated product, but I can't find its default language counterpart)";
				}
				// get the child's product ID
				$childProductId = apply_filters('wpml_object_id', $productId, 'post', false, $item->lang);
				$isNewProduct = ($childProductId === 0 || $childProductId === null); // if child, does the translatedProductId exist?
				if ($childProductId !== null) $productId = $childProductId; // if child exists, work with it
				unset($item->sku); // clear SKU to avoid 'duplicate SKU' errors
				$item->translation_of = $productId; // set product as translation of the parent
			}

			// get id's for all categories, tags, attributes, and images.
			if (isset($item->categories) && $item->categories != null) {
				foreach ($item->categories as $category) {
					if (!is_int($category->slug)) {
						$category->id = get_term_by('slug', $category->slug, 'product_cat')->term_id;
						if ($category->id === null) {
							$response['status'] = 'error';
							$response['message'] = "Category $category->slug not found";
						}
					}
				}
			}

			if (isset($item->tags) && $item->tags != null) {
				foreach ($item->tags as $tag) {
					if (!is_int($tag->slug)) {
						$tag->id = get_term_by('slug', $tag->slug, 'product_tag')->term_id;
						if ($tag->id === null) {
							$response['status'] = 'error';
							$response['message'] = "Tag $category->tag not found";
						}
					}
				}
			}

			// for each attribute, take the first option and add to default_attr
			$item->default_attributes = [];
			if (isset($item->attributes) && $item->attributes != null) {
				foreach ($item->attributes as $key => $attribute) {

					$attributeLookup = $this->getAttributeIdBySlug($attribute->slug, $currentAttributes['attributes']);
					if ($attributeLookup['result'] == 'error') {
						$response['status'] = 'error';
						$response['message'] = "Attribute {$attributeLookup['slug']} not found";
					} else {
						$attribute->id = $attributeLookup['id'];
						// set default attributes
						if ($attribute->default !== false) {
							if (!empty($attribute->default)) {
								// use the given default
								$item->default_attributes[$key]->id = $attribute->id;
								$item->default_attributes[$key]->option = $attribute->default;
							}
							if (empty($attribute->default)) {
								// first option is the default
								$item->default_attributes[$key]->id = $attribute->id;
								$item->default_attributes[$key]->option = $attribute->options[0];
							}
						}
					}
				}
			}

			if (isset($item->images) && $item->images != null) {
				foreach ($item->images as $image) {
					$imageId = $this->getImageIdByName($image->name);
					if ($imageId != null) {
						$image->id = $imageId;
					} else {
						$response['status'] = 'error';
						$response['message'] = "Image {$image->name} not found";
					}
				}
			}

			// handle up- & cross-sell products
			if ($item->upsell_skus !== null) {
				$item->upsell_ids = $this->get_product_ids_for_sku_array($item->upsell_skus);
			}
			if ($item->cross_sell_skus !== null) {
				$item->cross_sell_ids = $this->get_product_ids_for_sku_array($item->cross_sell_skus);
			}

			// handle videos
			if ($item->videos !== null && !empty($item->videos)) {
				$iterator = 0;
				$nrOfVideos = count($item->videos);
				$videoJsonStringArray = [];
				foreach ($item->videos as $video) {
					$youTubeId = $this->createGetParamArray($video->youtube_url)['v'];
					$response = $this->downloadYouTubeThumbnailAsWpAttachment($youTubeId, $video->title);
					if ($response['result'] === 'success') {
						$attachmentId = $response['data'];
						$videoJsonStringArray[] = $this->createJsonStringForVideo($iterator, $attachmentId, $video->title, $video->youtube_url);
					} else {
						$response['status'] = 'error';
						$response['message'] = $response['message'];
					}
					$iterator++;
				}
				$response = $this->addVideosToProduct($nrOfVideos, $videoJsonStringArray, $productId);

				if ($response === false) {
					$response['status'] = 'error';
					$response['message'] = 'Could not add video to product';
				}
			}

			if (!isset($response['status'])) {
				try {
					$api = $this->apiClient();
					if ($isNewProduct) {
						// this logic route creates a product ID
						$response = (array) $api->post($endpoint, $item);
						$response['status'] = 'success';
						$response['action'] = 'create product';
					} else {
						// this logic route has the product ID and edits it
						$response = (array) $api->put($endpoint . $productId, $item);
						$response['status'] = 'success';
						$response['action'] = 'modify product';
					}
				} catch (\Throwable $th) {
					$response['status'] = 'error';
					$response['message'] = $th->getMessage();
					$response['error_detail'] = $item ?? null;
				}
			}

			if (isset($response['status']) && $response['status'] == 'success') {
				array_push($finalResponse, array(
					'status' => $response['status'],
					'action' => $response['action'],
					'id' => $response['id'],
					'product' => $response_sku,
					'lang' => $item->lang
				));
			} else {
				array_push($finalResponse, array(
					'status' => $response['status'],
					'message' => $response['message'],
					'error_detail' => $response['error_detail'] ?? '',
					'product' => $response_sku,
					'lang' => $item->lang
				));
			}
			$response = array();
		}

		wp_send_json($finalResponse, 200);
	}

	/**
	 * Extracts GET parameters from a URL and returns them as an associative array
	 *
	 * @param string $url
	 * @return array
	 */
	private function createGetParamArray($url)
	{
		$getParams = substr($url, strpos($url, '?') + 1);
		$paramArray = explode('&', $getParams);

		$getParamArray = [];
		foreach ($paramArray as $param) {
			$explodedParam = explode('=', $param);
			$getParamArray[$explodedParam[0]] = $explodedParam[1];
		}
		return $getParamArray;
	}

	/**
	 * Downloads a YouTube thumbnail as a WordPres attachment
	 * 
	 * YouTube video thumbnails come in 4 formats.  This function tries to download the highest quality first and falls back to lower quality files until one is found
	 *
	 * @param string $videoId
	 * @param string $title
	 * @return string
	 */
	private function downloadYouTubeThumbnailAsWpAttachment($videoId, $title)
	{
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$youtubeImageFormatArray = [
			'maxresdefault',
			'sddefault',
			'hqdefault',
			'mqdefault',
		];
		$extension = 'jpg';
		$youtubeUrl = 'https://img.youtube.com/vi/' . $videoId . '/';

		foreach ($youtubeImageFormatArray as $imageFormat) {
			$imageUrl = $youtubeUrl . $imageFormat . '.' . $extension;
			try {
				$uploadResponse = media_sideload_image($imageUrl, 0, $title, 'id');
				if (is_int($uploadResponse)) {
					$response['result'] = 'success';
					$response['data'] = $uploadResponse;
					break;
				} else {
					throw new \Exception("Could not fetch YouTube thumbnail for url {$imageUrl}");
				}
			} catch (\Throwable $th) {
				$response['message'] = $th->getMessage();
			}
		}

		return $response;
	}

	/**
	 * Writes the video JSON string to wp_postmeta
	 *
	 * @param int $nrOfVideos
	 * @param array $videoJsonStringArray
	 * @param int $productId
	 * @return boolean
	 */
	private function addVideosToProduct($nrOfVideos, $videoJsonStringArray, $productId)
	{
		global $wpdb;
		$videoJsonExists = $wpdb->get_results("SELECT * FROM devb2b.wp_postmeta WHERE post_id = {$productId} AND meta_key = '_ywcfav_video';");

		if (count($videoJsonExists) > 0) { // if a video JSON exists, delete all
			$wpdb->delete(
				$wpdb->prefix . 'postmeta',
				[
					'post_id' => $productId,
					'meta_key' => '_ywcfav_video'
				]
			);
		}

		$finalJsonString = 'a:' . $nrOfVideos . ':{' . implode('', $videoJsonStringArray) . '}';
		$result = $wpdb->insert(
			$wpdb->prefix . 'postmeta',
			[
				'meta_value' => $finalJsonString,
				'post_id' => $productId,
				'meta_key' => '_ywcfav_video'
			]
		);

		if ($result > 0) {
			return true;
		}

		return false;
	}

	/**
	 * Creates a YITH JSON string for a YouTube video
	 * TODO: the JSON string is actually a serialised array - it should be treated as such.
	 *
	 * @param int $iterator
	 * @param int $attachmentId
	 * @param string $title
	 * @param string $youTubeVideoUrl
	 * @return string
	 */
	private function createJsonStringForVideo($iterator, $attachmentId, $title, $youTubeVideoUrl)
	{
		$jsonId = 'ywcfav_video_id-' . $this->generateRandomString(11);

		return 'i:' . $iterator . ';a:7:{s:6:"thumbn";s:' . strlen((string)$attachmentId) . ':"'
			. $attachmentId
			. '";s:2:"id";s:' . strlen($jsonId)
			. ':"'
			. $jsonId
			. '";s:4:"type";s:3:"url";s:8:"featured";s:2:"no";s:4:"name";s:' . strlen($title) . ':"'
			. $title
			. '";s:4:"host";s:7:"youtube";s:7:"content";s:' . strlen($youTubeVideoUrl) . ':"'
			. $youTubeVideoUrl
			. '";}';
	}

	/**
	 * Generates a random string for a given length
	 *
	 * @param int $length
	 * @return string
	 */
	private function generateRandomString($length)
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyz';
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$index = rand(0, strlen($characters) - 1);
			$randomString .= $characters[$index];
		}

		return $randomString;
	}

	/**
	 * Given an array of SKU's, it returns an array of product Id's
	 *
	 * @param array $skuArray
	 * @return array
	 */
	private function get_product_ids_for_sku_array($skuArray)
	{
		$productIdArray = [];
		foreach ($skuArray as $sku) {
			array_push($productIdArray, wc_get_product_id_by_sku($sku));
		}

		return $productIdArray;
	}

	/**
	 * Upload handler: categories
	 */
	private function handleCategoriesAndTags($dataArray, $shortObjectName, $longObjectName)
	{
		$finalResponse = array();
		$iclType = $shortObjectName === 'cat' ? 'category' : 'post_tag';

		foreach ($dataArray as $item) {
			$slug = $item->slug;
			$hasParentLanguage = !empty($item->english_slug);

			try {
				// child
				if ($hasParentLanguage) {
					$parentObject = get_term_by('slug', $item->english_slug, 'product_' . $shortObjectName);
					/**
					 * once a translation exists, a child is seemingly removed from the 'main' tags/categories.  Searching on it's slug or ID will return it's parent.
					 * therefore we use icl_object_id
					 */

					$translatedObjectTermId = icl_object_id($parentObject->term_id, $iclType, false, $item->language_code);
					// item already exists
					if ($translatedObjectTermId) {
						$updateResponse = $this->updateTranslatedTagOrCategory($translatedObjectTermId, $item, $shortObjectName);
						if ($updateResponse === false) {
							$tempResponse['status'] = 'error';
							$tempResponse['message'] = "error encountered updating terms & terms taxonomy in database";
						} else {
							global $wpdb;
							$term_taxonomy = $wpdb->get_results("SELECT term_taxonomy_id FROM " . $wpdb->prefix . "term_taxonomy WHERE taxonomy = 'product_{$shortObjectName}' AND term_id = {$translatedObjectTermId};")[0];

							$this->joinTranslatedTagOrCategoryWithParent($parentObject, $item, $term_taxonomy->term_taxonomy_id, $shortObjectName);
							$this->updateOrAddCategoryOrTagSeoData($shortObjectName, $translatedObjectTermId, $item->seo);
							$response['term_id'] = $translatedObjectTermId;
							$response['term_taxonomy_id'] = $term_taxonomy->term_taxonomy_id;
							$tempResponse['action'] = 'modify child ' . $longObjectName;
						}
					}
					// new item
					if (!$translatedObjectTermId) {
						$response = wp_insert_term($item->name, 'product_' . $shortObjectName, array(
							'name' => $item->name,
							'description' => $item->description,
							'slug' => $slug
						));
						$this->joinTranslatedTagOrCategoryWithParent($parentObject, $item, $response['term_taxonomy_id'], $shortObjectName);
						$this->updateOrAddCategoryOrTagSeoData($shortObjectName, $response['term_taxonomy_id'], $item->seo);
						$tempResponse['action'] = 'create child ' . $longObjectName;
					}
				} else { // parent
					$object = get_term_by('slug', $item->slug, 'product_' . $shortObjectName);
					// item already exists
					if ($object) {
						$objectId = $object->term_id;
						$response = wp_update_term($objectId, 'product_' . $shortObjectName, array(
							'name' => $item->name,
							'description' => $item->description,
						));
						$tempResponse['action'] = 'modify parent ' . $longObjectName;
						// SEO data
						$this->updateOrAddCategoryOrTagSeoData($shortObjectName, $objectId, $item->seo);
					}
					// new item
					if (!$object) {
						$response = wp_insert_term($item->name, 'product_' . $shortObjectName, array(
							'name' => $item->name,
							'description' => $item->description,
							'slug'    => $item->slug
						));
						// SEO data
						$this->updateOrAddCategoryOrTagSeoData($shortObjectName, $response['term_id'], $item->seo);
						$tempResponse['action'] = 'create parent ' . $longObjectName;
					}
				}
			} catch (\Throwable $th) {
				$tempResponse['status'] = 'error';
				$tempResponse['message'] = $th->getMessage();
			}

			if (is_wp_error($response)) {
				array_push($finalResponse, array(
					'status' => 'error',
					'message' => $tempResponse['message'] ?? $response->errors,
					$longObjectName => $item->name
				));
			} else {
				array_push($finalResponse, array(
					'status' => 'success',
					'action' => $tempResponse['action'],
					'term_id' => $response['term_id'],
					'term_taxonomy_id' => $response['term_taxonomy_id'],
					$longObjectName => $item->name
				));
			}
			$response = array();
		}
		$statusCode = !in_array('error', array_column($finalResponse, 'status')) ? 200 : 207;

		wp_send_json($finalResponse, $statusCode);
	}

	private function updateOrAddCategoryOrTagSeoData($shortObjectName, $objectId, $seoData)
	{
		$currentSeoMetaData = get_option('wpseo_taxonomy_meta');
		$type = $shortObjectName === 'cat' ? 'product_cat' : 'product_tag';

		$currentSeoMetaData[$type][$objectId]['wpseo_focuskw'] = $seoData->focus_keyword;
		$currentSeoMetaData[$type][$objectId]['wpseo_desc'] = $seoData->description;

		update_option('wpseo_taxonomy_meta', $currentSeoMetaData);
	}

	private function joinTranslatedTagOrCategoryWithParent($parentObject, $item, $termTaxonomyId, $shortObjectName)
	{
		$type = $shortObjectName === 'cat' ? 'tax_product_cat' : 'tax_product_tag';

		global $wpdb;
		$table = $wpdb->dbname . '.' . $wpdb->prefix . 'icl_translations';

		// get parent trid
		$sql = "SELECT * FROM {$table} WHERE element_id = {$parentObject->term_taxonomy_id} AND language_code = 'en' AND element_type = '{$type}';";
		$result = $wpdb->get_results($sql)[0];
		$trid =  $result->trid;

		$updateQuery = "UPDATE {$table} SET language_code = '{$item->language_code}', source_language_code = 'en', trid = {$trid} WHERE element_type = '{$type}' AND element_id = {$termTaxonomyId};";
		$wpdb->get_results($updateQuery);
	}

	private function updateTranslatedTagOrCategory($termId, $termObject, $shortObjectName)
	{
		/**
		 * do wp_terms AND wp_terms_taxonomy
		 */
		$type = $shortObjectName === 'cat' ? 'product_cat' : 'product_tag';

		global $wpdb;
		$termsTable = $wpdb->prefix . 'terms';
		$termsTaxonomyTable = $wpdb->prefix . 'term_taxonomy';

		$termsResult = $wpdb->update($termsTable, ['name' => $termObject->name, 'slug' => $termObject->slug], ['term_id' => $termId]);
		$termsTaxonomyResult = $wpdb->update($termsTaxonomyTable, ['description' => $termObject->description], ['term_id' => $termId, 'taxonomy' => $type]);

		if ($termsResult === false || $termsTaxonomyResult === false) {
			return false;
		}
		return true;
	}

	/**
	 * Upload handler: product variations
	 */
	private function handleProductVariations($dataArray)
	{
		$finalResponse = array();

		// get all current attributes
		$tempCurrentAttributes = wc_get_attribute_taxonomies();
		$currentAttributesArray = array();
		foreach ($tempCurrentAttributes as $attribute) {
			$currentAttributesArray['pa_' . $attribute->attribute_name] = array(
				'id' => $attribute->attribute_id,
				'slug' => $attribute->attribute_name,
			);
		}

		// get all terms per current attribute
		$allTerms = array();
		foreach ($currentAttributesArray as $attrKey => $attrValue) {
			$attributeTerms = $this->getAllTermsForSlug($attrValue['slug']);
			if (empty($attributeTerms)) continue;
			foreach ($attributeTerms as $term) {
				array_push($allTerms, $term->name);
			}
		}
		$currentAttributes = $this->getFormattedArrayOfExistingItems('products/attributes/', 'attributes');

		// Products loop
		foreach ($dataArray as $item) {
			// Variations loop
			foreach ($item->variations as $variation) {
				$isParentVariation = empty($variation->lang); // no lang means default language & thus parent
				$productId =
					$isParentVariation ?
					wc_get_product_id_by_sku($item->parent_product_sku) :
					apply_filters('wpml_object_id', wc_get_product_id_by_sku($item->parent_product_sku), 'post', TRUE, $variation->lang);

				$parentVariationId = wc_get_product_id_by_sku($variation->sku);
				$childVariationId = apply_filters('wpml_object_id', $parentVariationId, 'post', false, $variation->lang);

				$variation_sku = $variation->sku;
				// given the variant ID's, is it a new or existing variant?
				$noParentVariationFound = $parentVariationId === 0 || $parentVariationId === null;
				$isNewVariation = $noParentVariationFound;
				if (!$isParentVariation) { // =child (other language than English)
					if ($noParentVariationFound) {
						$response['status'] = 'error';
						$response['message'] = "Parent variation not found (you are trying to upload a translated variation, but I can't find its default language counterpart)";
					}

					unset($variation->sku); // clear SKU for child/translated products to avoid 'duplicate SKU' errors					
					$variation->translation_of = $parentVariationId; // set variation as translation of the parent

					$isNewVariation = $childVariationId === null;
				}

				$endpoint = 'products/' . $productId . '/variations/';

				// Attributes loop
				// get all product terms
				$allProductTerms = [];
				$parentProductId = wc_get_product_id_by_sku($item->parent_product_sku);
				foreach ($currentAttributes['slugs'] as $attributeSlug) {
					$termsPerAttribute = get_the_terms($parentProductId, $attributeSlug);
					if (empty($termsPerAttribute)) continue;
					$allProductTerms = array_merge($allProductTerms, array_column($termsPerAttribute, 'name'));
				}
				if (isset($variation->attributes) && $variation->attributes != null) {
					foreach ($variation->attributes as $variationAttribute) {
						$attributeLookup = $this->getAttributeIdBySlug($variationAttribute->slug, $currentAttributes['attributes']);
						if ($attributeLookup['result'] == 'error') {
							$response['status'] = 'error';
							$response['message'] = "Attribute {$attributeLookup['slug']} not found";
						} else if (!in_array($variationAttribute->option, $allTerms)) {
							$response['status'] = 'error';
							$response['message'] = "Attribute term {$variationAttribute->option} not found";
						} else if (!in_array($variationAttribute->option, $allProductTerms)) {
							$response['status'] = 'error';
							$response['message'] = "Attribute term {$variationAttribute->option} is not defined as a term for product SKU {$item->parent_product_sku}";
						} else {
							$variationAttribute->id = $attributeLookup['id'];
						}
					}
				}

				// handle images
				if (isset($variation->image) && $variation->image != null) {

					$imageId = $this->getImageIdByName($variation->image);
					if ($imageId) {
						$variation->image = array('id' => $imageId);
					} else {
						$response['status'] = 'error';
						$response['message'] = "Image {$variation->image} not found";
					}
				}

				if (!isset($response['status'])) {
					try {
						$api = $this->apiClient();
						if ($isNewVariation) {
							// create new
							$response = (array) $api->post($endpoint, $variation);
							$response['status'] = 'success';
							$response['action'] = 'create variation';
						} else {
							// edit existing
							$endpoint .= $isParentVariation ? $parentVariationId : $childVariationId;
							$response = (array) $api->put($endpoint, $variation);
							$response['status'] = 'success';
							$response['action'] = 'modify variation';
						}
					} catch (\Throwable $th) {
						$response['status'] = 'error';
						$response['message'] = $th->getMessage();
						$response['error_detail'] = $variation ?? null;
					}
				}
				if ($response['status'] == 'success') {
					array_push($finalResponse, array(
						'status' => $response['status'],
						'action' => $response['action'],
						'id' => $response['id'],
						'product' => $variation_sku,
						'lang' => $variation->lang
					));
				} else {
					array_push($finalResponse, array(
						'status' => $response['status'],
						'message' => $response['message'],
						'product' => $variation_sku,
						'error_detail' => $response['error_detail'] ?? '',
						'lang' => $variation->lang
					));
				}
				$response = array();
			}
		}
		$statusCode = !in_array('error', array_column($finalResponse, 'status')) ? 200 : 207;

		wp_send_json($finalResponse, $statusCode);
	}

	/**
	 * Upload handler: attributes
	 */
	private function handleAttributes($dataArray)
	{
		$finalResponse = array();

		$api = $this->apiClient();
		$endpoint = 'products/attributes/';
		$currentAttributes = wc_get_attribute_taxonomies();

		$currentAttributesArray = array();
		foreach ($currentAttributes as $attribute) {
			$currentAttributesArray[$attribute->attribute_name] = array(
				'id' => $attribute->attribute_id,
				'slug' => $attribute->attribute_name,
				'name' => $attribute->attribute_label
			);
		}

		foreach ($dataArray as $item) {
			$isParentAttribute = empty($item->english_slug);

			try {
				if (key_exists($item->slug, $currentAttributesArray)) {
					if ($isParentAttribute) {
						$response = (array) $api->put($endpoint . $currentAttributesArray[$item->slug]['id'], $item);
					} else {
						$this->addOrUpdateTranslatedAttribute($item, $currentAttributesArray[$item->english_slug]['name']);
					}
					$tempResponse['action'] = 'modify attribute';
				} else {
					if ($isParentAttribute) {
						$response = (array) $api->post($endpoint, $item);
					} else {
						$this->addOrUpdateTranslatedAttribute($item, $currentAttributesArray[$item->english_slug]['name']);
					}
					$tempResponse['action'] = 'create attribute';
				}
				$tempResponse['status'] = 'success';
			} catch (\Throwable $th) {
				$tempResponse['status'] = 'error';
				$tempResponse['message'] = $th->getMessage();
			}

			if ($tempResponse['status'] == 'error') {
				array_push($finalResponse, array(
					'status' => 'error',
					'message' => $tempResponse['message'],
					'attribute' => $item->name,
					'slug' => $item->slug
				));
			} else {
				array_push($finalResponse, array(
					'status' => 'success',
					'action' => $tempResponse['action'],
					'id' => $response['id'],
					'attribute' => $item->name,
					'slug' => $item->slug
				));
			}
			$response = array();
		}

		$statusCode = !in_array('error', array_column($finalResponse, 'status')) ? 200 : 207;

		wp_send_json($finalResponse, $statusCode);
	}

	private function addOrUpdateTranslatedAttribute($attribute, $parentAttributeName)
	{
		$languageCode = $attribute->language_code;
		$translatedAttributeName = $attribute->name;

		global $wpdb;

		// get parent string results
		$singularSelectStringQuery = "SELECT `id`, `name`, `value` FROM `{$wpdb->prefix}icl_strings` WHERE `name` = 'taxonomy singular name: " . $parentAttributeName . "' AND `value` = '" . $parentAttributeName . "' AND `context` = 'WordPress' LIMIT 1";
		$pluralSelectStringQuery = "SELECT `id`, `name`, `value` FROM `{$wpdb->prefix}icl_strings` WHERE `name` = 'taxonomy general name: Product " . $parentAttributeName . "' AND `value` = 'Product " . $parentAttributeName . "' AND `context` = 'WordPress' LIMIT 1";
		$singularStringResult = $wpdb->get_results($singularSelectStringQuery);
		$pluralStringResult = $wpdb->get_results($pluralSelectStringQuery);

		// if parent exists
		if (count($singularStringResult) > 0) {
			// get specific language results
			$singularTranslatedStringResult = $wpdb->get_results("SELECT `id`, `string_id`, `language`, `value` FROM `{$wpdb->prefix}icl_string_translations` WHERE `string_id` = '" . $singularStringResult[0]->id . "' AND `language` = '" . $languageCode . "' LIMIT 1");
			$pluralTranslatedStringResult = $wpdb->get_results("SELECT `id`, `string_id`, `language`, `value` FROM `{$wpdb->prefix}icl_string_translations` WHERE `string_id` = '" . $pluralStringResult[0]->id . "' AND `language` = '" . $languageCode . "' LIMIT 1");

			if (count($singularTranslatedStringResult) == 0) {
				$wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->prefix}icl_string_translations` ( `string_id`, `language`, `status`, `value` ) VALUES ( %d, %s, %d, %s )", $singularStringResult[0]->id, $languageCode, 10, $translatedAttributeName));
				$wpdb->query($wpdb->prepare("INSERT INTO `{$wpdb->prefix}icl_string_translations` ( `string_id`, `language`, `status`, `value` ) VALUES ( %d, %s, %d, %s )", $pluralStringResult[0]->id, $languageCode, 10, $translatedAttributeName));
			} else {
				if ($singularTranslatedStringResult[0]->value != $translatedAttributeName) {
					$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->prefix}icl_string_translations` SET `value` = %s WHERE `id` = %d", $translatedAttributeName, $singularTranslatedStringResult[0]->id));
					$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->prefix}icl_string_translations` SET `value` = %s WHERE `id` = %d", $translatedAttributeName, $pluralTranslatedStringResult[0]->id));
				}
			}
		}
	}

	/**
	 * Upload handler: attribute terms
	 */
	private function handleAttributeTerms($dataArray)
	{
		$finalResponse = array();

		// get all current attributes
		$currentAttributes = wc_get_attribute_taxonomies();
		$currentAttributesArray = array();
		foreach ($currentAttributes as $attribute) {
			$currentAttributesArray['pa_' . $attribute->attribute_name] = array(
				'id' => $attribute->attribute_id,
				'slug' => $attribute->attribute_name,
			);
		}

		// get all terms per current attribute
		$allTerms = array();
		foreach ($currentAttributesArray as $attrKey => $attrValue) {
			$attributeTerms = $this->getAllTermsForSlug($attrValue['slug']);

			if (empty($attributeTerms)) continue;

			$tempArray = array();
			foreach ($attributeTerms as $term) {
				$tempArray[$term->slug] = array(
					'attributeId' => $attrValue['id'],
					'attributeSlug' => $attrKey,
					'id' => $term->term_taxonomy_id,
				);
			}
			$allTerms[$attrKey] = $tempArray;
		}

		$api = $this->apiClient();
		foreach ($dataArray as $item) {
			$hasParentLanguage = !empty($item->english_slug);
			try {
				$attrName = 'pa_' . strtolower($item->attribute);
				if (key_exists($attrName, $currentAttributesArray)) {
					// get id of attribute, regardless of whether the term is found
					foreach ($currentAttributes as $attribute) {
						if ($attribute->attribute_name == strtolower($item->attribute)) {
							$attrId = $attribute->attribute_id;
							break;
						}
					}
					$foundTermId = $this->getExistingSlugId($attrName, $item->slug);
					$foundParentTermId = $this->getExistingSlugId($attrName, $item->english_slug);
					if (!$foundTermId) { //term doesn't exist
						$endpoint = 'products/attributes/' . $attrId . '/terms';
						$tempResponse['action'] = 'create term';
						$response = (array) $api->post($endpoint, $item);
						if ($item->menu_order > 0) $this->updateTermOrder($response['id'], $item->menu_order);
					} else { // term exists
						// for a child/translation, the WC endpoint it will edit the parent, not the translation.  This causes problems
						// if item is a child/translation - do the edit in a different way
						if ($hasParentLanguage === true) { // if it's a translated/childItem, get the parent's details to link to parent
							$this->updateExistingTranslatedTerm($foundTermId, $item);
							$response['id'] = intval($foundTermId);
						} else {
							$endpoint = 'products/attributes/' . $attrId . '/terms/' . $foundTermId;
							$response = (array) $api->put($endpoint, $item);
							if ($item->menu_order > 0) $this->updateTermOrder($response['id'], $item->menu_order);
						}
						$tempResponse['action'] = 'modify term';
					}
					if ($hasParentLanguage === true) { // if it's a translated/childItem, get the parent's details to link to parent
						$this->joinTranslatedTermWithParent($attrName, $foundParentTermId, $this->getExistingSlugId($attrName, $item->slug), $item->language_code);
					}
					$tempResponse['status'] = 'success';
					$tempResponse['id'] = $response['id'];
					// set the Haru term meta color
					if ($item->extra !== '' && $item->extra->hex_code !== '') $this->setColor($response['id'], $item->extra->hex_code);
				} else {
					$tempResponse['status'] = 'error';
					$tempResponse['message'] = "attribute not found";
				}
			} catch (\Throwable $th) {
				$tempResponse['status'] = 'error';
				$tempResponse['message'] = $th->getMessage();
			}

			if ($tempResponse['status'] == 'error') {
				array_push($finalResponse, array(
					'status' => 'error',
					'message' => $tempResponse['message'],
					'attribute' => $item->attribute,
					'term' => $item->name,
					'slug' => $item->slug
				));
			} else if ($tempResponse['status'] == 'success') {
				array_push($finalResponse, array(
					'status' => 'success',
					'action' => $tempResponse['action'],
					'id' => $tempResponse['id'],
					'attribute' => $item->attribute,
					'term' => $item->name,
					'slug' => $item->slug
				));
			}
			$response = array();
		}

		$statusCode = !in_array('error', array_column($finalResponse, 'status')) ? 200 : 207;

		wp_send_json($finalResponse, $statusCode);
	}

	private function setColor($id, $hexCode)
	{
		global $wpdb;
		// check if line exists
		$results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}haru_termmeta WHERE haru_term_id = $id AND meta_key = 'product_attribute_color';");
		if (empty($results)) {
			$wpdb->insert(
				$wpdb->prefix . 'haru_termmeta',
				[
					'term_id' => 0,
					'haru_term_id' => $id,
					'meta_key' => 'product_attribute_color',
					'meta_value' => $hexCode
				]
			);
			return true;
		}
		$wpdb->update(
			$wpdb->prefix . 'haru_termmeta',
			[
				'meta_value' => $hexCode
			],
			[
				'haru_term_id' => $id,
				'meta_key' => 'product_attribute_color',
			]
		);
		return true;
	}

	private function updateTermOrder($termId, $order)
	{
		global $wpdb;
		$termMetaTable = $wpdb->prefix . 'termmeta';

		// delete all term order lines
		$condition = $wpdb->esc_like('order') . '%';
		$sql  = $wpdb->prepare("SELECT meta_id FROM $termMetaTable WHERE term_id = $termId AND meta_key LIKE %s;", $condition);
		$currentTermsOrderResult = $wpdb->get_results($sql);

		foreach ($currentTermsOrderResult as $result) {
			$wpdb->delete($termMetaTable, ['meta_id' => $result->meta_id]);
		}

		// create a proper one
		$wpdb->insert($termMetaTable, ['meta_key' => 'order', 'term_id' => $termId, 'meta_value' => $order]);
	}

	private function updateExistingTranslatedTerm($termId, $termItem)
	{
		global $wpdb;
		$termsTable = $wpdb->prefix . 'terms';
		$termsTaxonomyTable = $wpdb->prefix . 'term_taxonomy';

		$termsResult = $wpdb->update($termsTable, ['name' => $termItem->name, 'slug' => $termItem->slug], ['term_id' => $termId]);
		$termsTaxonomyResult = $wpdb->update($termsTaxonomyTable, ['description' => $termItem->description], ['term_id' => $termId]);

		if ($termsResult === false || $termsTaxonomyResult === false) {
			return false;
		}
		return true;
	}

	private function getExistingSlugId($attrName, $slug, $name = null)
	{
		global $wpdb;
		$table = $wpdb->dbname . '.' . $wpdb->prefix . 'term_taxonomy';
		$joinTable = $wpdb->dbname . '.' . $wpdb->prefix . 'terms';

		// get parent trid
		$sql = "SELECT {$table}.term_taxonomy_id, {$table}.term_id FROM {$table} INNER JOIN {$joinTable} on {$table}.term_id = {$joinTable}.term_id WHERE taxonomy = '{$attrName}' AND slug = '{$slug}'";
		if (is_null($name)) {
			$sql .= ";";
		} else {
			$sql .= " AND name = '{$name}';";
		}
		$result = $wpdb->get_results($sql);

		if (empty($result)) {
			return false;
		}

		return $result[0]->term_id;
	}

	private function getAllTermsForSlug($slug)
	{
		global $wpdb;
		$table = $wpdb->dbname . '.' . $wpdb->prefix . 'term_taxonomy';
		$joinTable = $wpdb->dbname . '.' . $wpdb->prefix . 'terms';

		// get parent trid
		$sql = "SELECT {$table}.term_taxonomy_id, {$table}.term_id, {$table}.taxonomy, {$joinTable}.name, {$joinTable}.slug FROM {$table} INNER JOIN {$joinTable} on {$table}.term_id = {$joinTable}.term_id WHERE taxonomy = 'pa_{$slug}';";
		return $wpdb->get_results($sql);
	}

	private function joinTranslatedTermWithParent($type, $parentTermId, $childId, $languageCode)
	{
		global $wpdb;
		$table = $wpdb->dbname . '.' . $wpdb->prefix . 'icl_translations';
		$elementType = "tax_{$type}";
		// get parent trid
		$sql = "SELECT * FROM {$table} WHERE element_id = {$parentTermId} AND language_code = 'en' AND element_type = '{$elementType}';";
		$result = $wpdb->get_results($sql)[0];
		$trid = $result->trid;

		$updateQuery = "UPDATE {$table} SET language_code = '{$languageCode}', source_language_code = 'en', trid = {$trid} WHERE element_type = '{$elementType}' AND element_id = {$childId};";
		$wpdb->get_results($updateQuery);
	}

	/**
	 * Facilitates linking images to categories, products, etc
	 */
	private function getImageIdByName($imageName)
	{
		global $wpdb;
		$sql = "SELECT post_id FROM " . $wpdb->base_prefix . "postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%/" . $imageName . "';";
		$result = $wpdb->get_results($sql);

		if (!empty($result)) {
			return $result[0]->post_id;
		}
		return false;
	}

	/**
	 * Get current attributes and return 2 arrays: a flattened
	 */
	private function getFormattedArrayOfExistingItems($endpoint, $type)
	{
		$api = $this->apiClient();
		$currentArrayItems = $api->get($endpoint);

		$currentArrayItemsSlugs = array_map(function ($e) {
			return $e->slug;
		}, $currentArrayItems);

		return array($type => $currentArrayItems, 'slugs' => $currentArrayItemsSlugs);
	}

	/**
	 * Get attribute ID by slug
	 */
	private function getAttributeIdBySlug($slug, $attributeArray)
	{
		$foundArrayKey = (array_search('pa_' . $slug, array_column($attributeArray, 'slug')));
		if (gettype($foundArrayKey) == 'boolean' && !$foundArrayKey) return array('result' => 'error', 'slug' => $slug);
		return array('result' => 'success', 'id' => $attributeArray[$foundArrayKey]->id);
	}

	/**
	 * Disable image downscaling - this adds the word "scaled" to images - return false to disable
	 */
	public function disableImageDownscaling()
	{
		return false;
	}

	/**
	 * Creates a WordPress menu
	 * 
	 * Creating a menu and a megamenu are 2 seperate things, but since a megamenu needs a menu,
	 * the menu is created first.
	 *
	 * @param object $data	
	 * @return string
	 */
	public function handleMenuUpload($menu)
	{
		$response = [];
		$errorsArray = [];
		$createdMenus = [];
		$items = $menu->items;
		$isTranslatedMenu = $menu->lang !== '' && $menu->lang !== 'en';

		if ($createdMenu = $this->createMenuContainer($menu->name)) { // create menu container
			$menuId = $createdMenu['menu_id'];
			$menuName = $createdMenu['name'];
			if ($menu->lang === '') $createdMenus[] = ['id' => $menuId, 'name' => $menuName, 'lang' => 'en'];
			if ($menu->lang !== '') $createdMenus[] = ['id' => $menuId, 'name' => $menuName, 'lang' => $menu->lang];
		} else {
			$response['status'] = 'error';
			$response['message'] = "Error creating menu container";
			wp_send_json($response, 400);
		}

		// divvy items up into parent- and child arrays depending on parent_item_menu_name being empty or not
		$parentItems = array_filter($items, function ($item) {
			return $item->parent_menu_item_name === '';
		});
		$childItems = array_filter($items, function ($item) {
			return $item->parent_menu_item_name !== '';
		});

		foreach ($parentItems as $parent) { // create parent menu items
			$result = $this->create_menu_item($menuId, $parent);
			if (!is_int($result)) {
				$response['status'] = 'error';
				$errorsArray[] = $result;
			}
		}

		$currentMenuItems = wp_get_nav_menu_items($menuName);
		$formattedCurrentMenuItemsArray = [];
		foreach ($currentMenuItems as $menuItem) {
			$formattedCurrentMenuItemsArray[$menuItem->title] = $menuItem->ID;
		}

		$finalItemArray = [];
		foreach ($childItems as $child) { // create child menu items
			$parentId = $formattedCurrentMenuItemsArray[$child->parent_menu_item_name];
			if (!is_int($parentId) || empty($parentId)) {
				$response['status'] = 'error';
				$errorsArray[] = [
					'message' => "Could not determine parent for \"{$child->menu_item_name}\"",
					'item' => $child,
				];
				continue;
			}

			$result = $this->create_menu_item($menuId, $child, $parentId);

			if (!is_int($result)) {
				$response['status'] = 'error';
				$errorsArray[] = $result;
			}
			$finalItemArray[] = ['childId' => $result, 'parentId' => $parentId];
		}

		// per parent create a parentString
		if ($this->createMegaMenu($currentMenuItems, wp_get_nav_menu_items($menuName)) === false) {
			$response['status'] = 'error';
			$response['message'] = "Error creating mega menu";
		}

		$this->joinCreatedMenus($createdMenus);
		$response['errors'] = $errorsArray;

		// what does this do?????
		if (isset($response['error']) && !empty($response['error'])) {
			$this->deleteAllCreatedMenus($createdMenus);
			wp_send_json($response, 400);
		}

		if ($isTranslatedMenu) {
			$term = get_term_by('name', $menu->parent_menu_name, 'nav_menu');
			$parentMenuId = $term->term_id;
			// join current table with parent
			$this->joinTranslatedMenuWithDefaultMenu($menuId, $menu->lang, $parentMenuId);
		}

		// set menu as active and vertical
		$locations = get_theme_mod('nav_menu_locations');
		$locations['vertical'] = $menuId;
		set_theme_mod('nav_menu_locations', $locations);

		$response['status'] = 'success';
		$response['message'] = 'menu(\'s) created successfully';
		$response['menus'] = $createdMenus;

		wp_send_json($response, 200);
	}

	private function joinTranslatedMenuWithDefaultMenu($createdMenuId, $menuLanguage, $parentMenuId)
	{
		global $wpdb;
		$sql = "SELECT trid FROM {$wpdb->prefix}icl_translations where element_id = " . $parentMenuId . " and element_type = \"tax_nav_menu\";";
		$result = $wpdb->get_results($sql);
		$trid = $result[0]->trid;
		$updateQuery = "UPDATE {$wpdb->prefix}icl_translations SET trid = " . $trid . ", language_code = \"" . $menuLanguage . "\", source_language_code = \"en\" WHERE element_id = " . $createdMenuId . " AND element_type = \"tax_nav_menu\";";
		$wpdb->get_results($updateQuery);
	}
	/**
	 * Creates a menu container (in wp_terms table)
	 *
	 * @param object $menu_name
	 * @return array
	 */
	private function createMenuContainer($name)
	{
		if (empty($name)) return false;
		$menuId = wp_create_nav_menu($name);

		if (isset($menuId->errors)) {
			return false;
		}
		return ['menu_id' => $menuId, 'name' => $name];
	}

	private function joinCreatedMenus($menuArray)
	{
		global $wpdb;
		$defaultLanguageMenuArray = [];
		// Get current term_relationships for menu ID's
		$existingRelationships = [];
		foreach ($menuArray as $menu) {
			if ($menu['lang'] !== 'en') {
				// get existing terms for secondary languages
				$tempResult[$menu['lang']] = $wpdb->get_results("SELECT object_id FROM {$wpdb->prefix}term_relationships WHERE term_taxonomy_id = {$menu['id']};");
				$mappedResult[$menu['lang']] = array_map(function ($e) {
					return $e->object_id;
				}, $tempResult[$menu['lang']]);
				$existingRelationships[$menu['lang']] = implode(',', $mappedResult[$menu['lang']]);
			}
			if ($menu['lang'] === 'en') {
				$defaultLanguageMenuArray = $menu;
			}
		}

		// update menu items langauges
		$stringTranslationsTable = $wpdb->prefix . 'icl_translations';
		foreach ($existingRelationships as $language => $relationshipsString) {
			$updateMenuItemsSql = "UPDATE $stringTranslationsTable SET language_code = '$language', source_language_code = 'en' WHERE element_id in ($relationshipsString);";
			$wpdb->get_results($updateMenuItemsSql);
		}

		$tridSql = "SELECT trid FROM $stringTranslationsTable WHERE language_code = 'en' AND element_id = {$defaultLanguageMenuArray['id']};";
		$trid = $wpdb->get_results($tridSql)[0]->trid;

		// update menu container languages and sync trid's of menu container
		foreach ($menuArray as $menu) {
			if ($menu['lang'] === 'en') continue;
			$updateMenuContainersSql = "UPDATE $stringTranslationsTable SET language_code = '{$menu['lang']}', trid = $trid, source_language_code = 'en' WHERE element_id = {$menu['id']};";
			$wpdb->get_results($updateMenuContainersSql);
		}
	}

	private function deleteAllCreatedMenus($menuArray)
	{
		foreach ($menuArray as $menuItem) {
			wp_delete_nav_menu($menuItem['id']);
		}
	}

	/**
	 * Creates a menu item
	 *
	 * @param int $menuId
	 * @param object $item
	 * @param integer $parentId
	 * @return void
	 */
	private function create_menu_item($menuId, $item, $parentId = 0)
	{

		if ((!is_int($item->position) || empty($item->position)) && $item->position !== 0) {
			$response['status'] = 'error';
			$response = [
				'message' => "Could not determine position for \"{$item->menu_item_name}\"",
				'item' => $item
			];
			return $response;
		}

		$menuObject = [
			'menu-item-position' => $item->position,
			'menu-item-status' => 'publish',
			'menu-item-parent-id' => $parentId,
			'menu-item-title' => $item->menu_item_name, // display name
			'menu-item-attr-title' => $item->menu_item_name, // css title attribute
		];

		if (!empty($item->category_slug) && empty($item->custom_url) && empty($item->product_sku)) {
			// category menu item
			$term = get_term_by('slug', $item->category_slug, 'product_cat');
			if ($term === false) {
				$response['message'] = "Error finding category";
				$response['item'] = $item;
				return $response;
			}

			$menuObject['menu-item-type'] = 'taxonomy';
			$menuObject['menu-item-object'] = $term->taxonomy;
			$menuObject['menu-item-object-id'] = $term->term_id;
		} else if (empty($item->category_slug) && empty($item->custom_url) && !empty($item->product_sku)) {
			// product menu item
			$productId = wc_get_product_id_by_sku($item->product_sku);
			if ($productId === 0) {
				$response['message'] = "Error finding product";
				$response['item'] = $item;
				return $response;
			}

			$menuObject['menu-item-type'] = 'post_type';
			$menuObject['menu-item-object'] = 'product';
			$menuObject['menu-item-object-id'] = $productId;
		} else if (empty($item->category_slug) && !empty($item->custom_url) && empty($item->product_sku) || $item->heading_text === true) {
			// custom menu item
			$menuObject['menu-item-type'] = 'custom';
			$menuObject['menu-item-url'] = $item->custom_url;
		} else {
			$response['message'] = "Error defining menu item type";
			$response['item'] = $item;
			return $response;
		}

		try {
			$response = wp_update_nav_menu_item(
				$menuId,
				0, // current menu item ID - 0 for new item,
				$menuObject
			);
			// save upload item information to items metadata to use later
			update_post_meta($response, 'peleman_mega_menu', $item);
		} catch (\Throwable $th) {
			$response['message'] = $th->getMessage();
			return $response;
		}

		return $response;
	}

	private function createMegaMenu($parentItemArray, $completeMenuItemArray)
	{
		$menuItemsArray = $this->createArrayOfParentAndChildMenuItems($parentItemArray, $completeMenuItemArray);

		foreach ($menuItemsArray as $parentId => $childArray) {
			if (empty($childArray)) continue;

			$parentItemData = get_post_meta($parentId, "peleman_mega_menu", true);
			$parentSettingsArray = $this->createMegaMenuParentObjectString($childArray, $parentItemData->column_widths);

			// this relies on the existance of the CSS class 'mega-disablelink'
			if ($this->addMenuObjectStringToPostMetaData($parentId, $parentSettingsArray, ['disablelink']) === false) {
				return false;
			}
			foreach ($childArray as $child) {
				$childSettingsArray = $this->createMegaMenuChildObjectString($child['item']);

				if ($this->addMenuObjectStringToPostMetaData($child['item'], $childSettingsArray) === false) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Updates a nav menu item's metadata
	 *
	 * @param int $id
	 * @param array $settingsArray
	 * @param array $cssClasses
	 * @return void
	 */
	private function addMenuObjectStringToPostMetaData($id, $settingsArray, $cssClasses = [''])
	{
		update_post_meta($id, '_menu_item_megamenu_col', 'columns-2');
		update_post_meta($id, '_menu_item_classes', $cssClasses);
		update_post_meta($id, '_menu_item_megamenu_col_tab', 'columns-1');
		update_post_meta($id, '_menu_item_megamenu_icon_alignment', 'left');
		update_post_meta($id, '_menu_item_megamenu_icon_size', 13);
		update_post_meta($id, '_menu_item_megamenu_style', 'menu_style_column');
		update_post_meta($id, '_menu_item_megamenu_widgetarea', 0);
		update_post_meta($id, '_menu_item_megamenu_background_image', '');
		update_post_meta($id, '_menu_item_megamenu_icon', '');
		update_post_meta($id, '_menu_item_megamenu_icon_color', '');
		update_post_meta($id, '_menu_item_megamenu_sublabel', '');
		update_post_meta($id, '_menu_item_megamenu_sublabel_color', '');
		update_post_meta($id, '_megamenu', $settingsArray);
	}


	/**
	 * Creates a postmetadata string for a mega menu child item
	 *
	 * @param int $childId	Child item ID
	 * @return array
	 */
	private function createMegaMenuChildObjectString($childId)
	{
		$itemType = get_post_meta($childId, '_menu_item_object');
		$jsonItemInformation = get_post_meta($childId, 'peleman_mega_menu', true);
		$isHeading = $jsonItemInformation->heading_text;
		$childImageId = 0;

		if (isset($itemType[0]) && $itemType[0] === 'product') {
			$productId = get_post_meta($childId, '_menu_item_object_id')[0];
			$childImageId = get_post_thumbnail_id($productId);
		}

		$childSettingsArray["type"] = "grid";


		if ($isHeading) {
			if (
				!empty($jsonItemInformation->category_slug)
				|| !empty($jsonItemInformation->custom_url)
				|| !empty($jsonItemInformation->product_sku)
			) {
				$childSettingsArray['disable_link'] = 'false';
			} else {
				$childSettingsArray['disable_link'] = 'true';
			}
			$childSettingsArray['styles'] = [
				'enabled' => [
					'menu_item_link_color' => '#333',
					'menu_item_link_color_hover' => '#333',
					'menu_item_link_weight' => 'bold'
				],
				'disabled' => [
					'menu_item_link_text_decoration' => 'none',
					'menu_item_border_color' => 'rgba(51, 51, 51, 0)',
					'menu_item_background_from' => '#333',
					'menu_item_background_to' => '#333',
					'menu_item_background_hover_from' => '#333',
					'menu_item_background_hover_to' => '#333',
					'menu_item_link_weight_hover' => 'inherit',
					'menu_item_font_size' => '0px',
					'menu_item_link_text_align' => 'left',
					'menu_item_link_text_transform' => 'none',
					'menu_item_link_text_decoration_hover' => 'none',
					'menu_item_border_color_hover' => '#333',
					'menu_item_border_top' => '0px',
					'menu_item_border_right' => '0px',
					'menu_item_border_bottom' => '0px',
					'menu_item_border_left' => '0px',
					'menu_item_border_radius_top_left' => '0px',
					'menu_item_border_radius_top_right' => '0px',
					'menu_item_border_radius_bottom_right' => '0px',
					'menu_item_border_radius_bottom_left' => '0px',
					'menu_item_icon_size' => '0px',
					'menu_item_icon_color' => '#333',
					'menu_item_icon_color_hover' => '#333',
					'menu_item_padding_left' => '0px',
					'menu_item_padding_right' => '0px',
					'menu_item_padding_bottom' => '0px',
					'menu_item_margin_left' => '0px',
					'menu_item_margin_right' => '0px',
					'menu_item_margin_top' => '0px',
					'menu_item_margin_bottom' => '0px',
					'menu_item_height' => '0px',
					'panel_width' => '0px',
					'panel_horizontal_offset' => '0px',
					'panel_vertical_offset' => '0px',
					'panel_background_from' => '#333',
					'panel_background_to' => '#333',
					'panel_background_image' => '0',
					'panel_background_image_size' => 'auto',
					'panel_background_image_repeat' => 'no-repeat',
					'panel_background_image_position' => 'left top'
				]
			];
			if ($jsonItemInformation->position !== 1) {
				$childSettingsArray['styles']['enabled']['menu_item_padding_top'] = '10px';
			}
		}
		if ($childImageId !== 0) {
			$childSettingsArray['image_swap'] = [
				"id" => strval($childImageId),
				"size" => "full"
			];
		}

		return $childSettingsArray;
	}

	private function divideIntoArrayOnColumnNumber($array, $columnNumber)
	{
		$finalArray = [];
		foreach ($array as $arrayKey => $arrayElement) {
			if ($arrayElement->column_number < 1 || $arrayElement->column_number > 3) {
				$response['status'] = 'error';
				$response['message'] = "column_number must be 1,2, or 3";
				$response['item'] = $arrayElement->menu_item_name;
				$response['column_number'] = $arrayElement->column_number;
				wp_send_json($response, 400);
			}
			if ($arrayElement->column_number === $columnNumber) {
				$finalArray[$arrayKey] = $arrayElement;
			}
		}
		return $finalArray;
	}

	/**
	 * Creates a postmetadata string for a mega menu parent item
	 *
	 * @param array $navMenuParentItemArray	Array of post item IDs aka nav menu item IDs
	 * @return string
	 */
	private function createMegaMenuParentObjectString($navMenuChildItemArray, $columnWidths /*, $imageSwapWidgetName*/)
	{
		// add JSON item data to elements
		$navMenuItemColumns = [];
		foreach ($navMenuChildItemArray as $navMenuItem) {
			$navMenuItemColumns[$navMenuItem['item']] = get_post_meta($navMenuItem['item'], "peleman_mega_menu", true);
		}

		// divvy up into columns
		$navMenuItemGroups = [
			'columnOne' => $this->divideIntoArrayOnColumnNumber($navMenuItemColumns, 1),
			'columnTwo' => $this->divideIntoArrayOnColumnNumber($navMenuItemColumns, 2),
			'columnThree' => $this->divideIntoArrayOnColumnNumber($navMenuItemColumns, 3),
		];

		$navColumnItemArray = [];
		$navColumnItemArray[] = $this->createMegaMenuParentObjectColumnString($columnWidths->one, $navMenuItemGroups['columnOne']);
		if (!empty($navMenuItemGroups['columnTwo'])) $navColumnItemArray[] = $this->createMegaMenuParentObjectColumnString($columnWidths->two, $navMenuItemGroups['columnTwo']);
		if (!empty($navMenuItemGroups['columnThree'])) $navColumnItemArray[] = $this->createMegaMenuParentObjectColumnString($columnWidths->three, $navMenuItemGroups['columnThree']);

		$imageSwapWidgetName = $this->updateMegaMenuImageSwapWidgets($navMenuItemGroups['columnOne']);

		$navColumnItemArray[] = [
			"meta" => [
				"span" => strval($columnWidths->four),
				"class" => "",
				"hide-on-desktop" => "false",
				"hide-on-mobile" => "false",
			],
			"items" => [
				[
					"id" => $imageSwapWidgetName,
					"type" => "widget"
				]
			]
		];

		$settingsArray = [
			"type" => "grid",
			"item_align" => "left",
			"icon_position" => "left",
			"sticky_visibility" => "always",
			"align" => "bottom-right",
			"hide_text" => "false",
			"disable_link" => "false",
			"hide_arrow" => "false",
			"hide_on_mobile" => "false",
			"hide_on_desktop" => "false",
			"close_after_click" => "false",
			"hide_sub_menu_on_mobile" => "false",
			"collapse_children" => "false",
			"grid" => [
				[
					"meta" => [
						"class" => "",
						"hide-on-desktop" => "false",
						"hide-on-mobile" => "false",
						"columns" => "12",
					],
					"columns" => $navColumnItemArray
				]
			]
		];

		return $settingsArray;
	}

	private function updateMegaMenuImageSwapWidgets($columnArray)
	{
		$firstChildImageId = $this->getFirstChildImageId($columnArray);

		$megaMenuImageSwapWidgets = get_option('widget_maxmegamenu_image_swap', true);
		array_push($megaMenuImageSwapWidgets, [
			'media_file_id' => $firstChildImageId,
			'media_file_size' => 'full',
			'wpml_language' => 'all',
		]);
		update_option('widget_maxmegamenu_image_swap', $megaMenuImageSwapWidgets);

		return 'maxmegamenu_image_swap-' . array_key_last($megaMenuImageSwapWidgets);
	}


	/**
	 * Given an array of child items, get the first image ID from it
	 *
	 * @param array $childArray
	 * @return int|null
	 */
	private function getFirstChildImageId($columnArray)
	{
		foreach ($columnArray as $arrayElement) {
			if (is_null($arrayElement->product_sku) || empty($arrayElement->product_sku)) {
				continue;
			} else {
				$product = wc_get_product(wc_get_product_id_by_sku($arrayElement->product_sku));

				return $product->get_image_id();
			}
		}
	}

	private function createMegaMenuParentObjectColumnString($columnWidth, $columnObjectArray)
	{
		$columnObjectItemsArray = [];
		foreach ($columnObjectArray as $key => $columnObjectItem) {
			$columnObjectItemsArray[] = $this->createMegaMenuParentObjectColumnObjectItemArray($key);
		}
		$columnObjectItems = [
			"meta" => [
				"span" => strval($columnWidth),
				"class" => "",
				"hide-on-desktop" => "false",
				"hide-on-mobile" => "false",
			],
			"items" => $columnObjectItemsArray
		];

		return $columnObjectItems;
	}

	private function createMegaMenuParentObjectColumnObjectItemArray($menuObjectId)
	{
		return [
			"id" => strval($menuObjectId),
			"type" => "item"
		];
	}

	/**
	 * A helper function that returns an array of parent item ID's keys with as value, an array of their child ID's & image ID's if present
	 *
	 * @param array $parentItemArray
	 * @param array $completeMenuItemArray
	 * @return array
	 */
	private function createArrayOfParentAndChildMenuItems($parentItemArray, $completeMenuItemArray)
	{
		// reduce array of parent items to array of parent item ID's
		$parentIdArray = array_map(function ($el) {
			return $el->ID;
		}, $parentItemArray);

		// take previous array and create array with parent ID's as key with empty arrays as values
		$menuItemsArray = [];
		foreach ($parentIdArray as $key => $value) {
			$menuItemsArray[$value] = [];
		}

		// loop over each menu item Id and, if it's parent is matched in the parentItemIdArray,
		// push IT'S Id under that parent
		foreach ($completeMenuItemArray as $menuItem) {
			array_push($menuItemsArray[$menuItem->menu_item_parent], ['item' => $menuItem->ID]);
		}

		return $menuItemsArray;
	}
}
