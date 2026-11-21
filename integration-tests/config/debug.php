<?php

// craft-pest asks for Yii's debug toolbar on every request (X-Debug: enable).
// The toolbar's inline scripts and styles would show up in tests of the pages
// themselves, such as their Content Security Policy, so it's never shown.
return [
    'allowedIPs' => [],
];
