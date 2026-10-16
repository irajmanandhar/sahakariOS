<?php

/*
| Proxies whose X-Forwarded-* headers Laravel may trust (comma-separated IPs or
| CIDRs). Needed so login throttling sees the real client IP behind the Next.js
| BFF and nginx. Leave empty when Laravel is reached directly; never trust "*"
| if the API port is reachable by clients, or they could spoof their IP.
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
