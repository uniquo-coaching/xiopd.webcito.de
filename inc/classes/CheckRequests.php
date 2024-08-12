<?php

/**
 * Description of CheckRequests
 *
 * @author Thomas Kirsch
 */
class CheckRequests
{

    const DEFAULT_ERROR_LABEL = 'default';
    const SECURE_GUID = '2286ca55-ac38-44ad-9fe6-fdc1eaa3a054';

    private
	$values = [],
	$required = [],
	$warnings = [],
	$optionals = [],
	$remove = [],
	$seconds = 0,
	$ok,
	$data,
	$errorList,
	$execution_time_before,
	$files,
	$filesRequired,
	$idField;
    public $errors;

    /**
     * Translation Labels
     * @var array
     */
    public array $labels = [];

    /**
     * CONSTRUCTOR
     */
    public function __construct(?array $values = [], ?array $required = [], ?array $optionals = [
	], ?array $remove = [], array $filesRequired = [],bool $check = true)
    {
	$this->seconds = round(microtime(true) * 1000) / 1000;
	$this->values = empty($values) ? [] : $values;
	$this->required = empty($required) ? [] : $required;
	$this->optionals = empty($optionals) ? [] : $optionals;
	$this->remove = empty($remove) ? [] : $remove;
	$this->errors = [];
	$this->warnings = [];
	$this->files = $_FILES;
	$this->filesRequired = [];
	$this->data = [];
	$this->ok = true;

	$this->errorList = [
	    0 => 'Die Eingabe fehlt oder ist fehlerhaft.',
	    1 => 'Dieser Eintrag scheint bereits zu existieren.',
	    2 => 'Die Eingabe enth&auml;lt m&ouml;glicherweise einen sch&auml;dlichen Ausdruck.',
	    3 => 'ung&uuml;ltige EAN',
	    4 => 'Die Überprüfung des reCAPTCHA ist fehlgeschlagen.',
	    5 => 'Die Datum ist ungültig.',
	    6 => 'Falsches Zeitformat',
	    7 => 'empty',
	    8 => 'Die Eingabe ist keine Zeichenkette.',
	    9 => 'Verlängern Sie den Text auf mindestens {0} Zeichen. Derzeit verwenden Sie {1} Zeichen.',
	    10 => 'Verkürzen Sie den Text auf maximal {0} Zeichen.  Derzeit verwenden Sie {1} Zeichen.'
	];

	if ($check):
	    $this->check();
	endif;
    }

    /**
     *
     * @param string $label
     * @param string $text
     * @return void
     */
    public function setLabel(string $label, string $text): void
    {
	if (array_key_exists($label, $this->values)):
	    $this->labels[$label] = $text;
	endif;
    }

    /**
     * Prüft ob der String ein String ist und ob sich die Länge des Strings in der übergebenen Range befindet.
     * @param string $label
     * @param int $min
     * @param int $max
     */
    public function checkStrlen(string $label, int $min = 0, int $max = null)
    {
	if (array_key_exists($label, $this->values)):
	    if (!is_string($this->values[$label])):
		$this->setError($label, $this->errorList[8]);
	    else:
		$length = mb_strlen($this->values[$label]);
		if ($length < $min):
		    $this->setError($label, str_replace('{0}', $min, str_replace('{1}', $length, $this->errorList[9])));
		endif;

		if (($max !== null) && (false === $this->getError($label))):
		    if ($length > $max):
			 $this->setError($label, str_replace('{0}', $max, str_replace('{1}', $length, $this->errorList[10])));
		    endif;
		endif;
	    endif;
	endif;
    }

    public function checkSecureGUID($label)
    {
	if (array_key_exists($label, $this->values)):
	    $success = ($this->values[$label] === self::SECURE_GUID);
	    if (!$success):
		exit('secure error');
	    endif;
	else:
	    exit('secure error');
	endif;

	return $this;
    }

