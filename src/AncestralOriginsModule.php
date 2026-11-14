<?php

/**
 * Ancestral Origins - a webtrees custom module.
 * Copyright (C) 2026 Larry Bislew
 * Licensed under the GNU General Public License v3.0 or later.
 */

declare(strict_types=1);

namespace AncestralOrigins;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Adds an "Origins" tab to each individual's page with a pie chart of where
 * their ancestors came from, worked out from the tree (not from DNA).
 */
class AncestralOriginsModule extends AbstractModule implements ModuleCustomInterface, ModuleTabInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleTabTrait;
    use ModuleConfigTrait;

    public const VERSION = '0.9.0';

    /** Categorical palette, fixed order (validated for colour-blind separation). */
    private const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
    private const OTHER_COLOR = '#9a9590';
    private const UNKNOWN_COLOR = '#d8d1c1';

    // ------------------------------------------------------------------
    // Module metadata
    // ------------------------------------------------------------------

    public function title(): string
    {
        return I18N::translate('Ancestral origins');
    }

    public function description(): string
    {
        return I18N::translate('A pie chart of where a person’s ancestors came from, worked out from the birthplaces in the family tree.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Larry Bislew';
    }

    public function customModuleVersion(): string
    {
        return self::VERSION;
    }

    public function customModuleLatestVersionUrl(): string
    {
        return 'https://raw.githubusercontent.com/bislewl/webtrees-ancestral-origins/main/latest-version.txt';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/bislewl/webtrees-ancestral-origins/issues';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    // ------------------------------------------------------------------
    // Tab
    // ------------------------------------------------------------------

    public function tabTitle(): string
    {
        return I18N::translate('Origins');
    }

    public function defaultTabOrder(): int
    {
        return 50;
    }

    public function canLoadAjax(): bool
    {
        // Ancestry can be large; load the tab only when it is opened.
        return true;
    }

    public function hasTabContent(Individual $individual): bool
    {
        return true;
    }

    public function isGrayedOut(Individual $individual): bool
    {
        return $individual->childFamilies()->isEmpty();
    }

    public function supportedFacts(): Collection
    {
        return new Collection();
    }

    public function getTabContent(Individual $individual): string
    {
        $overrides = OriginCalculator::parseOverrides($this->getPreference('overrides', self::defaultOverrides()));
        $settler   = $this->getPreference('settler_countries_count', '0') === '1';
        $max_depth = max(5, min(60, (int) $this->getPreference('max_depth', '40')));
        $tree      = $individual->tree();

        $cache_people = [];
        $find = static function (string $xref) use (&$cache_people, $tree): ?Individual {
            if (!array_key_exists($xref, $cache_people)) {
                $cache_people[$xref] = Registry::individualFactory()->make($xref, $tree);
            }

            return $cache_people[$xref];
        };

        $parents = static function (string $xref) use ($find): ?array {
            $person = $find($xref);
            if ($person === null || !$person->canShow()) {
                return null;
            }
            $family = $person->childFamilies()->first();
            if ($family === null) {
                return [null, null];
            }
            $father = $family->husband();
            $mother = $family->wife();

            return [
                $father !== null && $father->canShow() ? $father->xref() : null,
                $mother !== null && $mother->canShow() ? $mother->xref() : null,
            ];
        };

        $origin = static function (string $xref) use ($find, $overrides, $settler): ?string {
            $person = $find($xref);
            if ($person === null || !$person->canShow()) {
                return null;
            }
            // 1. An explicit NATI (nationality / tribe) fact wins.
            foreach ($person->facts(['NATI']) as $fact) {
                $value = trim($fact->value());
                if ($value !== '') {
                    return $value;
                }
            }
            // 2/3. Birthplace (BIRT, then CHR or BAPM).
            return OriginCalculator::classifyPlace($person->getBirthPlace()->gedcomName(), $overrides, $settler);
        };

        $calculator = new OriginCalculator($parents, $origin, $max_depth);
        $shares     = $calculator->calculate($individual->xref());

        return view($this->name() . '::tab', [
            'individual' => $individual,
            'slices'     => $this->slices($shares),
            'traced'     => 1.0 - ($shares[OriginCalculator::UNKNOWN] ?? 0.0),
            'ancestors'  => $calculator->ancestors_seen,
            'module'     => $this,
        ]);
    }

    /**
     * Turn shares into chart slices: up to 8 coloured origins, then "Other",
     * then "Not traced" last.
     *
     * @param array<string,float> $shares
     *
     * @return array<int,array{label:string,share:float,color:string,unknown:bool}>
     */
    private function slices(array $shares): array
    {
        $unknown = $shares[OriginCalculator::UNKNOWN] ?? 0.0;
        unset($shares[OriginCalculator::UNKNOWN]);
        arsort($shares);

        $slices = [];
        $other  = 0.0;
        $i      = 0;
        foreach ($shares as $label => $share) {
            if ($share < 0.0005) {
                continue;
            }
            if ($i < count(self::COLORS) - (count($shares) > count(self::COLORS) ? 1 : 0)) {
                $slices[] = ['label' => (string) $label, 'share' => $share, 'color' => self::COLORS[$i], 'unknown' => false];
                $i++;
            } else {
                $other += $share;
            }
        }
        if ($other > 0.0) {
            $slices[] = ['label' => I18N::translate('Other'), 'share' => $other, 'color' => self::OTHER_COLOR, 'unknown' => false];
        }
        if ($unknown > 0.0005) {
            $slices[] = ['label' => I18N::translate('Not traced'), 'share' => $unknown, 'color' => self::UNKNOWN_COLOR, 'unknown' => true];
        }

        return $slices;
    }

    // ------------------------------------------------------------------
    // Settings page (Control panel → Modules → Ancestral origins)
    // ------------------------------------------------------------------

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::settings', [
            'title'     => $this->title(),
            'overrides' => $this->getPreference('overrides', self::defaultOverrides()),
            'settler'   => $this->getPreference('settler_countries_count', '0'),
            'max_depth' => $this->getPreference('max_depth', '40'),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            throw new HttpAccessDeniedException();
        }
        $body = Validator::parsedBody($request);
        $this->setPreference('overrides', $body->string('overrides', ''));
        $this->setPreference('settler_countries_count', $body->boolean('settler', false) ? '1' : '0');
        $this->setPreference('max_depth', (string) max(5, min(60, $body->integer('max_depth', 40))));

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    public static function defaultOverrides(): string
    {
        return "# One rule per line:  text in the place name = origin to show\n"
            . "# Rules are checked before the built-in country list, top to bottom.\n"
            . "# Examples (remove the # to use them):\n"
            . "# Tolna = German (Danube Swabian)\n"
            . "# Brothertown = Native American (Brothertown)\n";
    }
}
