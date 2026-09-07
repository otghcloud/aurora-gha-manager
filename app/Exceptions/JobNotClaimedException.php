<?php

namespace App\Exceptions;

/**
 * Indicates that GitHub did not assign the triggering job to its intended runner.
 *
 * The runner remains available so another job can claim it.
 */
class JobNotClaimedException extends ProvisioningException {}
