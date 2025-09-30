<?php
http_response_code(410); // Gone
header('Content-Type: application/json');
echo json_encode(['success'=>false,'message'=>'Inventory endpoint removed']);
