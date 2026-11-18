<?php
// Private adapter selector for the PHP hardware worker.
// Install OUTSIDE the web root:
//   sudo install -m 0640 -o root -g medivend deploy/hardware-adapter.example.php /etc/medivend/hardware-adapter.php
// then set MEDIVEND_HARDWARE_ADAPTER=/etc/medivend/hardware-adapter.php in /etc/medivend/medivend.env.
// The adapter connects to the local Python service (deploy/medivend-hwd.service).
// In MEDIVEND_MODE=hardware it refuses a simulated service; in device_simulation it requires one.
require_once '/srv/medivend/includes/hardware-http-adapter.php';
return new MedivendHttpHardwareAdapter('http://127.0.0.1:8765', '/etc/medivend-hw/hwd.token');
