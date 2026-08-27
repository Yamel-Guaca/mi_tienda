<?php
// includes/helpers.php
function json_response($data, $status = 200) {
    header('Content-Type: application/json', true, $status);
    echo json_encode($data);
    exit;
}

function format_currency($value) {
    return number_format((float)$value, 2, '.', '');
}
