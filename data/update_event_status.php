<?php
require_once __DIR__ . '/../include/config.php';
date_default_timezone_set("Asia/Kolkata");
$now = new DateTime();

$updatedCount = 0; // Counter for updated events

// Get all active events
$eventQuery = $conn->query("
    SELECT id, tittle, start, end, multiple_dates 
    FROM events 
    WHERE status = 1
");

if($eventQuery->num_rows > 0){
    while($event = $eventQuery->fetch_assoc()){
        $eventId = $event['id'];
        $eventTitle = $event['tittle'];
        $end = new DateTime($event['end']);
        $multipleDates = [];

        // Handle multiple_dates
        if(!empty($event['multiple_dates'])){
            $dates = array_map('trim', explode(',', $event['multiple_dates']));
            foreach($dates as $d){
                $startTime = (new DateTime($event['start']))->format('H:i:s'); // keep original start time
                $ts = strtotime($d . ' ' . $startTime);
                $multipleDates[] = $ts;
            }
        }

        $statusChange = false;

        if(!empty($multipleDates)){
            // Check if all multiple dates are past AND end date is past
            $allPast = true;
            foreach($multipleDates as $ts){
                if($ts >= $now->getTimestamp()){
                    $allPast = false;
                    break;
                }
            }
            if($allPast && $end < $now){
                $statusChange = true;
            }
        } else {
            // No multiple dates, only check end date
            if($end < $now){
                $statusChange = true;
            }
        }

        if($statusChange){
            $conn->query("UPDATE events SET status=0 WHERE id='$eventId'");
            $updatedCount++;
            // echo "[".date('Y-m-d H:i:s')."] Event ID $eventId ('$eventTitle') expired and status set to 0\n";
        }
    }

    // echo "[".date('Y-m-d H:i:s')."] Total events updated: $updatedCount\n";
} else {
    // echo "[".date('Y-m-d H:i:s')."] No active events found.\n";
}
?>
