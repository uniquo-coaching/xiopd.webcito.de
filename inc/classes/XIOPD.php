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
    public function __construct(int|float $version, string $xml, bool $xmlString = false)
    {
        $this->feedSchema = $_SERVER['DOCUMENT_ROOT'].'/inc/'.match($version){
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
    public function validateLogic(): void
    {
        $xml = new SimpleXMLIterator($this->content);
        $arr = $this->sxiToArray($xml);

        $errors = [];

        if (!$this->hastElementAndElementNotEmpty($arr, 'POSITION')):
            $error = new stdClass();
            $error->level = 'logical error';
            $error->element = 'POSITION';
            $error->index = 'ROOT';
            $error->message = "Each document must have at least one POSITION. ";
            $errors[] = $error;
        else:
            foreach ($arr['POSITION'] as $index => $position):
                $this->checkPOSITION($position, [$index], $errors);
            endforeach;
        endif;


        if (!empty($errors)):
            echo $this->displayErrorsAsTable($errors);
        else:
            echo <<<SUCCESS
	    <div class="alert alert-success" role="alert">
		<i class="fas fa-check fa-fw"></i> Logic was successfully validated
	    </div>
SUCCESS;
        endif;
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
             * Preisangaben unten drunter sind rein informativ. Ansonsten errechnet sich der POSITION_TOTALPRICE des
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
             * Auch wenn der POSITION_PRICE oder  POSITION_TOTALPRICE leer ist, weil er sich aus den nachfolgenden
             * Positionen errechnet (Vererbung), müssen POSITION_QTY, POSITION_QU und POSITION_VAT gefüllt sein.
             */
            if (!$this->hastElementAndElementNotEmpty($position, 'POSITION_QTY') || !$this->hastElementAndElementNotEmpty($position, 'POSITION_VAT')):
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
     * Display Error if Resource is not validated as table
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
