<?php

namespace App\Calendar;

use RuntimeException;

/** A fiscal-year or business-day rule was not met. The message is safe to show to staff. */
class CalendarRuleViolation extends RuntimeException {}
