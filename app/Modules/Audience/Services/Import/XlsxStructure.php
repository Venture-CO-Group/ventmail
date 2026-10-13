<?php

declare(strict_types=1);

namespace App\Modules\Audience\Services\Import;

use OpenSpout\Common\Helper\Escaper\XLSX as XlsxEscaper;
use XMLReader;

/**
 * Az XLSX SZERKEZETI ellenőrzése — ugyanazzal a parserrel és ugyanazzal a feloldással, amit az
 * openspout használ (v1.1 · modul 5, 7. review-kör, D179–D182).
 *
 * ## Miért nem bájtminta
 *
 * A bájt-/regex-alapú DTD- és kódolás-szűrést a reviewk újra és újra megkerülték: UTF-16, EBCDIC,
 * 512 bájton túl nyúló UTF-7 deklaráció, előtagos vagy beágyazott `Relationship`. A libxml maga
 * dekódol (bármely kódolásból), tehát amit az `XMLReader` lát, azt látja az openspout is. A
 * bájt-szintű ellenőrzések (NUL, BOM, első bájt `<`, UTF-8 deklaráció) mélységi védelemként maradnak.
 *
 * ## Mit tilt
 *
 * DTD (`DOC_TYPE`), entitás-hivatkozás és -csomópont (`ENTITY_REF`, `ENTITY`), jelölés (`NOTATION`),
 * és bármely olvasási hiba (a nem deklarált `&foo;` is az). Opciók: csak `LIBXML_NONET` — NINCS
 * `LIBXML_NOENT` (entitás-behelyettesítés) és `LIBXML_DTDLOAD`; pontosan, ahogy az openspout nyitja.
 *
 * ## Hivatkozás-feloldás (openspout-paritás)
 *
 * A `workbook.xml.rels` `Relationship` csomópontja BÁRMELY mélységben, BÁRMELY előtaggal számít
 * (az openspout a `localName`-et nézi); a `sheet` csomópontok a `sheets` végéig, a `r:id` attribútum
 * szó szerint. Az útvonal-szabályok az openspout `WorkbookRelationshipsManager` / `SheetManager`
 * szabályai (lásd `partPathFor()`, `sheetPathFor()`, `sheetTarget()`).
 */
