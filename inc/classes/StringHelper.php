<?php
namespace Webcito;

/**
 * Description of StringHelper
 *
 * @author Thomas Kirsch <t.kirsch@webcito.de>
 */
class StringHelper
{

    private static function getColorOfType($type, $value)
    {
	switch ($type):
	    case 'string':
		return '<code>"'.$value.'"</code>';
	    case 'int':
	    case 'integer':
	    case 'float':
	    case 'double':
		return '<span style="color:blue">'.$value.'</span>';
	    case 'NULL':
		return '<span style="color:gray"><em>NULL</em></span>';
	    case 'array':
		return $value;
	    default:
		return '<span style="color:green">'.$value.'</span>';
	endswitch;
    }

    public static function checkPassword($pwd, &$errors)
    {
	$errors_init = $errors;

	if (strlen($pwd) < 8)
	{
	    $errors[] = "Passwort zu kurz! (min 8 Zeichen)";
	}

	if (!preg_match("#[0-9]+#", $pwd))
	{
	    $errors[] = "das Passwort muss mindestens eine Zahl enthalten!";
	}

	if (!preg_match("#[a-zA-Z]+#", $pwd))
	{
	    $errors[] = "das Passwort muss mindestens einen Buchstaben enthalten!";
	}

	return ($errors == $errors_init);
    }

    public static function generatePassword(int $length = 8)
    {
	$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890@-_*!';
	$pass = array(); //remember to declare $pass as an array
	$alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
	for ($i = 0; $i < $length; $i++)
	{
	    $n = rand(0, $alphaLength);
	    $pass[] = $alphabet[$n];
	}
	return implode($pass); //turn the array into a string
    }

    public static function arrayToString($array)
    {
	$retStr = $array;

	if (is_array($array))
	{
	    $retStr = '<table class="table">';
	    $retStr .= '<thead>';
	    $retStr .= '<tr>';
	    $retStr .= '<th>';
	    $retStr .= 'key';
	    $retStr .= '</th>';
	    $retStr .= '<th>';
	    $retStr .= 'value';
	    $retStr .= '</th>';
	    $retStr .= '</tr>';
	    $retStr .= '</thead>';

	    foreach ($array as $key => $val)
	    {

		$retStr .= '<tr>';
		$retStr .= '<td valign="top">['.$key.']</td>';
		if (is_array($val) || is_object($val))
		{

		    $retStr .= '<td>'.self::arrayToString($val).'</td>';
		}
		else
		{
		    $type = gettype($val);
		    $retStr .= '<td>'.self::getColorOfType($type, $val).'</td>';
		}
		$retStr .= '</tr>';
	    }
	    $retStr .= '</table>';
	}

	return $retStr;
    }

    public static function cleanEmail(string $email): string
    {
	return trim(str_replace('googlemail', 'gmail', strtolower($email)));
    }

    public static function toString($class): string
    {
	$vars = get_object_vars($class);

	$return = [
	    "<div class='container'>",
	    "<table class='table table-striped table-bordered table-hover'>",
	    "<thead>",
	    "<tr>",
	    "<th colspan='3' class='bg-primary'>",
	    is_object($class) ? get_class($class).' :object' : "[]",
	    "</th>",
	    "</tr>",
	    "<tr>",
	    "<th class='bg-info'>property</th>",
	    "<th class='bg-info'>type</th>",
	    "<th class='bg-info'>value</th>",
	    "</tr>",
	    "</thead>",
	    "<tbody>",
	];

	foreach ($vars as $key => $value):
	    if ($key !== 'db'):
		$type = gettype($value);
		$return[] = "<tr><td><strong>$key</strong></td>";
		$return[] = '<td><span class="text-dark">['.$type.']</span></td><td>';

		switch (true):
		    case is_array($value):
//			foreach ($value as $val):
			$return[] = self::arrayToString($value);
			//			endforeach;
			break;
		    case is_object($value):
			$return[] = self::toString($value);
			break;

		    case is_bool($value):
			$return[] = empty($value) ? 'false' : 'true';
			break;
		    default:
			$return[] = self::getColorOfType(gettype($value), $value);
			break;
		endswitch;

		$return[] = "</td></tr>";
	    endif;
	endforeach;

	$return[] = "</tbody>";
	$return[] = "</table>";
	$return[] = "</div>";

	return implode('', $return);
    }

    public static function objectToString($object)
    {
	$retStr = $object;

	if (is_object($object))
	{
	    $retStr = '<table class="table">';
	    $retStr .= '<thead>';
	    $retStr .= '<tr>';
	    $retStr .= '<th>';
	    $retStr .= 'key';
	    $retStr .= '</th>';
	    $retStr .= '<th>';
	    $retStr .= 'value';
	    $retStr .= '</th>';
	    $retStr .= '</tr>';
	    $retStr .= '</thead>';

	    foreach ($object as $key => $val)
	    {

		$retStr .= '<tr>';
		$retStr .= '<td valign="top">['.$key.']</td>';
		if (is_object($val))
		{
		    $retStr .= '<td>'.self::objectToString($val).'</td>';
		}
		elseif (is_array($val))
		{

		    $retStr .= '<td>'.self::arrayToString($val).'</td>';
		}
		else
		{
		    $type = gettype($val);
		    $retStr .= '<td>'.self::getColorOfType($type, $val).'</td>';
		}
		$retStr .= '</tr>';
	    }
	    $retStr .= '</table>';
	}

	return $retStr;
    }

    public static function formatMoney($money): string
    {
	return '<span style="">'.number_format($money, 2, ",", ".").' €</span>';
//	return '<span style="font-family:monospace">'.number_format($money, 2, ",", ".").'</span>';
    }

}
