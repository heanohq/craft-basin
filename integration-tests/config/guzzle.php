<?php

use GuzzleHttp\HandlerStack;
use heano\basin\tests\craft\FakeBasin;

// Every Guzzle client Craft creates (Craft::createGuzzleClient()) sends its
// requests to the test's FakeBasin instead of the network.
return [
    'handler' => HandlerStack::create(FakeBasin::handler()),
];
