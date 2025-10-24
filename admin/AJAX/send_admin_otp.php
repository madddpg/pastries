<?php
// Placeholder: send_admin_otp endpoint (not implemented yet)
// This stub intentionally returns a 501 status so callers know it's not available.

session_start();
header('Content-Type: application/json');
http_response_code(501); // Not Implemented
echo json_encode([
    'success' => false,
    'message' => 'send_admin_otp endpoint is not implemented yet.',
    'error'   => 'NotImplemented',
]);
exit;
