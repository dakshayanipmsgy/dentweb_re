<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'PDF generation has been removed. Please open the HTML challan and use Ctrl+P from the browser.';