    public function checkDate($key)
    {
	if (array_key_exists($key, $this->values)):
	    $date = $this->parseToSQLDate($key);
	    $datas = explode('-', $date);
	    $success = checkdate($datas[1], $datas[2], $datas[0]);
	    if (!$success):
		$this->setError($key, $this->errorList[5]);
	    endif;
	endif;

	return $this;
    }

    public function ucfirst($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = ucfirst($this->values[$key]);
	endif;

	return $this->values[$key];
    }

    public function checkTime($key)
    {
	if (array_key_exists($key, $this->values)):
	    $ok = true;
	    $arr = explode(':', $this->values[$key]);
	    $hour = $arr[0];
	    $min = $arr[1];
	    $sec = $arr[2];

	    if ($hour < 0 || $hour > 23 || !is_numeric($hour))
	    {
		$ok = false;
	    }
	    if ($min < 0 || $min > 59 || !is_numeric($min))
	    {
		$ok = false;
	    }
	    if ($sec < 0 || $sec > 59 || !is_numeric($sec))
	    {
		$ok = false;
	    }

	    if (!$ok):
		$this->setError($key, $this->errorList[6]);
	    endif;

	endif;

	return $this;
    }


    public function setSession($key, $value)
    {
	\Session::w($key, $value);
    }

    public function getFile($key)
    {
	if (array_key_exists($key, $this->files)):
	    return $this->files[$key];
	endif;

	return false;
    }

    public function hasFileUploadedSuccess($label)
    {
	if (!empty($this->files[$label])):
	    if (intval($this->files[$label]['error']) === UPLOAD_ERR_OK):
		return true;
	    endif;
	endif;

	return false;
    }

    public function setFiles($files)
    {
	$this->files = $files;
	return $this;
    }

    public function setFilesRequired(array $required)
    {
	$this->filesRequired = $required;
	return $this;
    }

    public function addFilesRequired(string $required)
    {
	$this->filesRequired[] = $required;
	return $this;
    }

    public function parseInt($key)
    {
	if (array_key_exists($key, $this->values)):
	    if (is_array($this->values[$key])):
		$this->values[$key] = array_map('intval', $this->values[$key]);
	    else:
		$this->values[$key] = intval($this->values[$key]);
	    endif;

	endif;
	return $this->values[$key];
    }

    public function verifyDate($date, $strict = true)
    {
	$dateTime = DateTime::createFromFormat('m/d/Y', $date);
	if ($strict)
	{
	    $errors = DateTime::getLastErrors();
	    if (!empty($errors['warning_count']))
	    {
		return false;
	    }
	}
	return $dateTime !== false;
    }

    public function parseToSQLDate($key)
    {
	if (array_key_exists($key, $this->values) && !empty($this->values[$key])):
	    try
	    {
		$date = new \DateTime($this->values[$key]);
		$this->values[$key] = $date->format('Y-m-d');
	    }
	    catch (Exception $e)
	    {
		$this->values[$key] = null;
		$this->setError($key, $e->getMessage());
	    }

	endif;
	return $this->values[$key];
    }

    public function parseToArrayInt($key, $seperator = ",")
    {
	if (array_key_exists($key, $this->values)):
	    if (!is_array($this->values[$key])):
		$this->values[$key] = explode($seperator, $this->values[$key]);
	    endif;
	    $this->values[$key] = array_map('intval', $this->values[$key]);
	endif;

	return $this->values[$key];
    }

    public function parseToSQLTime($key)
    {
	if (array_key_exists($key, $this->values)):

	    if (strlen($this->values[$key]) === 5):
		$this->values[$key] = $this->values[$key].':00';
	    endif;

	    $currdate = date('Y-m-d');

	    $date = date('H:i:s', strtotime($currdate.' '.$this->values[$key]));

	    $this->values[$key] = $date;
	endif;
	return $this->values[$key];
    }

    public function getRequestSeconds(): float
    {
	return (round(microtime(true) * 1000) / 1000) - $this->seconds;
    }

