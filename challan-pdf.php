<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo 'PDF generation has been retired. Please use the HTML document view and Ctrl+P if needed.';
