<?php

/**
 * Ancestral Origins - a webtrees custom module.
 * Copyright (C) 2026 Larry Bislew
 * Licensed under the GNU General Public License v3.0 or later.
 */

declare(strict_types=1);

namespace AncestralOrigins;

/**
 * Pure calculation, with no webtrees dependencies, so it can be unit tested.
 *
 * Every ancestor passes on a share of the root person: each parent 1/2, each
 * grandparent 1/4, and so on (shares add up when an ancestor appears on more
 * than one line). A line's share is credited to the origin of the earliest
 * known person on it. An origin comes, in order, from:
 *   1. a NATI (nationality / tribe) fact on the person,
 *   2. a place override set by the site administrator (e.g. "Mucsi = German"),
 *   3. the country in the person's birth / christening / baptism place.
 * Places in "settler" countries (USA, Canada, ...) do not count as an origin:
 * the line keeps climbing, and if it stops there the share is "Not traced"
 * unless a descendant on that line already had an origin.
 */
final class OriginCalculator
{
    public const UNKNOWN = '__unknown__';

    /** @var callable(string): ?array{0:?string,1:?string} returns [father, mother] ids, or null when the person can't be seen */
    private $parents;

    /** @var callable(string): ?string returns the origin label of a person, or null */
    private $origin;

    private int $max_depth;

    /** @var array<string,array<string,float>> */
    private array $memo = [];

    /** @var array<string,bool> */
    private array $in_progress = [];

    /** Number of distinct ancestors visited during the last run. */
    public int $ancestors_seen = 0;

    /** @var array<string,bool> */
    private array $seen = [];

    /**
     * @param callable $parents   fn(string $id): ?array{0:?string,1:?string}
     * @param callable $origin    fn(string $id): ?string
     */
    public function __construct(callable $parents, callable $origin, int $max_depth = 40)
    {
        $this->parents   = $parents;
        $this->origin    = $origin;
        $this->max_depth = $max_depth;
    }

    /**
     * Origins of a person's ancestry, as shares that add up to 1.
     * The person's own birthplace does not count (we're asking where their
     * ancestors came from), except as a fallback for a line with no known
     * ancestors at all.
     *
     * @return array<string,float> origin label => share; self::UNKNOWN = not traced
     */
    public function calculate(string $root): array
    {
        $this->memo = [];
        $this->in_progress = [];
        $this->seen = [];

        $parents = ($this->parents)($root) ?? [null, null];
        $result  = [];

        foreach ([$parents[0] ?? null, $parents[1] ?? null] as $parent) {
            $part = $parent === null
                ? [self::UNKNOWN => 1.0]
                : $this->distribution($parent, null, 1);
            foreach ($part as $label => $share) {
                $result[$label] = ($result[$label] ?? 0.0) + $share / 2;
            }
        }

        $this->ancestors_seen = count($this->seen);
        arsort($result);

        return $result;
    }

    /**
     * @return array<string,float>
     */
    private function distribution(string $id, ?string $fallback, int $depth): array
    {
        $own   = ($this->origin)($id);
        $label = $own ?? $fallback;
        $key   = $id . '|' . ($label ?? '');

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $this->seen[$id] = true;
        $parents = ($this->parents)($id);

        if ($parents === null || $depth >= $this->max_depth || isset($this->in_progress[$id])) {
            return $this->memo[$key] = [$label ?? self::UNKNOWN => 1.0];
        }

        [$father, $mother] = [$parents[0] ?? null, $parents[1] ?? null];

        if ($father === null && $mother === null) {
            return $this->memo[$key] = [$label ?? self::UNKNOWN => 1.0];
        }

        $this->in_progress[$id] = true;
        $result = [];
        foreach ([$father, $mother] as $parent) {
            $part = $parent === null
                ? [$label ?? self::UNKNOWN => 1.0]
                : $this->distribution($parent, $label, $depth + 1);
            foreach ($part as $origin => $share) {
                $result[$origin] = ($result[$origin] ?? 0.0) + $share / 2;
            }
        }
        unset($this->in_progress[$id]);

        return $this->memo[$key] = $result;
    }

