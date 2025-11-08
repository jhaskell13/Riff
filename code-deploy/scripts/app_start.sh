#!/bin/bash
set -e

# Restart PHP and Nginx
systemctl restart php-fpm
systemctl restart nginx
