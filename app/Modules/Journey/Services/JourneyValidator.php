<?php

declare(strict_types=1);

namespace App\Modules\Journey\Services;

use App\Modules\Journey\Enums\StepType;
use App\Modules\Journey\Models\Journey;
use App\Modules\Journey\Models\JourneyStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A LÉPÉS- ÉS CÉL-KONFIGURÁCIÓ ellenőrzése a MENTÉSNÉL (68., 70. kritérium).
 *
 * ## Miért a mentésnél, és nem (csak) futáskor
 *
 * Mert futáskor már késő, és mérve pontosan ez történt. A típusos `uuid` oszlopra egy
 * `'vip'` vagy `''` értékkel lekérdezni nem „nem talál", hanem `SQLSTATE[22P02]`. A kivétel
 * a `DB::transaction`-on BELÜL keletkezett, tehát a claim és a `steps_taken` increment is
 * visszagördült: a futás `active` maradt `steps_taken = 0`-val, vagyis TOVÁBBRA IS esedékes.
 * A percenkénti ütemező örökké újradobta — futásonként napi 1440 hiba, és a `MAX_STEPS_TOTAL`
 * sem ütött be, mert a számláló nem nőtt.
 *
 * A motor most már parkoltatja az ilyen futást (`backOff()`), de az a MÁSODIK védelmi vonal:
 * egy hibás konfigurációt nem menteni kell, hanem el nem fogadni. A marketinges egy
 * megnevezett hibaüzenetet kap a mentésnél, nem egy csendben megálló journey-t három nappal
 * később.
 *
 * ## A CIKLUS-ELLENŐRZÉS ugyanez a gondolat
 *
 * A `MAX_STEPS_TOTAL` FOG egy önmagára mutató láncot (mérve: `failed` / `step_limit`) — de
 * addig 26 percet fut és 500 sort ír EGY futásra. 100 000 futásnál ez 50 millió sor. A
 * lánc bejárása mentéskor ezt nullára viszi, és a hibát oda teszi, ahol javítható.
 *
 * FIGYELEM: a ciklus nem mindig hiba. Egy `wait` lépést tartalmazó kör LEGITIM ismétlődő
 * automatizálás (heti emlékeztető) — a `journey_run_steps.iteration` és a küldés
 * idempotencia-kulcsa kifejezetten erre van. Amit tiltunk, az a VÁRAKOZÁS NÉLKÜLI kör:
 * az azonnal végrehajtható lépésekből álló ciklus, ami a workert égeti.
 */
final class JourneyValidator
{
    /** A lánc bejárásának felső korlátja — a védelem önvédelme. */
    private const MAX_CHAIN = 1000;

    /**
     * EGY LÉPÉS konfigurációjának ellenőrzése.
     *
     * @throws InvalidArgumentException
     */
    public function validateStep(JourneyStep $step): void
    {
        $config = (array) $step->config;

        match ($step->stepType()) {
            StepType::SendEmail => $this->requireUuid($config, 'template', 'send_email'),
            StepType::AddTag, StepType::RemoveTag => $this->requireUuid($config, 'tag', (string) $step->type),
            StepType::Wait => $this->validateWait($config),
            StepType::Condition => $this->validateCondition($config),
            StepType::Exit => null,
        };
    }

    /**
     * A JOURNEY cél-konfigurációja.
     *
     * A leggyakoribb éles hiba nem elgépelés volt, hanem a séma ÜRES `goal_config`-ja:
     * `goal_type = 'has_tag'` mellett a cél `''`-t adott a `uuid` oszlopra, és ezt a
     * `shouldExit()` MINDEN lépés előtt, az adott journey MINDEN futására megkérdezte.
     *
     * @throws InvalidArgumentException
     */
    public function validateGoal(Journey $journey): void
    {
        $type = (string) $journey->goal_type;

        if ($type === '' || $type === 'none') {
            return;
        }

        $config = (array) $journey->goal_config;

        match ($type) {
            'in_segment' => $this->requireUuid($config, 'segment', 'cél: in_segment'),
            'has_tag' => $this->requireUuid($config, 'tag', 'cél: has_tag'),
            'clicked_email' => null,
            default => throw new InvalidArgumentException(sprintf(
                'Ismeretlen cél-típus: `%s`. Engedélyezett: in_segment, has_tag, clicked_email, none.',
                $type
            )),
        };
    }

