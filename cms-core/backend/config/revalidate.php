<?php

return [
    /*
    | Shared secret with the Next.js app for the revalidate webhook.
    | Same value must be set as REVALIDATE_SECRET in the frontend env.
    */
    'secret' => env('REVALIDATE_SECRET', 'change-me-in-production'),

    // Max age (seconds) of a signed revalidate payload.
    'leeway' => (int) env('REVALIDATE_LEEWAY', 300),

    /*
    | Absolute URL of the Next.js revalidate route (per install).
    | Empty = dispatch disabled (publish still works, ISR expiry applies).
    | From inside containers the host frontend is host.docker.internal.
    */
    'url' => env('REVALIDATE_URL'),
];
