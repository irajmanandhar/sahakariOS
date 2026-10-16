<?php

namespace App\Members;

use RuntimeException;

/** A membership rule was not met. The message is safe to show to staff. */
class MembershipRuleViolation extends RuntimeException {}