final class XlsxStructure
{
    public const SHARED_STRINGS_TYPES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings',
        'http://purl.oclc.org/ooxml/officeDocument/relationships/sharedStrings',
    ];

    public const STYLES_TYPES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles',
        'http://purl.oclc.org/ooxml/officeDocument/relationships/styles',
    ];

    /** A legnagyobb megengedett elem-mélység (a valós XLSX-részek egy számjegyűek). */
    public const MAX_DEPTH = 128;

    /** A tiltott csomópont-típusok: DTD, entitás-hivatkozás, entitás, jelölés. */
    private const FORBIDDEN = [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF, XMLReader::ENTITY, XMLReader::NOTATION];

    /**
     * Az ismert BINÁRIS helyek (kis-nagybetűtől függetlenül): kép, nyomtatóbeállítás, beágyazott
     * objektum, Power Pivot modell, miniatűr. Ezek mentesek az XML-ellenőrzés alól — XML-ként a
     * munkafüzet NEM hivatkozhat rájuk (lásd `ImportFileInspector::assertStructure()`).
     */
    public static function isBinaryPart(string $name): bool
    {
        return (bool) preg_match(
            '#^(xl/media/|xl/printersettings/|xl/embeddings/|xl/model/item\.data$|docprops/thumbnail\.)#',
            strtolower(ltrim($name, '/')),
        );
    }

    /**
     * A sharedStrings / styles célja → bejegyzés-név, az openspout szabályával: ha a cél tartalmazza
     * az `xl/`-t, változatlan, különben `xl/` elé; a megnyitás a vezető `/`-t levágja.
     */
    public static function partPathFor(string $target): string
    {
        return ltrim(str_contains($target, 'xl/') ? $target : 'xl/'.$target, '/');
    }

    /** A munkalap célja → bejegyzés-név: ha nem `/xl/`-lel kezdődik, `/xl/` elé; a vezető `/` levágva. */
    public static function sheetPathFor(string $target): string
    {
        return ltrim(str_starts_with($target, '/xl/') ? $target : '/xl/'.$target, '/');
    }

    /**
     * A munkalap fájlja az `r:id`-hoz — az openspout CIKLUS-szemantikájával: a nem `/xl/`-es cél
     * előtagot kap és a keresés megáll; a `/xl/`-es cél felvételre kerül, de a keresés folytatódik
     * (egy későbbi azonos `Id` felülírja).
     *
     * @param  list<array{id: string, type: string, target: string, external: bool}>  $relationships
     */
    public static function sheetTarget(array $relationships, string $rid): ?string
    {
        $path = null;

        foreach ($relationships as $relationship) {
            if ($relationship['id'] !== $rid) {
                continue;
            }

            $path = self::sheetPathFor($relationship['target']);

            if (! str_starts_with($relationship['target'], '/xl/')) {
                break;
            }
        }

        return $path;
    }

    /**
     * A típusos (sharedStrings / styles) cél — az openspout szerint típusonként az UTOLSÓ nyer, és az
     * átmeneti (`…/2006/…`) típus elsőbbséget élvez a szigorú (`purl.oclc.org`) előtt.
     *
     * @param  list<array{id: string, type: string, target: string, external: bool}>  $relationships
     * @param  list<string>  $types
     */
    public static function typedTarget(array $relationships, array $types): ?string
    {
        $byType = [];

        foreach ($relationships as $relationship) {
            $byType[$relationship['type']] = $relationship['target'];
        }

        foreach ($types as $type) {
            if (isset($byType[$type])) {
                return $byType[$type];
            }
        }

        return null;
    }

    /**
     * A `Relationship` csomópontok BÁRMELY mélységben és előtaggal (localName).
     *
     * @return list<array{id: string, type: string, target: string, external: bool}>
     *
     * @throws ImportLimitExceeded
     */
    public static function relationshipsFrom(string $xml): array
    {
        $found = [];

        self::walk($xml, function (XMLReader $reader) use (&$found): bool {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $found[] = [
                    'id' => (string) $reader->getAttribute('Id'),
                    'type' => (string) $reader->getAttribute('Type'),
                    'target' => (string) $reader->getAttribute('Target'),
                    'external' => strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0,
                ];
            }

            return true;
        });

        return $found;
    }

    /**
     * A munkalapok a `workbook.xml`-ből: `sheet` csomópontok (localName) a `sheets` VÉGÉIG, a név az
     * openspout XLSX-escaperével visszafejtve, a `r:id` szó szerint.
     *
     * @return list<array{name: string, rid: string}>
     *
     * @throws ImportLimitExceeded
     */
    public static function sheetsFrom(string $xml): array
    {
        $sheets = [];
        $escaper = new XlsxEscaper;

        self::walk($xml, function (XMLReader $reader) use (&$sheets, $escaper): bool {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $sheets[] = [
                    'name' => $escaper->unescape((string) $reader->getAttribute('name')),
                    'rid' => (string) $reader->getAttribute('r:id'),
                ];
            }

            // Az openspout a `sheets` végén megáll.
            return ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'sheets');
        });

        return $sheets;
    }

    /**
     * Egy rész SZERKEZETI ellenőrzése a parserrel (`$uri`: pl. `zip:///…/f.xlsx#xl/styles.xml`).
     *
     * `$full = false` (munkalap): a prológus és a gyökérelem — DTD csak a gyökér ELŐTT állhat (a
     * libxml utána jól-formáltsági hibát ad), entitást pedig DTD nélkül nem lehet deklarálni; a
     * munkalap jól-formáltságát az openspout olvasása maga kényszeríti ki. `$full = true`: a teljes rész.
     *
     * @param  (callable(): void)|null  $tick  időkeret-ellenőrzés (néhány ezer csomópontonként)
     *
     * @throws ImportLimitExceeded
     */
    public static function assertWellFormedPart(string $uri, bool $full, ?callable $tick = null, int $maxNodes = PHP_INT_MAX): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if (@$reader->open($uri, null, LIBXML_NONET) !== true) {
                throw new ImportLimitExceeded('format');
            }

            self::scan($reader, $full, $tick, $maxNodes);
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  callable(XMLReader): bool  $visit  `false` → megállás
     *
     * @throws ImportLimitExceeded
     */
    private static function walk(string $xml, callable $visit): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = XMLReader::XML($xml, null, LIBXML_NONET);

        try {
            if (! $reader instanceof XMLReader) {
                throw new ImportLimitExceeded('format');
            }

            while (@$reader->read()) {
                self::assertAllowed($reader);

                if (! $visit($reader)) {
                    break;
                }
            }

            if (libxml_get_errors() !== []) {
                throw new ImportLimitExceeded('format');
            }
        } finally {
            if ($reader instanceof XMLReader) {
                $reader->close();
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @throws ImportLimitExceeded */
    private static function scan(XMLReader $reader, bool $full, ?callable $tick, int $maxNodes): void
    {
        $nodes = 0;

        while (@$reader->read()) {
            self::assertAllowed($reader);

            if (++$nodes > $maxNodes) {
                throw new ImportLimitExceeded('zip');
            }

            if ($tick !== null && $nodes % 4096 === 0) {
                $tick();
            }

            // A munkalapnál a gyökérelemig: a prológus (és benne az esetleges DTD) ekkorra lefutott.
            if (! $full && $reader->nodeType === XMLReader::ELEMENT) {
                break;
            }
        }

        if (libxml_get_errors() !== []) {
            throw new ImportLimitExceeded('format');
        }
    }

    /** @throws ImportLimitExceeded */
    private static function assertAllowed(XMLReader $reader): void
    {
        if (in_array($reader->nodeType, self::FORBIDDEN, true) || $reader->depth > self::MAX_DEPTH) {
            throw new ImportLimitExceeded('format');
        }
    }
}
