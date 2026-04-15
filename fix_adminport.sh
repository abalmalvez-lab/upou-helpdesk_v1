echo "=== Is Apache listening on 8080? ==="
sudo ss -tlnp | grep ':8080'

echo ""
echo "=== Vhost file exists? ==="
ls -la /etc/httpd/conf.d/upou-admin.conf

echo ""
echo "=== Vhost contents ==="
sudo cat /etc/httpd/conf.d/upou-admin.conf

echo ""
echo "=== Admin app files exist? ==="
ls /var/www/upou-helpdesk/admin/public/ 2>&1 | head

echo ""
echo "=== Apache config test ==="
sudo apachectl configtest

echo ""
echo "=== What does Apache think it's serving? ==="
sudo apachectl -S 2>&1 | grep -A 2 "8080\|admin"

echo ""
echo "=== Local request test ==="
curl -sI http://localhost:8080/ | head -5


//

sudo semanage port -a -t http_port_t -p tcp 8080
sudo systemctl restart httpd
sudo ss -tlnp | grep ':8080'

//

sudo dnf install -y policycoreutils-python-utils
sudo semanage port -a -t http_port_t -p tcp 8080
sudo systemctl restart httpd

//

sudo sed -i 's|/var/www/upou-admin/public|/var/www/upou-helpdesk/admin/public|g' /etc/httpd/conf.d/upou-admin.conf
sudo systemctl restart httpd