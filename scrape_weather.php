<?php

// here we publish MQTT
// https://www.cloudmqtt.com/docs-php.html
// https://github.com/bluerhinos/phpMQTT

require("phpMQTT.php");

$mqtt = new phpMQTT("localhost", 1883, "ambient"); //Change client name to something unique

define ("PRODUCTION", "True");
// comment out to go to test mode

// If Curl waits forever for data (as can happen on a weather statiom) just time out in php after x secs
set_time_limit(30);

// array with sensor values for Domoticz.
// 0 means: do not send
$idxlookup = array (
	'IndoorID' => 0,
	'Outdoor1ID' => 0,
	'Outdoor2ID' => 0,
	'inTemp' => 3,
	'inHumi' => 5,
	'AbsPress' => 0,
	'RelPress' => 7,
	'outTemp' => 4,
	'outHumi' => 6,
	'windir' => 0,
	'avgwind' => 9,
	'gustspeed' => 0,
	'dailygust' => 0,
	'uvi' => 10,
	'uv' => 0,
	'solarrad' => 11,
	'rainofhourly' => 8,
	'rainofdaily' => 0,
	'rainofweekly' => 0,
	'rainofmonthly' => 0,
	'rainofyearly' => 0,
	'eventrain' => 0,
	'pm25' => 0,
	'pm25out' => 0,
	'pm25in' => 0,
	'CurrTime' => 0,
	'inBattSta' => 0,
	'outBattSta1' => 0,
	'outBattSta2' => 0,
	'Apply' => 0,
	'Cancel' => 0,
	'rain_Default' => 0,
);

// based on original work from the PHP Laravel framework
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

if (defined('PRODUCTION')) {
	// create curl resource to retrieve the data from the weatherstation
	$ch = curl_init();

	// set url to the right page
	curl_setopt($ch, CURLOPT_URL, "192.168.2.22/livedata.htm");

	//return the transfer as a string
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

	// don't wait forever for the output but don't generate a name server alarm
	curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);	// wait a max of x seconds

	// $output contains the output string
	$html = curl_exec($ch);
	$curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        if ($curl_errno > 0) {
            echo "cURL Error ($curl_errno): $curl_error\n";
            exit;
        }
	                                                
	// close curl resource to free up system resources
	curl_close($ch);
} else {
    $html = file_get_contents('livedata.htm'); //get the html returned from the test page
}

$ambient_doc = new DOMDocument();

libxml_use_internal_errors(TRUE); //disable libxml errors

