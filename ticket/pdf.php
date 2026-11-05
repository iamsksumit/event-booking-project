
<?php
include('../include/config.php');

// Include Dompdf Library
require_once '../dompdf/autoload.inc.php'; 

use Dompdf\Dompdf;
use Dompdf\Options;

$id = $_REQUEST['url'];

$query = "SELECT events.url as url,place.name as placename, events.tittle as tittle, events.sub_tittle as sub_tittle, payments.price as price, events.description as description, events.term as term, events.video as video, events.artist as artist, events.Organised as Organised, events.special_detail as special_detail, events.image as image, events.start as start, events.end as end, payments.name as fname, payments.id as id,payments.created_at as pcreated_at, payments.pay_ref as ref from payments INNER JOIN events ON payments.event_id = events.url INNER JOIN place ON events.sub_tittle = place.id where payments.pay_ref='".$id."'"; 
$result = mysqli_query($conn, $query) or die(mysqli_error($query));
$row = mysqli_fetch_assoc($result);

    $month = $row["start"];

    $months = date_create($month);
     $monthss = date_format($months,"M");
     $date = date_format($months,"d");
          $day = date_format($months,"l");
          $time = date_format($months,"g:i a");

 $monthend = $row["end"];
    $monthsend = date_create($monthend);
     $monthssend = date_format($monthsend,"M");
     $dateend = date_format($monthsend,"d");
          $dayend = date_format($monthsend,"l");
          $timeend = date_format($monthsend,"g:i a");
                    $timesend = date_format($monthsend,"h:i:s");
     $yearend = date_format($monthsend,"Y");


// Dompdf setup
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isPhpEnabled', true);
$options->set('isUtf8', true);  // Ensure UTF-8 encoding support
$dompdf = new Dompdf($options);

// Generate the QR code image using QuickChart API and save it locally
$qr_code_url = 'https://quickchart.io/qr?text=' . urlencode($row['ref']) . '&size=200';
$qr_code_image = file_get_contents($qr_code_url);
$qr_code_base64 = base64_encode($qr_code_image);


$logoimg = 'https://www.highspinn.com/img/logo.png';
$logoimage = file_get_contents($logoimg);
$logobase64 = base64_encode($logoimage);

