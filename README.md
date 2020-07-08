# LoxoneWeather
How I've integrated my weatherstation with Loxone

I have recently bought a weatherstation that has a small web-server with actual measurement values. It has functionality to upload these measurements automatically to Weatherunderground. It is known as the FOSHK WH2600 (also sold as Froggit WH2601 LAN, by Conrad as Renkforce WH2600, by Ambient Weather USA as WS-1400). It costs EUR 180, and can be ordered https://www.conrad.nl/nl/digitaal-draadloos-weerstation-renkforce-wh2600-voorspelling-voor-12-tot-24-uur-1267654.html from Conrad.

I wrote a PHP script to scrape the actual measurement values from the internal webpage of the weatherstation (which is at http://ip-of-weather/livedata.htm). I then create json messages in the Domoticz style for temperature, humidity, pressure, wind, lux etc. These are sent via MQTT to Domoticz.

Define virtual sensors for temperature, humidity, windspeed in Domoticz using these MQTT values (see the php script). For details on the json formatting see https://www.domoticz.com/wiki/Domoticz_API/JSON_URL%27s .

Use the corresponding IDX values in the php script, to update the table $idxlookup. Everytime the php script is run the virtual devices in Domoticz will receive a new, updated value.

The script is run once every 2 minutes using crontab, and the measurement values are updated.

I'm enclosing my code as an example in scrape_weather.php

### Update to later version of Ambient software
I started encountering errors with the device, and the webpage would become slow or inaccessible. I upgraded the software (see versions here https://www.ambientweather.com/observerip.html) however this did not resolve the problem.

It was found that the Observerip module has difficulty when multiple clients access the data simultaneously. Because that happens in my situation (the raspberry Pi with Domoticz scrapes it once every 10 minutes, and Loxone scrapes it every 6 minutes) I have now modified the script so that a copy of the livedata.htm file is stripped of the Javascript and then written to the /var/www/html directory of the raspberry Pi. It can then be accessed by Loxone from  http://ip-of-raspberry/livedata.htm.

## Loxone
Loxone is set up with virtual inputs and the correct scrape commands to retrieve the values from the scraped html page.

### Wind direction
Command recognition: 
