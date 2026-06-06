<?php
declare(strict_types=1);

/** Send privacy headers before rendering or downloading a customer document. */
function protect_customer_document_response(): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
    header('Pragma: no-cache', true);
    header('Expires: 0', true);
}
