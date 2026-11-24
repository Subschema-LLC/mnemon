<?php

/**
 * A model, or a list of models, that breaks a rule: a missing or spaced id, a
 * negative price, two rows for one model, or two models marked for digests.
 * The message says which, so the admin panel and the settings file can show
 * it as it is.
 */

namespace Mnemon\Entities\LlmModel;

final class InvalidLlmModel extends \InvalidArgumentException {}