    // ------------------------------------------------------------------
    // Place classification
    // ------------------------------------------------------------------

    /** Country names and historic or local spellings, mapped to one label. */
    public const COUNTRIES = [
        'Germany' => ['germany', 'deutschland', 'prussia', 'preussen', 'preußen', 'bavaria', 'bayern', 'baden', 'württemberg', 'wurttemberg', 'wuerttemberg', 'hessen', 'hesse', 'saxony', 'sachsen', 'mecklenburg', 'pommern', 'pomerania', 'westfalen', 'westphalia', 'hannover', 'hanover', 'rheinland', 'rhineland', 'pfalz', 'palatinate', 'oldenburg', 'holstein', 'brandenburg', 'nordrhein-westfalen', 'niedersachsen', 'schleswig'],
        'Netherlands' => ['netherlands', 'nederland', 'holland', 'noord-brabant', 'gelderland', 'limburg (nl)', 'zeeland', 'friesland', 'groningen', 'drenthe', 'overijssel', 'utrecht'],
        'Belgium' => ['belgium', 'belgie', 'belgië', 'belgique', 'flanders', 'vlaanderen', 'brabant (be)'],
        'Luxembourg' => ['luxembourg', 'luxemburg'],
        'France' => ['france', 'alsace', 'elsass', 'lorraine', 'lothringen', 'normandy', 'normandie', 'brittany', 'bretagne'],
        'Switzerland' => ['switzerland', 'schweiz', 'suisse', 'svizzera', 'helvetia'],
        'Austria' => ['austria', 'österreich', 'oesterreich', 'tirol', 'tyrol'],
        'Denmark' => ['denmark', 'danmark'],
        'Norway' => ['norway', 'norge'],
        'Sweden' => ['sweden', 'sverige'],
        'Finland' => ['finland', 'suomi'],
        'Iceland' => ['iceland', 'ísland'],
        'England' => ['england', 'cumberland', 'westmorland', 'yorkshire', 'lancashire', 'northumberland', 'durham', 'cheshire', 'kent', 'sussex', 'essex', 'middlesex', 'surrey', 'devon', 'cornwall', 'somerset', 'dorset', 'norfolk', 'suffolk', 'lincolnshire', 'worcestershire', 'warwickshire', 'gloucestershire', 'oxfordshire', 'wiltshire', 'hampshire', 'shropshire', 'staffordshire', 'derbyshire', 'nottinghamshire', 'leicestershire', 'northamptonshire', 'cambridgeshire', 'hertfordshire', 'bedfordshire', 'buckinghamshire', 'berkshire', 'herefordshire', 'london'],
        'Scotland' => ['scotland', 'alba'],
        'Wales' => ['wales', 'cymru'],
        'Ireland' => ['ireland', 'éire', 'eire', 'mayo', 'galway', 'cork', 'kerry', 'armagh', 'antrim', 'tipperary', 'sligo', 'roscommon', 'donegal', 'tyrone', 'fermanagh', 'longford', 'wicklow', 'wexford', 'kilkenny', 'limerick', 'clare', 'leitrim', 'cavan', 'monaghan', 'louth', 'meath', 'westmeath', 'offaly', 'laois', 'kildare', 'carlow', 'waterford', 'derry', 'londonderry', 'dublin'],
        'Isle of Man' => ['isle of man', 'mannin'],
        'Poland' => ['poland', 'polska', 'posen', 'silesia', 'schlesien', 'galicia', 'galizien'],
        'Czechia' => ['czech', 'bohemia', 'böhmen', 'boehmen', 'moravia', 'mähren'],
        'Slovakia' => ['slovakia', 'slovensko'],
        'Hungary' => ['hungary', 'magyarország', 'ungarn'],
        'Romania' => ['romania', 'românia', 'transylvania', 'siebenbürgen', 'banat'],
        'Serbia' => ['serbia', 'srbija', 'vojvodina'],
        'Croatia' => ['croatia', 'hrvatska', 'slavonia'],
        'Slovenia' => ['slovenia', 'slovenija'],
        'Italy' => ['italy', 'italia', 'sicily', 'sicilia'],
        'Spain' => ['spain', 'españa', 'espana'],
        'Portugal' => ['portugal'],
        'Greece' => ['greece', 'ελλάδα', 'hellas'],
        'Russia' => ['russia', 'россия'],
        'Ukraine' => ['ukraine', 'україна'],
        'Lithuania' => ['lithuania', 'lietuva'],
        'Latvia' => ['latvia', 'latvija'],
        'Estonia' => ['estonia', 'eesti'],
        'Mexico' => ['mexico', 'méxico'],
        'China' => ['china', '中国'],
        'Japan' => ['japan', '日本'],
        'India' => ['india'],
        'Philippines' => ['philippines', 'pilipinas'],
    ];