    /**
     * A LÁNC ellenőrzése: elérhető-e a vég, és van-e VÁRAKOZÁS NÉLKÜLI kör.
     *
     * @throws InvalidArgumentException
     */
    public function validateChain(Journey $journey): void
    {
        // MINDEN lépésből indulva keresünk kört, a `first_step_id`-tól FÜGGETLENÜL: a lánc
        // nem feltétlenül a fejtől körkörös (egy `else` ág is visszamutathat), és a
        // szerkesztés közben a fej még nincs is beállítva — épp akkor kellene szólni.
        $steps = $journey->steps()->get()->keyBy('id');
        foreach ($steps as $start) {
            $this->walk($start, $steps);
        }
    }

    /**
     * @param  Collection<int, JourneyStep>  $steps
     */
    private function walk(JourneyStep $start, $steps): void
    {
        // ITERATÍV, nem rekurzív: egy ezer lépéses lánc rekurzívan a stacket vinné el.
        $stack = [[$start, [], false]];

        while ($stack !== []) {
            [$step, $seen, $waited] = array_pop($stack);

            $id = (int) $step->getKey();

            if (isset($seen[$id])) {
                // KÖR. Csak akkor hiba, ha nem volt közben `wait`: a várakozás nélküli kör
                // azonnal végrehajtható lépésekből áll, tehát a workert égeti.
                if (! $waited) {
                    throw new InvalidArgumentException(sprintf(
                        'A journey lánca VÁRAKOZÁS NÉLKÜLI kört tartalmaz (a %s lépésnél). '
                        .'Egy ilyen kör azonnal végrehajtható lépésekből áll, tehát a '
                        .'lépés-korlátig égeti a workert. Tegyél a körbe `wait` lépést, vagy '
                        .'szüntesd meg a visszamutató ágat.',
                        (string) $step->uuid
                    ));
                }

                continue;
            }

            if (count($seen) >= self::MAX_CHAIN) {
                throw new InvalidArgumentException('A journey lánca hosszabb, mint '.self::MAX_CHAIN.' lépés.');
            }

            $seen[$id] = true;
            $waited = $waited || $step->stepType() === StepType::Wait;

            foreach ([$step->next_step_id, $step->else_step_id] as $nextId) {
                if ($nextId !== null && isset($steps[$nextId])) {
                    $stack[] = [$steps[$nextId], $seen, $waited];
                }
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function requireUuid(array $config, string $field, string $context): void
    {
        $value = (string) ($config[$field] ?? '');

        if (! Str::isUuid(trim($value))) {
            throw new InvalidArgumentException(sprintf(
                'A(z) `%s` lépéshez kötelező a `%s` mező, és UUID-nak kell lennie (kapott: „%s"). '
                .'Az oszlop típusos: egy nem-UUID érték futáskor adatbázis-hibát ad, nem üres találatot.',
                $context,
                $field,
                mb_substr($value, 0, 40)
            ));
        }
    }

    /** @param array<string, mixed> $config */
    private function validateWait(array $config): void
    {
        $minutes = $config['minutes'] ?? 60;

        if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > JourneyEngine::MAX_WAIT_MINUTES) {
            throw new InvalidArgumentException(sprintf(
                'A `wait` lépés `minutes` mezője 1 és %d (egy év) között lehet. Egy ennél nagyobb '
                .'érték a dátum-számítást buktatja el, egy `PHP_INT_MAX` pedig túlcsordulás után '
                .'AZONNALI lépéssé változtatja a „végtelen" várakozást.',
                JourneyEngine::MAX_WAIT_MINUTES
            ));
        }
    }

    /** @param array<string, mixed> $config */
    private function validateCondition(array $config): void
    {
        $type = (string) ($config['type'] ?? '');

        match ($type) {
            'in_segment' => $this->requireUuid($config, 'segment', 'condition: in_segment'),
            'has_tag' => $this->requireUuid($config, 'tag', 'condition: has_tag'),
            'opened_email', 'clicked_email' => null,
            'context_equals' => (string) ($config['key'] ?? '') === ''
                ? throw new InvalidArgumentException('A `context_equals` feltételhez kötelező a `key` mező.')
                : null,
            default => throw new InvalidArgumentException(sprintf(
                'Ismeretlen feltétel-típus: `%s`. Engedélyezett: in_segment, has_tag, opened_email, '
                .'clicked_email, context_equals.',
                $type
            )),
        };
    }
}
