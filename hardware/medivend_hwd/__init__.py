"""MediVend hardware service.

A localhost-only device service for the Orange Pi One. It owns GPIO (coin
acceptor pulses, IR break-beam, PCA9685 output-enable, optional E-stop) and the
PCA9685 servo driver. It never touches the MariaDB database: PHP remains the
only writer of money and stock. Observations are journaled durably in SQLite
until the PHP worker acknowledges them.
"""

__version__ = "0.1.0"
