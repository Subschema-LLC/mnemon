<?php

namespace App\Services\Documents;

use RuntimeException;

/**
 * A stored document whose text could not be read: a corrupt or encrypted
 * file, malformed XML. The original is kept, so it can be extracted again once
 * the cause is fixed. The message is written for the person who uploaded it.
 */
class ExtractionFailed extends RuntimeException {}
