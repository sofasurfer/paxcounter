<?php
// Copy to config.php and adjust. Do not commit config.php.
const DB_DSN  = 'mysql:host=localhost;dbname=paxcounter;charset=utf8mb4';
const DB_USER = 'paxcounter';
const DB_PASS = 'secret';

// Shared secret, must match HTTP_APIKEY in shared/paxcounter.conf
const API_KEY = 'change-me';

// Delete pax_devices rows older than this many days (0 = keep forever).
// MAC addresses are personal data, a retention limit is recommended.
const DEVICE_RETENTION_DAYS = 0;

// Dashboard login (index.php). Generate the hash with:
//   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
// Empty string disables the dashboard.
const DASHBOARD_PASSWORD_HASH = '';
