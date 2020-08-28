# TheLinkBox Dashboard
A very basic Dashboard to see who is connected to The Link Box reflector on your server.
Please consider helping us develop this dashboard, it could do a lot more!

# Prerequisites
This install makes the assumptions you are aware of:
1) Amateur Radio
2) IRLP
3) IRLP Expermimental Reflectors: http://experimental.irlp.net 
4) TheLinkBox
5) You have already installed your reflector from http://75.127.13.79/TheLinkBox on Debian 10 Buster.

# Install
1) Install Apache and PHP using these instructions: https://computingforgeeks.com/install-php-on-debian-10-buster/
2) Copy or create the <a href="https://raw.githubusercontent.com/Russell-KV4S/TheLinkBox-Dashboard/master/index.php" target="_blank">index.php</a> in the /var/www/html folder. SSH command: cd /var/www/html; sudo nano index.php
3) Open a browser window to your servers IP address and see if it renders. (remember to open port 80 if nesessary)
