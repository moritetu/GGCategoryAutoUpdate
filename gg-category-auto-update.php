<?php
/*
Plugin Name: GrepGrape Category Auto Update.
Plugin URI: http://wiki.grepgrape.net/?WordPress%2FGGCategoryAutoUpdate
Description: This plugin helps to update categories when you post an article to multiple sites which you own.
Author: grepgrape
Version: 1.0
Author URI: http://wiki.grepgrape.net/
*/

/*  Copyright 2013 grepgrape.net (email : webmaster@grepgrape.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


// Required ver 5.3
if (version_compare(PHP_VERSION, '5.3.0') < 0) {
  return;
}


// The debug flag.
// true: debug mode, false: normal mode.
define('GG_CAEGORY_AUTO_UPDATE_DEBUG', false);

// The i10n text domain.
define('GG_AUTOCAT_DOMAIN', 'GG_AUTOCAT');



if (! function_exists('gg_autocat_debug')) {
  /**
   * Appends args to a debug file.
   *
   * @param mixed $args The debug messages or data.
   */
  function gg_autocat_debug()
  {
    $output = __DIR__ . '/gg_autocat_debug.log';
    if (! GG_CAEGORY_AUTO_UPDATE_DEBUG) {
      return;
    }
    $log = array();
    foreach (func_get_args() as $var) {
      if (is_array($var) || is_object($var)) {
        $log[] = var_export($var, true);
      } else {
        $log[] = $var;
      }
    }
    $log[] = PHP_EOL;
    file_put_contents($output, date_i18n('Y/m/d H:i:s - ') . implode(PHP_EOL, $log), FILE_APPEND);
  }
}


