<?php

namespace App\Services\Documents;

use InvalidArgumentException;

/**
 * An upload refused before anything was stored: a type Mnemon does not read,
 * content that does not match its name, an empty file, or one over the size
 * limit. The message is written for the person who uploaded it.
 */
class DocumentRejected extends InvalidArgumentException {}
