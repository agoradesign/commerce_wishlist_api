<?php

namespace Drupal\commerce_wishlist_api\Plugin\rest\resource;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_wishlist\Resolver\ChainWishlistTypeResolverInterface;
use Drupal\commerce_wishlist\WishlistManagerInterface;
use Drupal\commerce_wishlist\WishlistProviderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rest\ModifiedResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Creates wishlist items for the session's wishlists.
 *
 * @todo Currently hardcoded to support product variations only.
 *
 * @RestResource(
 *   id = "commerce_wishlist_add",
 *   label = @Translation("Wishlist add"),
 *   uri_paths = {
 *     "create" = "/wishlist/add"
 *   }
 * )
 */
class WishlistAddResource extends WishlistResourceBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The wishlist item store.
   *
   * @var \Drupal\commerce_wishlist\WishlistItemStorageInterface
   */
  protected $wishlistItemStorage;

  /**
   * The chain wishlist type resolver.
   *
   * @var \Drupal\commerce_wishlist\Resolver\ChainWishlistTypeResolverInterface
   */
  protected $chainWishlistTypeResolver;

  /**
   * The current store.
   *
   * @var \Drupal\commerce_store\CurrentStoreInterface
   */
  protected $currentStore;

  /**
   * Constructs a new WishlistAddItemsResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\commerce_wishlist\WishlistProviderInterface $wishlist_provider
   *   The wishlist provider.
   * @param \Drupal\commerce_wishlist\WishlistManagerInterface $wishlist_manager
   *   The wishlist manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_wishlist\Resolver\ChainWishlistTypeResolverInterface $chain_wishlist_type_resolver
   *   The chain wishlist type resolver.
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, WishlistProviderInterface $wishlist_provider, WishlistManagerInterface $wishlist_manager, EntityTypeManagerInterface $entity_type_manager, ChainWishlistTypeResolverInterface $chain_wishlist_type_resolver, CurrentStoreInterface $current_store) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger, $wishlist_provider, $wishlist_manager);

    $this->entityTypeManager = $entity_type_manager;
    $this->wishlistItemStorage = $entity_type_manager->getStorage('commerce_wishlist_item');
    $this->chainWishlistTypeResolver = $chain_wishlist_type_resolver;
    $this->currentStore = $current_store;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('commerce_wishlist.wishlist_provider'),
      $container->get('commerce_wishlist.wishlist_manager'),
      $container->get('entity_type.manager'),
      $container->get('commerce_wishlist.chain_wishlist_type_resolver'),
      $container->get('commerce_store.current_store')
    );
  }

  /**
   * Add wishlist items to the session's wishlists.
   *
   * @param array $body
   *   The unserialized request body.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   The resource response.
   *
   * @throws \Exception
   */
  public function post(array $body, Request $request) {
    $wishlist_items = [];

    // Do an initial validation of the payload before any processing.
    foreach ($body as $key => $wishlist_item_data) {
      if (!isset($wishlist_item_data['purchasable_entity_type'])) {
        throw new UnprocessableEntityHttpException(sprintf('You must specify a purchasable entity type for row: %s', $key));
      }
      if (!isset($wishlist_item_data['purchasable_entity_id'])) {
        throw new UnprocessableEntityHttpException(sprintf('You must specify a purchasable entity ID for row: %s', $key));
      }
      if (!$this->entityTypeManager->hasDefinition($wishlist_item_data['purchasable_entity_type'])) {
        throw new UnprocessableEntityHttpException(sprintf('You must specify a valid purchasable entity type for row: %s', $key));
      }
    }
    foreach ($body as $wishlist_item_data) {
      $storage = $this->entityTypeManager->getStorage($wishlist_item_data['purchasable_entity_type']);
      $purchasable_entity = $storage->load($wishlist_item_data['purchasable_entity_id']);
      if (!$purchasable_entity || !$purchasable_entity instanceof PurchasableEntityInterface) {
        continue;
      }
      $wishlist_item = $this->wishlistItemStorage->createFromPurchasableEntity($purchasable_entity, [
        'quantity' => (!empty($wishlist_item_data['quantity'])) ? $wishlist_item_data['quantity'] : 1,
      ]);

      $wishlist_type_id = $this->chainWishlistTypeResolver->resolve($wishlist_item);
      $store = $this->selectStore($purchasable_entity);
      $wishlist = $this->wishlistProvider->getWishlist($wishlist_type_id, $store);
      if (!$wishlist) {
        $wishlist = $this->wishlistProvider->createWishlist($wishlist_type_id, $store);
      }
      $wishlist_items[] = $this->wishlistManager->addWishlistItem($wishlist, $wishlist_item, TRUE);
    }

    $response = new ModifiedResourceResponse(array_values($wishlist_items), 200);
    return $response;
  }

  /**
   * Selects the store for the given purchasable entity.
   *
   * If the entity is sold from one store, then that store is selected.
   * If the entity is sold from multiple stores, and the current store is
   * one of them, then that store is selected.
   *
   * @param \Drupal\commerce\PurchasableEntityInterface $entity
   *   The entity being added to wishlist.
   *
   * @throws \Exception
   *   When the entity can't be purchased from the current store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface
   *   The selected store.
   */
  protected function selectStore(PurchasableEntityInterface $entity) {
    $stores = $entity->getStores();
    if (count($stores) === 1) {
      $store = reset($stores);
    }
    elseif (count($stores) === 0) {
      // Malformed entity.
      throw new \Exception('The given entity is not assigned to any store.');
    }
    else {
      $store = $this->currentStore->getStore();
      if (!in_array($store, $stores)) {
        // Indicates that the site listings are not filtered properly.
        throw new \Exception("The given entity can't be purchased from the current store.");
      }
    }

    return $store;
  }

}
