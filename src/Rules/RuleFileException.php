<?php
/**
 * This file is part of cjw-network/request-shield.
 *
 * @copyright Copyright (C) 2026 JAC Systeme GmbH, CJW Network
 * @license MIT, see LICENSE
 */

declare(strict_types=1);

namespace CjwNetwork\RequestShield\Rules;

/** A rule file that cannot be used; the message starts with file and line ("site.rules:7: ..."). */
final class RuleFileException extends \InvalidArgumentException
{
}
