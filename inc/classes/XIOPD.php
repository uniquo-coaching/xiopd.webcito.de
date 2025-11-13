<?php

use JetBrains\PhpStorm\ArrayShape;


/**
 * Description of XIOPD
 *
 * @example <code>
 * $validator = new XIOPD();
 * $validated = $validator->validate('sample.xml');
 * if ($validated) {
 *   echo "Feed successfully validated";
 * } else {
 * print_r($validator->displayErrors());
 * }</code>
 *
 * @author Thomas Kirsch <t.kirsch@webcito.de>
 */
class XIOPD
{

    /**
     * @var int
     */
    public int $feedErrors = 0;
    /**
     * Formatted libxml Error details
     *
     * @var array
     */
    public array $errorDetails;
    public ?string $content = null;
    /** @noinspection PhpUnused */
    public bool $xmlAsString = false;

    /**
     * @var string
     */
    protected string $feedSchema = "";

    /**
     * Validation Class constructor Instantiating DOMDocument
     *
     * @param int|float $version
     * @param string $xml
     * @param bool $xmlString
     */
    public function __construct(int | float $version, string $xml, bool $xmlString = false)
    {
        $this->feedSchema = $_SERVER['DOCUMENT_ROOT'] . '/inc/' . match ($version) {
                    1 => 'Exchange_Interface_Open_ProjectData_102.xsd',
                    1.1 => 'xi-opd_V1_10.xsd'
                };

        if (!$xmlString):
            $fp = fopen($xml, 'rb');
            $contents = fread($fp, filesize($xml));
            fclose($fp);
        else:
            $contents = $xml;
        endif;

        $this->content = $contents;
    }


