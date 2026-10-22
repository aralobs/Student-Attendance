<?php
/**
 * Public account registration is disabled.
 * Administrators create accounts through users/add.php after signing in.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
exit('Public registration is disabled. Contact an administrator to create an account.');
