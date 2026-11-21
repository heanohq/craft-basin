<?php

// The plugin never reaches Basin in these tests: every request goes to
// FakeBasin (see tests/Pest.php), so the key is a placeholder.
return [
    'apiKey' => 'fake-basin-key',
    'projectId' => '1002',
];
