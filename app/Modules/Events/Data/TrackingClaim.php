<?php

declare(strict_types=1);

namespace App\Modules\Events\Data;

/** Egy nyomkövetési token TARTALMA — a linkből visszafejtve. */
final readonly class TrackingClaim
{
    public function __construct(
        public int $workspaceId,
        public string $messageUuid,
        public string $recipient,
        /**
         * Az a HOSZT, amelyre ezt a tokent kiadtuk.
         *
         * A kérés csak ezen a hoszton fogadható el. Enélkül a `/t/c/…` átirányító az
         * ALKALMAZÁS saját domainjén is működne — azon az originen, ami a bejelentkezett
         * session cookie-ját szolgálja ki —, és bármelyik tenant kiválthatna magának egy
         * tetszőleges célra mutató linket: küld magának egy levelet, és kiolvassa a kimenő
         * törzsből. Ez működő nyílt átirányító lenne a fő domainen.
         */
        public string $host,
        public ?string $destination = null,
    ) {}
}