    /**
     * Renders a Bootstrap 5 HTML table from $this->content (xi:opd XML)
     * with tree visualization for nested Sets/Jumbos, collapsible long descriptions,
     * detailed components (PRODUCT incl. ARTNO/GTIN, LABOUR, EXTERNAL_SERVICE),
     * and (only when applicable) a one-click **All products** view for groups that
     * consist of **pure material leaf positions on the same level**.
     *
     * Output is in **English**.
     *
     * Requirements:
     *  - Bootstrap 5 CSS+JS on the page (for Collapse).
     *  - Totals follow xi:opd pricing rules (TOTALPRICE precedence, set-quantities
     *    multiplication, head cost components, per-position VAT).
     *
     * @return string HTML string (table + tfoot)
     * @throws RuntimeException on XML errors
     */
    public function renderXiOpdTable(
            bool $pushUp = true,
            string $pushUpMode = 'display',
            string $vatMode = 'position_based'
    ): string {
        $xml = $this->xParseXml($this->content);

        $roots = [];
        foreach ($xml->xpath('/PROJECTDATA/POSITION') as $top) {
            $roots[] = $this->xBuildNode($top, $pushUp, $pushUpMode);
        }
        $this->xSortByPos($roots);

        $sumNet = 0.0;
        $sumGross = 0.0;
        $this->xCollectSumsList($roots, $sumNet, $sumGross);
        $sumVat = $sumGross - $sumNet;
        if ($vatMode === 'group_based') {
            $sumVat = round($sumNet * 0.19, 2);
            $sumGross = $sumNet + $sumVat;
        }

        ob_start(); ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover align-middle">
                <caption class="text-muted">Cost table (xi:opd) — nested view, long descriptions, components</caption>
                <thead class="table-light">
                <tr>
                    <th scope="col">Position No.</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="text-end">Quantity</th>
                    <th scope="col">Unit</th>
                    <th scope="col" class="text-end">Net €</th>
                    <th scope="col" class="text-end">VAT %</th>
                    <th scope="col" class="text-end">Gross €</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($roots as $root): ?><?= $this->xRenderNode($root) ?><?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="4" class="text-end">Totals (price-carrying rows)</td>
                    <td class="text-end"><?= $this->xMoney($sumNet) ?></td>
                    <td class="text-end"><?= $this->xMoney($sumVat) ?></td>
                    <td class="text-end"><?= $this->xMoney($sumGross) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    protected function xRenderNode(array $node, int $level = 0): string
    {
        $cidChildren = 'child-' . $node['id'];
        $cidLong = 'ld-' . $node['id'];
        $cidComps = 'cmp-' . $node['id'];
        $cidGroup = 'grp-' . $node['id'];

        $hasChildren = !empty($node['children']);
        $hasLong = !empty($node['longHtml']);
        $hasComps = !empty($node['components']);
        $hasGroupPr = !empty($node['groupProducts'] ?? []);

        $toggleChildren = $hasChildren
                ? '<button class="btn btn-sm btn-outline-secondary me-2" data-bs-toggle="collapse" data-bs-target="#' . $this->xEsc(
                        $cidChildren
                ) . '" aria-expanded="false" aria-controls="' . $this->xEsc($cidChildren) . '">▸</button>'
                : '<span class="me-2" style="display:inline-block;width:28px;"></span>';

        $links = [];
        if ($hasLong) {
            $links[] = '<a href="#" class="link-secondary text-decoration-none" data-bs-toggle="collapse" data-bs-target="#' . $this->xEsc(
                            $cidLong
                    ) . '" aria-expanded="false" aria-controls="' . $this->xEsc($cidLong) . '">Description</a>';
        }
        if ($hasComps) {
            $links[] = '<a href="#" class="link-secondary text-decoration-none" data-bs-toggle="collapse" data-bs-target="#' . $this->xEsc(
                            $cidComps
                    ) . '" aria-expanded="false" aria-controls="' . $this->xEsc($cidComps) . '">Components</a>';
        }
        if ($hasGroupPr) {
            $links[] = '<a href="#" class="link-secondary text-decoration-none" data-bs-toggle="collapse" data-bs-target="#' . $this->xEsc(
                            $cidGroup
                    ) . '" aria-expanded="false" aria-controls="' . $this->xEsc($cidGroup) . '">All products</a>';
        }

        $indent = 'padding-left: ' . max(0, ($level * 18)) . 'px;';

        $netDisp = $node['uiNet'] ?? ($node['priceCarrier'] ? ($node['net'] ?? null) : null);
        $groDisp = $node['uiGross'] ?? ($node['priceCarrier'] ? ($node['gross'] ?? null) : null);
        $netCell = ($netDisp !== null) ? $this->xMoney((float)$netDisp) : '—';
        $grossCell = ($groDisp !== null) ? $this->xMoney((float)$groDisp) : '—';

        ob_start(); ?>
        <tr>
            <td style="<?= $indent ?>">
                <?= $toggleChildren ?>
                <span class="text-nowrap"><?= $this->xEsc((string)$node['pos']) ?></span>
                <?php if (($node['informativeChildren'] ?? false) || !$node['priceCarrier']): ?>
                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-2">informational</span>
                <?php endif; ?>
            </td>
            <td>
                <?= $this->xEsc((string)$node['text']) ?>
                <?php if (!empty($links)): ?>
                    <span class="ms-2"><?= implode(' | ', $links) ?></span>
                <?php endif; ?>
            </td>
            <td class="text-end"><?= $this->xQty((float)$node['qty']) ?></td>
            <td><?= $this->xEsc((string)$node['unit']) ?></td>
            <td class="text-end"><?= $netCell ?></td>
            <td class="text-end"><?= rtrim(rtrim(number_format((float)$node['vat'], 2, ',', '.'), '0'), ',') ?></td>
            <td class="text-end"><?= $grossCell ?></td>
        </tr>

        <?php if ($hasLong): ?>
        <tr class="bg-transparent">
            <td colspan="7" class="p-0">
                <div id="<?= $this->xEsc($cidLong) ?>" class="collapse">
                    <div class="ps-5 pe-3 pb-3 pt-1">
                        <div class="small text-muted mb-1">Description</div>
                        <div class="border rounded p-3 bg-body-tertiary"><?= $node['longHtml'] ?></div>
                    </div>
                </div>
            </td>
        </tr>
    <?php endif; ?>

        <?php if ($hasComps): ?>
        <tr class="bg-transparent">
            <td colspan="7" class="p-0">
                <div id="<?= $this->xEsc($cidComps) ?>" class="collapse">
                    <?= $this->xRenderComponents($node['components']) ?>
                </div>
            </td>
        </tr>
    <?php endif; ?>

        <?php if ($hasGroupPr): ?>
        <tr class="bg-transparent">
            <td colspan="7" class="p-0">
                <div id="<?= $this->xEsc($cidGroup) ?>" class="collapse">
                    <?= $this->xRenderGroupProducts($node['groupProducts']) ?>
                </div>
            </td>
        </tr>
    <?php endif; ?>

        <?php if ($hasChildren): ?>
        <tr class="bg-transparent">
            <td colspan="7" class="p-0">
                <div id="<?= $this->xEsc($cidChildren) ?>" class="collapse">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <tbody>
                        <?php foreach ($node['children'] as $child): ?>
                            <?= $this->xRenderNode($child, $level + 1) ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
    <?php endif;

        return (string)ob_get_clean();
    }


    protected function xRenderGroupProducts(array $groupProducts): string
    {
        if (empty($groupProducts)) {
            return '';
        }
        ob_start(); ?>
        <div class="ps-3 pe-3 pb-2 pt-0">
            <div class="small text-muted mb-1">All products on this level</div>
            <table class="table table-borderless table-sm mb-0">
                <thead>
                <tr class="text-muted">
                    <th style="width:12%">Pos.-No.</th>
                    <th style="width:38%">Details</th>
                    <th class="text-end" style="width:10%">Qty</th>
                    <th style="width:10%">Unit</th>
                    <th class="text-end" style="width:15%">Unit price</th>
                    <th class="text-end" style="width:15%">Total&nbsp;€</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($groupProducts as $gp):
                    $pos = $gp['pos'];
                    $c = $gp['cmp'];
                    $details = trim(($c['artno'] ?? '') . ' ' . ($c['text'] ?? ''));
                    $gtin = isset($c['gtin']) && $c['gtin'] !== '' ? ' | GTIN: ' . $this->xEsc((string)$c['gtin']) : '';
                    $ep = isset($c['price']) ? $this->xMoney(
                                    (float)$c['price']
                            ) . ((isset($c['pbase']) && (float)$c['pbase'] !== 1.0) ? ' / ' . $this->xEsc(
                                            (string)$c['pbase']
                                    ) : '') : '–'; ?>
                    <tr>
                        <td><span class="badge text-bg-secondary"><?= $this->xEsc((string)$pos) ?></span></td>
                        <td><?= $this->xEsc($details !== '' ? $details : '–') ?><?= $gtin ?></td>
                        <td class="text-end"><?= $this->xQty((float)($c['qty'] ?? 0.0)) ?></td>
                        <td><?= $this->xEsc((string)($c['unit'] ?? '')) ?></td>
                        <td class="text-end"><?= $ep ?></td>
                        <td class="text-end"><?= $this->xMoney((float)$c['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php return (string)ob_get_clean();
    }

    protected function xRenderComponents(array $comps, bool $compact = false): string
    {
        if (empty($comps)) {
            return '';
        }
        ob_start(); ?>
        <div class="ps-3 pe-3 pb-2 pt-0">
            <div class="small text-muted mb-1"><?= $compact ? 'Products' : 'Components' ?></div>
            <table class="table table-borderless table-sm mb-0">
                <thead>
                <tr class="text-muted">
                    <th style="width:10%">Type</th>
                    <th style="width:40%">Details</th>
                    <th class="text-end" style="width:10%">Qty/Time</th>
                    <th style="width:10%">Unit</th>
                    <th class="text-end" style="width:15%">Unit price</th>
                    <th class="text-end" style="width:15%">Total&nbsp;€</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($comps as $c):
                    $type = (string)($c['type'] ?? '');
                    if ($type === 'PRODUCT'):
                        $details = trim(($c['artno'] ?? '') . ' ' . ($c['text'] ?? ''));
                        $gtin = isset($c['gtin']) && $c['gtin'] !== '' ? ' | GTIN: ' . $this->xEsc(
                                        (string)$c['gtin']
                                ) : '';
                        $bp = isset($c['baseprice']) ? ' (Base: ' . $this->xMoney(
                                        (float)$c['baseprice']
                                ) . ')' : '';
                        $ep = isset($c['price']) ? $this->xMoney(
                                        (float)$c['price']
                                ) . ((isset($c['pbase']) && (float)$c['pbase'] !== 1.0) ? ' / ' . $this->xEsc(
                                                (string)$c['pbase']
                                        ) : '') : '–'; ?>
                        <tr>
                            <td><span class="badge rounded-pill text-bg-primary">PRODUCT</span></td>
                            <td><?= $this->xEsc($details !== '' ? $details : '–') ?><?= $gtin ?><?= $bp ?></td>
                            <td class="text-end"><?= $this->xQty((float)($c['qty'] ?? 0.0)) ?></td>
                            <td><?= $this->xEsc((string)($c['unit'] ?? '')) ?></td>
                            <td class="text-end"><?= $ep ?></td>
                            <td class="text-end"><?= $this->xMoney((float)$c['total']) ?></td>
                        </tr>
                    <?php elseif ($type === 'LABOUR'):
                        $kind = (string)($c['kind'] ?? 'Labour');
                        $ep = (isset($c['price'])) ? $this->xMoney(
                                        (float)$c['price']
                                ) . (isset($c['pbase']) && (float)$c['pbase'] ? ' / ' . (int)$c['pbase'] . ' ' . ($this->xEsc(
                                                (string)($c['unit'] ?? 'MIN')
                                        )) : '') : '–'; ?>
                        <tr>
                            <td><span class="badge rounded-pill text-bg-warning text-dark">LABOUR</span></td>
                            <td><?= $this->xEsc($kind) ?></td>
                            <td class="text-end"><?= $this->xQty((float)($c['time'] ?? 0.0)) ?></td>
                            <td><?= $this->xEsc((string)($c['unit'] ?? 'MIN')) ?></td>
                            <td class="text-end"><?= $ep ?></td>
                            <td class="text-end"><?= $this->xMoney((float)$c['total']) ?></td>
                        </tr>
                    <?php else:
                        $kind = (string)($c['kind'] ?? 'External service');
                        $ep = (isset($c['price'])) ? $this->xMoney(
                                        (float)$c['price']
                                ) . ((isset($c['pbase']) && (float)$c['pbase'] !== 1.0) ? ' / ' . $this->xEsc(
                                                (string)$c['pbase']
                                        ) : '') : '–'; ?>
                        <tr>
                            <td><span class="badge rounded-pill text-bg-info">EXTERNAL</span></td>
                            <td><?= $this->xEsc($kind) ?></td>
                            <td class="text-end"><?= $this->xQty((float)($c['qty'] ?? 0.0)) ?></td>
                            <td><?= $this->xEsc((string)($c['unit'] ?? '')) ?></td>
                            <td class="text-end"><?= $ep ?></td>
                            <td class="text-end"><?= $this->xMoney((float)$c['total']) ?></td>
                        </tr>
                    <?php endif; endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php return (string)ob_get_clean();
    }

    protected function xCollectSumsList(array $nodes, float &$sumNet, float &$sumGross): void
    {
        foreach ($nodes as $n) {
            if ($n['priceCarrier']) {
                $sumNet += (float)$n['net'];
                $sumGross += (float)$n['gross'];
            }
            if (!empty($n['children'])) {
                $this->xCollectSumsList($n['children'], $sumNet, $sumGross);
            }
        }
    }

    protected function xSortByPos(array &$nodes): void
    {
        usort($nodes, function ($a, $b) {
            $as = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$a['pos'])));
            $bs = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', (string)$b['pos'])));
            $len = max(count($as), count($bs));
            for ($i = 0; $i < $len; $i++) {
                $ai = $as[$i] ?? 0;
                $bi = $bs[$i] ?? 0;
                if ($ai < $bi) {
                    return -1;
                }
                if ($ai > $bi) {
                    return 1;
                }
            }
            return strcmp($a['text'] ?? '', $b['text'] ?? '');
        });
        foreach ($nodes as &$n) {
            if (!empty($n['children'])) {
                $this->xSortByPos($n['children']);
            }
        }
    }

