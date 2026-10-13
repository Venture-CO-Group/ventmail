<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** A lekérdezés túllépte a keretét (`QueryBudget`) — a felület 422-t mutat, nem 500-at. */
final class QueryTooExpensive extends RuntimeException {}