// Create HTML content for the PDF
$html = '
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <style>
        body {
            font-family: "DejaVu Sans", sans-serif;
            margin: 0;
            padding: 0;
            background-color: #fafafa;
        }
        .container {
            padding: 0;
            margin-top: 20px;
        }
        .ticket {
            background: #fff;
            border-radius: 10px;
            width: 100%;
            max-width: 800px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            margin: auto;
            padding: 20px;
        }
        .ticket-header {
            background-color: #000;
            color: #fff;
            padding: 25px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ticket-header img {
            width: 80px;
            margin-top: 15px;
        }
        .ticket-header .event-title {
            flex-grow: 1;
            text-align: left;
            padding-left: 20px;
        }
        .ticket-body {
            padding: 20px;
            color: #333;
        }
        .ticket-body h4 {
            color: #000;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .ticket-body p {
            font-size: 14px;
            margin-bottom: 10px;
        }
        .ticket-body table {
            width: 100%;
        }
        .ticket-body td {
            padding: 8px;
            font-size: 14px;
        }
        .ticket-footer {
            background-color: #000;
            color: #fff;
            text-align: center;
            padding: 15px;
            font-size: 14px;
            border-radius: 0 0 10px 10px;
        }
        .important-instructions {
            padding: 15px;
            background-color: #f8f8f8;
            border-top: 2px solid #ddd;
            font-size: 12px;
        }
        .qr-code {
            text-align: center;
        }
        .qr-code img {
            max-width: 120px;
            margin-top: 15px;
        }
        .price-summary {
            margin-top: 20px;
        }
        .price-summary td {
            padding: 10px;
            font-size: 14px;
        }
        .price-summary .left-column {
            width: 50%;
        }
        .price-summary .right-column {
            width: 50%;
            text-align: right;
        }
        @media (max-width: 768px) {
            .ticket-header h2 {
                font-size: 18px;
            }
            table td {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="ticket">

                  <div class="ticket-header">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <!-- Logo Placeholder -->
            <td style="width: 20%; text-align: left; padding-left: 10px;">
                <img src="data:image/png;base64,' . $logobase64 . '" alt="Logo" style="max-width: 80px; max-height: 80px; object-fit: contain;">
            </td>
            <!-- Event Title -->
            <td style="width: 60%; text-align: center;">
                <h2 style="margin: 0; font-size: 20px; color: #ffffff;">' . htmlspecialchars($row['tittle']) . '</h2>
                <p style="margin: 0; font-size: 14px; color: #ffffff;">' . htmlspecialchars($row['placename']) . '</p>
                                <p style="margin: 0; font-size: 14px; color: #ffffff;"> '.$day.'  '.$date.'  '.$monthss.' '.$time. '</p>

               
            </td>
            <!-- QR Code -->
            <td style="width: 20%; text-align: right; padding-right: 10px;">
                <div class="qr-code">
                    <img src="data:image/png;base64,' . $qr_code_base64 . '" alt="QR Code" style="max-width: 80px; max-height: 80px; object-fit: contain;">
                </div>
            </td>
        </tr>
    </table>
</div>

            </div>

            <div class="ticket-body">
                <table>
                    <tr>
                        <!-- Booking Details Column -->
                        <td>
                            <h4>Booking Details</h4>
                            <p><strong>Order No:</strong> ' . $row['id'] . '</p>
                            <p><strong>Booked By:</strong> ' . $row['fname'] . '</p>
                            <p><strong>Event Details:</strong> ' . $row['sub_tittle'] . '</p>
                            <p><strong>Booking Date:</strong> ' . $row['pcreated_at'] . '</p>
                            <p><strong>Location:</strong> ' . $row['placename'] . '</p>
                        </td>

                        <!-- Purchased Tickets Column -->
                        <td>
                            <h4>Tickets Purchased</h4>';
                            // Fetch ticket order details
                            $sql = "SELECT ticket_orders.ticket_name, ticket_orders.quantity FROM ticket_orders INNER JOIN payments ON ticket_orders.payment_ref = payments.pay_ref WHERE ticket_orders.payment_ref = '$id'";
                            $result = $conn->query($sql);
                            if ($result->num_rows > 0) {
                                while ($rowss = $result->fetch_assoc()) {
                                    $html .= '<p>' . $rowss['ticket_name'] . ': ' . $rowss['quantity'] . '</p>';
                                }
                            }
$html .= '</td>
                    </tr>
                </table>

                <div class="price-summary">
                    <table>
                        <tr>
                            <td class="left-column"><strong>Price:</strong> ₹' . number_format($row['price'], 2) . '</td>
                            <td class="right-column"><strong>Discount:</strong> ₹0</td>
                        </tr>
                        <tr>
                            <td class="left-column"><strong>Subtotal:</strong> ₹' . number_format($row['price'], 2) . '</td>
                            <td class="right-column"><strong>Convenience Charges:</strong> ₹0.0</td>
                        </tr>
                        <tr>
                            <td class="left-column"><strong>CGST/SGST/IGST:</strong> ₹0.0</td>
                            <td class="right-column"><strong>Amount Paid:</strong> ₹' . number_format($row['price'], 2) . '</td>
                        </tr>
                    </table>
                </div>

                <div class="important-instructions">
                    <h5>Important Instructions</h5>
                    <ul>
                        <li>Kindly show your Email/SMS at the venue to avail your entry.</li>
                        <li>This ticket is valid only for the event mentioned above.</li>
                        <li>No refund will be issued post event.</li>
                    </ul>
                </div>
            </div>

            <div class="ticket-footer">
                <p>Organized By: ' . $row['Organised'] . '</p>
                <p>Email: highspinn@gmail.com</p>
            </div>

        </div>
    </div>

</body>
</html>';

$dompdf->loadHtml($html);

// Set paper size (A4, Portrait)
$dompdf->setPaper('A4', 'portrait');

// Render PDF (first pass to get PDF generated)
$dompdf->render();

// Output the generated PDF (stream to browser)
$dompdf->stream('ticket.pdf', array('Attachment' => 0)); // 0 means it will open in browser, 1 will force download
?>