--------------------Install Apache2----------------
sudo apt update
sudo apt install apache2
sudo apache2ctl -v
systemctl is-enabled apache2


----------------Install MySQL----------------
sudo apt install mysql-server

sudo mysql_secure_installation

sudo mysql
SELECT user,authentication_string,plugin,host FROM mysql.user;
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY 'your_secure_password';
SELECT user,authentication_string,plugin,host FROM mysql.user;
exit

mysql -u root -p
SELECT user,authentication_string,plugin,host FROM mysql.user;

===Create New User===
CREATE USER 'magento2'@'localhost' IDENTIFIED BY 'your_secure_password';
ALTER USER 'magento2'@'localhost' IDENTIFIED WITH mysql_native_password BY 'Avinash@508';
GRANT ALL PRIVILEGES ON *.* TO 'magento2'@'localhost' WITH GRANT OPTION;
exit

mysql -u magento2 -p
CREATE DATABASE magento2;
exit

----------Install PHP and required extensions----------------
sudo apt update

sudo apt install php8.3 libapache2-mod-php php-mysql
sudo apt install php8.3-curl php8.3-gd php8.3-intl php8.3-mbstring php8.3-soap php8.3-xml php8.3-zip php8.3-bcmath php8.3-json

sudo nano /etc/apache2/mods-enabled/dir.conf
==Set Below Data==
<IfModule mod_dir.c>
    DirectoryIndex index.php index.html index.cgi index.pl index.xhtml index.htm
</IfModule>

sudo apt install php8.3-mbstring
sudo phpenmod mbstring
sudo a2enmod rewrite

sudo apt install php8.3-bcmath php8.3-intl php8.3-soap php8.3-zip php8.3-gd php8.3-json php8.3-curl php8.3-cli php8.3-xml php8.3-xmlrpc php8.3-gmp php8.3-common

sudo systemctl reload apache2

php -i | grep "Configuration File"
sudo nano <path_of_php.ini_file>

==Set Below Data==
max_execution_time=18000
max_input_time=1800
memory_limit=4G


-------------Install ElasticSearch----------------
sudo apt install openjdk-17-jre
sudo apt install curl

sudo curl -sSfL https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --no-default-keyring --keyring=gnupg-ring:/etc/apt/trusted.gpg.d/magento.gpg --import
sudo sh -c 'echo "deb https://artifacts.elastic.co/packages/7.x/apt stable main" > /etc/apt/sources.list.d/elastic-7.x.list'
sudo chmod 666 /etc/apt/trusted.gpg.d/magento.gpg

sudo apt update
sudo apt install elasticsearch
sudo systemctl daemon-reload
sudo systemctl enable elasticsearch.service
sudo systemctl start elasticsearch.service

sudo nano /etc/elasticsearch/elasticsearch.yml
==Set Below Data==
node.name: "My First Node"
cluster.name: my-application

network.host: 127.0.0.1
http.port: 9200

sudo nano /etc/elasticsearch/jvm.options
==Set Below Data==
-Xms256m
-Xmx256m

sudo nano /usr/lib/systemd/system/elasticsearch.service
==Set Below Data==
TimeoutStartSec: 900

sudo systemctl daemon-reload
sudo systemctl start elasticsearch.service


---------Testing ElasticSearch----------------
curl -X GET 'http://localhost:9200'



---------------Install Composer----------------
curl -sS https://getcomposer.org/installer -o composer-setup.php
sudo php composer-setup.php --install-dir=/usr/bin --filename=composer

composer


---------------Install Magento 2----------------
cd /var/www/html
sudo composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition=2.4.7-p2 magento2
Username: Your public Key  2fc966a913d4e83b28041eeb3c3b72e5
Password: Your private key. 48e05400d17ca1bcb4e693825c45416e

sudo chown -R www-data:www-data magento2
cd magento2

sudo nano /etc/apache2/sites-available/000-default.conf
<Directory "/var/www/html">
    AllowOverride All
</Directory>

php bin/magento setup:install --base-url="http://79.137.33.228/engagewave/pub" --db-host="localhost" --db-name="magento2" --db-user="root" --db-password="Avinash@508" --admin-firstname="admin" --admin-lastname="admin" --admin-email="sauravsidharth992@gmail.com" --admin-user="admin" --admin-password="admin123" --language="en_US" --currency="INR" --timezone="America/Chicago" --use-rewrites="1" --backend-frontname="admin" --search-engine=elasticsearch7 --elasticsearch-host="localhost" --elasticsearch-port=9200


