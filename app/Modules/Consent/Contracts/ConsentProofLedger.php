<?php

declare(strict_types=1);

namespace App\Modules\Consent\Contracts;

use App\Modules\Consent\Data\ConsentProof;
use App\Modules\Consent\Data\ConsentReceipt;

/**
 * A hozzájárulás-bizonyíték főkönyve (ventCookie, hash-láncolt, append-only). A consent
 * bizonyítékának gazdája a ventCookie (golden rule: consent csak a ventCookie-n át); a ventMail
 * helyi consentje a küldési kapu hivatalos forrása marad, a főkönyv ezt NEM írja át.
 *
 * Külső gazda interfész mögött; a fake teszttel fedett kontraktus.
 */
interface ConsentProofLedger
{
    /**
     * @return ConsentReceipt|null `null`: végleges lezárás nyugta nélkül (pl. 409 azonos kulcs +
     *                             eltérő törzs, vagy végleges 4xx) — újrapróbálni nem érdemes
     *
     * @throws \RuntimeException újrapróbálható hiba (hálózat, 5xx, 429, 401/403 konfig-hiba)
     */
    public function record(ConsentProof $proof): ?ConsentReceipt;
}
