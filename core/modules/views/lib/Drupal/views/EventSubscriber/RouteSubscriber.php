<?php

/**
 * @file
 * Contains \Drupal\views\EventSubscriber\RouteSubscriber.
 */

namespace Drupal\views\EventSubscriber;

use Drupal\Core\Page\HtmlPage;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\KeyValueStore\StateInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\views\Plugin\views\display\DisplayRouterInterface;
use Drupal\views\ViewExecutable;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Builds up the routes of all views.
 *
 * The general idea is to execute first all alter hooks to determine which
 * routes are overridden by views. This information is used to determine which
 * views have to be added by views in the dynamic event.
 *
 * Additional to adding routes it also changes the htmlpage response code.
 *
 * @see \Drupal\views\Plugin\views\display\PathPluginBase
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * Stores a list of view,display IDs which haven't be used in the alter event.
   *
   * @var array
   */
  protected $viewsDisplayPairs;

  /**
   * The view storage controller.
   *
   * @var \Drupal\Core\Entity\EntityStorageControllerInterface
   */
  protected $viewStorageController;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\KeyValueStore\StateInterface
   */
  protected $state;

  /**
   * Stores an array of route names keyed by view_id.display_id.
   *
   * @var array
   */
  protected $viewRouteNames = array();

  /**
   * Constructs a \Drupal\views\EventSubscriber\RouteSubscriber instance.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\KeyValueStore\StateInterface $state
   *   The state key value store.
   */
  public function __construct(EntityManagerInterface $entity_manager, StateInterface $state) {
    $this->viewStorageController = $entity_manager->getStorageController('view');
    $this->state = $state;
  }

  /**
   * Resets the internal state of the route subscriber.
   */
  public function reset() {
    $this->viewsDisplayPairs = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = parent::getSubscribedEvents();
    $events[KernelEvents::VIEW][] = array('onHtmlPage', 75);
    $events[RoutingEvents::FINISHED] = array('routeRebuildFinished');
    return $events;
  }

  /**
   * Gets all the views and display IDs using a route.
   */
  protected function getViewsDisplayIDsWithRoute() {
    if (!isset($this->viewsDisplayPairs)) {
      $this->viewsDisplayPairs = array();

      // @todo Convert this method to some service.
      $views = $this->getApplicableViews();
      foreach ($views as $data) {
        list($view, $display_id) = $data;
        $id = $view->storage->id();
        $this->viewsDisplayPairs[] = $id . '.' . $display_id;
      }
      $this->viewsDisplayPairs = array_combine($this->viewsDisplayPairs, $this->viewsDisplayPairs);
    }
    return $this->viewsDisplayPairs;
  }

  /**
   * Sets the proper response code coming from the http status area handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent $event
   *   The Event to process.
   *
   * @see \Drupal\views\Plugin\views\area\HTTPStatusCode
   */
  public function onHtmlPage(GetResponseForControllerResultEvent $event) {
    $page = $event->getControllerResult();
    if ($page instanceof HtmlPage) {
      if (($request = $event->getRequest()) && $request->attributes->has('view_id')) {
        $page->setStatusCode($request->attributes->get('_http_statuscode', 200));
      };
    }
  }

  /**
   * Returns a set of route objects.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   A route collection.
   */
  public function routes() {
    $collection = new RouteCollection();
    foreach ($this->getViewsDisplayIDsWithRoute() as $pair) {
      list($view_id, $display_id) = explode('.', $pair);
      $view = $this->viewStorageController->load($view_id);
      // @todo This should have an executable factory injected.
      if (($view = $view->getExecutable()) && $view instanceof ViewExecutable) {
        if ($view->setDisplay($display_id) && $display = $view->displayHandlers->get($display_id)) {
          if ($display instanceof DisplayRouterInterface) {
            $this->viewRouteNames += (array) $display->collectRoutes($collection);
          }
        }
        $view->destroy();
      }
    }

    $this->state->set('views.view_route_names', $this->viewRouteNames);
    return $collection;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection, $provider) {
    foreach ($this->getViewsDisplayIDsWithRoute() as $pair) {
      list($view_id, $display_id) = explode('.', $pair);
      $view = $this->viewStorageController->load($view_id);
      // @todo This should have an executable factory injected.
      if (($view = $view->getExecutable()) && $view instanceof ViewExecutable) {
        if ($view->setDisplay($display_id) && $display = $view->displayHandlers->get($display_id)) {
          if ($display instanceof DisplayRouterInterface) {
            // If the display returns TRUE a route item was found, so it does not
            // have to be added.
            $view_route_names = $display->alterRoutes($collection);
            $this->viewRouteNames = $view_route_names + $this->viewRouteNames;
            foreach ($view_route_names as $id_display => $route_name) {
              unset($this->viewsDisplayPairs[$id_display]);
            }
          }
        }
        $view->destroy();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function routeRebuildFinished() {
    $this->reset();
    $this->state->set('views.view_route_names', $this->viewRouteNames);
  }

  /**
   * Returns all views/display combinations with routes.
   *
   * @see views_get_applicable_views()
   */
  protected function getApplicableViews() {
    return views_get_applicable_views('uses_route');
  }

}
