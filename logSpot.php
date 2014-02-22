<?php date_default_timezone_set('America/Vancouver'); // Set default timezone for script

/*****************************************************************************************
NOTE: You will need to make a public page through the spot dashboard, and then view the
source to get the message API url needed a few lines down.

More Information about feeds: http://faq.findmespot.com/index.php?action=showEntry&data=69

To capture all tracking data, this script needs to be run at verying minimum frequencies,
depending on the device's tracking speed.  Consult the chart below for tracking rates, and
the corresponding minimum intervals this script needs to be run. (Tracking Interval x 50)

	2.5 min		2.08 hours
	5 min		4.17 hours
	10 min		8.33 hours
	30 min		25.0 hours
	60 min		50.0 hours

I suggest setting up this code to run on a cron tab at one of the frequencies seen above.

OTHER DEVE NOTES:
 - If you don't log for 7 days and the feed returns empty, I'm not sure how this script
   will respond.
******************************************************************************************/

/***************************
	GET DATA FROM SPOT
***************************/

// Copy the URL from the source code on the public tracking page
$spot_messageServiceUrl = "https://api.findmespot.com/spot-main-web/consumer/rest-api/2.0/public/feed/<<<YOUR-UNIQUE-FEED-CODE-HERE>>>/message";

// Get at format tracking data from spot server
$xmlFromSpot = file_get_contents($spot_messageServiceUrl);
$spotLogs = json_decode($xmlFromSpot, true); // Convert JSON to array
$spotLogs = $spotLogs['response']['feedMessageResponse']['messages']['message']; // Trim the containers



/*********************************
 CONNECT TO DB AND GET LAST ENTRY
*********************************/
// The database connection settings
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
$db_table = "spot_log";

// Make the connection and select the database.
$dbc = mysql_connect (DB_HOST, DB_USER, DB_PASSWORD) OR
die ('Could not connect to MySQL: ' . mysql_error() );
mysql_select_db (DB_NAME) OR
die ('Could not select the database: ' . mysql_error() );

// If this is the first time running, then create the table in the database
$sql = "CREATE TABLE IF NOT EXISTS `$db_table` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `idFromSpot` int(15) NOT NULL,
  `messengerId` varchar(50) NOT NULL,
  `messengerName` varchar(100) NOT NULL,
  `unixTime` int(12) NOT NULL,
  `messageType` varchar(25) NOT NULL,
  `latitude` varchar(25) NOT NULL,
  `longitude` varchar(25) NOT NULL,
  `modelId` varchar(25) NOT NULL,
  `showCustomMsg` varchar(25) NOT NULL,
  `dateTime` varchar(100) NOT NULL,
  `batteryState` varchar(25) NOT NULL,
  `hidden` varchar(25) NOT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
if (!mysql_query($sql)) die("Could not create table in databse: ".mysql_error());

// Get the most recent entry in our database for this spot device
$result = mysql_query("SELECT * FROM $db_table WHERE messengerId = '".$spotLogs[0]['messengerId']."' ORDER BY dateCreated DESC LIMIT 1");
$row = mysql_fetch_array($result, MYSQL_ASSOC);



/***************************
	LOOP THROUGH DATA
***************************/
$values = array();
$spotLogs = array_reverse($spotLogs);  // Flip the array to log oldest first
foreach ($spotLogs as $spot){
	
	// Only log if it's a new data point
	if (empty($row) || ( $spot['unixTime'] > $row['unixTime'] && $spot['idFromSpot'] != $row['id'] ) ){
		array_shift($spot); // Drop the "clientUnixTime" in the array
		$values[] = "('".implode("','",$spot)."')"; // Save the values from this row
	}
}

// If there aren't any new data points, then quit.
if (sizeof($values) == 0) die("No new data points.");



/***************************
	SAVE TO DATABASE
***************************/
// Build SQL Insert Query
$sql = "INSERT INTO $db_table (
			idFromSpot,
			messengerId,
			messengerName,
			unixTime,
			messageType,
			latitude,
			longitude,
			modelId,
			showCustomMsg,
			dateTime,
			batteryState,
			hidden
		) VALUES ".implode(",",$values);

// Insert spot logs into database
if (mysql_query($sql)) $error = "";
else $error = "Could not INSERT data: ".mysql_error()."<br /><br />SQL Query: ".$sql;



/******************************
	EMAIL IF ERROR INSERTING
******************************/
if ($error != ""){
	
	// Include the email class
	include("includes/Mail.php");

	// Setup the email headers
	$email_from = "example@gmail.com";
	$email_to = "example@gmail.com";

	// Email body text
	$message = "<html><body>
	<p>There was an error with the cron to log the Spot data.</p>
	<p style=\"font-size: 12px; font-weight: bold;\">".$error."</p>
	<br /><br />
	<p>--<br />
	<em>Automated email</em></p>
	<hr />
	<p style=\"font-size: 18px; font-weight: bold;\">Below is the XML captured from the SPOT Messaging URL:</p>
	<div style=\"font-size: 8px; color: #AAA; margin: 24px; padding: 24px; border: 9px solid #DADFE6;\">".$xmlFromSpot."</div>
	</body></html>";
	
	// Email credentials
	$host = "ssl://smtp.gmail.com";
	$username = "example@gmail.com";
	$password = "your-password";
	
	$headers = array ('From' => $email_from,
		'To' => $email_to,
		'Content-Type' => 'text/html;charset=iso-8859-1',
		'Subject' => "Spot Logging Error");
	$smtp = Mail::factory('smtp',
		array ('host' => $host,
		'port' => '465',
		'auth' => true,
		'username' => $username,
		'password' => $password) );

	// To Blind CC (aka, BCC) an address, simply add the address to the $recipients, but not to any of the $headers
	$mail = $smtp->send($email_to, $headers, $message);
	
	if (PEAR::isError($mail)) $error = "Failed to send email. (".$mail->getMessage().")";
	else $error = "";

	echo $message;
	
	
	
// If no error, return a success message
}else echo "Successfully logged data points: ".sizeof($values);

?>