<?php

declare(strict_types=1);

namespace App\Modules\Sending\Exceptions;

use RuntimeException;

/**
 * A workspace kiküldésre váró csatolmány-bájtjai elérnék a felső korlátot (H123, S4).
 *
 * A `SendMessage` tranzakciójából repül ki: a sor ilyenkor nem `accepted`, hanem átmeneti
 * `ATTACHMENT_PENDING_LIMIT` elutasítás, felszabaduló kulccsal.
 */
final class AttachmentPendingLimitExceeded extends RuntimeException {}
