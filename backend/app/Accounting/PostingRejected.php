<?php

namespace App\Accounting;

use RuntimeException;

/** A posting or ledger rule was not met. The message is safe to show to staff. */
class PostingRejected extends RuntimeException {}