    public function parsePrice($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = number_format($this->values[$key], 2, ".", "");
	endif;
	return $this->values[$key];
    }

    public function parseFloat($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = floatval(str_replace(',', '.', $this->values[$key]));
	endif;
	return $this->values[$key];
    }

    public function strtolower($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = strtolower($this->values[$key]);
	endif;
	return $this->values[$key];
    }

    public function strtoupper($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = strtoupper($this->values[$key]);
	endif;
	return $this->values[$key];
    }

    public function changeValue($key, $val)
    {
	$this->values[$key] = $val;
	return $this;
    }

    public function getData($key)
    {
	if (array_key_exists($key, $this->data)):
	    return $this->data[$key];
	endif;
	return false;
    }

    public function setEnumToBool($key)
    {
	if (array_key_exists($key, $this->values)):
	    $this->values[$key] = $this->strtolower($key) === 'y';
	endif;

	return $this;
    }

    public function setExecutionTime($sec)
    {
	$this->execution_time_before = ini_get('max_execution_time');
	ini_set('max_execution_time', $sec);
    }

    public function resetExecutionTime()
    {
	ini_set('max_execution_time', $this->execution_time_before);
    }

    /**
     *
     * @param ASSOZIATIVES ARRAY $var
     */
    public function setValues(&$var)
    {
	if (isset($var['action'])):
	    unset($var['action']);
	endif;

	if (!is_array($var)):
	    $var = [];
	endif;

	$this->values = $var;
	return $this;
    }

    /**
     *
     * @param string $key
     * @param mixed $value
     * @return \CheckRequests
     */
    public function addValue(string $key, $value): \CheckRequests
    {
	$this->values[$key] = $value;
	return $this;
    }

    /**
     *
     * @param string $label
     * @return mixed boolean|value
     */
    public function val(string $label)
    {
	if (array_key_exists($label, $this->values)):
	    return $this->values[$label];
	endif;

	return false;
    }

    /**
     *
     * @return array
     */
    public function getValues(): array
    {
	return empty($this->values) ? [] : $this->values;
    }

    /**
     *
     * @param string $key
     * @return bool
     */
    public function checkEan(string $key): bool
    {
	if (!empty($this->values[$key])):
	    $validation = $this->validateEan13($this->values[$key]);
	    if ($validation === false || ($validation['checksum'] !== $validation['originalcheck'])):
		$this->setError($key, $this->errorList[3]);
		return false;
	    else:
		return true;
	    endif;
	else:
	    return false;
	endif;
    }

    private function validateEan13($digits)
    {
	$originalcheck = false;
	if (strlen($digits) == 13)
	{
	    $originalcheck = substr($digits, -1);
	    $digits = substr($digits, 0, -1);
	}
	elseif (strlen($digits) != 12)
	{
	    // Invalid EAN13 barcode
	    return false;
	}

	// Add even numbers together and Multiply this result by 3
	$even = ($digits[1] + $digits[3] + $digits[5] + $digits[7] + $digits[9] + $digits[11]) * 3;

	// Add odd numbers together
	$odd = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8] + $digits[10];

	// Add two totals together
	$total = $even + $odd;

	// Calculate the checksum
	// Divide total by 10 and store the remainder
	$checksum = $total % 10;
	// If result is not 0 then take away 10
	if ($checksum != 0)
	{
	    $checksum = 10 - $checksum;
	}

