<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Highspinn App Promo</title>
    <style>
        body {
            margin: 0;
            font-family: Helvetica, Arial, sans-serif;
            background-color: #f4f4f9;
            color: #333;
        }

        header {
            background-color: #4a4a8d;
            color: #fff;
            padding: 1.5em 0;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        header h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: bold;
        }

        header p {
            margin: 0.5em 0 1em;
            font-size: 1.2em;
        }

        .cta-button {
            background-color: #ff5733;
            color: #fff;
            padding: 0.75em 1.5em;
            font-size: 1em;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 1em;
            transition: background-color 0.3s ease;
        }

        .cta-button:hover {
            background-color: #e64523;
        }

        main {
            padding: 2em;
        }

        .features {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            gap: 2em;
            margin-bottom: 2em;
        }

        .feature {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 1.5em;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            max-width: 300px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature:hover {
            transform: translateY(-10px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .feature img {
            max-width: 100%;
            height: auto;
            margin-bottom: 1em;
            border-radius: 8px;
        }

        .feature h3 {
            margin-bottom: 0.5em;
            font-size: 1.5em;
            color: #4a4a8d;
        }

        .news-app-promo {
            box-sizing: border-box;
            background-color: #4a4a8d;
            padding: 2em;
            margin-top: 2em;
            text-align: center;
            color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .news-app-promo__section {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1.5em;
            margin-top: 1.5em;
        }

        .news-app-promo-text {
            color: #fff;
            min-width: 200px;
            border: 0.25em solid #fff;
            padding: 1.5em;
            width: 40%;
            margin: 0 auto;
            text-align: center;
            border-radius: 10px;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .news-app-promo-text__tagline {
            font-size: 1.3em;
            margin-bottom: 0.5em;
        }

        .news-app-promo-text__download {
            font-size: 1.5em;
            font-weight: bold;
        }

        .news-app-promo-subsection {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .news-app-promo__bbc-logo {
            width: 106px;
            margin-bottom: 1em;
            border-radius: 10px;
        }

        .news-app-promo__play-store,
        .news-app-promo__app-store {
            width: 161px;
            margin-bottom: 1em;
        }

        .news-app-promo-subsection--link {
            text-decoration: none;
            border: none;
        }

        footer {
            background-color: #4a4a8d;
            color: #fff;
            padding: 1.5em 0;
            text-align: center;
            margin-top: 2em;
            box-shadow: 0 -2px 6px rgba(0, 0, 0, 0.1);
        }

        footer a {
            color: #ffcccb;
            text-decoration: none;
            margin: 0 1em;
            font-size: 1em;
            transition: color 0.3s ease;
        }

        footer a:hover {
            color: #fff;
        }

        .floating-buttons {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 1000;
        }

        .floating-buttons a {
            display: inline-block;
            background-color: #000;
            color: #fff;
            /*padding: 10px 20px;*/
            border-radius: 5px;
            text-align: center;
            text-decoration: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s ease, transform 0.3s ease;
        }

        .floating-buttons a:hover {
            background-color: #333;
            transform: scale(1.1);
        }

        .floating-buttons a img {
            /*width: 24px;*/
            height: 45px;
            /*margin-right: 8px;*/
            vertical-align: middle;
        }
    </style>
    
    <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-9133394092059433"
     crossorigin="anonymous"></script>
</head>
<body>

<header>
    <h1>Welcome to Highspinn</h1>
    <p>Plan, Book, and Enjoy Events Seamlessly</p>
</header>

<main>
    <section class="features">
        <div class="feature">
            <img src="/img/binoculars.png" alt="Discover Events" width="40%">
            <h3>Discover Events</h3>
            <p>Explore a wide range of events tailored to your interests.</p>
            <a href="https://www.highspinn.com/" class="cta-button">Explore Now</a>
        </div>
        <div class="feature">
            <img src="/img/booking.png" alt="Easy Booking" width="40%">
            <h3>Easy Booking</h3>
            <p>Book your tickets effortlessly with our user-friendly interface.</p>
            <a href="https://www.highspinn.com/all-events" class="cta-button">Book Now</a>
        </div>
        <div class="feature">
            <img src="/img/safety.png" alt="Secure Payments" width="40%">
            <h3>Secure Payments</h3>
            <p>Make payments with confidence through our secure gateway.</p>
            <a href="https://www.highspinn.com/" class="cta-button">Pay Securely</a>
        </div>
    </section>

    <div id="newsspec-19854-app" class="news-app-promo">
        <div class="news-app-promo-text">
            <div class="news-app-promo-text__tagline">The best way to book Events</div>
            <div class="news-app-promo-text__download">Download the Highspinn App.</div>
        </div>
        <div class="news-app-promo__section">
            <div class="news-app-promo-subsection">
                <img class="news-app-promo__bbc-logo" 
                     src="https://www.highspinn.com/img/android-icon-192x192.png" 
                     alt="Highspinn Logo" width="106" height="106">
            </div>
            <div class="news-app-promo-subsection">
                <a class="news-app-promo-subsection--link" 
                   href="https://play.google.com/store/apps/details?id=com.highspinn&hl=en" 
                   target="_blank">
                    <img class="news-app-promo__play-store" 
                         src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" 
                         alt="Google Play Store Button" width="161">
                </a>
                <a class="news-app-promo-subsection--link" 
                   href="https://apps.apple.com/in/app/highspinn/id6739353376" 
                   target="_blank">
                    <img class="news-app-promo__app-store" 
                         src="//news.files.bbci.co.uk/include/newsspec/19854/assets/app-project-assets/ios_app_store.svg" 
                         alt="Apple App Store Button" width="161">
                </a>
            </div>
        </div>
    </div>
</main>

<footer>
    <p>&copy; 2025 Highspinn. All rights reserved.</p>
    <a href="https://www.highspinn.com/page/privacy-policy">Privacy Policy</a>
    <a href="https://www.highspinn.com/page/terms-and-condition">Terms of Service</a>
    <!--<br>-->
    <!--<a href="https://play.google.com/store/apps/details?id=com.highspinn&hl=en" class="cta-button" target="_blank">Get it on Google Play</a>-->
    <!--<a href="https://apps.apple.com/in/app/highspinn/id6739353376" class="cta-button" target="_blank">Download on the App Store</a>-->
</footer>

<div class="floating-buttons">
    <a href="https://play.google.com/store/apps/details?id=com.highspinn&hl=en" target="_blank">
        <img src="https://upload.wikimedia.org/wikipedia/commons/7/78/Google_Play_Store_badge_EN.svg" alt="Google Play"></a>
    <a href="https://apps.apple.com/in/app/highspinn/id6739353376" target="_blank">
        <img src="//news.files.bbci.co.uk/include/newsspec/19854/assets/app-project-assets/ios_app_store.svg" alt="Apple Store"></a>
</div>

</body>
</html>