    protected function xBuildNode(SimpleXMLElement $pos, bool $pushUp, string $pushUpMode): array
    {
        $num = isset($pos->POSITIONNUMBER) ? trim((string)$pos->POSITIONNUMBER) : '';
        $text = $this->xText($pos) ? : $this->xText($pos, '');
        $long = $this->xLongHtml($pos);
        $qty = $this->xQtyOf($pos);
        $unit = $this->xUnitOf($pos);
        $vat = $this->xVatOf($pos);

        $headP = $this->xCompProduct($pos->PRODUCT ?? null);
        $headL = $this->xCompLabour($pos->LABOUR ?? null);
        $headE = $this->xCompExternal($pos->EXTERNAL_SERVICE ?? null);
        $headCompTotal = $headP['total'] + $headL['total'] + $headE['total'];

        $children = $pos->xpath('POSITION');
        $hasChild = !empty($children);
        $id = $this->xId($num !== '' ? $num : spl_object_hash($pos));

        // Pauschalposition
        if (isset($pos->POSITION_TOTALPRICE) && trim((string)$pos->POSITION_TOTALPRICE) !== '') {
            $explicit = (float)$this->xDec($pos->POSITION_TOTALPRICE);
            $childNodes = [];
            foreach ($children as $c) {
                $childNodes[] = $this->xBuildNode($c, $pushUp, $pushUpMode);
            }
            return [
                    'id' => $id,
                    'pos' => $num,
                    'text' => ($text !== '' ? $text : 'Lump-sum position'),
                    'longHtml' => $long,
                    'qty' => $qty,
                    'unit' => $unit,
                    'vat' => $vat,
                    'net' => $explicit,
                    'gross' => $explicit * (1 + $vat / 100),
                    'priceCarrier' => true,
                    'uiNet' => $explicit,
                    'uiGross' => $explicit * (1 + $vat / 100),
                    'components' => [],
                    'children' => $childNodes,
                    'informativeChildren' => true,
                    'pureMaterialLeaf' => false,
                    'groupProducts' => []
            ];
        }

        // Struktur/Set
        if ($hasChild) {
            $childNodes = [];
            $childrenTotal = 0.0;
            foreach ($children as $c) {
                $n = $this->xBuildNode($c, $pushUp, $pushUpMode);
                $childrenTotal += $n['net'];
                $childNodes[] = $n;
            }

            // Technischer Wert: Kinder * Menge
            $netTechnical = $childrenTotal * ($qty > 0 ? $qty : 1.0);

            // Für "All products" Sammelansicht prüfen (vor Einfügen synthetischer Knoten!)
            $groupProducts = [];
            $pureSet = true;
            foreach ($childNodes as $cn) {
                if (!empty($cn['children']) || !$cn['priceCarrier']) {
                    $pureSet = false;
                    break;
                }
                if (empty($cn['components'])) {
                    $pureSet = false;
                    break;
                }
                foreach ($cn['components'] as $cmp) {
                    if (($cmp['type'] ?? '') !== 'PRODUCT') {
                        $pureSet = false;
                        break 2;
                    }
                }
                foreach ($cn['components'] as $cmp) {
                    $groupProducts[] = ['pos' => $cn['pos'], 'cmp' => $cmp];
                }
            }

            // Kopf-Kostenanteile vorhanden
            if ($headCompTotal > 0.0) {
                if ($pushUp && $pushUpMode === 'transfer') {
                    // Kinder aus der Summe herausnehmen, Kopf übernimmt (preisführend)
                    foreach ($childNodes as &$cn) {
                        $cn['uiNet'] = $cn['net'];
                        $cn['uiGross'] = $cn['gross'];
                        $cn['priceCarrier'] = false;
                    }
                    unset($cn);
                    $netTechnical += $headCompTotal;

                    // UI-Anzeige am Kopf = Summe (vorher sichtbarer) Kinder + Kopfanteile
                    $uiChildren = 0.0;
                    foreach ($childNodes as $cn) {
                        $uiChildren += ($cn['uiNet'] ?? $cn['net']);
                    }
                    /** @noinspection DuplicatedCode */
                    $uiNet = $uiChildren + $headCompTotal;

                    return [
                            'id' => $id,
                            'pos' => $num,
                            'text' => ($text !== '' ? $text : 'Structure'),
                            'longHtml' => $long,
                            'qty' => $qty,
                            'unit' => $unit,
                            'vat' => $vat,
                            'net' => $netTechnical,
                            'gross' => $netTechnical * (1 + $vat / 100),
                            'priceCarrier' => true,
                            'uiNet' => $uiNet,
                            'uiGross' => $uiNet * (1 + $vat / 100),
                            'components' => array_merge($headP['rows'], $headL['rows'], $headE['rows']),
                            'children' => $childNodes,
                            'informativeChildren' => false,
                            'pureMaterialLeaf' => false,
                            'groupProducts' => ($pureSet ? $groupProducts : [])
                    ];
                } else {
                    // DISPLAY-Modus: Kinder bleiben preisführend,
                    // ABER wir fügen eine synthetische Kopf-Zeile als eigene preisführende Zeile hinzu,
                    // damit die Gesamtsumme unverändert korrekt bleibt.
                    $synthetic = [
                            'id' => $this->xId($id . '-head'),
                            'pos' => $num,
                            'text' => ($text !== '' ? $text : 'Structure') . ' — head cost components',
                            'longHtml' => null,
                            'qty' => $qty,
                            'unit' => $unit,
                            'vat' => $vat,
                            'net' => $headCompTotal,
                            'gross' => $headCompTotal * (1 + $vat / 100),
                            'priceCarrier' => true,
                            'uiNet' => $headCompTotal,
                            'uiGross' => $headCompTotal * (1 + $vat / 100),
                            'components' => array_merge($headP['rows'], $headL['rows'], $headE['rows']),
                            'children' => [],
                            'informativeChildren' => false,
                            'pureMaterialLeaf' => false,
                            'groupProducts' => []
                    ];
                    $childNodes[] = $synthetic;

                    // Technisch addieren wir Kopfanteile weiterhin am Titel (für Transparenz),
                    // die Totals kommen aber über die preisführenden Kinder + synthetischen Kopf.
                    $netTechnical += $headCompTotal;

                    // UI-Anzeige am Kopf = Summe der (ursprünglichen) Kinder + Kopfanteile
                    $uiChildren = 0.0;
                    foreach ($childNodes as $cn) {
                        // Synthetic NICHT doppelt zählen → nur Original-Kinder berücksichtigen
                        if ($cn['id'] === $synthetic['id']) {
                            continue;
                        }
                        $uiChildren += ($cn['uiNet'] ?? ($cn['priceCarrier'] ? $cn['net'] : 0.0));
                    }
                    /** @noinspection DuplicatedCode */
                    $uiNet = $uiChildren + $headCompTotal;

                    return [
                            'id' => $id,
                            'pos' => $num,
                            'text' => ($text !== '' ? $text : 'Structure'),
                            'longHtml' => $long,
                            'qty' => $qty,
                            'unit' => $unit,
                            'vat' => $vat,
                            'net' => $netTechnical,
                            'gross' => $netTechnical * (1 + $vat / 100),
                            'priceCarrier' => false,
                            'uiNet' => $uiNet,
                            'uiGross' => $uiNet * (1 + $vat / 100),
                            'components' => array_merge($headP['rows'], $headL['rows'], $headE['rows']),
                            'children' => $childNodes,
                            'informativeChildren' => false,
                            'pureMaterialLeaf' => false,
                            'groupProducts' => ($pureSet ? $groupProducts : [])
                    ];
                }
            }

            // Kein Kopf-Kostenanteil
            $uiChildren = 0.0;
            foreach ($childNodes as $cn) {
                $uiChildren += ($cn['uiNet'] ?? ($cn['priceCarrier'] ? $cn['net'] : 0.0));
            }
            return [
                    'id' => $id,
                    'pos' => $num,
                    'text' => ($text !== '' ? $text : 'Structure'),
                    'longHtml' => $long,
                    'qty' => $qty,
                    'unit' => $unit,
                    'vat' => $vat,
                    'net' => $netTechnical,
                    'gross' => $netTechnical * (1 + $vat / 100),
                    'priceCarrier' => false,
                    'uiNet' => $uiChildren,
                    'uiGross' => $uiChildren * (1 + $vat / 100),
                    'components' => [],
                    'children' => $childNodes,
                    'informativeChildren' => false,
                    'pureMaterialLeaf' => false,
                    'groupProducts' => ($pureSet ? $groupProducts : [])
            ];
        }

        // Leaf
        $leafNet = $headCompTotal;
        $comps = array_merge($headP['rows'], $headL['rows'], $headE['rows']);
        if ($leafNet <= 0.0) {
            $price = isset($pos->POSITION_PRICE) ? (float)$this->xDec($pos->POSITION_PRICE) : 0.0;
            $base = isset($pos->POSITION_PRICEBASE) ? max(1.0, (float)$this->xDec($pos->POSITION_PRICEBASE, '1')) : 1.0;
            $leafNet = $price * ($qty > 0 ? $qty : 1.0) / $base;
        }
        $hadProduct = !empty($headP['rows']);
        $hadLabour = !empty($headL['rows']);
        $hadExternal = !empty($headE['rows']);
        $pureMaterialLeaf = $hadProduct && !$hadLabour && !$hadExternal;

        return [
                'id' => $id,
                'pos' => $num,
                'text' => ($text !== '' ? $text : 'Position'),
                'longHtml' => $long,
                'qty' => $qty,
                'unit' => $unit,
                'vat' => $vat,
                'net' => $leafNet,
                'gross' => $leafNet * (1 + $vat / 100),
                'priceCarrier' => true,
                'uiNet' => $leafNet,
                'uiGross' => $leafNet * (1 + $vat / 100),
                'components' => $comps,
                'children' => [],
                'informativeChildren' => false,
                'pureMaterialLeaf' => $pureMaterialLeaf,
                'groupProducts' => []
        ];
    }