	// Return results.
	if ($originalcheck !== false)
	{
	    return array('barcode' => $digits, 'checksum' => intval($checksum), 'originalcheck' => intval($originalcheck));
	}
	else
	{
	    return false;
//	    return array('barcode' => intval($digits), 'checksum' => intval($checksum));
	}
    }

    /**
     *
     * @param array $array
     * @return \CheckRequests
     */
    public function setRequired(array $array): \CheckRequests
    {
	$this->required = $array;
	return $this;
    }

    /**
     *
     * @param ASSOZIATIVES ARRAY $var (key is name of input)
     */
    public function getRequired(): array
    {
	return empty($this->required) ? [] : $this->required;
    }

    /**
     *
     * @param string $key
     * @return bool
     */
    public function isRequired(string $key): bool
    {
	return empty($this->required) ? false : in_array($key, $this->required);
    }

    /**
     *
     * @param string $label
     * @return \CheckRequests
     */
    public function addRequired(string $label): \CheckRequests
    {
	if (!is_array($this->required)):
	    $this->required = [];
	endif;

	if (array_key_exists($label, $this->optionals)):
	    unset($this->optionals[$label]);
	endif;

	$this->required[] = $label;
	return $this;
    }

    /**
     *
     * @param string $label
     * @param mixed $default
     * @return \CheckRequests
     */
    public function addOptional(string $label, $default): \CheckRequests
    {
	if (!is_array($this->optionals)):
	    $this->optionals = [];
	endif;

	if (array_key_exists($label, $this->required)):
	    unset($this->required[$label]);
	endif;

	$this->optionals[$label] = $default;
	return $this;
    }

    /**
     *
     * @param string $key
     * @param mixed $data
     * @return \CheckRequests
     */
    public function setData(string $key, $data): \CheckRequests
    {
	$this->data[$key] = $data;
	return $this;
    }

    /**
     *
     * @param array $array
     * @return \CheckRequests
     */
    public function setOptionals(array $array): \CheckRequests
    {
	$this->optionals = $array;
	return $this;
    }

    public function setRemove($var)
    {
	$this->remove = $var;
	return $this;
    }

    public function check()
    {
	$this->check_posts();

	if (!empty($this->optionals)):
	    $this->check_optionals();
	endif;

	if (!empty($this->files)):
	    $this->check_required_files();
	endif;

	$this->removeValues();

	return $this->ok();
    }

    private function check_required_files()
    {
	if (!empty($this->filesRequired)):
	    for ($i = 0; $i < count($this->filesRequired); $i++):
		if (!array_key_exists($this->filesRequired[$i], $this->files)):
		    $this->errors[$this->filesRequired[$i]] = $this->errorList[0];
		else:
		    $error = false;
		    switch ($this->files[$this->filesRequired[$i]]['error']):
			case UPLOAD_ERR_OK:
			    continue 2;
			case UPLOAD_ERR_INI_SIZE:
			    $error = 'Die hochgeladene Datei überschreitet die in der Anweisung upload_max_filesize in php.ini festgelegte Größe';
			    break;
			case UPLOAD_ERR_FORM_SIZE:
			    $error = 'Die hochgeladene Datei überschreitet die in dem HTML Formular mittels der Anweisung MAX_FILE_SIZE angegebene maximale Dateigröße.';
			    break;
			case UPLOAD_ERR_PARTIAL:
			    $error = 'Die Datei wurde nur teilweise hochgeladen.';
			    break;
			case UPLOAD_ERR_NO_FILE:
			    $error = 'Es wurde keine Datei hochgeladen.';
			    break;
			case UPLOAD_ERR_NO_TMP_DIR:
			    $error = 'Fehlender temporärer Ordner. Eingeführt in PHP 5.0.3.';
			    break;
			case UPLOAD_ERR_CANT_WRITE:
			    $error = 'Speichern der Datei auf die Festplatte ist fehlgeschlagen. Eingeführt in PHP 5.1.0.';
			    break;
			case UPLOAD_ERR_EXTENSION:
			    $error = 'Eine PHP Erweiterung hat den Upload der Datei gestoppt. PHP bietet keine Möglichkeit an, um festzustellen welche Erweiterung das Hochladen der Datei gestoppt hat. Überprüfung aller geladenen Erweiterungen mittels phpinfo() könnte helfen. Eingeführt in PHP 5.2.0.';
			    break;
		    endswitch;

		    if ($error !== false):
			$this->errors[$this->filesRequired[$i]] = $error;
		    endif;
		endif;
	    endfor;
	endif;
    }

    private function removeValues()
    {
	if (!empty($this->remove)):
	    for ($i = 0; $i < count($this->remove); $i++):
		if (array_key_exists($this->remove[$i], $this->values)):
		    unset($this->values[$this->remove[$i]]);
		endif;
	    endfor;
	endif;
	return $this;
    }

    public function changeOptionalToRequired(string $label): \CheckRequests
    {
	return $this
		->removeOptional($label)
		->addRequired($label);
    }

    public function removeOptional($label): \CheckRequests
    {
	if (array_key_exists($label, $this->optionals)):
	    unset($this->optionals[$label]);
	endif;

	return $this;
    }

    /**
     * ungetestet
     */
    private function check_optionals()
    {
	foreach ($this->optionals AS $label => $default):
	    if (array_key_exists($label, $this->values)):
		if (!empty($this->values[$label])):
		    $this->clean_var($this->values[$label]);
		    $this->check_injection($this->values[$label], $label);
		else:
		    $this->values[$label] = $default;
		endif;
	    else:
		$this->values[$label] = $default;
	    endif;
	endforeach;
    }

    /**
     *
     * @return array of errors (key is name of input which produce the error)
     */
    public function getErrors()
    {
	return $this->errors;
    }

    public function getError(string $label)
    {
	if (array_key_exists($label, $this->errors)):
	    return $this->errors[$label];
	endif;
	return false;
    }

    public function setWarning($label, $message)
    {
	$this->warnings[$label] = $message;
    }

    /**
     *
     * @param string $label Kann mit Pipe(|) mehrere Labels empfangen
     * @param string $message
     * @return \CheckRequests
     */
    public function setError(string $label, string $message): \CheckRequests
    {
	$arr = explode('|', $label);
	foreach ($arr as $l):
	    $this->errors[trim($l)] = $message;
	endforeach;
	return $this;
    }

    public function setDefaultError($message)
    {
	$this->errors[self::DEFAULT_ERROR_LABEL] = $message;
    }

    public function removeError($label)
    {
	if (array_key_exists($label, $this->errors)):
	    unset($this->errors[$label]);
	endif;
    }

    public function setIdField($key)
    {
	$this->idField = $key;
	return $this;
    }

    public function isNew(): bool
    {
	if (empty($this->idField) || !array_key_exists($this->idField, $this->values)):
	    if (array_key_exists('id', $this->values)):
		$this->idField = 'id';
	    else:
		exit('idField undefined or not exists in values');
	    endif;
	endif;

	return empty($this->values[$this->idField]);
    }

    public function ok()
    {
	return empty($this->errors);
    }

    private function check_posts()
    {
	if (!is_array($this->values)):
	    $this->clean_var($this->values);
	    $this->check_injection($this->values, 'default', $this->errors);
	    $this->values = [$this->values];
	else:
	    foreach ($this->values as $k => $v):
		$this->clean_var($this->values[$k]);
		$this->check_injection($this->values[$k], $k, $this->errors);
	    endforeach;
	endif;

	if (!is_array($this->required)):
	    $this->required = [$this->required];
	endif;

	for ($i = 0; $i < count($this->required); $i++):
	    $test = explode('||', $this->required[$i]);
	    if (count($test) === 1):
		if (!array_key_exists($this->required[$i], $this->values) || ( empty($this->values[$this->required[$i]]) && $this->values[$this->required[$i]] !== "0")):
		    $this->errors[$this->required[$i]] = $this->errorList[0];
		endif;
	    else:
		$error = [];
		for ($j = 0; $j < count($test); $j++):
		    if (!array_key_exists($test[$j], $this->values) || ( empty($this->values[$test[$j]]) && $this->values[$test[$j]] !== "0")):
			$error[] = $test[$j];
		    endif;
		endfor;
		if (count($error) === count($test)):
		    for ($j = 0; $j < count($test); $j++):
			$this->errors[$test[$j]] = $this->errorList[0];
		    endfor;
		endif;
	    endif;
	endfor;
    }

    private function make_injection_check(&$data, $label)
    {
	$sql_injection = array(
	    'delete from',
	    'drop table',
	    'drop database',
	    'insert into',
	    'select from',
	    'select into',
	    'update set');

	// Auf potentielle SQL Injections prüfen
	foreach ($sql_injection as $injection)
	{
	    if (preg_match("/{$injection}/i", $data))
	    {
		$this->errors[$label] = $this->errorList[2];
		$data = '';
	    }
	}

	$email_injection = array('bcc:',
	    'boundary',
	    'cc:',
	    'content-transfer-encoding:',
	    'content-type:',
	    'mime-version:',
	    'subject:');

	// Auf potentielle Email Injections prüfen
	foreach ($email_injection as $injection)
	{
	    if (preg_match("/{$injection}/i", $data))
	    {
		$this->errors[$label] = $this->errorList[2];
		$data = '';
	    }
	}
    }

    private function check_injection(&$data, $label)
    {


	if (is_array($data))
	{
	    $this->throw_array($data, $label);
	}
	else
	{
	    $this->make_injection_check($data, $label);
	}
    }

    private function make_clean(&$value, $wordwrap)
    {
	$value = trim($value);
//        $linebreakes = array(
//            "/(\r\n)|(\r)/m",
//            "/(\n){3,}/m",
//            "/\s{3,}/m",
//            "/(.)\\1{15,}/im");
//
//        $newLinebreakes = array(
//            "\n",
//            "\n\n",
//            " ",
//            "\\1");
//
//        if (get_magic_quotes_gpc()) {
//            $value = stripslashes($value);
//        }
//        $value = preg_replace($linebreakes, $newLinebreakes, $value);
	$value = trim($value);
    }

    private function throw_array(&$data, $wordwrap)
    {
	foreach ($data AS $index => $value)
	{
	    if (is_array($data[$index]))
	    {
		$this->throw_array($data[$index], $wordwrap);
	    }
	    else
	    {
		$this->make_clean($data[$index], $wordwrap);
	    }
	}
    }

    private function hasWarnings(): bool
    {
	return !empty($this->warnings);
    }

    public function getAssoResponse()
    {
	$return = [
	    'success' => $this->ok()
	];

	if (!$this->ok()):
	    $return['error'] = $this->errors;
	endif;

	if ($this->hasWarnings()):
	    $return['warning'] = $this->warnings;
	endif;

	if (!empty($this->data)):
	    $return['data'] = $this->data;
	endif;

	return $return;
    }

    public function getJSONResponse()
    {


	return json_encode($this->getAssoResponse());
    }

    private function clean_var(&$data, $wordwrap = 500)
    {
	if (is_array($data))
	{
	    $this->throw_array($data, $wordwrap);
	}
	else
	{
	    $this->make_clean($data, $wordwrap);
	}
    }

    /**
     * Dekodiert ein JSON-String
     * @param string $json
     * @param bool $assoc
     * @return type
     * @throws \Exception Wirft eine Exception, wenn beim dekodieren ein Fehler auftrat.
     */
    public static function jsonDecode(string $json, bool $assoc = false)
    {
	$ret = json_decode($json, $assoc);
	$error = json_last_error();
	if ($error)
	{
	    $errorReference = [
		JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded.',
		JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON.',
		JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded.',
		JSON_ERROR_SYNTAX => 'Syntax error.',
		JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded.',
		JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded.',
		JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded.',
		JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given.',
	    ];
	    $errStr = isset($errorReference[$error]) ? $errorReference[$error] : "Unknown error ($error)";
	    throw new \Exception("JSON decode error ($error): $errStr");
	}
	return $ret;
    }

    public function __toString()
    {
	return StringHelper::toString($this);
    }

}
