<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-XB2VDHK2BK"></script>
<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
<link rel="manifest" href="/site.webmanifest">
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-XB2VDHK2BK');
</script>
<?php





// Animation Variables
$carouselSpeed = 4;

$boldPeak = 900;










$SERVICE_KEYWORD = "for-the-best";
$jsonString = file_get_contents('data/data.json');

// Decode the JSON string into an associative array
$data = json_decode($jsonString, true);


if(getValueOrDefault($SERVICE_KEYWORD,"") !=  ""){
    $page = $data[getValueOrDefault($SERVICE_KEYWORD,"")];
    // Access the values and assign them to variables
    $editingTitle = $page['editingTitle'];
    $editingDescription = $page['editingDescription'];
    $finalText = $page['finalText'];
    $circleCenterText = $page['circleCenterText'];
}


function getValueOrDefault($param, $default = null) {
    if (isset($_GET[$param])) {
        return $_GET[$param];
    } else {
        return $default;
    }
}




function send_whatsapp($body)
{
    $phone_number = "9540867732"; // Replace with the desired phone number
    $from = "whatsapp:+14155238886";
    $to = "+91" . $phone_number;
    $accountSid = "ACd53b3266e382e294482e58a1f96c6f51";
    $authToken = "c1e705fc862f46882c6e14bb5b508ef4";

    // Send the new request using the cURL command
    $command = "curl 'https://api.twilio.com/2010-04-01/Accounts/$accountSid/Messages.json' -X POST " .
               "--data-urlencode 'To=whatsapp:$to' " .
               "--data-urlencode 'From=whatsapp:+14155238886' " .
               "--data-urlencode 'Body=$body' " .
               "-u $accountSid:$authToken";
    exec($command, $output, $returnVar);

    return $response;
}
$currentURL = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$currentURL .= "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$visitor_info = getVisitorInfo();
$ip = $visitor_info['ip'];
$device = $visitor_info['device'];
//send_whatsapp("There was a website visit. ".$currentURL."\nVisitor IP: " . $ip . "\n"."Device: " . $device);



function getVisitorInfo() {
    // Get visitor's IP address
    $ip = $_SERVER['REMOTE_ADDR'];

    // Get device information
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $device_info = parse_user_agent($user_agent);

    // Combine IP and device information into an array
    $visitor_info = array(
        'ip' => $ip,
        'device' => $device_info
    );

    return $visitor_info;
}

function parse_user_agent($user_agent) {
    // Array of known devices and their patterns
    $devices = array(
        'iPhone' => '/iPhone/i',
        'iPad' => '/iPad/i',
        'Android Phone' => '/Android/i',
        'Android Tablet' => '/Android/i',
        'BlackBerry' => '/BlackBerry/i',
        'Windows Phone' => '/IEMobile/i',
        'Windows Tablet' => '/Windows NT/i',
        'Desktop' => '/Windows NT|Macintosh|Linux/i'
    );

    // Iterate through the devices array to match the user agent string
    foreach ($devices as $device => $pattern) {
        if (preg_match($pattern, $user_agent)) {
            return $device;
        }
    }

    return 'Unknown';
}