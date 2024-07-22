<?php
if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly.
}

class WC_Gateway_DFinSell_Admin_Notices
{
  private $notices = [];

  public function add_notice($key, $type, $message)
  {
    $this->notices[] = array('key' => $key, 'type' => $type, 'message' => $message);
  }

  public function remove_notice($key)
  {
    unset($this->notices[$key]);
  }

  public function display_notices()
  {
    foreach ($this->notices as $notice) {
      echo '<div class="' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['message']) . '</p></div>';
    }
  }
}
