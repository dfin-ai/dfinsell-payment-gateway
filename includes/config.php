<?php
// config.php
if (!defined('DFINSELL_PROTOCOL')) {
    define('DFINSELL_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('DFINSELL_HOST')) {
    define('DFINSELL_HOST', 'sell.dfin.ai');
}

if (!defined('DFINSELL_BASE_URL')) {
	define('DFINSELL_BASE_URL', DFINSELL_PROTOCOL . DFINSELL_HOST);
}
