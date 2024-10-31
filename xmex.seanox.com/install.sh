[[ -z "$domain" ]] \
    && echo "Missing environment variable: domain" \
    && exit 1
[[ -z "$email" ]] \
    && echo "Missing environment variable: email" \
    && exit 1

# OS: Update / Upgrade
apt-get update
apt-get -y upgrade

# Apache: Install
# https://www.digitalocean.com/community/tutorials/how-to-install-the-apache-web-server-on-ubuntu-20-04
apt install apache2
ufw Status
systemctl status apache2

# PHP: Install
# https://www.atlantic.net/dedicated-server-hosting/how-to-install-php-8-3-on-ubuntu-22-04/
apt install -y curl gpg gnupg2 software-properties-common ca-certificates apt-transport-https lsb-release
apt install -y php8.3 php8.3-{xsl,simplexml}

domain=xmex.seanox.com

# Apache: Configure Virtual Hosts
# https://www.digitalocean.com/community/tutorials/how-to-install-the-apache-web-server-on-ubuntu-22-04#step-5-setting-up-virtual-hosts-recommended
rm -R /var/www/html
mkdir /var/www/${domain}
chown -R www-data:www-data /var/www/${domain}
chmod -R 755 /var/www/${domain}

# Cerbot + Let's Encrypt: Install
# https://www.digitalocean.com/community/tutorials/how-to-secure-apache-with-let-s-encrypt-on-ubuntu
# https://eff-certbot.readthedocs.io/en/latest/using.html
# https://eff-certbot.readthedocs.io/en/stable/using.html
apt install -y certbot python3-certbot-apache
certbot --apache --non-interactive \
    --agree-tos --domains ${domain} --email ${email}
# certbot --apache
#     email: ${email}
#     agree terms: Y
#     share your email address: N
#     domain: ${domain}
#     select domain: 1
systemctl status certbot.timer
certbot renew --dry-run

# XMEX: Install
apt install -y unzip
cd /var/www/${domain}
curl -LO https://github.com/seanox/xml-micro-exchange-php/releases/latest/download/seanox-xmex-latest.zip
unzip seanox-xmex-latest.zip
chown -R www-data:www-data /var/www/${domain}
chmod -R 755 /var/www/${domain}

# OS: Restart and Reboot
apache2ctl configtest
systemctl restart apache2
reboot