    protected function xCompExternal(?SimpleXMLElement $e): array
    {
        if (!$e) {
            return ['total' => 0.0, 'rows' => []];
        }
        if (isset($e->EXTERNAL_SERVICE_TOTALPRICE) && trim((string)$e->EXTERNAL_SERVICE_TOTALPRICE) !== '') {
            $tp = $this->xF($this->xDec($e->EXTERNAL_SERVICE_TOTALPRICE));
        } else {
            $price = isset($e->EXTERNAL_SERVICE_PRICE) ? $this->xF($this->xDec($e->EXTERNAL_SERVICE_PRICE)) : 0.0;
            $qty = isset($e->EXTERNAL_SERVICE_QTY) ? $this->xF($this->xDec($e->EXTERNAL_SERVICE_QTY)) : 0.0;
            $base = isset($e->EXTERNAL_SERVICE_PRICEBASE) ? max(
                    1.0,
                    $this->xF($this->xDec($e->EXTERNAL_SERVICE_PRICEBASE, '1'))
            ) : 1.0;
            $tp = $price * $qty / $base;
        }
        $row = [
                'type' => 'EXTERNAL_SERVICE',
                'kind' => isset($e->EXTERNAL_SERVICE_KIND) ? trim(
                        (string)$e->EXTERNAL_SERVICE_KIND
                ) : 'External service',
                'qty' => isset($e->EXTERNAL_SERVICE_QTY) ? $this->xF($this->xDec($e->EXTERNAL_SERVICE_QTY)) : 0.0,
                'unit' => isset($e->EXTERNAL_SERVICE_UNIT) ? trim((string)$e->EXTERNAL_SERVICE_UNIT) : '',
                'price' => isset($e->EXTERNAL_SERVICE_PRICE) ? $this->xF(
                        $this->xDec($e->EXTERNAL_SERVICE_PRICE)
                ) : null,
                'pbase' => isset($e->EXTERNAL_SERVICE_PRICEBASE) ? $this->xF(
                        $this->xDec($e->EXTERNAL_SERVICE_PRICEBASE, '1')
                ) : 1.0,
                'total' => $tp
        ];
        return ['total' => $tp, 'rows' => [$row]];
    }

    protected function xCompLabour(?SimpleXMLElement $l): array
    {
        if (!$l) {
            return ['total' => 0.0, 'rows' => []];
        }
        if (isset($l->LABOUR_TOTALPRICE) && trim((string)$l->LABOUR_TOTALPRICE) !== '') {
            $tp = $this->xF($this->xDec($l->LABOUR_TOTALPRICE));
        } elseif (isset($l->TOTALPRICE) && trim((string)$l->TOTALPRICE) !== '') {
            $tp = $this->xF($this->xDec($l->TOTALPRICE));
        } else {
            $price = isset($l->LABOUR_PRICE) ? $this->xF($this->xDec($l->LABOUR_PRICE)) : 0.0;
            $base = isset($l->LABOUR_PRICEBASE) ? max(1.0, $this->xF($this->xDec($l->LABOUR_PRICEBASE, '1'))) : 1.0;
            $time = isset($l->LABOUR_TIME) ? $this->xF($this->xDec($l->LABOUR_TIME)) : 0.0;
            $tp = ($price / $base) * $time;
        }
        $row = [
                'type' => 'LABOUR',
                'kind' => isset($l->LABOUR_KIND) ? trim((string)$l->LABOUR_KIND) : 'Labour',
                'time' => isset($l->LABOUR_TIME) ? $this->xF($this->xDec($l->LABOUR_TIME)) : 0.0,
                'unit' => isset($l->LABOUR_UNIT) ? trim(
                        (string)$l->LABOUR_UNIT
                ) : (isset($l->LABOUR_PRICEBASE) ? 'MIN' : ''),
                'price' => isset($l->LABOUR_PRICE) ? $this->xF($this->xDec($l->LABOUR_PRICE)) : null,
                'pbase' => isset($l->LABOUR_PRICEBASE) ? $this->xF($this->xDec($l->LABOUR_PRICEBASE, '1')) : 1.0,
                'vat' => isset($l->LABOUR_VAT) ? $this->xF($this->xDec($l->LABOUR_VAT)) : null,
                'total' => $tp
        ];
        return ['total' => $tp, 'rows' => [$row]];
    }

    protected function xCompProduct(?SimpleXMLElement $p): array
    {
        if (!$p) {
            return ['total' => 0.0, 'rows' => []];
        }
        if (isset($p->TOTALPRICE) && trim((string)$p->TOTALPRICE) !== '') {
            $tp = $this->xF($this->xDec($p->TOTALPRICE));
        } else {
            $price = isset($p->PRICE) ? $this->xF($this->xDec($p->PRICE)) : 0.0;
            $qty = isset($p->QTY) ? $this->xF($this->xDec($p->QTY)) : 0.0;
            $pb = isset($p->PRICEBASE) ? max(1.0, $this->xF($this->xDec($p->PRICEBASE, '1'))) : 1.0;
            $tp = $price * $qty / $pb;
        }
        $row = [
                'type' => 'PRODUCT',
                'artno' => isset($p->ARTNO) ? trim((string)$p->ARTNO) : '',
                'gtin' => isset($p->GTIN) ? trim((string)$p->GTIN) : '',
                'text' => isset($p->SHORTDESCRIPTION) ? trim((string)$p->SHORTDESCRIPTION) : '',
                'qty' => isset($p->QTY) ? $this->xF($this->xDec($p->QTY)) : 0.0,
                'unit' => isset($p->QU) ? trim((string)$p->QU) : '',
                'price' => isset($p->PRICE) ? $this->xF($this->xDec($p->PRICE)) : null,
                'pbase' => isset($p->PRICEBASE) ? $this->xF($this->xDec($p->PRICEBASE, '1')) : 1.0,
                'baseprice' => isset($p->BASEPRICE) ? $this->xF($this->xDec($p->BASEPRICE)) : null,
                'vat' => isset($p->VAT) ? $this->xF($this->xDec($p->VAT)) : null,
                'total' => $tp
        ];
        return ['total' => $tp, 'rows' => [$row]];
    }

    protected function xVatOf(SimpleXMLElement $pos): float
    {
        if (isset($pos->POSITION_VAT) && trim((string)$pos->POSITION_VAT) !== '') {
            return $this->xF($this->xDec($pos->POSITION_VAT));
        }
        if (isset($pos->PRODUCT->VAT) && trim((string)$pos->PRODUCT->VAT) !== '') {
            return $this->xF($this->xDec($pos->PRODUCT->VAT));
        }
        if (isset($pos->LABOUR->LABOUR_VAT) && trim((string)$pos->LABOUR->LABOUR_VAT) !== '') {
            return $this->xF($this->xDec($pos->LABOUR->LABOUR_VAT));
        }
        return 0.0;
    }

    protected function xUnitOf(SimpleXMLElement $pos): string
    {
        return isset($pos->POSITION_UNIT) ? trim((string)$pos->POSITION_UNIT) : '';
    }

    protected function xQtyOf(SimpleXMLElement $pos): float
    {
        return (isset($pos->POSITION_QTY) && trim((string)$pos->POSITION_QTY) !== '')
                ? $this->xF($this->xDec($pos->POSITION_QTY, '1')) : 1.0;
    }

    protected function xLongHtml(SimpleXMLElement $pos): ?string
    {
        foreach ($pos->xpath("TEXT[@type='longdescription']") as $t) {
            $f = strtolower((string)($t['format'] ?? ''));
            $raw = (string)$t;
            if ($raw === '') {
                continue;
            }
            return $f === 'html' ? $raw : nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        return null;
    }

    protected function xText(SimpleXMLElement $pos, string $type = 'shortdescription'): string
    {
        $xp = $type ? "TEXT[@type='$type']" : 'TEXT';
        $n = $pos->xpath($xp);
        if (!empty($n)) {
            return trim((string)$n[0]);
        }
        $any = $pos->xpath('TEXT');
        return !empty($any) ? trim((string)$any[0]) : '';
    }

    protected function xId(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '-', $s);
    }

    protected function xQty(float $q): string
    {
        $s = number_format($q, 3, ',', '.');
        return rtrim(rtrim($s, '0'), ',');
    }

