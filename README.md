# Event Booking Platform
 
A web-based platform for discovering events and booking tickets online. Organisers (partners) publish events and sell tickets, users book and pay online, and administrators manage the whole platform.
 
Submitted as a B.Tech (Computer Science and Engineering) project by **Vinit Kumar Gupta** (Roll No. 2004720109012), Institute of Technology & Management, Maharajganj, affiliated to Dr. A.P.J. Abdul Kalam Technical University, Lucknow. Supervisor: Amit Kaushal (HOD).
 
## Features
 
**Guest**
- Browse events, event details, artists, places and blogs
- Search events by city and category
**User**
- Register, log in, reset password and update profile
- Book tickets for self and for other guests
- Pay online through Razorpay
- Receive SMS alerts (Fast2SMS) and a unique ticket code for every guest
- View booking history, report an event, raise support tickets and receive notifications
**Partner (Event Organizer)**
- Create and edit events with one or multiple dates
- Define ticket types with price and quantity
- View bookings and guest lists
- Check in guests at the gate using the ticket code, or deny entry with a reason
- Submit KYC and payout details, and request payouts
- Buy boost packages to promote events
**Administrator**
- Manage users and partners (including banning accounts)
- Approve or reject events and verify partner KYC
- Manage categories, artists, places, banners, blogs and pages
- Create boost packages and process payouts
- Handle support tickets and event reports
## Tech Stack
 
| Layer | Technology |
|---|---|
| Server side | PHP, REST APIs |
| Client side | HTML, CSS, Bootstrap, JavaScript |
| Database | MySQL |
| Payment gateway | Razorpay API |
| SMS | Fast2SMS API |
 
## Database
 
The MySQL database has 35 tables. The main ones are:
 
| Area | Tables |
|---|---|
| Accounts | `users`, `partner`, `admin`, `partner_kyc`, `partner_payout_details` |
| Events | `events`, `event_tickets`, `category`, `event_category`, `artist`, `event_artist`, `place` |
| Bookings and payments | `payments`, `booking_guests`, `ticket_orders` |
| Promotion | `boost_packages`, `boost_package_orders`, `partner_promotions` |
| Payouts | `payouts` |
| Support | `support_tickets`, `support_ticket_replies`, `event_reports`, `notifications` |
| Site content and master data | `banner`, `blog`, `menu`, `page`, `settings`, `tabs`, `urls`, `views`, `event_type`, `tbl_cities`, `tbl_states`, `tbl_countries` |
 
## Booking Flow
 
1. User opens the site and browses events.
2. User logs in or registers.
3. User selects an event and ticket types; availability (`tickets_left`) is checked.
4. A Razorpay order is created and the user pays.
5. On payment confirmation the payment and guest records are saved and a ticket code is generated.
6. An SMS confirmation is sent through Fast2SMS and the e-ticket is shown to the user.
7. At the venue, the partner's staff verify the ticket code and check the guest in.
## Getting Started
 
### Prerequisites
- A web server with PHP
- MySQL (or MariaDB) and phpMyAdmin or another MySQL client
- A Razorpay account (API key id and secret)
- A Fast2SMS account (API key)
### Installation
 
1. Clone or copy the project into your web server's document root.
2. Create a database and import the SQL dump (for example `u197683135_host.sql`) using phpMyAdmin or the `mysql` command line.
3. Open the project's database configuration file and set your database host, name, username and password.
4. Add your Razorpay key id and secret, and your Fast2SMS API key, to the project's configuration.
5. Open the site in your browser.
> Keep API keys and database passwords out of version control. Store them in a configuration file that is listed in `.gitignore`, or in environment variables.
 
## Documentation
 
The project synopsis (use case, sequence and data flow diagrams, ER diagram, database design and modules) is provided as `BTech_Capstone_Project_Documentation_Vinit_Gupta.pdf`.
 
## Author
 
Vinit Kumar Gupta, B.Tech CSE, Institute of Technology & Management, Maharajganj (2020-2023).
 
