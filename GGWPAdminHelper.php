<?php
/**
 * Admin menu creator class.
 *
 * ex)
 * $binder = new GGAdminHelper($handler);
 * $binder->bind('menu', array('menu_title' => 'mymenu', 'method' => 'mainMenu', 'menu_slug' => 'my_plugins')
 *        ->bind('submenu', array('parent' => 'my_plugins', 'menu_title' => 'submenu', 'method' => 'submenu')
 *        ->register();
 *
 *
 * @author grepgrape
 */
class GGWPAdminHelper
{
  /** Menu buffer */
  protected $menus = array();

  /** A handler class to bind a menu.*/
  protected $handler;

  /** Allowed sections */
  protected static $sections = array(
    'menu',
    'submenu',
    'options',
    'theme',
    'management',
    'users',
    'links',
    'utility',
    'plugins',
    'dashboard',
    'posts',
    'media',
    'pages',
  );

  private $registerd = false;

  /**
   * Sets a handler class.
   *
   * @param <Object> $handler
   */
  public function __construct($handler)
  {
    $this->handler = $handler;
  }

  /**
   * Adds menus in the $section.
   *
   * @param <string> $section
   * @param <array> $args
   * 
   * @return FHAdminMenuCreator
   */
  public function add($section, array $args)
  {
    if (! in_array($section, self::$sections)) {
      wp_die(sprintf('%s: Enable sections are %s.', get_class($this), implode(',', self::$sections)));
    }

    if ($section === 'submenu' && ! isset($args['parent'])) {
      wp_die(sprintf('%s: A submenu needs a parent parameter.', get_class($this)));
    }

    foreach (array('menu_title', 'method') as $required) {
      if (! array_key_exists($required, $args)) {
        wp_die(sprintf('%s: a %s is required parameter.', get_class($this), $required));
      }
    }

    $this->menus[$section][] = array(
      'parent'        => isset($args['parent']) ? $args['parent'] : null,
      'page_title'    => isset($args['page_title']) ? $args['page_title']: $args['menu_title'],
      'menu_title'    => $args['menu_title'],
      'capability'    => isset($args['capability']) ? $args['capability']: 'administrator',
      'menu_slug'     => isset($args['menu_slug']) ? $args['menu_slug']: $args['menu_title'],
      'callback'      => array($this->handler, $args['method']),
    );

    return $this;
  }

  /**
   * Adds an admin_menu action.
   */
  public function register()
  {
    if (! $this->registerd) {
      add_action('admin_menu', array($this, '_createMenu'));
      $this->registerd = true;
    }
  }

  /**
   * Creates menus.
   * This method is called on admin_menu hook.
   *
   */
  public function _createMenu()
  {
    if (! $this->registerd) {
      wp_die(sprintf('%s: This method is automatically called in an admin_menu action. Instead of this method, call registerMenu().', get_class($this)));
    }
    foreach ($this->menus as $section => $menus) {
      $menuFunction = "add_{$section}_page";;
      foreach ($menus as $menu) {
        if ($menu['parent']) {
          call_user_func($menuFunction, $menu['parent'], $menu['page_title'], $menu['menu_title'], $menu['capability'], $menu['menu_slug'], $menu['callback']);
        } else {
          call_user_func($menuFunction, $menu['page_title'], $menu['menu_title'], $menu['capability'], $menu['menu_slug'], $menu['callback']);
        }
      }
    }
  }
}



// Local variables:
// tab-width: 2
// c-basic-offset: 2
// indent-tabs-mode: nil
// End:
