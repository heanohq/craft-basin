<?php

namespace heano\basin\api\exceptions;

/**
 * Base exception for Basin API errors. Messages are safe to show to admins and
 * never contain the API key.
 */
class ApiException extends \RuntimeException
{
}
