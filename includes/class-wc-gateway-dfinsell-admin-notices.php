<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

class WC_Gateway_DFinSell_Admin_Notices
{
  private $notices = [];

  public function add_notice($slug, $class, $message)
  {
    $this->notices[$slug] = [
      'class' => $class,
      'message' => $message,
    ];
  }

  public function display_notices()
  {
    foreach ((array)$this->notices as $notice_key => $notice) {
      printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr(sanitize_html_class($notice['class'])), wp_kses($notice['message'], ['a' => ['href' => []]]));
    }
  }
}
