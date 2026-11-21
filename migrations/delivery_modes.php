<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config/database.php';
require_once dirname(__DIR__).'/includes/delivery_modes.php';
ensureDeliverySchema(getDB(true));
echo "Delivery schema installed. Existing attendance and modes preserved.\n";