if(!empty($html)){ //if any html is actually returned

    // remove javascript from html
    $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    
    // write the html file to a file here in the directory so that we can access it from loxone
    file_put_contents ("/var/www/html/livedata.html", $html);

    $ambient_doc->loadHTML($html);
    libxml_clear_errors(); //remove errors for yucky html

    $ambient_xpath = new DOMXPath($ambient_doc);

    //get all the input fields which represent data from the weather station
    // the name and the value are stored in attributes of the input field
    // e.g:
    //    <td bgcolor="#EDEFEF"><div class="item_1">Indoor Temperature</div></td>
    //    <td bgcolor="#EDEFEF"><input name="inTemp" disabled="disabled" type="text" class="item_2" style="WIDTH: 80px" value="21.5" maxlength="5" /></td>
    $ambient_row = $ambient_xpath->query('//input');

    foreach ($ambient_row as $value) {
		$attr = $value->getAttribute("name");
		if ($attr) {
			// store the name and the value for later processing
			$ambient[$attr]['name'] = $attr;
			$ambient[$attr]['value'] = $value->getAttribute("value");
			$ambient[$attr]['idx'] = $idxlookup[$attr];
		}
	}


if ($mqtt->connect()) {

	// now we have all values... go and create the JSON strings...

	// note, string differs depending on kind of value... needs some work for Domoticz
	// see: https://www.domoticz.com/wiki/Domoticz_API/JSON_URL%27s#Temperature

	// Temperature
	// json.htm?type=command&param=udevice&idx=IDX&nvalue=0&svalue=TEMP
	//IDX = id of your device (This number can be found in the devices tab in the column "IDX")
	//TEMP = Temperature

	// indoor temperature
	$arr = array ('idx' => $ambient['inTemp']['idx'], 'nvalue' => 0, 'svalue' => $ambient['inTemp']['value']);
	$arr2 = array ('value' => $ambient['inTemp']['value']);
	// echo json_encode($arr) . "\n";
	// if the value is nonsense, do not publish
	if (!str_contains($ambient['inTemp']['value'], "-.-") && $ambient['inTemp']['value'] > 0 && $ambient['inTemp']['value'] < 40) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/indoortemp",json_encode($arr2),0);
        }

	// outdoor temperature
	$arr = array ('idx' => $ambient['outTemp']['idx'], 'nvalue' => 0, 'svalue' => $ambient['outTemp']['value']);
	$arr2 = array ('value' => $ambient['outTemp']['value']);
	// echo json_encode($arr) . "\n";
	if (!str_contains($ambient['outTemp']['value'], "-.-") && $ambient['outTemp']['value'] > -20 &&  $ambient['outTemp']['value'] < 60) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/outdoortemp",json_encode($arr2),0);
        }

	// Humidity
	//json.htm?type=command&param=udevice&idx=IDX&nvalue=HUM&svalue=HUM_STAT
	//The above sets the parameters for a Humidity device
	//IDX = id of your device (This number can be found in the devices tab in the column "IDX")
	//HUM = Humidity: 45%
	//HUM_STAT = Humidity_status

	//Humidity_status can be one of:
	//0=Normal (30-70)
	//1=Comfortable (50-60)
	//2=Dry <30
	//3=Wet >70

	// indoor humidity
	$val = $ambient['inHumi']['value'];
	if ($val < 30) {
		$s = 2;
	} else if ($val > 70) {
		$s = 3;
	} else if (($val >= 50) && ($val <= 60)) {
		$s = 1;
	} else {
		$s = 0;
	}

	$arr = array ('idx' => $ambient['inHumi']['idx'], 'nvalue' => (int)$val, 'svalue' => strval($s));
	$arr2 = array ('value' => (int)$val);
	// echo json_encode($arr) . "\n";
	if ($val > 10 &&  $val < 100) {	
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/indoorhumidity",json_encode($arr2),0);
	}
	
	$val = $ambient['outHumi']['value'];
	// outdoor humidity
	if ($val < 30) {
		$s = 2;
	} else if ($val > 70) {
		$s = 3;
	} else if (($val >= 50) && ($val <= 60)) {
		$s = 1;
	} else {
		$s = 0;
	}

	// $arr = array ('idx' => $ambient['outHumi']['idx'], 'nvalue' => 'Humidity: ' . $val . '%', 'svalue' => $s);
	$arr = array ('idx' => $ambient['outHumi']['idx'], 'nvalue' => (int)$val, 'svalue' => strval($s));
	$arr2 = array ('value' => (int)$val);
	// echo json_encode($arr) . "\n";
	if ($val > 10 &&  $val < 100) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/outdoorhumidity",json_encode($arr2),0);
	}
	
	//Barometer
	//json.htm?type=command&param=udevice&idx=IDX&nvalue=0&svalue=BAR;BAR_FOR
	//The above sets the parameters for a Barometer device from hardware type 'General'
	// BAR = Barometric pressure
	// BAR_FOR = Barometer forecast

	// Barometer forecast can be one of:
	// 0 = Stable
	// 1 = Sunny
	// 2 = Cloudy
	// 3 = Unstable
	// 4 = Thunderstorm
	// 5 = Unknown
	// 6 = Cloudy/Rain
	$arr = array ('idx' => $ambient['RelPress']['idx'], 'nvalue' => 0, 'svalue' => $ambient['RelPress']['value'] . ';5');
	$arr2 = array ('value' => $ambient['RelPress']['value']);
	// echo json_encode($arr) . "\n";
	if (!str_contains($ambient['RelPress']['value'], "-.-") && $ambient['RelPress']['value'] > 600 &&  $ambient['RelPress']['value'] < 1400) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/barometer",json_encode($arr2),0);
        }

	//Rain
	//json.htm?type=command&param=udevice&idx=IDX&nvalue=0&svalue=RAINRATE;RAINCOUNTER
	//RAINRATE = amount of rain in last hour
	//RAINCOUNTER = continues counter of fallen Rain in mm
	$arr = array ('idx' => $ambient['rainofhourly']['idx'], 'nvalue' => 0, 'svalue' => $ambient['rainofhourly']['value'] . ';' .  $ambient['rainofyearly']['value']);
	$arr2 = array ('value' => $ambient['rainofhourly']['value']);
	// echo json_encode($arr) . "\n";
	if (!str_contains($ambient['rainofhourly']['value'], "-.-")) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/rain",json_encode($arr2),0);
        }

	//Wind
	//json.htm?type=command&param=udevice&idx=IDX&nvalue=0&svalue=WB;WD;WS;WG;22;24
	//WB = Wind bearing (0-359)
	//WD = Wind direction (S, SW, NNW, etc.)
	//WS = 10 * Wind speed [m/s]
	//WG = 10 * Gust [m/s]
	//22 = Temperature
	//24 = Temperature Windchill

	// To convert from km/h to m/s multiply by 0.277778, but system is in km/h now

	// First build the datastring
	$data = $ambient['windir']['value'];
	$data = $data . ';' . degToCompass($ambient['windir']['value']);
	$data = $data . ';' . $ambient['avgwind']['value'] * 10 * 0.2777778;
	$data = $data . ';' . $ambient['gustspeed']['value'] * 10 * 0.2777778;
	$data = $data . ';' . $ambient['outTemp']['value'];

	// T_{\rm wc}=13.12 + 0.6215 T_{\rm a}-11.37 V^{+0.16} + 0.3965 T_{\rm a} V^{+0.16}\,\!
	//where
	//T_{\rm wc}\,\! is the wind chill index, based on the Celsius temperature scale,
	//T_{\rm a}\,\! is the air temperature in degrees Celsius (°C), and
	//V\,\! is the wind speed at 10 metres (standard anemometer height), in kilometres per hour (km/h).[9]
	$v = $ambient['avgwind']['value']; // our Ambient Weather station returns windspeed in km/h
	$twc = 13.12 + 0.6215 * $ambient['outTemp']['value'] - (11.37 * pow ($v, 0.16)) + (0.3965 * $ambient['outTemp']['value'] * pow ($v, 0.16));
	$data = $data . ';' . $twc;
	$arr = array ('idx' => $ambient['avgwind']['idx'], 'nvalue' => 0, 'svalue' => $data);
	// echo json_encode($arr) . "\n";
	$mqtt->publish("domoticz/in",json_encode($arr),0);

	//UV
	//json.htm?type=command&param=udevice&idx=IDX&nvalue=0&svalue=COUNTER;0
	// COUNTER = Float (in example: 2.1) with current UV reading.
	// Don't loose the ";0" at the end - without it database may corrupt.
	$arr = array ('idx' => $ambient['uvi']['idx'], 'nvalue' => 0, 'svalue' => $ambient['uvi']['value'] . ';0');
	$arr2 = array ('value' => $ambient['uvi']['value']);
	// echo json_encode($arr) . "\n";
	if (!str_contains($ambient['uv']['value'], "--")) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
		$mqtt->publish("rpi1/uv",json_encode($arr2),0);
        }

	//Lux
	//json.htm?type=command&param=udevice&idx=IDX&svalue=VALUE
	//VALUE = value of luminosity in Lux
	$arr = array ('idx' => $ambient['solarrad']['idx'], 'svalue' => $ambient['solarrad']['value']);
	$arr2 = array ('value' => $ambient['solarrad']['value']);
	// echo json_encode($arr) . "\n";
	if (!str_contains($ambient['solarrad']['value'], "-.-")) {
		$mqtt->publish("domoticz/in",json_encode($arr),0);
        	$mqtt->publish("rpi1/lux",json_encode($arr2),0);
        }

    $mqtt->close();
    } else {
    	echo "No MQTT connection";
    }

}

function degToCompass($num) {
	// from a degree value on the compass rose, create the textual representation
    if ($num < 0) $num += 360;
    if ($num >= 360) $num -= 360;

    $val = round (($num-11.25) / 22.5); // each segment is 22.5 degrees... place the number in the middle
    $arr = array ("N","NNE","NE","ENE","E","ESE","SE","SSE","S","SSW","SW","WSW","W","WNW","NW","NNW");
    return $arr[abs($val)]; // use absolute values to ensure that 359 degrees translates as N, like 1 degree
}


?>
