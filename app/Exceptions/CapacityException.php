<?php

namespace App\Exceptions;

/**
 * Indicates that a global or per-pool limit has been reached; capacity is expected to free up.
 */
class CapacityException extends ProvisioningException {}