if (! class_exists('GGCategoryAutoUpdate')) {
  include_once __DIR__ . '/GGWPAdminHelper.php';

  /**
   * This class supports to update the category of the post when you post a article using XMLRPC.
   *
   * @author grepgrape
   */ 
  class GGCategoryAutoUpdate
  {
    /**
     * The plugin name.
     *
     * @var string 
     */
    const NAME = 'GGCategoryAutoUpdate';

    /**
     * The plugin version.
     *
     * @var string
     */
    const VERSION = '1.0';

    /**
     * The plugin options.
     *
     * @var object
     */
    private $options;

    /**
     * The freeze flag.
     *
     * @var bool
     */
    private $freeze = false;

    /**
     * XMLRPC Call Method
     *
     * @var string
     */
    private $xmlrpcMethod = null;

    /**
     * The category which the current post has.
     *
     * @var array
     */
    private $currentCategories = array();

    /**
     * Initializes a object and registers several hooks.
     * This plugin uses the following actions.
     * 
     * - save_post
     * - after_setup_theme
     */
    public function __construct()
    {
      // Regists plugin's handlers.
      register_activation_hook(__FILE__, array(&$this, 'install'));
//      register_deactivation_hook(__FILE__, array(&$this, 'uninstall'));
      add_action('after_setup_theme', array($this, 'boot'));
      // Regists xmlrpc request handlers.
      add_action('save_post', array($this, 'handleSavePost'), 99, 2);
      add_action('xmlrpc_call', array($this, 'handleXMLRpcCall'), 99, 1);
      add_action('set_object_terms', array($this, 'handleSetTexonomy'), 99, 6);
    }

    /**
     * Boots this plugin.
     */
    public function boot()
    {
      // Loads options.
      $options = (array)get_option(self::NAME, $this->getDefaultOptions());
      $options = array_merge((array)$this->getDefaultOptions(), $options);
      $this->options = (object)$options;

      // Loads text.
      load_textdomain(GG_AUTOCAT_DOMAIN, plugin_dir_path(__FILE__) . '/languages/' . get_locale() . '.mo');

      // Registers admin features.
      $admin = new GGWPAdminHelper($this);
      $menuSlug = self::NAME;
      $admin->add('options', array('menu_title' => __('GGCategoryAutoUpdate', GG_AUTOCAT_DOMAIN), 'method' => 'ggAdminMainMenu', 'menu_slug' => $menuSlug))
        ->register();
    }

    /**
     * This method is called in the xmlrpc method.
     *
     * @param string $method The called method.
     */
    public function handleXMLRpcCall($method)
    {
      global $wp_xmlrpc_server;
      gg_autocat_debug("Called xmlrpc method:", $method);
      $this->xmlrpcMethod = $method;
      if ($this->xmlrpcMethod === 'mt.setPostCategories') {
        // Get current category and save.
        $postId = $wp_xmlrpc_server->message->params[0];
        $this->currentCategories = array();
        $category = (array)get_the_category($postId);
        foreach ($category as $cat) {
          $this->currentCategories[] = $cat->cat_ID;
        }
        gg_autocat_debug(__METHOD__, 'Save current category:', $postId, $this->currentCategories);
      }
    }

    /**
     * The handler for the save_post action.
     * The save_post action is invoked in the wp_insert_post function.
     * 
     * @param int    $postId  The post id.
     * @param object $post    The post object.
     */
    public function handleSavePost($postId, $post)
    {
      // This plugin supports only a request by way of XMLRPC, but optionally you can change this operation.
      if (! $this->options->enableToAllPosts) {
        if (! defined('XMLRPC_REQUEST')) {
          return;
        }
      }

      // Check the post type.
      $postType = $post->post_type;
      if (! in_array($postType, $this->options->allowedPostTypes)) {
        return;
      }

      // Check user's role.
      if ($this->options->createCategoryIfNotExists && ! current_user_can('manage_categories')) {
        return;
      }

      if ($postType == 'page') {
        // The page has not have category attribute, but for the future.
        foreach (array('edit_pages') as $capability) {
          if (! current_user_can($capability)) {
            gg_autocat_debug("you don't have permission to $capability.");
            return;
          }
        }
      } else {
        foreach (array('edit_posts', 'edit_published_posts') as $capability) {
          if (! current_user_can($capability)) {
            gg_autocat_debug("you don't have permission to $capability.");
            return;
          }
        }
      }

      // Check if the post is revision.
      if ($postParent = wp_is_post_revision($postId)) {
        gg_autocat_debug("The post $postId is revision. It's parent is $postParent.");
        return;
      }

      // Check if supported category.
      if (! is_object_in_taxonomy($postType, 'category')) {
        // This postType object does not supports category.
        gg_autocat_debug("This post $postId does not support category.");
        return;
      }

      if ($this->shouldUpdateCategory($post)) {
        // Update post's category.
        $this->updatePostCategory($post);
      }
    }

    /**
     * This method is called in wp_set_object_terms function.
     *
     * @param string $object_id The post id.
     * @param array|int|string $terms The slug or id of the term, will replace all existing related terms in this taxonomy.
     * @param array $tt_ids The term taxonomy ids.
     * @param bool $append If false will delete difference of terms.
     * @param array $old_tt_ids The old term texonomy ids.
     *
     */
    public function handleSetTexonomy($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids)
    {
      if ($this->freeze) {
        return;
      }
      gg_autocat_debug(__METHOD__, $object_id, $terms);

      // Each method process.
      if ($this->xmlrpcMethod === 'mt.setPostCategories') {
        if (! $this->shouldUpdateCategory(get_post($object_id))) {
          gg_autocat_debug(__METHOD__, 'We do not need to update category.');
          return;
        }
        // Get current category.
        $updateCategories = $this->currentCategories;
        // Freeze this method call.
        $this->freeze = true;
        $result = wp_set_post_categories($object_id, $updateCategories);
        gg_autocat_debug('Updates post category:' , $object_id, $updateCategories, $result);
      } else if ($this->xmlrpcMethod === 'metaWeblog.editPost') {
        // Nothing.
      }
    }

    /**
     * Extracts a category from the post content.
     * 
     * <pre>
     * The syntax for hierarchical categories.
     * <!-- ggcat[cat1>cat2>cat3] -->
     *
     * The syntax for multiple categories.
     * <!-- ggcat[cat1,cat2,cat3] -->
     * </pre>
     *
     * @param object $post The post object.
     * @return array The categories. 
     */
    private function updatePostCategory($post)
    {
      if ($this->freeze) {
        return;
      }
      $content = $post->post_content;
      preg_match('/' . $this->options->ggSyntax . '/', $content, $matches);
      gg_autocat_debug('Matches:', $matches);
      if (empty($matches) || ! isset($matches[1]) || empty($matches[1])) {
        return;
      }

      $updateCategories = array();
      $categoryStr = $matches[1];
      if (strpos($categoryStr, '>') !== false) {
        gg_autocat_debug("Hierarchical: $categoryStr");
        $i = 0;
        $catstack = array();
        foreach (explode('>', $categoryStr) as $category) {
          $category = trim($category);
          if (empty($category)) {
            gg_autocat_debug('Found a invalid category. Stops to update category.');
            Return;
          }
          $categoryId = category_exists($category);
          $parent = $i > 0 ? $catstack[$i - 1] : 0;
          if ($categoryId = $this->getCategoryId($category, $parent)) {
            $catstack[] = $categoryId;
          } else {
            gg_autocat_debug('Stops to update category.');
            return;
          }
          $i++;
        }
        $updateCategories[] = array_pop($catstack);
      } else {
        gg_autocat_debug("Multiple: $categoryStr");
        $categoryStr = preg_replace('/(\s|　)+/', ',', $categoryStr);
        foreach (explode(',', $categoryStr) as $category) {
          $category = trim($category);
          if (empty($category)) {
            gg_autocat_debug('Found a invalid category. Stops to update category.');
            return;
          }
          if ($categoryId = $this->getCategoryId($category)) {
            $updateCategories[] = $categoryId;
          }
        }
      }
      if (! empty($updateCategories)) {
        $result = wp_set_post_categories($post->ID, $updateCategories);
        gg_autocat_debug('Updates post category:' , $post->ID, $updateCategories, $result);
        // Deletes cache. Referes to the clean_term_cache function in taxonomy.php.
        $taxonomy = 'category';
        wp_cache_delete('all_ids', $taxonomy);
        wp_cache_delete('get', $taxonomy);
        delete_option("{$taxonomy}_children");
        _get_term_hierarchy($taxonomy);
        $this->freeze = true;
        if (! empty($result) && $this->options->deleteSyntaxAfterUpdate) {
          // Deletes the category syntax in the post content.
          if ($content = preg_replace('/' . $this->options->ggSyntax . '/', '', $content, 1)) {
            gg_autocat_debug('Deletes category syntax.', $post->ID, $content);
            $post->post_content = $content;
            $result = wp_update_post($post);
            if (is_wp_error($result)) {
              gg_autocat_debug('Failed to update post content:', $post);
            }
          }
        }
      }
    }

    /**
     * Returns the category id.
     *
     * @param string $categoryStr The cat_ID or cat_name or slug.
     * @return int The category id.
     */ 
    private function getCategoryId($categoryStr, $parent = 0)
    {
      $category = $categoryStr;
      if (preg_match('#^[0-9]+$#', $categoryStr)) {
        $category = (int)$categoryStr;
      }
      $categoryId = category_exists($category);
      if (! $categoryId && $this->options->createCategoryIfNotExists) {
        $categoryId = wp_create_category($category, $parent);
        gg_autocat_debug("Creates a category: $categoryId");
        if (is_wp_error($categoryId)) {
          gg_autocat_debug("Failed to create a category: $category");
          return 0;
        }
      }
      return $categoryId;
    }

    /**
     * Returns the bool flag to indicate whether we should update category of the post.
     *
     * @param object $post The post object.
     * @return bool If we should update category of the post, return true. Otherwise false.
     */
    private function shouldUpdateCategory($post)
    {
      $shouldUpdateCategory = false;
      $categories = (array)get_the_category($post->ID);
      if (empty($categories)) {
        // It is set nothing categories. If the post's post_status is auto-draft, it's category will be updated.
        $shouldUpdateCategory = true;
        gg_autocat_debug('nothing categories.');
      } else {
        $defcat = (array) $this->options->defaultCategory;
        if (count($defcat) == 1 && $defcat[0] === '*') {
          gg_autocat_debug('applies always.');
          $shouldUpdateCategory = true;
        } else {
          foreach ($categories as $cat) {
            if (in_array($cat->cat_ID, $this->options->defaultCategory)) {
              $shouldUpdateCategory = true;
              gg_autocat_debug("The post's category is default category:", $cat);
              break;
            }
          }
        }
      }
      return $shouldUpdateCategory;
    }

    /**
     * Install action.
     */
    public function install()
    {
      // do something
      if (! get_option(self::NAME)) {
        gg_autocat_debug('Install: Regists the default option.');
        update_option(self::NAME, $this->getDefaultOptions());
      }
    }

    /**
     * Uninstall action.
     */
    public function uninstall()
    {
      // do something
      delete_option(self::NAME);
      gg_autocat_debug('Uninstall: Deletes the option.');
    }

    /**
     * Returns the default options.
     *
     * @return stdClass The default options.
     */
    private function getDefaultOptions()
    {
      $options = new stdClass;
      $options->defaultCategory = array(get_option('default_category'));
      $options->enableToAllPosts = false;
      $options->createCategoryIfNotExists = false;
      $options->ggSyntax = '<!-- *ggcat\[ *([^]]+?) *\] *-->';
      $options->allowedPostTypes = array('post');
      $options->deleteSyntaxAfterUpdate = false;
      return $options;
    }

    /*+
     * Displays an admin page.
     */ 
    public function ggAdminMainMenu()
    {
      global $wp_post_types;
      $opt = $this->options;
      $message = '';
      // Is this request a update action?
      if (! empty($_POST) && isset($_POST['gg_autocat'])) {
        if (! wp_verify_nonce($_POST['gg_autocat'], 'gg_autocat')) {
          // The transaction token is invalid.
          $message .= sprintf("<p>%s</p>", __('Failed to update.', GG_AUTOCAT_DOMAIN));
        } else {
          // Updates option.
          if (isset($_POST['defaultCategory']) && strlen(trim($_POST['defaultCategory'])) != 0) {
            $defaultCategory = str_replace(' ', '', $_POST['defaultCategory']);
            $opt->defaultCategory = explode(',', $defaultCategory);
          }
          if (isset($_POST['enableToAllPosts'])) {
            $enableToAllPosts = $_POST['enableToAllPosts'];
            $opt->enableToAllPosts = (intval($enableToAllPosts) == 1) ? true: false;
          }
          if (isset($_POST['deleteSyntaxAfterUpdate'])) {
            $deleteSyntaxAfterUpdate= $_POST['deleteSyntaxAfterUpdate'];
            $opt->deleteSyntaxAfterUpdate = (intval($deleteSyntaxAfterUpdate) == 1) ? true: false;
          }
          if (isset($_POST['createCategoryIfNotExists'])) {
            $createCategoryIfNotExists = $_POST['createCategoryIfNotExists'];
            $opt->createCategoryIfNotExists = (intval($createCategoryIfNotExists) == 1) ? true: false;
          }
          if (isset($_POST['allowedPostTypes']) && strlen(trim($_POST['allowedPostTypes'])) != 0) {
            $allowedPostTypes = str_replace(' ', '', $_POST['allowedPostTypes']);
            $opt->allowedPostTypes = explode(',', $allowedPostTypes);
          }
          if (isset($_POST['ggSyntax'])) {
            $opt->ggSyntax = trim(stripslashes($_POST['ggSyntax']));
          }
          // Checks the specified categories.
          if (! empty($opt->defaultCategory) && $opt->defaultCategory[0] != '*') {
            $categories = get_categories(array('include' => $opt->defaultCategory, 'hide_empty' => false));
            if (is_wp_error($categories) || empty($categories) || count($categories) != count($opt->defaultCategory)) {
              $message .= sprintf("<p>%s</p>", __('Are the specified categories valid, really?', GG_AUTOCAT_DOMAIN));
              goto update_end;
            }
          }
          if (! update_option(self::NAME, $opt)) {
            $message .= sprintf("<p>%s</p>", __('Failed to update or not changed.', GG_AUTOCAT_DOMAIN));
          } else {
            $message .= sprintf("<p>%s</p>", __('Updated successfully.', GG_AUTOCAT_DOMAIN));
            gg_autocat_debug("Options have been updated successfully.", $opt);
          }
          update_end:
        }
      }
      //
      // From here, the html contents in a admin page.
      //
?>
<div class="wrap">
  <div id="icon-options-general" class="icon32"><br /></div><h2><?php _e('GGCategoryAutoUpdate Settings', GG_AUTOCAT_DOMAIN) ?></h2>
  <?php if (! empty($message)): ?><div class="updated"><?php echo $message ?></div><?php endif ?>
  <form action="options-general.php?page=GGCategoryAutoUpdate" method="post">
    <table class="form-table">
      <tr>
        <th><?php _e('Default Category', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label for="defaultCategory"><input type="text" name="defaultCategory" placeholder="1,2" value="<?php echo esc_attr(implode(',', $opt->defaultCategory)) ?>" required></label><br>
          <small><em><?php _e('Please default category ids. You can specify multiple categories separated by commnas. If "*" is set, applied always.', GG_AUTOCAT_DOMAIN) ?></em></small>
        </td>
      </tr>
      <tr>
        <th><?php _e('Enable to all posts', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label><input type="radio" name="enableToAllPosts" value="1" <?php echo $opt->enableToAllPosts ? 'checked': '' ?>> <?php _e('apply', GG_AUTOCAT_DOMAIN) ?></label>&nbsp;&nbsp;
          <label><input type="radio" name="enableToAllPosts" value="0" <?php echo ! $opt->enableToAllPosts ? 'checked': '' ?>> <?php _e('only xmlrpc post', GG_AUTOCAT_DOMAIN) ?></label><br>
          <small><em><?php _e('If this option is on, this plugin applies category auto update for all post actions.', GG_AUTOCAT_DOMAIN) ?></em></small>
        </td>
      </tr>
      <tr>
        <th><?php _e('Delete a syntax after update', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label><input type="radio" name="deleteSyntaxAfterUpdate" value="1" <?php echo $opt->deleteSyntaxAfterUpdate ? 'checked': '' ?>> <?php _e('delete', GG_AUTOCAT_DOMAIN) ?></label>&nbsp;&nbsp;
          <label><input type="radio" name="deleteSyntaxAfterUpdate" value="0" <?php echo ! $opt->deleteSyntaxAfterUpdate ? 'checked': '' ?>> <?php _e('not delete', GG_AUTOCAT_DOMAIN) ?></label><br>
          <small><em><?php _e('If this option is on, this plugin deletes the category syntax in the post content after updating a post.', GG_AUTOCAT_DOMAIN) ?></em></small>
        </td>
      </tr>
      <tr>
        <th><?php _e('Create category If not exists', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label><input type="radio" name="createCategoryIfNotExists" value="1" <?php echo $opt->createCategoryIfNotExists ? 'checked': '' ?>> <?php _e('create', GG_AUTOCAT_DOMAIN) ?></label>
          &nbsp;&nbsp;<label><input type="radio" name="createCategoryIfNotExists" value="0" <?php echo ! $opt->createCategoryIfNotExists ? 'checked': '' ?>> <?php _e('nothing', GG_AUTOCAT_DOMAIN) ?></label><br>
          <small><em><?php _e('If this option is on and the category of the posted article does not exist, this plugin creates newly categories.', GG_AUTOCAT_DOMAIN) ?></em></small>
        </td>
      </tr>
      <tr>
        <th><?php _e('Allowed post types', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label><input type="text" name="allowedPostTypes" value="<?php echo esc_attr(implode(',', $opt->allowedPostTypes)) ?>" required></label><br>
          <small><em><?php _e('You can specify multiple post types separated by commnas.', GG_AUTOCAT_DOMAIN) ?></em></small><br>
          <small><em><?php echo __('Post Types', GG_AUTOCAT_DOMAIN) . ' : ' . implode(' , ', array_keys($wp_post_types)) ?></em></small>
        </td>
      </tr>
      <tr>
        <th><?php _e('Category Syntax', GG_AUTOCAT_DOMAIN) ?></th>
        <td>
          <label><input type="text" name="ggSyntax" value="<?php echo esc_attr($opt->ggSyntax) ?>" placeholder="please set regex"></label><br>
          <small><em><?php _e("This plugin finds out this syntax from the post. Usually, you don't have to edit. Please make sure to include a sub pattern in the syntax.", GG_AUTOCAT_DOMAIN) ?></em></small><br>
          <pre><?php echo esc_html(sprintf("Examples:\n  %s%s\n  %s%s", __('Hierarchy', GG_AUTOCAT_DOMAIN), ' : <!-- ggcat[cat1>cat2(>cat3...)] -->', __('Multiple', GG_AUTOCAT_DOMAIN), ' : <!-- ggcat[cat1,cat2(,cat...)] -->')) ?></pre>
        </td>
      </tr>
    </table>
    <?php submit_button() ?>
    <?php wp_nonce_field('gg_autocat', 'gg_autocat') ?>
  </form>
  <hr/>
  <p><?php echo GGCategoryAutoUpdate::NAME ?> Version : <?php echo GGCategoryAutoUpdate::VERSION ?></p>
</div>
<?php
    } // End mehod
}   // End class

  // Booooooooot!
  new GGCategoryAutoUpdate();

} // End class_exists



// Local variables:
// tab-width: 2
// c-basic-offset: 2
// indent-tabs-mode: nil
// End:
