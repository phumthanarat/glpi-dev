<?php

namespace GlpiPlugin\Itqr;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CommonDBTM;
use CommonGLPI;
use GLPIKey;
use Html;
use MassiveAction;
use Session;

/**
 * QR label of an asset: the signed "report a problem" URL, its QR image, the asset tab and the
 * "Print QR labels" massive action.
 */
class Label extends CommonGLPI
{
    public const ITEMTYPES = ['Computer', 'Monitor', 'Printer', 'NetworkEquipment', 'Peripheral', 'Phone'];

    /** Request source put on tickets reported through a label (created by plugin_itqr_install()). */
    public const REQUEST_TYPE = 'QR code';

    /**
     * Ticket category per asset type (ITIL category complete name, from setup-04), so the
     * category -> team and SLA business rules from setup-05 apply to QR tickets as well.
     */
    public const CATEGORIES = [
        'Computer'         => 'IT Support > Hardware > Computer',
        'Monitor'          => 'IT Support > Hardware > Monitor',
        'Printer'          => 'IT Support > Hardware > Printer',
        'NetworkEquipment' => 'IT Support > Network > LAN',
        'Peripheral'       => 'IT Support > Hardware',
        'Phone'            => 'IT Support > Hardware',
    ];

    public static function getTypeName($nb = 0)
    {
        return 'QR แจ้งปัญหา';
    }

    /**
     * Signature of an (itemtype, id) pair, keyed on GLPI's own secret key: labels can't be
     * forged or enumerated without it.
     */
    public static function sign(string $itemtype, int $id): string
    {
        $key = hash('sha256', 'itqr-label|' . (new GLPIKey())->get(), true);
        return substr(hash_hmac('sha256', $itemtype . '|' . $id, $key), 0, 24);
    }

    public static function isValid(string $itemtype, int $id, string $sig): bool
    {
        return in_array($itemtype, self::ITEMTYPES, true) && $id > 0 && hash_equals(self::sign($itemtype, $id), $sig);
    }

    public static function reportUrl(CommonDBTM $item): string
    {
        global $CFG_GLPI;

        return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/itqr/front/report.php?' . http_build_query([
            't'  => $item::class,
            'id' => $item->getID(),
            's'  => self::sign($item::class, $item->getID()),
        ]);
    }

    public static function svg(string $text, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));
        // drop the XML prolog so it can be inlined in HTML
        return preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($text));
    }

    public static function labelsUrl(string $itemtype, array $ids): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/itqr/front/labels.php?' . http_build_query([
            'itemtype' => $itemtype,
            'ids'      => implode(',', array_map('intval', $ids)),
        ]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            !$withtemplate
            && Session::getCurrentInterface() === 'central'
            && $item instanceof CommonDBTM
            && in_array($item::class, self::ITEMTYPES, true)
            && !$item->isNewItem()
        ) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-qrcode');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof CommonDBTM) {
            return false;
        }
        $url = self::reportUrl($item);
        echo "<div class='d-flex flex-wrap gap-4 align-items-start p-3'>";
        echo "<div class='border rounded p-2 bg-white itqr-tab-qr'>" . self::svg($url) . "</div>";
        echo "<div style='max-width: 560px'>";
        echo "<h3 class='mb-2'>สแกนเพื่อแจ้งปัญหาเครื่องนี้</h3>";
        echo "<p class='text-secondary'>ติด QR นี้ที่ตัวเครื่อง ผู้ใช้สแกนด้วยมือถือแล้วจะเข้าหน้าแจ้งปัญหาที่ระบุเครื่องนี้ไว้แล้ว "
            . "Ticket ที่ได้จะผูกกับเครื่องนี้ และได้หมวด / ทีม / SLA ตามชนิดเครื่องอัตโนมัติ</p>";
        echo "<a class='btn btn-primary' target='_blank' href='" . htmlescape(self::labelsUrl($item::class, [$item->getID()])) . "'>"
            . "<i class='ti ti-printer me-1'></i>พิมพ์ป้าย</a>";
        echo "<div class='mt-3 small text-secondary text-break'>ลิงก์ในป้าย: <code class='itqr-url'>" . htmlescape($url) . "</code></div>";
        echo "</div></div>";
        return true;
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        if ($ma->getAction() !== 'print_labels') {
            return parent::showMassiveActionsSubForm($ma);
        }
        foreach ($ma->getItems() as $itemtype => $ids) {
            if (!in_array($itemtype, self::ITEMTYPES, true)) {
                continue;
            }
            echo "<a class='btn btn-primary' target='_blank' href='" . htmlescape(self::labelsUrl($itemtype, array_keys($ids))) . "'>"
                . "<i class='ti ti-printer me-1'></i>เปิดหน้าพิมพ์ป้าย QR (" . count($ids) . " เครื่อง)</a>";
        }
        return true;
    }
}
