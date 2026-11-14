# Ancestral Origins for webtrees

A custom module for [webtrees](https://webtrees.net) 2.2 that adds an **Origins** tab to every individual's page. The tab shows a pie chart of where that person's ancestors came from.

The chart is worked out from the family tree, not from DNA:

- Each parent passes on half, each grandparent a quarter, and so on. An ancestor who appears on more than one line counts once for each line.
- Each line counts toward the place where its **earliest known person** was born, christened or baptised.
- That person's origin comes from, in order:
  1. a **NATI** (nationality, tribe) fact on the person, if they have one;
  2. a **place rule** set by the site administrator, for example `Tolna = German (Danube Swabian)`;
  3. the **country** in their birthplace. About 40 countries are recognised, along with historic and regional names such as Prussia, Bavaria, Noord-Brabant, Alsace and the English and Irish counties.
- By default, places in the United States, Canada, Australia and other settler countries don't count as an origin. The line keeps climbing, and if it stops there, that share is shown as **Not traced**. You can change this setting.
- The chart respects webtrees privacy. Ancestors the visitor isn't allowed to see are treated as not traced.

> **Status: 0.9.0 (beta).** The origin calculation is tested against a real 10,000-person tree. This is the first public release: please report problems on the [issues page](https://github.com/bislewl/webtrees-ancestral-origins/issues).

## Installing

1. Download `ancestral-origins.zip` from the [latest release](https://github.com/bislewl/webtrees-ancestral-origins/releases/latest) and unzip it.
2. Upload the `ancestral-origins` folder into your webtrees `modules_v4` folder, so you have `modules_v4/ancestral-origins/module.php`.
3. In webtrees, go to **Control panel → Modules → Tabs** (or **All modules**) and check that **Ancestral origins** is enabled.
4. Optional: open **Control panel → Modules → Ancestral origins** to add place rules.

Requires webtrees 2.2 and PHP 8.2 or later.

## Place rules

One rule per line, in the form `text in the place name = origin to show`. Rules are checked top to bottom, before the built-in country list. Lines starting with `#` are ignored.

```
# Germans who settled in Hungary in the 1700s
Tolna = German (Danube Swabian)
Baranya = German (Danube Swabian)
# Brothertown Indian Nation, Wisconsin
Brothertown = Native American (Brothertown)
```

For a single person you can also add a **NATI** fact, for example "Narragansett", to their record. It wins over any place.

## Limits

- The result is only as good as the birthplaces in the tree. A line whose earliest person has no recorded birthplace is shown as not traced.
- Countries are modern labels for old places. For example, Alsace counts as France and Silesia as Poland. Use place rules to change any of them.
- The chart shows up to 8 origins. Anything smaller is folded into "Other".

## Licence

GNU General Public License v3.0 or later, the same licence as webtrees. See https://www.gnu.org/licenses/gpl-3.0.html

Copyright © 2026 Larry Bislew