    /** Countries whose places do not count as an ethnic origin by default. */
    public const SETTLER_COUNTRIES = ['usa', 'u.s.a.', 'united states', 'united states of america', 'us', 'america', 'canada', 'australia', 'new zealand', 'south africa', 'argentina', 'brazil', 'brasil', 'chile', 'uruguay'];

    public const US_STATES = ['alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia', 'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland', 'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey', 'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina', 'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming', 'district of columbia', 'quebec', 'québec', 'ontario', 'nova scotia', 'new brunswick', 'manitoba', 'saskatchewan', 'alberta', 'british columbia'];

    /**
     * Parse the administrator's override list. One rule per line:
     *   Mucsi = German (Danube Swabian)
     *   Brothertown = Native American (Brothertown)
     * The left side is matched, ignoring case, anywhere in a place name.
     * Lines starting with # are comments.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function parseOverrides(string $text): array
    {
        $rules = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$pattern, $label] = array_map('trim', explode('=', $line, 2));
            if ($pattern !== '' && $label !== '') {
                $rules[] = [mb_strtolower($pattern), $label];
            }
        }

        return $rules;
    }

    /**
     * Classify a place name. Returns an origin label, or null when the place
     * is empty, unrecognised, or in a settler country.
     *
     * @param array<int,array{0:string,1:string}> $overrides
     */
    public static function classifyPlace(string $place, array $overrides, bool $settler_countries_count = false): ?string
    {
        $place = trim($place);
        if ($place === '') {
            return null;
        }
        $lower = mb_strtolower($place);

        foreach ($overrides as [$pattern, $label]) {
            if (str_contains($lower, $pattern)) {
                return $label;
            }
        }

        // Split into the GEDCOM's comma-separated parts, most general last.
        $parts = array_values(array_filter(array_map(
            static fn (string $p): string => trim(mb_strtolower($p)),
            explode(',', $lower)
        ), static fn (string $p): bool => $p !== ''));

        // Work from the country end of the place name towards the town.
        foreach (array_reverse($parts) as $part) {
            if (in_array($part, self::SETTLER_COUNTRIES, true) || in_array($part, self::US_STATES, true)) {
                return $settler_countries_count ? self::settlerLabel($part) : null;
            }
            foreach (self::COUNTRIES as $label => $names) {
                foreach ($names as $name) {
                    if ($part === $name || preg_match('/\b' . preg_quote($name, '/') . '\b/u', $part) === 1) {
                        return $label;
                    }
                }
            }
        }

        return null;
    }

    private static function settlerLabel(string $part): string
    {
        if (in_array($part, ['canada', 'quebec', 'québec', 'ontario', 'nova scotia', 'new brunswick', 'manitoba', 'saskatchewan', 'alberta', 'british columbia'], true)) {
            return 'Canada';
        }
        if (in_array($part, self::US_STATES, true) || in_array($part, ['usa', 'u.s.a.', 'united states', 'united states of america', 'us', 'america'], true)) {
            return 'United States';
        }

        return ucwords($part);
    }
}
