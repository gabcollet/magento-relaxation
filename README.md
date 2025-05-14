# Magento Relaxation - Environnement Warden

## Stack
- Magento Open Source 2.4.8
- PHP 8.3
- MySQL 8.4
- OpenSearch 2.19
- Redis 8.0-rc1
- Varnish 7.5
- Mailpit

## Installation

```
git clone <repo>
cd magento-relaxation
warden up -d
composer install
bash scripts/setup-magento.sh
```
