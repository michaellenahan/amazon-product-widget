<?php

namespace Drupal\amazon_product_widget\Plugin\QueueWorker;

use Drupal\amazon_product_widget\ProductService;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates locally cached product data via Amazon API.
 *
 * @QueueWorker(
 *   id = "amazon_product_widget.product_data_update",
 *   title = @Translation("Update amazon product data"),
 *   cron = {"time" = 300}
 * )
 */
class ProductDataUpdate extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  use LoggerChannelTrait;

  /**
   * Product service.
   *
   * @var \Drupal\amazon_product_widget\ProductService
   */
  protected $productService;

  /**
   * Keep track of processed collection updates per request.
   *
   * @var array
   */
  protected static $processed = [];

  /**
   * Constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\amazon_product_widget\ProductService $product_service
   *   Product service.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ProductService $product_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->productService = $product_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('amazon_product_widget.product_service'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    if (isset(static::$processed[$item['collection']])) {
      // Only process items of a single collection once during a queue run.
      return;
    }
    static::$processed[$item['collection']] = TRUE;

    $store = $this->productService->getProductStore();
    $outdated_asins = $store->getOutdatedKeys();
    $this->productService->getProductData($outdated_asins, TRUE);

    $this->getLogger('amazon_product_widget')->info('Updated %number amazon product data.', [
      '%number' => count($outdated_asins),
    ]);

    // Allow the queue to finish processing items when invoked multiple times -
    // without cron.
    if ($store->hasStaleData()) {
      throw new RequeueException();
    }
  }

}
