<?php
/**
 * Plugin Name: Content List
 * Description: Creates post/page content list and inserts it before content
 * Author: FurryCat
 * Author URI: http://portfolio.furrycat.ru/
 * Version: 1.0
 * Text Domain: contentlistl10n
 * Domain Path: /lang
 */

require_once 'utils/translit.php';

add_action('plugins_loaded', function() {
	load_plugin_textdomain('contentlistl10n', false, dirname( plugin_basename(__FILE__) ) . '/lang');
});

add_action('wp_enqueue_scripts', function() {
  if (is_singular()) {
    wp_enqueue_style(
      'contentlist-plugin',
      plugins_url('/dist/main.css', __FILE__)
    );
  }
});

add_action('add_meta_boxes', function() {
  add_meta_box('contentlist', __('Content list block', 'contentlistl10n'), 'content_list_meta_box_view', array('post', 'page'), 'side', 'low');
}, 1);

add_action('save_post', 'content_list_meta_update', 0);
add_filter('the_content', 'content_list_view');


function content_list_meta_update($postId) {
  if (!isset($_POST['contentlist'])) return false;

  $metaBox = $_POST['contentlist'];
  if (
    empty($metaBox)
    || ! wp_verify_nonce($_POST['contentlist_nonce'], __FILE__)
    || wp_is_post_autosave($postId )
    || wp_is_post_revision($postId )
  )
  return false;

  $metaBox = array_map(
    'sanitize_text_field',
    $metaBox
  );

  foreach($metaBox as $key=>$value) {
    $metaKey = 'contentlist_'.$key;

    if(empty($value)){
      delete_post_meta( $postId, $metaKey );
      continue;
    }

    update_post_meta( $postId, $metaKey, $value );
  }

  return $postId;
}

function content_list_meta_box_view($post) {
  $postId = $post->ID;
  $needContentlist = get_post_meta($postId, 'contentlist_need', 1);
  $oneLevelContentlist = get_post_meta($postId, 'contentlist_one_level', 1);

  ?>
    <div class="form-group" style="margin: 20px 0">
      <input type="hidden" name="contentlist[need]" value="" />
      <input type="checkbox" name="contentlist[need]" value="1"
        id="contentlist-need"
        <?php checked($needContentlist, 1)?> />
      <label for="contentlist-need"><?= __('Create content list', 'contentlistl10n') ?></>
    </div>

    <div class="form-group" style="margin: 20px 0">
      <input type="hidden" name="contentlist[one_level]" value="" />
      <input type="checkbox" name="contentlist[one_level]" value="1"
        id="contentlist-onelevel"
        <?php checked($oneLevelContentlist, 1)?> />
      <label for="contentlist-onelevel"><?= __('Only first headers level', 'contentlistl10n') ?></label>
    </div>

    <input type="hidden" name="contentlist_nonce" value="<?php echo wp_create_nonce(__FILE__); ?>" />
  <?php
}

function handleHeader($dom, $header, $isRoot) {
  $text = $header->textContent;
  $line = $header->getLineNo();

  $headerData =  [
    'text' => $text,
    'id' => translit($text).'-'.$line,
    'root' => $isRoot,
    'line' => $line
  ];

  $header->setAttribute('data-anchored', '');
  $children = $header->childNodes;
  $anchor = $dom->createElement('a');
  $anchor->setAttribute('class', 'anchor');
  $anchor->setAttribute('href', '#'.$headerData['id']);
  $anchor->setAttribute('data-text', $headerData['text']);
  $anchor->setAttribute('name', $headerData['id']);
  $header->insertBefore($anchor, $children[0]);

  return $headerData;
}

function content_list_view($content) {
  if (!is_singular()) return $content;

  global $post;
  $postId = $post->ID;
  $contentlistVisible = get_post_meta($postId, 'contentlist_need', true );
  $isOneLevel = get_post_meta($postId, 'contentlist_one_level', 1);

  $dom = new DOMDocument();
  $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'utf-8');
  libxml_use_internal_errors(true);
  $dom->loadHtml($content);
  libxml_use_internal_errors(false);

  $headersLvl1 = $dom->getElementsByTagName('h2');
  $headersLvl2 = $dom->getElementsByTagName('h3');

  $headersList = [];

  if (!empty($headersLvl1)) {
    foreach($headersLvl1 as $header) {
      $headersList[] = handleHeader($dom, $header, true);
    }
  }

  if (!empty($headersLvl2)) {
    foreach($headersLvl2 as $header) {
      $headersList[] = handleHeader($dom, $header, false);
    }
  }

  if (!$contentlistVisible) return $content;

  usort($headersList, function($a, $b) {
    return $a['line'] - $b['line'];
  });

  $list = [];
  global $sublists;
  $sublists = [];

  $currentRoot = null;

  foreach($headersList as $header) {
    if ($header['root']) {
      $list[] = $header;
      $currentRoot = $header['id'];
      $sublists[$currentRoot] = [];
    } else {
      if ($isOneLevel || !$currentRoot) continue;
      $sublists[$currentRoot][] = $header;
    }
  }

  function createListItem($header) {
    global $sublists;
    $link = "<a href='#{$header['id']}'>{$header['text']}</a>";
    $items = $sublists[$header['id']];
    if (empty($items)) return "<li class='contentlist__item contentlist__root'>$link</li>";

    $items = array_map(function($item) {
      return "<li class='contentlist__item'><a href='#{$item['id']}'>{$item['text']}</a></li>";
    }, $items);
    $sublist = "<ul class='contentlist__sublist'>".implode('', $items)."</ul>";

    return "<li class='contentlist__item contentlist__root'>{$link}{$sublist}</li>";
  }

  $list = array_map('createListItem', $list);

  $listHTML = "<section class='contentlist' id='_contentlist'>
    <h2 class='contentlist__title' onclick='this.parentElement.classList.toggle(\"contentlist--closed\")'>".__('Content', 'contentlistl10n')."</h2>
    <ul class='contentlist__list'>".implode('', $list)."</ul></section>";

  return $listHTML.$dom->saveHTML();
}
