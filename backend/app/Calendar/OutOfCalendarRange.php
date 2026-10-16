<?php

namespace App\Calendar;

use RuntimeException;

/** The date is outside the verified Bikram Sambat data. Never guess: add official data instead. */
class OutOfCalendarRange extends RuntimeException {}