    protected function xMoney(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    protected function xF(string $s): float
    {
        return (float)$s;
    }

    protected function xDec($v, $fallback = '0'): string
    {
        if ($v === null) {
            return (string)$fallback;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return (string)$fallback;
        }
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        if (preg_match('/^\d{1,3}(\.\d{3})*,\d{2}$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        return str_replace(',', '.', $s);
    }

    protected function xEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function xParseXml(string $xmlString): SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        if (!$xml) {
            throw new RuntimeException('XIOPD: XML could not be loaded.');
        }
        return $xml;
    }

    /**
     * Validate Incoming Feeds against Listing Schema
     * @return bool
     *
     * @throws DOMException|RuntimeException
     */
    public function validateSchema(): bool
    {
        if (!class_exists('DOMDocument')) {
            throw new DOMException("'DOMDocument' class not found!");
        }
        if (!file_exists($this->feedSchema)) {
            throw new RuntimeException('Schema is Missing, Please add schema to feedSchema property');
        }

        libxml_use_internal_errors(true);

        $handler = new DOMDocument('1.0', 'utf-8');
        $handler->loadXML($this->content, LIBXML_NOBLANKS);
        if (!$handler->schemaValidate($this->feedSchema)) {
            $this->errorDetails = $this->libxmlDisplayErrors();
//            var_dump($this->errorDetails);
            $this->feedErrors = 1;
        } else {
            //The file is valid
            return true;
        }
        return false;
    }

    /**
     * @return array
     */
    private function libxmlDisplayErrors(): array
    {
        $errors = libxml_get_errors();
        $result = [];
        foreach ($errors as $error) {
            $result[] = $this->libxmlDisplayError($error);
        }
        libxml_clear_errors();
        return $result;
    }

    /**
     * @param libXMLError $error object $error
     *
     * @return array
     */
    #[ArrayShape(['level' => "string", 'code' => "", 'line' => "", 'message' => ""])]
    private function libxmlDisplayError(libXMLError $error): array
    {
        $level = match ($error->level) {
            LIBXML_ERR_NONE => 'no',
            LIBXML_ERR_WARNING => 'warning',
            LIBXML_ERR_ERROR => 'error',
            LIBXML_ERR_FATAL => 'fatal'
        };
        return [
                'level' => $level,
                'code' => $error->code,
                'line' => $error->line,
                'message' => $error->message,
        ];
    }

    /**
     * @throws Exception
     */
    public function validateLogic(bool $showSuccess = true): bool
    {
        $xml = new SimpleXMLIterator($this->content);
        $arr = $this->sxiToArray($xml);

        $errors = [];

        if (!$this->hastElementAndElementNotEmpty($arr, 'POSITION')) {
            $error = new stdClass();
            $error->level = 'logical error';
            $error->element = 'POSITION';
            $error->index = 'ROOT';
            $error->message = "Each document must have at least one POSITION. ";
            $errors[] = $error;
        } else {
            foreach ($arr['POSITION'] as $index => $position) {
                $this->checkPOSITION($position, [$index], $errors);
            }
        }


        if (!empty($errors)) {
            echo $this->displayErrorsAsTable($errors);
            return false;
        } else {
            if ($showSuccess) {
            echo <<<SUCCESS
	    <div class="alert alert-success" role="alert">
		<i class="fas fa-check fa-fw"></i> Logic was successfully validated
	    </div>
SUCCESS;
            }
            return true;
        }
    }

    private function sxiToArray(SimpleXMLIterator $sxi): array
    {
        $a = array();
        for ($sxi->rewind(); $sxi->valid(); $sxi->next()) {
            if (!array_key_exists(strtoupper($sxi->key()), $a)) {
                $a[strtoupper($sxi->key())] = array();
            }
            if ($sxi->hasChildren()) {
                $a[strtoupper($sxi->key())][] = $this->sxiToArray($sxi->current());
            } else {
                $a[strtoupper($sxi->key())][] = (string)$sxi->current();
            }
        }
        return $a;
    }

    private function checkPOSITION(array $position, array $index, array &$errors): void
    {
        $isFlatRate = $this->hastElementAndElementNotEmpty($position, 'POSITION_TOTALPRICE');
        $hasProduct = $this->hastElementAndElementNotEmpty($position, 'PRODUCT');
        $hasLabour = $this->hastElementAndElementNotEmpty($position, 'LABOUR');
        $hasExternalService = $this->hastElementAndElementNotEmpty($position, 'EXTERNAL_SERVICE');
        $hasSubPosition = $this->hastElementAndElementNotEmpty($position, 'POSITION');

        if ($isFlatRate):
            /**
             * Das Strukturelement kann einen POSITION_TOTALPRICE haben, allerdings keine Menge (POSITION_QTY) und
             * keinen Einzelpreis (POSITION_PRICE). Mit POSITION_TOTALPRICE ist es ein Pauschalpreis für den Titel. Alle
             * Preisangaben unten darunter sind rein informativ. Ansonsten errechnet sich der POSITION_TOTALPRICE des
             * Strukturelementes aus der Summe der darunter liegen Strukturen und Positionen.
             */
            if (isset($position['POSITION_QTY']) || isset($position['POSITION_PRICE'])):
                $error = new stdClass();
                $error->level = 'logical error';
                $error->element = 'POSITION';
                $error->index = '[' . implode('][', $index) . ']';
                $error->message = "The structure element can have a POSITION_TOTALPRICE, but no quantity (POSITION_QTY) and no unit price (POSITION_PRICE). With POSITION_TOTALPRICE it is a flat price for the title. All prices below are purely informative. Otherwise the POSITION_TOTALPRICE of the structure element is calculated from the sum of the structures and positions below it.";
                $errors[] = $error;
            endif;
        endif;

        if ($hasProduct || $hasLabour || $hasExternalService):
            /**
             * Auch wenn der POSITION_PRICE oder POSITION_TOTALPRICE leer ist, weil er sich aus den nachfolgenden
             * Positionen errechnet (Vererbung), müssen POSITION_QTY, POSITION_QU und POSITION_VAT gefüllt sein.
             */
            if (!$this->hastElementAndElementNotEmpty(
                            $position,
                            'POSITION_QTY'
                    ) || !$this->hastElementAndElementNotEmpty($position, 'POSITION_VAT')):
                $error = new stdClass();
                $error->level = 'logical error';
                $error->element = 'POSITION';
                $error->index = '[' . implode('][', $index) . ']';
                $error->message = "Even if POSITION_PRICE or POSITION_TOTALPRICE is empty, because it is calculated from the following positions (inheritance), POSITION_QTY, POSITION_QU and POSITION_VAT must be filled. ";
                $errors[] = $error;
            endif;
        endif;

        if ($hasSubPosition):

            foreach ($position['POSITION'] as $i => $pos):
                $ind = $index;
                $ind[] = $i;
                $this->checkPOSITION($pos, $ind, $errors);
            endforeach;
        endif;
    }

    /**
     * Display Error if Resource is not validated as a table
     *
     * @param array|null $errors
     * @return string
     */
    public function displayErrorsAsTable(?array $errors = null): string
    {
        $e = is_null($errors) ? $this->errorDetails : $errors;
        $table = <<<TABLE
	    <table class="table table-sm table-dark table-bordered" style="font-family:monospace">
TABLE;
        foreach ($e as $arr):
            $table .= <<<TABLE
	    <tr>
TABLE;
            foreach ($arr as $label => $value):
//		$lol = explode(':', $arr);
                $table .= <<<TABLE
		<td>$label:<br> <code>$value</code></td>
TABLE;
            endforeach;
            $table .= <<<TABLE
	    </tr>
TABLE;
        endforeach;

        $table .= <<<TABLE
	    </table>
TABLE;

        return $table;
    }

    /**
     * Display Error if Resource is not validated
     *
     * @return array
     * @noinspection PhpUnused
     */
    public function displayErrors(): array
    {
        return $this->errorDetails;
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function hastSubPosition(array $position): bool
    {
        return $this->hastElementAndElementNotEmpty($position, 'POSITION');
    }

    private function hastElementAndElementNotEmpty(array $element, string $key): bool
    {
        $k = strtoupper($key);

        if (!isset($element[$key])):
            return false;
        endif;

        if (is_int($element[$k]) || is_float($element[$k])):
            return true;
        endif;
        return !empty($element[$k]);
    }

}
