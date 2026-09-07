<?php

use App\Models\Credential;

/**
 * Backward-compatible alias for integrations published before model namespaces were grouped.
 */
class_alias(App\Models\Credentials\Credential::class, Credential::class);
